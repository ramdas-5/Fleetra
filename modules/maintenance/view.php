<?php
/**
 * Fleetra — Maintenance / Record detail
 * ------------------------------------------------------------------
 * modules/maintenance/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('maintenance.view');

$recordId = get_int('id');

if ($recordId <= 0) {
    abort_not_found('No maintenance record was specified.');
}

$record = find_maintenance_record($recordId);

if ($record === null) {
    abort_not_found('That maintenance record does not exist.');
}

$canManage = can('maintenance.manage');
$busId     = (int) $record['bus_id'];

$history = db_all(
    'SELECT id, maintenance_type, service_date, next_service_date, cost, status
       FROM maintenance
      WHERE bus_id = ? AND id <> ?
      ORDER BY service_date DESC
      LIMIT 6',
    [$busId, $recordId]
);

$busTotals = db_one(
    "SELECT COUNT(*) AS records,
            COALESCE(SUM(cost), 0) AS spend,
            MIN(service_date) AS since
       FROM maintenance
      WHERE bus_id = ? AND status <> 'cancelled'",
    [$busId]
) ?? [];

$nextServiceDate = $record['next_service_date'];
$nextDueIn       = $nextServiceDate !== null
    ? (int) floor((strtotime((string) $nextServiceDate) - strtotime(date('Y-m-d'))) / 86400)
    : null;

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/maintenance/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Maintenance
            </a>';

if ($canManage) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('modules/maintenance/edit.php?id=' . $recordId)) . '">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit record
                 </a>';

    $actions .= '<form method="post" action="' . e(url('modules/maintenance/delete.php?id=' . $recordId)) . '" class="d-inline"
                  data-confirm="The record is removed from the workshop register. The bus status is recalculated afterwards."
                  data-confirm-title="Delete this maintenance record?"
                  data-confirm-button="Delete record">'
        . csrf_field()
        . '<button type="submit" class="btn btn-outline-danger">
               <i class="bi bi-trash3" aria-hidden="true"></i> Delete
           </button>
       </form>';
}

$page_title       = 'Maintenance record #' . $recordId;
$active_nav       = 'maintenance';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Maintenance', 'url' => url('modules/maintenance/index.php')],
    ['label' => 'Record #' . $recordId],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    labelize((string) $record['maintenance_type']) . ' · ' . $record['bus_number'],
    format_date((string) $record['service_date'], 'D, d M Y') . ' · '
        . ($record['service_provider'] !== null ? (string) $record['service_provider'] : 'No provider recorded'),
    $actions
) ?>

<div class="stat-grid">
    <?= stat_card('Status', labelize((string) $record['status']), 'bi-clipboard-check', status_variant($record['status']), 'Workshop job status') ?>
    <?= stat_card('Cost', money($record['cost']), 'bi-cash-coin', 'primary', 'Recorded against ' . $record['bus_number']) ?>
    <?= stat_card(
        'Odometer',
        $record['odometer_reading'] !== null ? number_format((float) $record['odometer_reading'], 0) . ' km' : '—',
        'bi-speedometer2',
        'info',
        'Bus now at ' . number_format((float) $record['bus_mileage'], 0) . ' km'
    ) ?>
    <?= stat_card(
        'Next service',
        $nextServiceDate !== null ? format_date((string) $nextServiceDate, 'd M Y') : 'Not set',
        'bi-calendar-check',
        $nextDueIn === null ? 'muted' : ($nextDueIn < 0 ? 'danger' : ($nextDueIn <= 15 ? 'warning' : 'success')),
        $nextDueIn === null
            ? 'No follow-up date booked'
            : ($nextDueIn < 0
                ? abs($nextDueIn) . ' day(s) overdue'
                : 'In ' . $nextDueIn . ' day(s)')
    ) ?>
</div>

<?php if ($nextDueIn !== null && $nextDueIn < 0 && in_array((string) $record['status'], ['scheduled', 'in_progress', 'overdue'], true)): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            The follow-up service for <?= e($record['bus_number']) ?> was due on
            <?= e(format_date((string) $nextServiceDate)) ?> (<?= abs($nextDueIn) ?> day(s) ago) and this job is still open.
        </span>
    </div>
<?php endif; ?>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Work carried out</h2>
                    <p class="card-fl__subtitle">Recorded description for this job</p>
                </div>
                <?= status_badge($record['status']) ?>
            </div>

            <div class="card-fl__body">
                <p style="white-space:pre-line;font-size:14.5px;"><?= e((string) $record['description']) ?></p>

                <?php if ($record['remarks'] !== null): ?>
                    <div class="divider"></div>
                    <p class="form-text mb-0">
                        <strong>Remarks:</strong> <?= e((string) $record['remarks']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Service history for <?= e($record['bus_number']) ?></h2>
                    <p class="card-fl__subtitle">Other work recorded against this vehicle</p>
                </div>
                <?php if ($busTotals !== []): ?>
                    <span class="chip">
                        Total spend <strong><?= e(money($busTotals['spend'] ?? 0)) ?></strong>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($history === []): ?>
                <?= empty_state(
                    'No other maintenance recorded',
                    'This is the only job on file for this bus so far.',
                    'bi-clock-history'
                ) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Service date</th>
                                <th>Type</th>
                                <th>Next due</th>
                                <th>Cost</th>
                                <th>Status</th>
                                <th class="cell-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td><?= e(format_date((string) $row['service_date'], 'd M Y')) ?></td>
                                    <td><?= e(labelize((string) $row['maintenance_type'])) ?></td>
                                    <td><?= e(format_date($row['next_service_date'], 'd M Y')) ?></td>
                                    <td class="cell-num"><?= e(money($row['cost'])) ?></td>
                                    <td><?= status_badge($row['status']) ?></td>
                                    <td class="cell-actions">
                                        <a class="row-action" href="<?= e(url('modules/maintenance/view.php?id=' . (int) $row['id'])) ?>"
                                           title="Open record" aria-label="Open maintenance record">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Job details</h2>
                    <p class="card-fl__subtitle">Reference information</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Record</span>
                        <span class="fact-list__value">MNT-<?= str_pad((string) $recordId, 4, '0', STR_PAD_LEFT) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Bus</span>
                        <span class="fact-list__value">
                            <?= e($record['bus_number']) ?>
                            <span class="cell-muted">(<?= e($record['registration_number']) ?>)</span>
                        </span>
                    </li>
                    <li>
                        <span class="fact-list__label">Vehicle</span>
                        <span class="fact-list__value">
                            <?= e(join_parts([$record['manufacturer'], $record['model']], ' ')) ?>
                        </span>
                    </li>
                    <li>
                        <span class="fact-list__label">Type</span>
                        <span class="fact-list__value"><?= e(labelize((string) $record['maintenance_type'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Provider</span>
                        <span class="fact-list__value">
                            <?= $record['service_provider'] !== null ? e((string) $record['service_provider']) : '—' ?>
                        </span>
                    </li>
                    <li>
                        <span class="fact-list__label">Service date</span>
                        <span class="fact-list__value"><?= e(format_date((string) $record['service_date'], 'D, d M Y')) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Next service</span>
                        <span class="fact-list__value"><?= e(format_date($record['next_service_date'], 'D, d M Y')) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Logged</span>
                        <span class="fact-list__value"><?= e(format_datetime((string) $record['created_at'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Bus status</span>
                        <span class="fact-list__value"><?= status_badge($record['bus_status']) ?></span>
                    </li>
                </ul>

                <a class="btn btn-outline-secondary w-100 mt-3"
                   href="<?= e(url('modules/buses/view.php?id=' . $busId)) ?>">
                    <i class="bi bi-bus-front" aria-hidden="true"></i> Open bus <?= e($record['bus_number']) ?>
                </a>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Workshop status</h2>
                    <p class="card-fl__subtitle">How this job affects the fleet</p>
                </div>
            </div>

            <div class="card-fl__body">
                <?php if (maintenance_is_open($record)): ?>
                    <p class="form-text mb-3">
                        This job is still open. The bus is held out of the assignable pool while work is in progress,
                        so it cannot be scheduled for a service.
                    </p>
                <?php else: ?>
                    <p class="form-text mb-3">
                        This job is closed. The bus is released once no other job is open on the vehicle.
                    </p>
                <?php endif; ?>

                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Jobs on record</span>
                        <span class="fact-list__value"><?= (int) ($busTotals['records'] ?? 0) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">First recorded</span>
                        <span class="fact-list__value"><?= e(format_date($busTotals['since'] ?? null, 'd M Y')) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Lifetime cost</span>
                        <span class="fact-list__value"><?= e(money($busTotals['spend'] ?? 0)) ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
