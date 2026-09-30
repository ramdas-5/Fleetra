<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/operations.php
 *
 * Shared operational logic for the scheduling and trip modules.
 *
 * Two things every part of the system must agree on live here:
 *
 *   1. How a departure/arrival pair is compared. A service can run past
 *      midnight, so 22:30 → 01:15 is a valid 165 minute trip. Every
 *      overlap check therefore works in minutes from midnight and adds a
 *      day when the arrival is earlier than the departure.
 *
 *   2. What happens to passengers when a service is cancelled. Bookings
 *      are cancelled, paid fares are refunded, tickets are voided and the
 *      affected passengers are notified — in one transaction, so a
 *      service can never be cancelled while its seats still look sold.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------
 | Time helpers
 ------------------------------------------------------------------ */

/** Minutes from midnight for a HH:MM(:SS) value. Returns -1 when invalid. */
function time_to_minutes(?string $time): int
{
    $time = trim((string) $time);

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
        return -1;
    }

    $hours   = (int) $matches[1];
    $minutes = (int) $matches[2];

    if ($hours > 23 || $minutes > 59) {
        return -1;
    }

    return ($hours * 60) + $minutes;
}

/**
 * Normalise a departure/arrival pair into minutes, extending the arrival
 * past midnight when the service crosses into the next day.
 *
 * @return array{0:int, 1:int}|null Null when either value is not a time.
 */
function time_range_minutes(?string $departure, ?string $arrival): ?array
{
    $start = time_to_minutes($departure);
    $end   = time_to_minutes($arrival);

    if ($start < 0 || $end < 0) {
        return null;
    }

    if ($end <= $start) {
        $end += 1440;
    }

    return [$start, $end];
}

/** Duration in minutes between a departure and an arrival. */
function time_range_duration(?string $departure, ?string $arrival): int
{
    $range = time_range_minutes($departure, $arrival);

    if ($range === null) {
        return 0;
    }

    return $range[1] - $range[0];
}

