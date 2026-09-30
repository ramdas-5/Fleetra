<?php
/**
 * Fleetra — Maintenance / Log maintenance
 * ------------------------------------------------------------------
 * modules/maintenance/create.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('maintenance.manage');

$errors = [];
$values = maintenance_form_values();

if (is_post()) {
    require_csrf();

    $result = validate_maintenance_request($_POST);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        $recordId = db_insert('maintenance', maintenance_db_payload($values));

        sync_bus_odometer((int) $values['bus_id'], $values['odometer_reading'] === '' ? null : (float) $values['odometer_reading']);
        reconcile_bus_maintenance_state((int) $values['bus_id']);

        log_activity(
            'Logged maintenance for bus #' . $values['bus_id'],
            'maintenance',
            $recordId,
            labelize($values['maintenance_type']) . ' recorded with status ' . labelize($values['status'])
        );

        flash('success', 'The maintenance record was saved.');
        redirect('modules/maintenance/view.php?id=' . $recordId);
    }
}

$formAction = url('modules/maintenance/create.php');
$isEdit     = false;

$page_title       = 'Log maintenance';
$active_nav       = 'maintenance';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Maintenance', 'url' => url('modules/maintenance/index.php')],
    ['label' => 'Log maintenance'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Log maintenance',
    'Record a service, repair or inspection against a fleet vehicle',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/maintenance/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to maintenance
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
