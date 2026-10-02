<?php
/**
 * Fleetra — Bookings / Create
 * ------------------------------------------------------------------
 * modules/bookings/create.php
 *
 * POST only, CSRF protected.
 *
 * One booking and one ticket are created per selected seat, because a seat
 * is the unit that can be cancelled, refunded and scanned at the gate. All
 * of the seats in one submission are written in a single transaction, so a
 * clash on the last seat leaves nothing half-booked behind.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('bookings.create');

$scheduleId = post_int('schedule_id');

if (!is_post()) {
    flash('warning', 'Choose your seats to make a booking.');
    redirect('modules/search/index.php');
}

require_csrf();

if ($scheduleId <= 0) {
    abort_not_found('No departure was selected.');
}

/* ------------------------------------------------------------------
 | The departure
 ------------------------------------------------------------------ */

$service = db_one(
    'SELECT s.id, s.schedule_date, s.departure_time, s.arrival_time, s.status,
            r.id AS route_id, r.route_code, r.route_name, r.source, r.destination,
            r.base_fare, r.estimated_duration, r.status AS route_status,
            b.id AS bus_id, b.bus_number, b.bus_type, b.capacity, b.status AS bus_status
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
       JOIN buses b  ON b.id = s.bus_id
      WHERE s.id = ?
      LIMIT 1',
    [$scheduleId]
);

if ($service === null) {
    abort_not_found('That departure does not exist.');
}

$departureTs = strtotime((string) $service['schedule_date'] . ' ' . (string) $service['departure_time']);

if ((string) $service['status'] === 'cancelled'
    || (string) $service['route_status'] !== 'active'
    || $departureTs === false
    || $departureTs <= time()
) {
    flash('danger', 'That departure is no longer open for booking. Please search again.');
    redirect('modules/search/index.php');
}

/* ------------------------------------------------------------------
 | The seats
 ------------------------------------------------------------------ */

$capacity = (int) $service['capacity'];
$requested = $_POST['seat_numbers'] ?? [];

if (!is_array($requested)) {
    $requested = [$requested];
}

$seats = [];

foreach ($requested as $seat) {
    $seat = strtoupper(trim((string) $seat));

    if ($seat === '' || isset($seats[$seat])) {
        continue;
    }

    if (!is_valid_seat($capacity, $seat)) {
        flash('danger', 'Seat ' . $seat . ' is not part of bus ' . $service['bus_number'] . '. Please pick again.');
        redirect('modules/search/seats.php?schedule_id=' . $scheduleId);
    }

    $seats[$seat] = $seat;
}

$seats = array_values($seats);

if ($seats === []) {
    flash('warning', 'Select at least one seat before confirming the booking.');
    redirect('modules/search/seats.php?schedule_id=' . $scheduleId);
}

if (count($seats) > 6) {
    flash('warning', 'A single booking can cover up to six seats. Please book the rest separately.');
    redirect('modules/search/seats.php?schedule_id=' . $scheduleId);
}

$seatQuery = static fn (array $list): string => http_build_query([
    'schedule_id' => $scheduleId,
    'passengers'  => count($list),
    'seats'       => implode(',', $list),
]);

/* ------------------------------------------------------------------
 | The journey
 ------------------------------------------------------------------ */

$boardingId    = post_int('boarding_stop_id');
$destinationId = post_int('destination_stop_id');

// Price this departure the same way the seat picker did (bus type + time),
// so the amount charged matches what the passenger was shown.
$scheduleFare = fleetra_fare_for(
    (float) $service['base_fare'],
    (string) $service['bus_type'],
    (string) $service['departure_time']
);

$journey = resolve_journey(
    (int) $service['route_id'],
    $scheduleFare,
    $boardingId > 0 ? $boardingId : null,
    $destinationId > 0 ? $destinationId : null,
    (int) $service['estimated_duration']
);

if ($journey['boarding'] === null || $journey['destination'] === null
    || (int) $journey['destination']['arrival_offset'] <= (int) $journey['boarding']['arrival_offset']
) {
    flash('danger', 'Choose a destination that comes after your boarding stop.');
    redirect('modules/search/seats.php?schedule_id=' . $scheduleId);
}

$fare         = (float) $journey['fare'];
$paymentChoice = post('payment_method') === 'cash' ? 'cash' : 'simulated';
$payNow       = $paymentChoice === 'simulated';

/* ------------------------------------------------------------------
 | Write it all, or none of it
 ------------------------------------------------------------------ */

$created = [];

$pdo = db();

