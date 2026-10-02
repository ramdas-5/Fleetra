<?php
/**
 * Fleetra — Passenger services / Book a demo service
 * ------------------------------------------------------------------
 * modules/search/book_demo.php
 *
 * POST only, CSRF protected.
 *
 * Demo services are generated, not stored, so they have no schedule to
 * attach a booking to. When a passenger picks one, this endpoint turns
 * just that service into real rows (route, stops, bus, driver, schedule)
 * and then hands them to the ordinary seat picker — so a demo departure
 * is booked exactly like a scheduled one and issues a real ticket.
 *
 * It never creates a booking itself: the passenger still chooses seats
 * on the next screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/operations.php';
require_once __DIR__ . '/../../includes/demo_booking.php';

require_permission('bookings.create');

if (!is_post()) {
    flash('warning', 'Choose a bus from the search results to book a ticket.');
    redirect('modules/search/index.php');
}

require_csrf();

$origin      = post('origin');
$destination = post('destination');
$date        = post('date');
$time        = post('time');
$passengers  = max(1, min(6, post_int('passengers', 1, 1)));
$from        = post('from');
$to          = post('to');

try {
    $scheduleId = fleetra_materialize_demo_service($origin, $destination, $date, $time);
} catch (Throwable $exception) {
    fleetra_log('Demo service booking failed: ' . $exception->getMessage(), 'WARNING');

    flash('danger', 'That demo service could not be prepared for booking. Please try another departure.');
    redirect('modules/search/index.php?' . http_build_query(array_filter([
        'tab'  => 'search',
        'from' => $from !== '' ? $from : null,
        'to'   => $to !== '' ? $to : null,
        'date' => $date,
    ])));
}

redirect('modules/search/seats.php?' . http_build_query(array_filter([
    'schedule_id' => $scheduleId,
    'passengers'  => $passengers > 1 ? $passengers : null,
    'from'        => $from !== '' ? $from : null,
    'to'          => $to !== '' ? $to : null,
])));
