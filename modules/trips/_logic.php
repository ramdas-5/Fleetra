<?php
/**
 * Fleetra — Trip module logic
 * ------------------------------------------------------------------
 * modules/trips/_logic.php
 *
 * A trip is the operational side of a schedule: the same departure, seen
 * from the driver's seat. This file owns the status machine and every side
 * effect a status change carries, so the rule "a completed trip closes its
 * bookings and uses its tickets" lives in exactly one place.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/operations.php';

/**
 * Allowed status moves, keyed by the current status.
 * Completed is final; a cancelled trip can only be re-opened.
 *
 * @return array<string, array<int, string>>
 */
function trip_status_transitions(): array
{
    return [
        'scheduled' => ['boarding', 'running', 'delayed', 'cancelled'],
        'boarding'  => ['running', 'delayed', 'completed', 'cancelled'],
        'running'   => ['delayed', 'completed', 'cancelled'],
        'delayed'   => ['boarding', 'running', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => ['scheduled'],
    ];
}

/**
 * Statuses a trip may move to right now, as key => label.
 *
 * @return array<string, string>
 */
function available_trip_transitions(string $current): array
{
    $allowed = trip_status_transitions()[$current] ?? [];
    $labels  = trip_status_options();

    $options = [];

    foreach ($allowed as $status) {
        $options[$status] = $labels[$status] ?? labelize($status);
    }

    return $options;
}

/** Button text for moving a trip into a status. */
function trip_status_action_label(string $status): string
{
    return match ($status) {
        'boarding'  => 'Start boarding',
        'running'   => 'Mark as departed',
        'delayed'   => 'Report a delay',
        'completed' => 'Complete trip',
        'cancelled' => 'Cancel trip',
        'scheduled' => 'Re-open trip',
        default     => labelize($status),
    };
}

/** Bootstrap icon for a status action. */
function trip_status_action_icon(string $status): string
{
    return match ($status) {
        'boarding'  => 'bi-people',
        'running'   => 'bi-play-circle',
        'delayed'   => 'bi-clock-history',
        'completed' => 'bi-check2-circle',
        'cancelled' => 'bi-x-octagon',
        'scheduled' => 'bi-arrow-counterclockwise',
        default     => 'bi-dot',
    };
}

/** Driver record id for the signed-in account, or 0 when not a driver. */
function current_driver_id(): int
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = (int) db_value('SELECT id FROM drivers WHERE user_id = ? LIMIT 1', [user_id()], 0);

    return $cache;
}

/** Full joined trip row, or null. */
function find_trip(int $tripId): ?array
{
    return db_one(
        'SELECT t.*,
                s.schedule_date, s.departure_time, s.arrival_time, s.status AS schedule_status,
                r.route_code, r.route_name, r.source, r.destination, r.distance, r.estimated_duration, r.base_fare,
                b.bus_number, b.registration_number, b.capacity, b.bus_type, b.status AS bus_status,
                d.id AS driver_id, d.employee_id, d.license_expiry, d.employment_status,
                u.id AS driver_user_id, u.name AS driver_name, u.phone AS driver_phone
           FROM trips t
           JOIN schedules s ON s.id = t.schedule_id
           JOIN routes r    ON r.id = t.route_id
           JOIN buses b     ON b.id = t.bus_id
           JOIN drivers d   ON d.id = t.driver_id
           JOIN users u     ON u.id = d.user_id
          WHERE t.id = ?
          LIMIT 1',
        [$tripId]
    );
}

/**
 * Drivers may only see their own trips; every other role with trip access
 * sees the whole operation.
 *
 * @param array<string, mixed> $trip
 */
function trip_visible_to_current_user(array $trip): bool
{
    if (can('trips.view') || can('trips.manage')) {
        return true;
    }

    if (can('trips.own')) {
        return (int) ($trip['driver_user_id'] ?? 0) === user_id();
    }

    return false;
}

/**
 * Can the signed-in user change this trip's status?
 *
 * @param array<string, mixed> $trip
 */
