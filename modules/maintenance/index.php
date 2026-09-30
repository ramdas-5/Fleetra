<?php
/**
 * Fleetra — Maintenance
 * ------------------------------------------------------------------
 * modules/maintenance/index.php
 *
 * The workshop register: what was done, what is booked in, what is due
 * and what it has cost.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('maintenance.view');

$canManage = can('maintenance.manage');

$search       = get('q');
$typeFilter   = get('type');
$statusFilter = get('status');
$busFilter    = get('bus_id');
$dateFrom     = get('date_from');
$dateTo       = get('date_to');
$view         = get('view');

$where  = [];
$params = [];

// The "due" view is the maintenance diary: everything still to be done.
$dueOnly = $view === 'due';

if ($dueOnly) {
    $where[] = "m.status IN ('scheduled','in_progress','overdue')";
}

if ($search !== '') {
    $where[] = '(b.bus_number LIKE ? OR b.registration_number LIKE ? OR m.service_provider LIKE ? OR m.description LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if (is_valid_option(maintenance_type_options(), $typeFilter)) {
    $where[]  = 'm.maintenance_type = ?';
    $params[] = $typeFilter;
}

if (is_valid_option(maintenance_status_options(), $statusFilter)) {
    $where[]  = 'm.status = ?';
    $params[] = $statusFilter;
}

if ($busFilter !== '' && is_valid_option(maintenance_bus_options(true), $busFilter)) {
    $where[]  = 'm.bus_id = ?';
    $params[] = (int) $busFilter;
}

if (is_valid_date($dateFrom)) {
    $where[]  = 'm.service_date >= ?';
    $params[] = $dateFrom;
}

if (is_valid_date($dateTo)) {
    $where[]  = 'm.service_date <= ?';
    $params[] = $dateTo;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
$joins    = 'FROM maintenance m JOIN buses b ON b.id = m.bus_id';

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params, 0);
$page  = paginate($total, 15);

$records = db_all(
    "SELECT m.*, b.bus_number, b.registration_number, b.status AS bus_status
       $joins
       $whereSql
      ORDER BY (m.status = 'in_progress') DESC, m.service_date DESC, m.id DESC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

/* Headline numbers — always fleet-wide, independent of the current filter. */
$inWorkshop = (int) db_value("SELECT COUNT(*) FROM maintenance WHERE status = 'in_progress'", [], 0);
$scheduled  = (int) db_value("SELECT COUNT(*) FROM maintenance WHERE status IN ('scheduled','overdue')", [], 0);
$costYear   = (float) db_value(
    "SELECT COALESCE(SUM(cost), 0) FROM maintenance WHERE YEAR(service_date) = YEAR(CURDATE()) AND status <> 'cancelled'",
    [],
    0
);
$costMonth  = (float) db_value(
    "SELECT COALESCE(SUM(cost), 0) FROM maintenance
      WHERE service_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND status <> 'cancelled'",
    [],
    0
);

$hasFilters = $search !== '' || $typeFilter !== '' || $statusFilter !== ''
    || $busFilter !== '' || is_valid_date($dateFrom) || is_valid_date($dateTo) || $dueOnly;

$page_title       = 'Maintenance';
$active_nav       = 'maintenance';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet'],
    ['label' => 'Maintenance'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Maintenance',
    number_format($total) . ' record' . ($total === 1 ? '' : 's') . ' matching the current view',
    $canManage
        ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/maintenance/index.php?view=due')) . '">
               <i class="bi bi-calendar-check" aria-hidden="true"></i> Service diary
           </a>
           <a class="btn btn-primary" href="' . e(url('modules/maintenance/create.php')) . '">
               <i class="bi bi-plus-lg" aria-hidden="true"></i> Log maintenance
           </a>'
        : ''
) ?>