try {
    $pdo->beginTransaction();

    // Re-check availability inside the transaction: two passengers can reach
    // the same seat picker at the same moment.
    $taken = booked_seat_map($scheduleId);

    foreach ($seats as $seat) {
        if (isset($taken[$seat])) {
            throw new RuntimeException('SEAT_TAKEN:' . $seat);
        }
    }

    $today           = date('Y-m-d');
    $bookingSequence = next_booking_sequence();
    $ticketSequence  = next_ticket_sequence();

    foreach ($seats as $index => $seat) {
        $bookingNumber = booking_number_for($bookingSequence + $index);
        $bookingId     = db_insert('bookings', [
            'booking_number'      => $bookingNumber,
            'user_id'             => user_id(),
            'schedule_id'         => $scheduleId,
            'boarding_stop_id'    => (int) $journey['boarding']['id'],
            'destination_stop_id' => (int) $journey['destination']['id'],
            'seat_number'         => $seat,
            'fare'                => $fare,
            'booking_status'      => 'confirmed',
            'payment_status'      => $payNow ? 'paid' : 'unpaid',
            'booking_date'        => $today,
        ]);

        $ticketNumber = ticket_number_for($ticketSequence + $index);
        db_insert('tickets', [
            'ticket_number' => $ticketNumber,
            'booking_id'    => $bookingId,
            'qr_code'       => ticket_payload($ticketNumber, $bookingNumber),
            'status'        => 'valid',
        ]);

        db_insert('payments', [
            'booking_id'            => $bookingId,
            'transaction_reference' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))),
            'payment_method'        => $paymentChoice,
            'amount'                => $fare,
            'status'                => $payNow ? 'paid' : 'pending',
            'payment_date'          => $payNow ? date('Y-m-d H:i:s') : null,
        ]);

        $created[] = [
            'booking_id'     => $bookingId,
            'booking_number' => $bookingNumber,
            'ticket_number'  => $ticketNumber,
            'seat'           => $seat,
        ];
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (str_starts_with($exception->getMessage(), 'SEAT_TAKEN:')) {
        $clash = substr($exception->getMessage(), strlen('SEAT_TAKEN:'));

        flash('danger', 'Seat ' . $clash . ' was booked by someone else a moment ago. Please pick another seat.');
        redirect('modules/search/seats.php?' . $seatQuery($seats));
    }

    if (str_contains($exception->getMessage(), 'uq_bookings_active_seat')) {
        flash('danger', 'One of those seats was just taken. Please choose different seats.');
        redirect('modules/search/seats.php?' . $seatQuery($seats));
    }

    fleetra_log('Booking creation failed: ' . $exception->getMessage());
    fleetra_fatal('Something went wrong while creating your booking. Nothing has been charged.');
}

/* ------------------------------------------------------------------
 | Confirmation
 ------------------------------------------------------------------ */

$total   = $fare * count($created);
$numbers = array_column($created, 'booking_number');
$tickets = array_column($created, 'ticket_number');
$seatList = array_column($created, 'seat');

log_activity(
    'Created booking ' . implode(', ', $numbers),
    'bookings',
    (int) $created[0]['booking_id'],
    count($created) . ' seat(s) ' . implode(', ', $seatList) . ' on ' . $service['route_code'] . ' '
        . format_date((string) $service['schedule_date']) . ' at ' . format_time((string) $service['departure_time'])
);

notify_user(
    user_id(),
    'Booking confirmed — ' . implode(', ', $numbers),
    'Seat' . (count($seatList) === 1 ? ' ' : 's ') . implode(', ', $seatList) . ' on the '
    . format_date((string) $service['schedule_date']) . ' ' . format_time((string) $service['departure_time'])
    . ' ' . $service['route_code'] . ' service to ' . $service['destination'] . '. '
    . 'Ticket' . (count($tickets) === 1 ? ' ' : 's ') . implode(', ', $tickets) . '. '
    . ($payNow
        ? money($total) . ' paid (simulated).'
        : money($total) . ' is payable to the conductor at boarding.'),
    'booking',
    (int) $created[0]['booking_id']
);

flash(
    'success',
    count($created) . ' seat' . (count($created) === 1 ? '' : 's') . ' booked on '
    . $service['route_code'] . ' — seat' . (count($seatList) === 1 ? ' ' : 's ') . implode(', ', $seatList)
    . ', ticket' . (count($tickets) === 1 ? ' ' : 's ') . implode(', ', $tickets) . '. '
    . ($payNow
        ? money($total) . ' paid (simulated payment).'
        : money($total) . ' is payable to the conductor at boarding.')
    . ' Open your tickets to print or download them.'
);

// Send a single-ticket booking straight to the ticket so the passenger can
// view, print or download its PDF immediately.
if (count($created) === 1) {
    redirect('modules/tickets/view.php?ticket=' . urlencode((string) $created[0]['ticket_number']));
}

redirect('modules/bookings/index.php');
