<?php
/**
 * Fleetra — Fleet / Delete or archive a bus
 * ------------------------------------------------------------------
 * modules/buses/delete.php
 *
 * POST only, CSRF protected.
 *
 * A bus that has schedules or trips behind it cannot be deleted — the
 * foreign keys are RESTRICT precisely so operational history survives.
 * Those buses are moved to "Inactive" instead, which is the safe
 * equivalent of a delete for a fleet register.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

require_permission('fleet.manage');

if (!is_post()) {
    flash('warning', 'Deleting a bus requires a confirmed form submission.');
    redirect('modules/buses/index.php');
}

require_csrf();

$busId = get_int('id');

if ($busId <= 0) {
    abort_not_found('No bus was specified.');
}

$bus = db_one('SELECT * FROM buses WHERE id = ? LIMIT 1', [$busId]);

if ($bus === null) {
    abort_not_found('That bus does not exist in the fleet.');
}

$busNumber = (string) $bus['bus_number'];

/* ------------------------------------------------------------------
 | Decide between a real delete and an archive
 ------------------------------------------------------------------ */

$scheduleCount = (int) db_value('SELECT COUNT(*) FROM schedules WHERE bus_id = ?', [$busId], 0);
$tripCount     = (int) db_value('SELECT COUNT(*) FROM trips WHERE bus_id = ?', [$busId], 0);
$bookingCount  = (int) db_value(
    'SELECT COUNT(*) FROM bookings b JOIN schedules s ON s.id = b.schedule_id WHERE s.bus_id = ?',
    [$busId],
    0
);
$maintenanceCount = (int) db_value('SELECT COUNT(*) FROM maintenance WHERE bus_id = ?', [$busId], 0);

$hasHistory = ($scheduleCount + $tripCount + $bookingCount + $maintenanceCount) > 0;

if ($hasHistory) {
    db_update('buses', ['status' => 'inactive'], ['id' => $busId]);

    log_activity(
        'Archived bus ' . $busNumber,
        'buses',
        $busId,
        sprintf(
            'Bus set to inactive because it has %d schedules, %d trips and %d maintenance records',
            $scheduleCount,
            $tripCount,
            $maintenanceCount
        )
    );

    flash(
        'warning',
        'Bus ' . $busNumber . ' has operational history (' . $tripCount . ' trips, ' . $scheduleCount
        . ' schedules) and was moved to Inactive instead of being deleted, so past records stay intact.'
    );

    redirect('modules/buses/view.php?id=' . $busId);
}

/* ------------------------------------------------------------------
 | Safe to remove completely
 ------------------------------------------------------------------ */

if (!empty($bus['image'])) {
    delete_upload((string) $bus['image'], 'buses');
}

db_execute('DELETE FROM buses WHERE id = ?', [$busId]);

log_activity('Deleted bus ' . $busNumber, 'buses', $busId, 'Bus removed from the fleet');
fleetra_log('Bus #' . $busId . ' (' . $busNumber . ') deleted by user #' . user_id(), 'INFO');

flash('success', 'Bus ' . $busNumber . ' was deleted from the fleet.');
redirect('modules/buses/index.php');