function trip_status_change_allowed(array $trip): bool
{
    if (can('trips.manage')) {
        return true;
    }

    return can('trips.update_status')
        && (int) ($trip['driver_id'] ?? 0) === current_driver_id()
        && current_driver_id() > 0;
}

/**
 * Validate a status update.
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $trip
 * @return array{errors:array<string,string>, values:array{status:string, delay_minutes:string, passenger_count:string, remarks:string}}
 */
function validate_trip_status_request(array $post, array $trip): array
{
    $errors = [];
    $current = (string) $trip['trip_status'];

    $values = [
        'status'          => trim((string) ($post['status'] ?? '')),
        'delay_minutes'   => trim((string) ($post['delay_minutes'] ?? '')),
        'passenger_count' => trim((string) ($post['passenger_count'] ?? '')),
        'remarks'         => trim((string) ($post['remarks'] ?? '')),
    ];

    $allowed = available_trip_transitions($current);

    if ($values['status'] === '') {
        $errors['status'] = 'Choose the status this trip should move to.';
    } elseif (!array_key_exists($values['status'], $allowed)) {
        $errors['status'] = 'A trip that is ' . strtolower(labelize($current)) . ' cannot be moved to '
            . strtolower(labelize($values['status'])) . '.'
            . ($allowed === []
                ? ' This trip is already finished.'
                : ' Allowed next steps: ' . implode(', ', array_map('strtolower', array_values($allowed))) . '.');
    }

    if ($values['delay_minutes'] !== '') {
        if (!ctype_digit($values['delay_minutes']) || (int) $values['delay_minutes'] > 600) {
            $errors['delay_minutes'] = 'Enter the delay in whole minutes, up to 600.';
        } elseif ($values['status'] === 'delayed' && (int) $values['delay_minutes'] <= 0) {
            $errors['delay_minutes'] = 'Report how many minutes late the trip is.';
        }
    } elseif ($values['status'] === 'delayed') {
        $errors['delay_minutes'] = 'Report how many minutes late the trip is.';
    }

    if ($values['passenger_count'] !== '') {
        if (!ctype_digit($values['passenger_count'])) {
            $errors['passenger_count'] = 'Passengers carried must be a whole number.';
        } elseif ((int) $values['passenger_count'] > (int) $trip['capacity']) {
            $errors['passenger_count'] = 'This bus seats ' . (int) $trip['capacity']
                . ' passengers, so that figure cannot be right.';
        }
    }

    if (mb_strlen($values['remarks']) > 255) {
        $errors['remarks'] = 'Keep the note under 255 characters.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Apply a status change and everything that follows from it.
 *
 * - running / boarding / delayed: the schedule is marked running and the
 *   actual departure is stamped the first time the trip moves.
 * - completed: the arrival is stamped, booked seats are closed as
 *   completed, valid tickets are used and the passenger count is recorded.
 * - delayed: the passengers booked on the trip are told.
 * - cancelled: the whole cancellation cascade runs, refunds included.
 *
 * @param array<string, mixed> $trip
 * @param array{status:string, delay_minutes:string, passenger_count:string, remarks:string} $values
 * @return array{passengers:int, notified:int, bookings:int, refunded:float}
 */
function apply_trip_status(array $trip, array $values): array
{
    $tripId     = (int) $trip['id'];
    $scheduleId = (int) $trip['schedule_id'];
    $status     = $values['status'];
    $remarks    = $values['remarks'];
    $summary    = ['passengers' => (int) $trip['passenger_count'], 'notified' => 0, 'bookings' => 0, 'refunded' => 0.0];

    if ($status === 'cancelled') {
        $cancelled = cancel_schedule_service(
            $scheduleId,
            $remarks !== '' ? $remarks : 'Service cancelled from the trip sheet.'
        );

        return [
            'passengers' => 0,
            'notified'   => $cancelled['notified'],
            'bookings'   => $cancelled['bookings'],
            'refunded'   => $cancelled['refunded'],
        ];
    }

    $now         = date('Y-m-d H:i:s');
    $busyStatus  = in_array($status, ['boarding', 'running', 'delayed'], true);
    $payload     = ['trip_status' => $status];

    if ($status === 'delayed') {
        $payload['delay_minutes'] = (int) ($values['delay_minutes'] !== '' ? $values['delay_minutes'] : 0);
    } elseif ($values['delay_minutes'] !== '') {
        $payload['delay_minutes'] = (int) $values['delay_minutes'];
    }

    if ($remarks !== '') {
        $payload['remarks'] = $remarks;
    }

    if ($busyStatus && $trip['actual_start_time'] === null) {
        $payload['actual_start_time'] = $now;
    }

    if ($status === 'completed') {
        $payload['actual_end_time'] = $now;
        $payload['delay_minutes']   = (int) ($values['delay_minutes'] !== '' ? $values['delay_minutes'] : $trip['delay_minutes']);
    }

    if ($status === 'scheduled') {
        // Re-opened after a cancellation: clear the closing stamps.
        $payload['actual_end_time'] = null;
        $payload['delay_minutes']   = 0;
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        db_update('trips', $payload, ['id' => $tripId]);

        if ($busyStatus) {
            db_update('schedules', ['status' => 'running'], ['id' => $scheduleId]);
        } elseif ($status === 'scheduled' && (string) $trip['schedule_status'] === 'cancelled') {
            db_update('schedules', ['status' => 'scheduled'], ['id' => $scheduleId]);
        }

        if ($status === 'completed') {
            // Close the journey for everyone who actually travelled.
            $booked = (int) db_value(
                'SELECT COUNT(*) FROM bookings WHERE schedule_id = ? AND booking_status IN ("pending", "confirmed")',
                [$scheduleId],
                0
            );

            $summary['bookings'] = (int) db_value(
                'SELECT COUNT(*) FROM bookings WHERE schedule_id = ? AND booking_status = "confirmed"',
                [$scheduleId],
                0
            );

            db_execute(
                'UPDATE bookings SET booking_status = "completed"
                  WHERE schedule_id = ? AND booking_status IN ("pending", "confirmed")',
                [$scheduleId]
            );

            db_execute(
                'UPDATE tickets t
                   JOIN bookings b ON b.id = t.booking_id
                    SET t.status = "used"
                  WHERE b.schedule_id = ? AND t.status = "valid"',
                [$scheduleId]
            );

            db_update('schedules', ['status' => 'completed'], ['id' => $scheduleId]);

            // A physical head count beats the booked count when supplied.
            $passengers = $values['passenger_count'] !== '' ? (int) $values['passenger_count'] : $booked;
            db_update('trips', ['passenger_count' => $passengers], ['id' => $tripId]);
            $summary['passengers'] = $passengers;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fleetra_log('Trip status update failed: ' . $exception->getMessage());
        fleetra_fatal('Something went wrong while updating this trip. Please try again.');
    }

    if ($status === 'delayed') {
        $minutes = (int) db_value('SELECT delay_minutes FROM trips WHERE id = ?', [$tripId], 0);

        $summary['notified'] = notify_schedule_passengers(
            $scheduleId,
            'Trip delayed — ' . $trip['route_code'],
            'The ' . format_date((string) $trip['schedule_date']) . ' ' . format_time((string) $trip['departure_time'])
            . ' ' . $trip['route_code'] . ' ' . $trip['route_name'] . ' service is running '
            . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' late.'
            . ($remarks !== '' ? ' ' . $remarks : ''),
            'delay'
        );
    }

    if ($status === 'completed') {
        $summary['notified'] = notify_schedule_passengers(
            $scheduleId,
            'Journey completed — ' . $trip['route_code'],
            'Your journey on the ' . format_time((string) $trip['departure_time']) . ' ' . $trip['route_code']
            . ' service to ' . $trip['destination'] . ' is complete. Thank you for travelling with Fleetra.',
            'trip',
            ['completed']
        );
    }

    return $summary;
}
