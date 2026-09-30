<?php
/**
 * Fleetra — Booking module logic
 * ------------------------------------------------------------------
 * modules/bookings/_logic.php
 *
 * Shared by the passenger search, the seat picker, the booking records and
 * the tickets. Everything that must agree between those screens lives
 * here: how a bus is laid out, which seats are free, what a journey costs
 * and how booking and ticket references are numbered.
 *
 * Seat availability uses exactly the same status list as the generated
 * bookings.seat_lock column (pending, confirmed, completed). Anything
 * narrower would let the form offer a seat the database then refuses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/operations.php';

/** Prefix used when signing a ticket payload. */
const FLEETRA_TICKET_SALT = 'fleetra-salt';

/**
 * Ordered seat labels for a bus: four to a row (A, B aisle C, D) with the
 * last row carrying whatever is left. Bus 45 seats = A1..D11 + A12.
 *
 * @return array<int, string>
 */
function seat_labels(int $capacity): array
{
    $columns = ['A', 'B', 'C', 'D'];
    $labels  = [];

    for ($seat = 1; $seat <= $capacity; $seat++) {
        $row     = intdiv($seat - 1, 4) + 1;
        $column  = $columns[($seat - 1) % 4];

        $labels[] = $column . $row;
    }

    return $labels;
}

/**
 * The bus laid out in rows for rendering, e.g.
 * [['A1','B1','C1','D1'], ['A2','B2','C2','D2'], ...]
 *
 * @return array<int, array<int, string>>
 */
function seat_layout(int $capacity): array
{
    return array_chunk(seat_labels($capacity), 4);
}

/** True when a seat label is part of a bus with this capacity. */
function is_valid_seat(int $capacity, string $seat): bool
{
    return in_array(strtoupper(trim($seat)), seat_labels($capacity), true);
}

/**
 * Seats that can still be sold on a schedule.
 *
 * @return array<string, bool> seat label => true when taken
 */
function booked_seat_map(int $scheduleId): array
{
    $rows = db_all(
        'SELECT seat_number FROM bookings
          WHERE schedule_id = ? AND booking_status IN ("pending", "confirmed", "completed")',
        [$scheduleId]
    );

    $taken = [];

    foreach ($rows as $row) {
        $taken[strtoupper((string) $row['seat_number'])] = true;
    }

    return $taken;
}

/**
 * Seats still available on a schedule.
 *
 * @return array<int, string>
 */
function available_seats(int $scheduleId, int $capacity): array
{
    $taken = booked_seat_map($scheduleId);

    return array_values(array_filter(
        seat_labels($capacity),
        static fn (string $seat): bool => !isset($taken[$seat])
    ));
}

/** Number of seats still on sale. */
function seats_remaining(int $scheduleId, int $capacity): int
{
    return count(available_seats($scheduleId, $capacity));
}

/**
 * Stops of a route as stop id => "1. Majestic Bus Station (+25 min)".
 *
 * @return array<int, string>
 */
function route_stop_options(int $routeId): array
{
    $options = [];

    foreach (db_all('SELECT id, stop_name, stop_order, arrival_offset FROM stops WHERE route_id = ? ORDER BY stop_order', [$routeId]) as $stop) {
        $options[(int) $stop['id']] = $stop['stop_order'] . '. ' . $stop['stop_name']
            . ((int) $stop['arrival_offset'] > 0 ? ' (+' . (int) $stop['arrival_offset'] . ' min)' : ' (departure)');
    }

    return $options;
}

/**
 * Fare for the part of the route actually travelled.
 *
 * Stops carry a planned offset in minutes rather than a price, so the
 * base fare is apportioned by the share of the journey performed. A full
 * end-to-end booking therefore always costs exactly the base fare.
 */
function journey_fare(float $baseFare, int $boardingOffset, int $destinationOffset, int $totalMinutes): float
{
    if ($totalMinutes <= 0 || $destinationOffset <= $boardingOffset) {
        return round($baseFare, 2);
    }

    $share = ($destinationOffset - $boardingOffset) / $totalMinutes;

    return round($baseFare * max(0.0, min(1.0, $share)), 2);
}

/**
 * Boarding and destination stops with the fare they imply.
 *
 * @return array{boarding:?array<string,mixed>, destination:?array<string,mixed>, fare:float}
 */
