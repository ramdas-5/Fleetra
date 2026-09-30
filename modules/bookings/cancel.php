<?php
/**
 * Fleetra — Bookings / Cancel a booking
 * ------------------------------------------------------------------
 * modules/bookings/cancel.php
 *
 * POST only, CSRF protected.
 *
 * A passenger may cancel their own seat; dispatch, managers and
 * administrators may cancel any seat. The seat is released, the ticket is
 * voided, a collected fare is refunded and the passenger is notified. A
 * booking can never be cancelled after its bus has left.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

$bookingId = get_int('id');

if ($bookingId <= 0) {
    abort_not_found('No booking was specified.');
}

$booking = find_booking($bookingId);

if ($booking === null) {
    abort_not_found('That booking does not exist.');
}

$canManage = can('bookings.manage');
$isOwner   = (int) $booking['user_id'] === user_id();

if (!$canManage && !($isOwner && can('bookings.cancel'))) {
    fleetra_log(
        'Permission denied: user #' . user_id() . ' tried to cancel booking #' . $bookingId,
        'WARNING'
    );

    fleetra_fatal('You can only cancel bookings made with your own account.', 403);
}

if (!is_post()) {
    flash('warning', 'Cancelling a booking requires a confirmed form submission.');
    redirect('modules/bookings/view.php?id=' . $bookingId);
}

require_csrf();

if (!booking_cancellable($booking)) {
    $reason = match (true) {
        (string) $booking['booking_status'] === 'cancelled' => 'That booking was already cancelled.',
        (string) $booking['schedule_status'] === 'cancelled' => 'The operator cancelled that service, so nothing is left to cancel.',
        strtotime((string) $booking['schedule_date'] . ' ' . (string) $booking['departure_time']) <= time()
            => 'That bus has already departed, so the booking can no longer be cancelled online.',
        default => 'That booking is ' . strtolower(labelize((string) $booking['booking_status'])) . ' and cannot be cancelled.',
    };

    flash('warning', $reason);
    redirect('modules/bookings/view.php?id=' . $bookingId);
}

$summary = cancel_booking($booking);

log_activity(
    'Cancelled booking ' . $booking['booking_number'],
    'bookings',
    $bookingId,
    'Seat ' . $booking['seat_number'] . ' on ' . $booking['route_code'] . ' '
        . format_date((string) $booking['schedule_date']) . ' released'
        . ($summary['refunded'] > 0 ? ' · ' . money($summary['refunded']) . ' refunded' : '')
);
fleetra_log('Booking #' . $bookingId . ' cancelled by user #' . user_id(), 'INFO');

flash(
    'warning',
    'Booking ' . $booking['booking_number'] . ' (seat ' . $booking['seat_number'] . ') was cancelled. '
    . ($summary['refunded'] > 0
        ? money($summary['refunded']) . ' has been marked refunded on the payment record.'
        : 'Nothing had been collected for this seat.')
);

redirect('modules/bookings/view.php?id=' . $bookingId);
