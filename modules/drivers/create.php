<?php
/**
 * Fleetra — Fleet / Add driver
 * ------------------------------------------------------------------
 * modules/drivers/create.php
 *
 * Creates the login account (users) and the operational profile
 * (drivers) together. Both writes happen in one transaction, so a
 * driver can never exist without a way to sign in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('drivers.manage');

$errors = [];
$values = driver_form_values();
$buses  = assignable_buses();

if (is_post()) {
    require_csrf();

    $result = validate_driver_request($_POST, false);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            $userId = db_insert('users', array_merge(driver_user_payload($values), [
                'password' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
            ]));

            $driverId = db_insert('drivers', array_merge(driver_db_payload($values), [
                'user_id' => $userId,
            ]));

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            fleetra_log('Driver creation failed: ' . $exception->getMessage());
            fleetra_fatal('Something went wrong while saving the driver. Please try again.');
        }

        log_activity(
            'Created driver ' . $values['employee_id'],
            'drivers',
            $driverId,
            'Added ' . $values['name'] . ' with account #' . $userId
        );
        fleetra_log('Driver #' . $driverId . ' created by user #' . user_id(), 'INFO');

        flash('success', 'Driver ' . $values['name'] . ' was added.');
        redirect('modules/drivers/view.php?id=' . $driverId);
    }
}

$formAction = url('modules/drivers/create.php');
$isEdit     = false;

$page_title       = 'Add driver';
$active_nav       = 'drivers';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/drivers/index.php')],
    ['label' => 'Drivers', 'url' => url('modules/drivers/index.php')],
    ['label' => 'Add driver'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Add a driver',
    'Create the driver account and operational profile',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/drivers/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to drivers
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
