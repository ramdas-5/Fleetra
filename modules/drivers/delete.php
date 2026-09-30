<?php
/**
 * Fleetra — Fleet / Delete a driver
 * ------------------------------------------------------------------
 * modules/drivers/delete.php
 *
 * POST only, CSRF protected.
 *
 * A driver with trips behind them cannot be deleted: the trips foreign
 * key is RESTRICT so operational history survives. Those drivers are
 * marked as terminated or resigned instead, which also disables their
 * login. A driver with no history is removed along with their account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

require_permission('drivers.manage');

if (!is_post()) {
    flash('warning', 'Deleting a driver requires a confirmed form submission.');
    redirect('modules/drivers/index.php');
}

require_csrf();

$driverId = get_int('id');

if ($driverId <= 0) {
    abort_not_found('No driver was specified.');
}

$driver = db_one(
    'SELECT d.*, u.id AS user_id, u.name
       FROM drivers d
       JOIN users u ON u.id = d.user_id
      WHERE d.id = ?
      LIMIT 1',
    [$driverId]
);

if ($driver === null) {
    abort_not_found('That driver does not exist.');
}

$name   = (string) $driver['name'];
$userId = (int) $driver['user_id'];

$tripCount     = (int) db_value('SELECT COUNT(*) FROM trips WHERE driver_id = ?', [$driverId], 0);
$scheduleCount = (int) db_value('SELECT COUNT(*) FROM schedules WHERE driver_id = ?', [$driverId], 0);
$incidentCount = (int) db_value('SELECT COUNT(*) FROM incidents WHERE driver_id = ?', [$driverId], 0);

if ($tripCount + $scheduleCount > 0) {
    // Keep the history: mark them as resigned and release the bus.
    db_update(
        'drivers',
        ['employment_status' => 'resigned', 'assigned_bus_id' => null],
        ['id' => $driverId]
    );
    db_update('users', ['status' => 'inactive'], ['id' => $userId]);
    db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);

    log_activity(
        'Archived driver ' . $driver['employee_id'],
        'drivers',
        $driverId,
        sprintf(
            'Marked as resigned instead of deleting: %d trips, %d schedules, %d incidents',
            $tripCount,
            $scheduleCount,
            $incidentCount
        )
    );

    flash(
        'warning',
        $name . ' has ' . $tripCount . ' trip(s) on record, so the profile was kept and marked as resigned. '
        . 'Their Fleetra login has been disabled and their bus assignment released.'
    );

    redirect('modules/drivers/view.php?id=' . $driverId);
}

/* ------------------------------------------------------------------
 | No history — remove the profile and the login together
 ------------------------------------------------------------------ */

$pdo = db();

try {
    $pdo->beginTransaction();

    db_execute('DELETE FROM drivers WHERE id = ?', [$driverId]);
    db_execute('DELETE FROM users WHERE id = ?', [$userId]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fleetra_log('Driver deletion failed: ' . $exception->getMessage());
    fleetra_fatal('Something went wrong while deleting the driver. Please try again.');
}

log_activity('Deleted driver ' . $driver['employee_id'], 'drivers', $driverId, $name . ' removed with their account');
fleetra_log('Driver #' . $driverId . ' deleted by user #' . user_id(), 'INFO');

flash('success', $name . ' was deleted along with their Fleetra login.');
redirect('modules/drivers/index.php');
