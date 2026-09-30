<?php
/**
 * Fleetra — Trips / Update trip status
 * ------------------------------------------------------------------
 * modules/trips/status.php
 *
 * POST only, CSRF protected.
 *
 * The status machine lives in _logic.php. This endpoint only decides who
 * is allowed to move the trip, validates the submission and reports back.
 * Drivers are restricted to their own duty; dispatch, managers and
 * administrators can move any trip.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

$tripId = get_int('id');

if ($tripId <= 0) {
    abort_not_found('No trip was specified.');
}

$trip = find_trip($tripId);

if ($trip === null) {
    abort_not_found('That trip does not exist.');
}

require_login();

if (!trip_status_change_allowed($trip)) {
    fleetra_log(
        'Permission denied: user #' . user_id() . ' (' . current_role() . ') tried to update trip #' . $tripId,
        'WARNING'
    );

    fleetra_fatal('Only the assigned driver or an operations manager can update this trip.', 403);
}

if (!is_post()) {
    flash('warning', 'Updating a trip requires a submitted form.');
    redirect('modules/trips/view.php?id=' . $tripId);
}

require_csrf();

$result = validate_trip_status_request($_POST, $trip);
$errors = $result['errors'];
$values = $result['values'];

if ($errors !== []) {
    flash('danger', reset($errors));
    redirect('modules/trips/view.php?id=' . $tripId);
}

$previous = (string) $trip['trip_status'];
$summary  = apply_trip_status($trip, $values);

$recordedDelay = (int) db_value('SELECT delay_minutes FROM trips WHERE id = ?', [$tripId], 0);

log_activity(
    'Updated trip #' . $tripId . ' status',
    'trips',
    $tripId,
    'Trip ' . $trip['route_code'] . ' moved from ' . $previous . ' to ' . $values['status']
        . ($recordedDelay > 0 ? ' with ' . $recordedDelay . ' minute delay' : '')
        . ($values['remarks'] !== '' ? ' · ' . $values['remarks'] : '')
);
fleetra_log('Trip #' . $tripId . ' moved to ' . $values['status'] . ' by user #' . user_id(), 'INFO');

/* ------------------------------------------------------------------
 | Feedback
 ------------------------------------------------------------------ */

if ($values['status'] === 'cancelled') {
    flash(
        'warning',
        'TRP-' . str_pad((string) $tripId, 4, '0', STR_PAD_LEFT) . ' was cancelled. '
        . $summary['bookings'] . ' booking(s) cancelled'
        . ($summary['refunded'] > 0 ? ', ' . money($summary['refunded']) . ' refunded' : '')
        . ($summary['notified'] > 0 ? ', ' . $summary['notified'] . ' passenger(s) notified' : '')
        . '.'
    );
} elseif ($values['status'] === 'completed') {
    flash(
        'success',
        'Trip completed with ' . $summary['passengers'] . ' passenger(s) recorded. '
        . $summary['bookings'] . ' booking(s) closed and their tickets marked as used.'
    );
} elseif ($values['status'] === 'delayed') {
    flash(
        'warning',
        'The delay was recorded and '
        . ($summary['notified'] > 0
            ? $summary['notified'] . ' booked passenger(s) were notified.'
            : 'no passengers were waiting on this trip to notify.')
    );
} else {
    flash('success', 'TRP-' . str_pad((string) $tripId, 4, '0', STR_PAD_LEFT) . ' is now '
        . strtolower(labelize($values['status'])) . '.');
}

redirect('modules/trips/view.php?id=' . $tripId);
