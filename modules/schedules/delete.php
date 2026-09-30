<?php
/**
 * Fleetra — Schedules / Delete a departure
 * ------------------------------------------------------------------
 * modules/schedules/delete.php
 *
 * POST only, CSRF protected.
 *
 * A departure may only be removed while nothing depends on it. Bookings
 * reference schedules with ON DELETE RESTRICT, so the database would
 * refuse anyway — this endpoint refuses first, in language an operator
 * can act on, and points at cancelling instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('schedules.manage');

if (!is_post()) {
    flash('warning', 'Deleting a departure requires a confirmed form submission.');
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

$counts = schedule_booking_counts($scheduleId);
$label  = $schedule['route_code'] . ' ' . format_date((string) $schedule['schedule_date'], 'd M Y')
    . ' at ' . format_time((string) $schedule['departure_time']);

/* ------------------------------------------------------------------
 | Refusals
 ------------------------------------------------------------------ */

if ($counts['total'] > 0) {
    flash(
        'warning',
        $label . ' has ' . $counts['total'] . ' booking(s) on record and cannot be deleted, because the booking '
        . 'history must survive. Cancel the service instead to release the seats and refund paid fares.'
    );

    redirect('modules/schedules/view.php?id=' . $scheduleId);
}

if ((string) $schedule['status'] === 'completed') {
    flash(
        'warning',
        $label . ' has already been operated, so it is kept as operational history. Cancel is not needed — nothing '
        . 'is scheduled to run.'
    );

    redirect('modules/schedules/view.php?id=' . $scheduleId);
}

/* ------------------------------------------------------------------
 | Safe to remove
 ------------------------------------------------------------------ */

$pdo = db();

try {
    $pdo->beginTransaction();

    // trips.schedule_id is ON DELETE CASCADE, so the trip sheet goes with it.
    db_execute('DELETE FROM schedules WHERE id = ?', [$scheduleId]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fleetra_log('Schedule deletion failed: ' . $exception->getMessage());
    fleetra_fatal('Something went wrong while deleting this departure. Please try again.');
}

log_activity('Deleted schedule #' . $scheduleId, 'schedules', $scheduleId, $label . ' removed with its trip');
fleetra_log('Schedule #' . $scheduleId . ' deleted by user #' . user_id(), 'INFO');

flash('success', 'The departure on ' . format_date((string) $schedule['schedule_date']) . ' was deleted.');
redirect('modules/schedules/index.php');