/** Format a HH:MM:SS clock value for display, e.g. "06:30 AM". */
function minutes_to_time(int $minutes): string
{
    $minutes = (($minutes % 1440) + 1440) % 1440;

    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

/** A human label such as "06:30 AM → 09:45 AM". */
function time_range_label(?string $departure, ?string $arrival): string
{
    return format_time($departure) . ' → ' . format_time($arrival);
}

/* ------------------------------------------------------------------
 | Conflict detection
 ------------------------------------------------------------------ */

/**
 * Every non-cancelled schedule on the same date that shares the bus or the
 * driver, with the overlap already worked out.
 *
 * The database unique keys only catch an identical (bus, date, departure)
 * or (driver, date, departure) slot. A range check is required to catch a
 * genuine overlap, e.g. 06:30–09:45 against 08:00–10:00.
 *
 * @param array<string, mixed> $schedule bus_id, driver_id, schedule_date, departure_time, arrival_time
 * @return array{bus:array<int,array<string,mixed>>, driver:array<int,array<string,mixed>>}
 */
function find_schedule_conflicts(array $schedule, ?int $excludeScheduleId = null): array
{
    $conflicts = ['bus' => [], 'driver' => []];

    $candidate = time_range_minutes(
        (string) ($schedule['departure_time'] ?? ''),
        (string) ($schedule['arrival_time'] ?? '')
    );

    if ($candidate === null) {
        return $conflicts;
    }

    $busId    = (int) ($schedule['bus_id'] ?? 0);
    $driverId = (int) ($schedule['driver_id'] ?? 0);
    $date     = (string) ($schedule['schedule_date'] ?? '');
    $exclude  = $excludeScheduleId ?? 0;

    if ($busId <= 0 || $driverId <= 0 || $date === '') {
        return $conflicts;
    }

    $rows = db_all(
        'SELECT s.id, s.bus_id, s.driver_id, s.schedule_date, s.departure_time, s.arrival_time, s.status,
                r.route_code, r.route_name, b.bus_number, u.name AS driver_name
           FROM schedules s
           JOIN routes r  ON r.id = s.route_id
           JOIN buses b   ON b.id = s.bus_id
           JOIN drivers d ON d.id = s.driver_id
           JOIN users u   ON u.id = d.user_id
          WHERE s.schedule_date = ?
            AND s.status <> "cancelled"
            AND s.id <> ?
            AND (s.bus_id = ? OR s.driver_id = ?)
          ORDER BY s.departure_time',
        [$date, $exclude, $busId, $driverId]
    );

    foreach ($rows as $row) {
        $existing = time_range_minutes((string) $row['departure_time'], (string) $row['arrival_time']);

        if ($existing === null) {
            continue;
        }

        // Overlap when the intervals share more than a single instant.
        if ($candidate[0] >= $existing[1] || $candidate[1] <= $existing[0]) {
            continue;
        }

        if ((int) $row['bus_id'] === $busId) {
            $conflicts['bus'][] = $row;
        }

        if ((int) $row['driver_id'] === $driverId) {
            $conflicts['driver'][] = $row;
        }
    }

    return $conflicts;
}

/**
 * Turn conflicts into a single sentence an operator can act on.
 *
 * @param array{bus:array<int,array<string,mixed>>, driver:array<int,array<string,mixed>>} $conflicts
 */
function describe_schedule_conflicts(array $conflicts): string
{
    $parts = [];

    foreach ($conflicts['bus'] as $row) {
        $parts[] = 'bus ' . $row['bus_number'] . ' is already on ' . $row['route_code'] . ' '
            . time_range_label((string) $row['departure_time'], (string) $row['arrival_time']);
    }

    foreach ($conflicts['driver'] as $row) {
        $parts[] = 'driver ' . $row['driver_name'] . ' is already on ' . $row['route_code'] . ' '
            . time_range_label((string) $row['departure_time'], (string) $row['arrival_time']);
    }

    if ($parts === []) {
        return '';
    }

    return 'This departure overlaps an existing service: ' . implode('; ', $parts)
        . '. Choose another time, bus or driver.';
}

/* ------------------------------------------------------------------
 | Passenger notifications
 ------------------------------------------------------------------ */

/**
 * Notify every passenger holding an active booking on a schedule.
 *
 * @param array<int, string> $bookingStatuses Bookings considered active.
 * @return int Number of passengers notified.
 */
function notify_schedule_passengers(
    int $scheduleId,
    string $title,
    string $message,
    string $type = 'trip',
    array $bookingStatuses = ['pending', 'confirmed']
): int {
    if ($scheduleId <= 0 || $bookingStatuses === []) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($bookingStatuses), '?'));
    $params       = array_merge([$scheduleId], array_values($bookingStatuses));

    $passengers = db_all(
        "SELECT DISTINCT b.user_id, b.id AS booking_id
           FROM bookings b
          WHERE b.schedule_id = ? AND b.booking_status IN ($placeholders)",
        $params
    );

    $notified = 0;

    foreach ($passengers as $passenger) {
        $userId = (int) $passenger['user_id'];

        if ($userId <= 0) {
            continue;
        }

        try {
            db_insert('notifications', [
                'user_id'           => $userId,
                'title'             => $title,
                'message'           => $message,
                'notification_type' => $type,
                'reference_id'      => $scheduleId,
                'is_read'           => 0,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            $notified++;
        } catch (Throwable $exception) {
            fleetra_log('Passenger notification failed: ' . $exception->getMessage(), 'WARNING');
        }
    }

    return $notified;
}

/** Send a notification to one user account. */
function notify_user(int $userId, string $title, string $message, string $type = 'system', ?int $referenceId = null): void
{
    if ($userId <= 0) {
        return;
    }

    try {
        db_insert('notifications', [
            'user_id'           => $userId,
            'title'             => $title,
            'message'           => $message,
            'notification_type' => $type,
            'reference_id'      => $referenceId,
            'is_read'           => 0,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $exception) {
        fleetra_log('Notification failed: ' . $exception->getMessage(), 'WARNING');
    }
}

/* ------------------------------------------------------------------
 | Cancelling a service
 ------------------------------------------------------------------ */

/**
 * Cancel a scheduled service and everything that depends on it.
 *
 * Bookings are cancelled, tickets are voided, fares that were actually
 * collected are marked refunded (with their payment record) and each
 * affected passenger plus the assigned driver is notified. All of it
 * happens in one transaction.
 *
 * @return array{bookings:int, tickets:int, refunded:float, notified:int}
 */
function cancel_schedule_service(int $scheduleId, string $reason, bool $notify = true): array
{
    $summary = ['bookings' => 0, 'tickets' => 0, 'refunded' => 0.0, 'notified' => 0];

    $schedule = db_one(
        'SELECT s.*, r.route_code, r.route_name, r.source, r.destination, b.bus_number, d.user_id AS driver_user_id,
                u.name AS driver_name
           FROM schedules s
           JOIN routes r  ON r.id = s.route_id
           JOIN buses b   ON b.id = s.bus_id
           JOIN drivers d ON d.id = s.driver_id
           JOIN users u   ON u.id = d.user_id
          WHERE s.id = ?
          LIMIT 1',
        [$scheduleId]
    );

    if ($schedule === null) {
        return $summary;
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        db_update('schedules', ['status' => 'cancelled'], ['id' => $scheduleId]);

        $trip = db_one('SELECT id FROM trips WHERE schedule_id = ? LIMIT 1', [$scheduleId]);

        if ($trip !== null) {
            db_update('trips', [
                'trip_status' => 'cancelled',
                'remarks'     => $reason !== '' ? $reason : 'Service cancelled.',
            ], ['id' => (int) $trip['id']]);
        }

        $affected = db_all(
            'SELECT b.id, b.user_id, b.booking_number, b.fare, b.payment_status, t.id AS ticket_id
               FROM bookings b
               LEFT JOIN tickets t ON t.booking_id = b.id
              WHERE b.schedule_id = ? AND b.booking_status IN ("pending", "confirmed")',
            [$scheduleId]
        );

        // Collected before the bookings are cancelled, so the passengers can
        // still be notified once the transaction is committed.
        $passengerIds = [];

        foreach ($affected as $booking) {
            $bookingId = (int) $booking['id'];
            $passengerIds[(int) $booking['user_id']] = (int) $booking['user_id'];

            // Only money that was actually collected gets refunded.
            if ((string) $booking['payment_status'] === 'paid') {
                db_update('bookings', [
                    'booking_status' => 'cancelled',
                    'payment_status' => 'refunded',
                ], ['id' => $bookingId]);

                db_execute(
                    'UPDATE payments SET status = "refunded" WHERE booking_id = ? AND status = "paid"',
                    [$bookingId]
                );

                $summary['refunded'] += (float) $booking['fare'];
            } else {
                db_update('bookings', ['booking_status' => 'cancelled'], ['id' => $bookingId]);
            }

            if ($booking['ticket_id'] !== null) {
                db_update('tickets', ['status' => 'cancelled'], ['id' => (int) $booking['ticket_id']]);
                $summary['tickets']++;
            }

            $summary['bookings']++;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fleetra_log('Service cancellation failed: ' . $exception->getMessage());
        fleetra_fatal('Something went wrong while cancelling this service. Please try again.');
    }

    if ($notify) {
        $routeLabel = $schedule['route_code'] . ' ' . $schedule['route_name'];
        $when       = format_date((string) $schedule['schedule_date']) . ' at '
            . format_time((string) $schedule['departure_time']);

        foreach ($passengerIds ?? [] as $passengerId) {
            notify_user(
                (int) $passengerId,
                'Service cancelled — ' . $schedule['route_code'],
                'The ' . $when . ' ' . $routeLabel . ' service from ' . $schedule['source'] . ' has been cancelled. '
                . ($reason !== '' ? 'Reason: ' . $reason : '')
                . ' Any paid fare is being refunded to the original payment method.',
                'emergency',
                $scheduleId
            );
            $summary['notified']++;
        }

        notify_user(
            (int) $schedule['driver_user_id'],
            'Duty cancelled — ' . $schedule['route_code'],
            'Your ' . $when . ' departure on ' . $routeLabel . ' has been cancelled. '
            . ($reason !== '' ? 'Reason: ' . $reason : ''),
            'trip',
            $scheduleId
        );
    }

    return $summary;
}