function resolve_journey(int $routeId, float $baseFare, ?int $boardingStopId, ?int $destinationStopId, int $routeMinutes = 0): array
{
    $stops = db_all(
        'SELECT id, stop_name, stop_order, arrival_offset FROM stops WHERE route_id = ? ORDER BY stop_order',
        [$routeId]
    );

    $boarding    = null;
    $destination = null;

    foreach ($stops as $stop) {
        if ($boardingStopId !== null && (int) $stop['id'] === $boardingStopId) {
            $boarding = $stop;
        }
        if ($destinationStopId !== null && (int) $stop['id'] === $destinationStopId) {
            $destination = $stop;
        }
    }

    // Fall back to the whole route when a stop is missing or out of order.
    if ($boarding === null || $destination === null
        || (int) $destination['arrival_offset'] <= (int) $boarding['arrival_offset']
    ) {
        $boarding    = $stops[0] ?? null;
        $destination = $stops === [] ? null : $stops[count($stops) - 1];
    }

    // The whole route is the yardstick for the fare, not the booked leg, so a
    // part journey never costs as much as the full one.
    if ($routeMinutes <= 0) {
        $routeMinutes = $stops === []
            ? 0
            : (int) $stops[count($stops) - 1]['arrival_offset'];
    }

    return [
        'boarding'    => $boarding,
        'destination' => $destination,
        'fare'        => journey_fare(
            $baseFare,
            (int) ($boarding['arrival_offset'] ?? 0),
            (int) ($destination['arrival_offset'] ?? 0),
            $routeMinutes
        ),
    ];
}

/* ------------------------------------------------------------------
 | Reference numbers
 ------------------------------------------------------------------ */

/**
 * Next free booking number as a sequence, so several seats booked together
 * can be numbered from one read instead of re-reading the table.
 */
function next_booking_sequence(): int
{
    return (int) db_value(
        'SELECT COALESCE(MAX(CAST(SUBSTRING(booking_number, 5) AS UNSIGNED)), 0) FROM bookings'
    ) + 1;
}

/** Next free ticket sequence, e.g. 6 for TKT-0006. */
function next_ticket_sequence(): int
{
    return (int) db_value(
        'SELECT COALESCE(MAX(CAST(SUBSTRING(ticket_number, 5) AS UNSIGNED)), 0) FROM tickets'
    ) + 1;
}

