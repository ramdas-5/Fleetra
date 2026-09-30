<?php
/**
 * Fleetra — Fleet / Drivers
 * ------------------------------------------------------------------
 * modules/drivers/index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('drivers.view');

$canManage = can('drivers.manage');

$search       = get('q');
$statusFilter = get('status');
$assignment   = get('assignment');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR d.employee_id LIKE ? OR d.license_number LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

if (is_valid_option(employment_status_options(), $statusFilter)) {
    $where[]  = 'd.employment_status = ?';
    $params[] = $statusFilter;
}

if ($assignment === 'assigned') {
    $where[] = 'd.assigned_bus_id IS NOT NULL';
} elseif ($assignment === 'unassigned') {
    $where[] = 'd.assigned_bus_id IS NULL';
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$total = (int) db_value(
    "SELECT COUNT(*) FROM drivers d JOIN users u ON u.id = d.user_id $whereSql",
    $params,
    0
);
$page = paginate($total, 15);

$drivers = db_all(
    "SELECT d.*, u.name, u.email, u.phone, u.status AS user_status,
            b.bus_number, b.registration_number,
            (SELECT r.route_code
               FROM trips t
               JOIN schedules s ON s.id = t.schedule_id
               JOIN routes r    ON r.id = t.route_id
              WHERE t.driver_id = d.id AND s.schedule_date = CURDATE()
              ORDER BY s.departure_time
              LIMIT 1) AS today_route,
            (SELECT COUNT(*) FROM trips t WHERE t.driver_id = d.id) AS trip_count
       FROM drivers d
       JOIN users u ON u.id = d.user_id
       LEFT JOIN buses b ON b.id = d.assigned_bus_id
       $whereSql
      ORDER BY u.name
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

$summary = array_column(
    db_all("SELECT d.employment_status, COUNT(*) AS total FROM drivers d JOIN users u ON u.id = d.user_id $whereSql GROUP BY d.employment_status", $params),
    'total',
    'employment_status'
);

$licencesExpiring = (int) db_value(
    'SELECT COUNT(*) FROM drivers
      WHERE license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)',
    [],
    0
);

$hasFilters = $search !== '' || is_valid_option(employment_status_options(), $statusFilter)
    || in_array($assignment, ['assigned', 'unassigned'], true);

$addButton = $canManage
    ? '<a class="btn btn-primary" href="' . e(url('modules/drivers/create.php')) . '">
           <i class="bi bi-plus-lg" aria-hidden="true"></i> Add driver
       </a>'
    : '';

$page_title       = 'Drivers';
$active_nav       = 'drivers';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/drivers/index.php')],
    ['label' => 'Drivers'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Drivers',
    $total . ' driver' . ($total === 1 ? '' : 's') . ' matching the current view',
    $addButton
) ?>

<div class="stat-grid">
    <?= stat_card('Total', $total, 'bi-person-badge', 'primary', 'Drivers in this view') ?>
    <?= stat_card('Active', (int) ($summary['active'] ?? 0), 'bi-check2-circle', 'success', 'Available for duty') ?>
    <?= stat_card('On leave', (int) ($summary['on_leave'] ?? 0), 'bi-calendar-x', 'warning', 'Temporarily unavailable') ?>
    <?= stat_card('Licences expiring', $licencesExpiring, 'bi-card-checklist', $licencesExpiring > 0 ? 'danger' : 'muted', 'Within the next 60 days') ?>
</div>

<?php if ($licencesExpiring > 0): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong><?= $licencesExpiring ?></strong> driver licence<?= $licencesExpiring === 1 ? '' : 's' ?>
            will expire within 60 days. Expired licences block bus assignments, so arrange renewals early.
        </span>
    </div>
<?php endif; ?>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/drivers/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search drivers</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($search) ?>" placeholder="Search drivers...">
            </div>

            <label class="visually-hidden" for="status">Employment status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(employment_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <label class="visually-hidden" for="assignment">Assignment</label>
            <select class="form-select" id="assignment" name="assignment" style="width:auto;">
                <?= option_tags(['assigned' => 'Bus assigned', 'unassigned' => 'No bus assigned'], $assignment, 'Any assignment') ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/drivers/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($drivers === []): ?>
        <?= empty_state(
            $hasFilters ? 'No drivers match these filters' : 'No drivers registered yet',
            $hasFilters
                ? 'Try a different search term, or clear the filters to see every driver.'
                : 'Add your first driver to assign buses and build schedules.',
            'bi-person-badge',
            $canManage ? $addButton : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Driver</th>
                        <th>Employee ID</th>
                        <th>Licence</th>
                        <th>Assigned bus</th>
                        <th>Route today</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drivers as $driver): ?>
                        <?php
                        $expiresSoon = strtotime((string) $driver['license_expiry']) < strtotime('+60 days');
                        $isExpired   = strtotime((string) $driver['license_expiry']) < strtotime('today');
                        ?>
                        <tr>
                            <td>
                                <div class="cell-user">
                                    <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials($driver['name'])) ?></span>
                                    <span class="cell-user__text">
                                        <span class="cell-user__name"><?= e($driver['name']) ?></span>
                                        <span class="cell-user__meta"><?= e($driver['phone'] ?: $driver['email']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($driver['employee_id']) ?></span><br>
                                <span class="cell-muted"><?= (int) $driver['trip_count'] ?> trips</span>
                            </td>
                            <td>
                                <?= e($driver['license_number']) ?><br>
                                <span class="cell-muted" style="<?= $isExpired ? 'color:var(--fl-danger);' : ($expiresSoon ? 'color:var(--fl-warning);' : '') ?>">
                                    <?= $isExpired ? 'Expired ' : 'Valid to ' ?><?= e(format_date($driver['license_expiry'], 'd M Y')) ?>
                                </span>
                            </td>
                            <td>
                                <?= $driver['bus_number'] !== null
                                    ? e($driver['bus_number'])
                                    : '<span class="cell-muted">Unassigned</span>' ?>
                            </td>
                            <td>
                                <?= $driver['today_route'] !== null
                                    ? '<span class="chip">' . e($driver['today_route']) . '</span>'
                                    : '<span class="cell-muted">No trip today</span>' ?>
                            </td>
                            <td><?= status_badge($driver['employment_status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/drivers/view.php?id=' . (int) $driver['id'])) ?>"
                                   title="View driver" aria-label="View <?= e($driver['name']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>

                                <?php if ($canManage): ?>
                                    <a class="row-action" href="<?= e(url('modules/drivers/edit.php?id=' . (int) $driver['id'])) ?>"
                                       title="Edit driver" aria-label="Edit <?= e($driver['name']) ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
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