<?php if ($inWorkshop > 0): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-tools app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong><?= $inWorkshop ?></strong> bus<?= $inWorkshop === 1 ? ' is' : 'es are' ?> currently in the workshop.
            Those vehicles cannot be assigned to schedules until the work is completed.
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('In the workshop', $inWorkshop, 'bi-tools', $inWorkshop > 0 ? 'warning' : 'muted', 'Work in progress now') ?>
    <?= stat_card('Booked in / overdue', $scheduled, 'bi-calendar-check', $scheduled > 0 ? 'info' : 'muted', 'Scheduled and overdue jobs') ?>
    <?= stat_card('Cost this month', money($costMonth), 'bi-cash-coin', 'primary', 'Completed and in progress') ?>
    <?= stat_card('Cost this year', money($costYear), 'bi-graph-up', 'muted', 'Excluding cancelled jobs') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/maintenance/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search maintenance</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Bus, provider or description...">
            </div>

            <label class="visually-hidden" for="type">Maintenance type</label>
            <select class="form-select" id="type" name="type" style="width:auto;">
                <?= option_tags(maintenance_type_options(), $typeFilter, 'All types') ?>
            </select>

            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(maintenance_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <label class="visually-hidden" for="bus_id">Bus</label>
            <select class="form-select" id="bus_id" name="bus_id" style="width:auto;">
                <?= option_tags(maintenance_bus_options(true), $busFilter, 'All buses') ?>
            </select>

            <label class="visually-hidden" for="date_from">Service date from</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>" style="width:auto;">

            <label class="visually-hidden" for="date_to">Service date to</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>" style="width:auto;">

            <?php if ($dueOnly): ?>
                <input type="hidden" name="view" value="due">
            <?php endif; ?>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/maintenance/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($records === []): ?>
        <?= empty_state(
            $hasFilters ? 'No maintenance records match these filters' : 'No maintenance recorded yet',
            $hasFilters
                ? 'Try a different bus or date range, or clear the filters to see the whole workshop register.'
                : 'Log the first service job to start building the maintenance history.',
            'bi-tools',
            $canManage
                ? '<a class="btn btn-primary" href="' . e(url('modules/maintenance/create.php')) . '">Log maintenance</a>'
                : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Bus</th>
                        <th>Service</th>
                        <th>Service date</th>
                        <th>Next due</th>
                        <th>Odometer</th>
                        <th>Cost</th>
                        <th>Provider</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $nextDue  = $record['next_service_date'];
                        $isOverdue = $nextDue !== null && $nextDue < date('Y-m-d')
                            && in_array((string) $record['status'], ['scheduled', 'in_progress', 'overdue'], true);
                        ?>
                        <tr>
                            <td>
                                <span class="cell-strong"><?= e($record['bus_number']) ?></span><br>
                                <span class="cell-muted"><?= e($record['registration_number']) ?></span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e(labelize((string) $record['maintenance_type'])) ?></span><br>
                                <span class="cell-muted"><?= e(truncate((string) $record['description'], 70)) ?></span>
                            </td>
                            <td><?= e(format_date((string) $record['service_date'], 'd M Y')) ?></td>
                            <td>
                                <?= e(format_date($nextDue, 'd M Y')) ?>
                                <?php if ($isOverdue): ?>
                                    <br><span class="badge-status badge-danger">Overdue</span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-num">
                                <?= $record['odometer_reading'] !== null
                                    ? e(number_format((float) $record['odometer_reading'], 0)) . ' km'
                                    : '<span class="cell-muted">—</span>' ?>
                            </td>
                            <td class="cell-num"><?= e(money($record['cost'])) ?></td>
                            <td>
                                <?= $record['service_provider'] !== null
                                    ? e(truncate((string) $record['service_provider'], 34))
                                    : '<span class="cell-muted">—</span>' ?>
                            </td>
                            <td><?= status_badge($record['status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/maintenance/view.php?id=' . (int) $record['id'])) ?>"
                                   title="Open record" aria-label="Open maintenance record">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <?php if ($canManage): ?>
                                    <a class="row-action" href="<?= e(url('modules/maintenance/edit.php?id=' . (int) $record['id'])) ?>"
                                       title="Edit record" aria-label="Edit maintenance record">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <a class="row-action" href="<?= e(url('modules/buses/view.php?id=' . (int) $record['bus_id'])) ?>"
                                       title="Open bus" aria-label="Open bus <?= e($record['bus_number']) ?>">
                                        <i class="bi bi-bus-front" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= render_pagination($page) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
