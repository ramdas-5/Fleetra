<?php
/**
 * Fleetra — Maintenance / Delete record
 * ------------------------------------------------------------------
 * modules/maintenance/delete.php
 *
 * POST only and CSRF protected. Nothing references a maintenance row, so
 * the record can be removed outright; the affected bus status is then
 * recalculated from whatever jobs remain open.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('maintenance.manage');

if (!is_post()) {
    flash('warning', 'Deleting a maintenance record requires a confirmed form submission.');
    redirect('modules/maintenance/index.php');
}

require_csrf();

$recordId = get_int('id');

if ($recordId <= 0) {
    abort_not_found('No maintenance record was specified.');
}

$record = db_one('SELECT id, bus_id, maintenance_type, service_date FROM maintenance WHERE id = ? LIMIT 1', [$recordId]);

if ($record === null) {
    abort_not_found('That maintenance record does not exist.');
}

$busId = (int) $record['bus_id'];

db_execute('DELETE FROM maintenance WHERE id = ?', [$recordId]);

reconcile_bus_maintenance_state($busId);

log_activity(
    'Deleted maintenance record #' . $recordId,
    'maintenance',
    $recordId,
    labelize((string) $record['maintenance_type']) . ' dated ' . $record['service_date'] . ' removed'
);

flash('success', 'The maintenance record was deleted.');
redirect('modules/maintenance/index.php');