/** Format a booking sequence as FLB-0008. */
function booking_number_for(int $sequence): string
{
    return 'FLB-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

/** Format a ticket sequence as TKT-0006. */
function ticket_number_for(int $sequence): string
{
    return 'TKT-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Signature carried inside a ticket's payload. Scanning the code proves the
 * ticket exists and has not been altered; the gate check compares it with
 * the stored ticket row rather than trusting the printed value.
 */
function ticket_signature(string $bookingNumber): string
{
    return hash('sha256', $bookingNumber . FLEETRA_TICKET_SALT);
}

/** Full payload stored in tickets.qr_code. */
function ticket_payload(string $ticketNumber, string $bookingNumber): string
{
    return 'FLTRA|' . $ticketNumber . '|' . ticket_signature($bookingNumber);
}

/** The short code printed on the ticket and encoded into its QR link. */
function ticket_code(string $ticketNumber, string $bookingNumber): string
{
    return $ticketNumber . '-' . substr(ticket_signature($bookingNumber), 0, 10);
}

/**
 * Look a ticket up from a printed code (TKT-0006-ab12cd34ef).
 *
 * @return array<string, mixed>|null
 */
function find_ticket_by_code(string $code): ?array
{
    $code = strtoupper(trim($code));

    if (!preg_match('/^(TKT-\d{4,})-([0-9a-f]{6,64})$/', $code, $matches)) {
        return null;
    }

    $ticket = find_ticket_by_number($matches[1]);

    if ($ticket === null) {
        return null;
    }

    if (!str_starts_with(ticket_signature((string) $ticket['booking_number']), strtolower($matches[2]))) {
        return null;
    }

    return $ticket;
}

/**
 * Full ticket row joined to its booking, schedule, route and passenger.
 *
 * @return array<string, mixed>|null
 */
function find_ticket_by_number(string $ticketNumber): ?array
{
    return db_one(
        'SELECT t.id, t.ticket_number, t.qr_code, t.issued_at, t.status AS ticket_status,
                b.id AS booking_id, b.booking_number, b.seat_number, b.fare,
                b.booking_status, b.payment_status, b.booking_date,
                s.id AS schedule_id, s.schedule_date, s.departure_time, s.arrival_time, s.status AS schedule_status,
                r.id AS route_id, r.route_code, r.route_name, r.source, r.destination, r.distance,
                bs.stop_name AS boarding_stop, ds.stop_name AS destination_stop,
                bu.bus_number, bu.registration_number, bu.bus_type, bu.capacity,
                pu.name AS passenger_name, pu.email AS passenger_email, pu.phone AS passenger_phone,
                pu.id AS passenger_id, du.name AS driver_name
           FROM tickets t
           JOIN bookings b ON b.id = t.booking_id
           JOIN schedules s ON s.id = b.schedule_id
           JOIN routes r ON r.id = s.route_id
           JOIN buses bu ON bu.id = s.bus_id
           JOIN drivers d ON d.id = s.driver_id
           JOIN users du ON du.id = d.user_id
           JOIN users pu ON pu.id = b.user_id
           LEFT JOIN stops bs ON bs.id = b.boarding_stop_id
           LEFT JOIN stops ds ON ds.id = b.destination_stop_id
          WHERE t.ticket_number = ?
          LIMIT 1',
        [$ticketNumber]
    );
}

/* ------------------------------------------------------------------
 | Access rules
 ------------------------------------------------------------------ */

/**
 * Full booking row joined to its journey, passenger and ticket.
 *
 * @return array<string, mixed>|null
 */
function find_booking(int $bookingId): ?array
{
    return db_one(
        'SELECT b.*, s.schedule_date, s.departure_time, s.arrival_time, s.status AS schedule_status,
                r.id AS route_id, r.route_code, r.route_name, r.source, r.destination, r.distance,
                r.estimated_duration, r.base_fare,
                bu.bus_number, bu.registration_number, bu.bus_type, bu.capacity,
                u.id AS passenger_id, u.name AS passenger_name, u.email AS passenger_email, u.phone AS passenger_phone,
                du.name AS driver_name, du.phone AS driver_phone, dd.employee_id,
                bs.stop_name AS boarding_stop, bs.arrival_offset AS boarding_offset,
                ds.stop_name AS destination_stop, ds.arrival_offset AS destination_offset,
                t.id AS ticket_id, t.ticket_number, t.status AS ticket_status, t.issued_at
           FROM bookings b
           JOIN schedules s ON s.id = b.schedule_id
           JOIN routes r    ON r.id = s.route_id
           JOIN buses bu    ON bu.id = s.bus_id
           JOIN users u     ON u.id = b.user_id
           JOIN drivers dd  ON dd.id = s.driver_id
           JOIN users du    ON du.id = dd.user_id
           LEFT JOIN stops bs ON bs.id = b.boarding_stop_id
           LEFT JOIN stops ds ON ds.id = b.destination_stop_id
           LEFT JOIN tickets t ON t.booking_id = b.id
          WHERE b.id = ?
          LIMIT 1',
        [$bookingId]
    );
}

/**
 * Passengers only ever see their own bookings; operations roles see
 * everything.
 *
 * @param array<string, mixed> $booking
 */
function booking_visible_to_current_user(array $booking): bool
{
    if (can('bookings.view') || can('bookings.manage')) {
        return true;
    }

    return can('bookings.own') && (int) ($booking['user_id'] ?? 0) === user_id();
}

/**
 * A booking can only be cancelled while it is live and the service has not
 * left yet.
 *
 * @param array<string, mixed> $booking
 */
function booking_cancellable(array $booking): bool
{
    if (!in_array((string) $booking['booking_status'], ['pending', 'confirmed'], true)) {
        return false;
    }

    if ((string) ($booking['schedule_status'] ?? '') === 'cancelled') {
        return false;
    }

    $departure = strtotime((string) $booking['schedule_date'] . ' ' . (string) $booking['departure_time']);

    return $departure !== false && $departure > time();
}

/**
 * Release a booking and everything attached to it.
 *
 * @param array<string, mixed> $booking Row including booking_status, payment_status, fare.
 * @param string $reason
 * @return array{refunded:float, ticket_voided:bool, notified:bool}
 */
function cancel_booking(array $booking, string $reason = ''): array
{
    $bookingId = (int) $booking['id'];
    $summary   = ['refunded' => 0.0, 'ticket_voided' => false, 'notified' => false];

    $pdo = db();

    try {
        $pdo->beginTransaction();

        if ((string) $booking['payment_status'] === 'paid') {
            db_update('bookings', [
                'booking_status' => 'cancelled',
                'payment_status' => 'refunded',
            ], ['id' => $bookingId]);

            db_execute(
                'UPDATE payments SET status = "refunded" WHERE booking_id = ? AND status = "paid"',
                [$bookingId]
            );

            $summary['refunded'] = (float) $booking['fare'];
        } else {
            db_update('bookings', ['booking_status' => 'cancelled'], ['id' => $bookingId]);
        }

        $ticket = db_one('SELECT id FROM tickets WHERE booking_id = ? LIMIT 1', [$bookingId]);

        if ($ticket !== null) {
            db_update('tickets', ['status' => 'cancelled'], ['id' => (int) $ticket['id']]);
            $summary['ticket_voided'] = true;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fleetra_log('Booking cancellation failed: ' . $exception->getMessage());
        fleetra_fatal('Something went wrong while cancelling this booking. Please try again.');
    }

    notify_user(
        (int) $booking['user_id'],
        'Booking cancelled — ' . $booking['booking_number'],
        'Seat ' . $booking['seat_number'] . ' on the '
        . format_date((string) $booking['schedule_date']) . ' ' . format_time((string) $booking['departure_time'])
        . ' service has been cancelled.'
        . ($reason !== '' ? ' Reason: ' . $reason : '')
        . ($summary['refunded'] > 0
            ? ' ' . money($summary['refunded']) . ' is being refunded to the original payment method.'
            : ''),
        'booking',
        $bookingId
    );
    $summary['notified'] = true;

    return $summary;
}
