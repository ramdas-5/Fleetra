<?php
/**
 * Fleetra — Fleet / Edit driver
 * ------------------------------------------------------------------
 * modules/drivers/edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('drivers.manage');

$driverId = get_int('id');

if ($driverId <= 0) {
    abort_not_found('No driver was specified.');
}

$driver = db_one(
    'SELECT d.*, u.name, u.email, u.phone
       FROM drivers d
       JOIN users u ON u.id = d.user_id
      WHERE d.id = ?
      LIMIT 1',
    [$driverId]
);

if ($driver === null) {
    abort_not_found('That driver does not exist.');
}

$userId = (int) $driver['user_id'];
$errors = [];
$values = driver_form_values($driver);
$buses  = assignable_buses($driver['assigned_bus_id'] !== null ? (int) $driver['assigned_bus_id'] : null);

if (is_post()) {
    require_csrf();

    $result = validate_driver_request($_POST, true, $driverId, $userId);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            db_update('users', driver_user_payload($values), ['id' => $userId]);
            db_update('drivers', driver_db_payload($values), ['id' => $driverId]);

            // An administrator may set a new password for the driver here.
            $newPassword = (string) ($_POST['password'] ?? '');
            if ($newPassword !== '') {
                db_update('users', ['password' => password_hash($newPassword, PASSWORD_DEFAULT)], ['id' => $userId]);
                db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
                fleetra_log('Password reset for driver #' . $driverId . ' by user #' . user_id(), 'INFO');
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            fleetra_log('Driver update failed: ' . $exception->getMessage());
            fleetra_fatal('Something went wrong while saving the driver. Please try again.');
        }

        log_activity(
            'Updated driver ' . $values['employee_id'],
            'drivers',
            $driverId,
            $values['name'] . ' profile updated'
        );

        flash('success', 'Driver ' . $values['name'] . ' was updated.');
        redirect('modules/drivers/view.php?id=' . $driverId);
    }
}

$formAction = url('modules/drivers/edit.php?id=' . $driverId);
$isEdit     = true;

$page_title       = 'Edit ' . $driver['name'];
$active_nav       = 'drivers';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/drivers/index.php')],
    ['label' => (string) $driver['name'], 'url' => url('modules/drivers/view.php?id=' . $driverId)],
    ['label' => 'Edit'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Edit ' . $driver['name'],
    'Employee ' . $driver['employee_id'] . ' · licence ' . $driver['license_number'],
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/drivers/view.php?id=' . $driverId)) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to driver
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
