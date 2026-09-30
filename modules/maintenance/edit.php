<?php
/**
 * Fleetra — Maintenance / Edit record
 * ------------------------------------------------------------------
 * modules/maintenance/edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('maintenance.manage');

$recordId = get_int('id');

if ($recordId <= 0) {
    abort_not_found('No maintenance record was specified.');
}

$record = find_maintenance_record($recordId);

if ($record === null) {
    abort_not_found('That maintenance record does not exist.');
}

$errors = [];
$values = maintenance_form_values($record);
$previousBusId = (int) $record['bus_id'];

if (is_post()) {
    require_csrf();

    $result = validate_maintenance_request($_POST);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        db_update('maintenance', maintenance_db_payload($values), ['id' => $recordId]);

        sync_bus_odometer((int) $values['bus_id'], $values['odometer_reading'] === '' ? null : (float) $values['odometer_reading']);

        // The job may have been moved to another bus, so both vehicles need
        // their workshop status recalculated.
        reconcile_bus_maintenance_state($previousBusId);
        if ((int) $values['bus_id'] !== $previousBusId) {
            reconcile_bus_maintenance_state((int) $values['bus_id']);
        }

        log_activity(
            'Updated maintenance record #' . $recordId,
            'maintenance',
            $recordId,
            labelize($values['maintenance_type']) . ' updated, status ' . labelize($values['status'])
        );

        flash('success', 'The maintenance record was updated.');
        redirect('modules/maintenance/view.php?id=' . $recordId);
    }
}

$formAction = url('modules/maintenance/edit.php?id=' . $recordId);
$isEdit     = true;

$page_title       = 'Edit maintenance record';
$active_nav       = 'maintenance';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Maintenance', 'url' => url('modules/maintenance/index.php')],
    ['label' => 'Record #' . $recordId, 'url' => url('modules/maintenance/view.php?id=' . $recordId)],
    ['label' => 'Edit'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Edit maintenance record',
    'Bus ' . $record['bus_number'] . ' · ' . format_date((string) $record['service_date']),
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/maintenance/view.php?id=' . $recordId)) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to record
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
