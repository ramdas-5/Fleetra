<?php
/**
 * Fleetra — Schedules / Cancel a service
 * ------------------------------------------------------------------
 * modules/schedules/cancel.php
 *
 * POST only, CSRF protected.
 *
 * Cancelling is the safe counterpart of deleting: the departure stays on
 * record for reporting, but every seat is released, tickets are voided,
 * paid fares are refunded and each booked passenger is notified.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('schedules.manage');

if (!is_post()) {
    flash('warning', 'Cancelling a service requires a confirmed form submission.');
    redirect('modules/schedules/index.php');
}

require_csrf();

$scheduleId = get_int('id');

if ($scheduleId <= 0) {
    abort_not_found('No schedule was specified.');
}

$schedule = find_schedule($scheduleId);

if ($schedule === null) {
    abort_not_found('That departure does not exist.');
}

if ((string) $schedule['status'] === 'cancelled') {
    flash('info', 'That service was already cancelled.');
    redirect('modules/schedules/view.php?id=' . $scheduleId);
}

$reason  = trim((string) post('reason', ''));
$summary = cancel_schedule_service(
    $scheduleId,
    $reason !== '' ? $reason : 'Service cancelled by the operator.'
);

log_activity(
    'Cancelled schedule #' . $scheduleId,
    'schedules',
    $scheduleId,
    sprintf(
        '%s departure on %s cancelled · %d bookings, %s refunded, %d passengers notified',
        format_time((string) $schedule['departure_time']),
        format_date((string) $schedule['schedule_date']),
        $summary['bookings'],
        money($summary['refunded']),
        $summary['notified']
    )
);
fleetra_log('Schedule #' . $scheduleId . ' cancelled by user #' . user_id(), 'INFO');

flash(
    'warning',
    'The ' . format_time((string) $schedule['departure_time']) . ' service on '
    . format_date((string) $schedule['schedule_date']) . ' was cancelled. '
    . $summary['bookings'] . ' booking(s) cancelled'
    . ($summary['tickets'] > 0 ? ', ' . $summary['tickets'] . ' ticket(s) voided' : '')
    . ($summary['refunded'] > 0 ? ', ' . money($summary['refunded']) . ' refunded' : '')
    . ($summary['notified'] > 0 ? ', ' . $summary['notified'] . ' passenger(s) notified' : '')
    . '.'
);

redirect('modules/schedules/view.php?id=' . $scheduleId);
