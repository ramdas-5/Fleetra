<?php
/**
 * Fleetra — Fleet / All buses
 * ------------------------------------------------------------------
 * modules/buses/index.php
 *
 * Paginated fleet register with search and filters. Read access uses the
 * fleet.view capability, so dispatchers can monitor the fleet while only
 * administrators and transport managers can change it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

require_permission('fleet.view');

$canManage = can('fleet.manage');

/* ------------------------------------------------------------------
 | Filters
 ------------------------------------------------------------------ */

$search       = get('q');
$statusFilter = get('status');
$typeFilter   = get('type');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(b.bus_number LIKE ? OR b.registration_number LIKE ? OR b.manufacturer LIKE ? OR b.model LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if (is_valid_option(bus_status_options(), $statusFilter)) {
    $where[]  = 'b.status = ?';
    $params[] = $statusFilter;
}

if (is_valid_option(bus_type_options(), $typeFilter)) {
    $where[]  = 'b.bus_type = ?';
    $params[] = $typeFilter;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

/* ------------------------------------------------------------------
 | Page of results
 ------------------------------------------------------------------ */

$total = (int) db_value("SELECT COUNT(*) FROM buses b $whereSql", $params, 0);
$page  = paginate($total, 15);

$buses = db_all(
    "SELECT b.*,
            (SELECT GROUP_CONCAT(u.name SEPARATOR ', ')
               FROM drivers d
               JOIN users u ON u.id = d.user_id
              WHERE d.assigned_bus_id = b.id) AS driver_names,
            (SELECT r.route_code
               FROM trips t
               JOIN schedules s ON s.id = t.schedule_id
               JOIN routes r    ON r.id = t.route_id
              WHERE t.bus_id = b.id AND s.schedule_date = CURDATE()
              ORDER BY s.departure_time
              LIMIT 1) AS today_route
       FROM buses b
       $whereSql
      ORDER BY b.bus_number
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

/* ------------------------------------------------------------------
 | Fleet summary (respects the active filters)
 ------------------------------------------------------------------ */

$summary = array_column(
    db_all("SELECT b.status, COUNT(*) AS total FROM buses b $whereSql GROUP BY b.status", $params),
    'total',
    'status'
);

$hasFilters = $search !== '' || is_valid_option(bus_status_options(), $statusFilter)
    || is_valid_option(bus_type_options(), $typeFilter);

$addButton = $canManage
    ? '<a class="btn btn-primary" href="' . e(url('modules/buses/create.php')) . '">
           <i class="bi bi-plus-lg" aria-hidden="true"></i> Add bus
       </a>'
    : '';

$page_title       = 'Buses';
$active_nav       = 'buses';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/buses/index.php')],
    ['label' => 'All buses'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Fleet',
    $total . ' bus' . ($total === 1 ? '' : 'es') . ' matching the current view',
    $addButton
) ?>

<div class="stat-grid">
    <?= stat_card('Total', $total, 'bi-bus-front', 'primary', 'Buses in this view') ?>
    <?= stat_card('Active', (int) ($summary['active'] ?? 0), 'bi-check2-circle', 'success', 'Available for service') ?>
    <?= stat_card('In maintenance', (int) ($summary['maintenance'] ?? 0), 'bi-tools', 'warning', 'Currently in the workshop') ?>
    <?= stat_card('Inactive', (int) ($summary['inactive'] ?? 0), 'bi-slash-circle', 'muted', 'Out of service') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/buses/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search buses</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($search) ?>" placeholder="Search buses...">
            </div>

            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(bus_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <label class="visually-hidden" for="type">Type</label>
            <select class="form-select" id="type" name="type" style="width:auto;">
                <?= option_tags(bus_type_options(), $typeFilter, 'All types') ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/buses/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($buses === []): ?>
        <?= empty_state(
            $hasFilters ? 'No buses match these filters' : 'No buses in the fleet yet',
            $hasFilters
                ? 'Try a different search term, or clear the filters to see the whole fleet.'
                : 'Add your first bus to start building schedules and assigning drivers.',
            'bi-bus-front',
            $canManage ? $addButton : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Bus</th>
                        <th>Registration</th>
                        <th>Capacity</th>
                        <th>Driver</th>
                        <th>Route today</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($buses as $bus): ?>
                        <tr>
                            <td>
                                <div class="cell-user">
                                    <?php if (!empty($bus['image'])): ?>
                                        <img class="avatar avatar--sm" src="<?= e(upload_url($bus['image'], 'buses')) ?>"
                                             alt="" loading="lazy">
                                    <?php else: ?>
                                        <span class="avatar avatar--sm" aria-hidden="true">
                                            <i class="bi bi-bus-front"></i>
                                        </span>
                                    <?php endif; ?>
                                    <span class="cell-user__text">
                                        <span class="cell-user__name"><?= e($bus['bus_number']) ?></span>
                                        <span class="cell-user__meta">
                                            <?= e(join_parts([$bus['manufacturer'], $bus['model']], ' ')) ?: 'No model recorded' ?>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td><?= e($bus['registration_number']) ?></td>
                            <td class="cell-num"><?= (int) $bus['capacity'] ?> seats</td>
                            <td>
                                <?= $bus['driver_names'] !== null
                                    ? e($bus['driver_names'])
                                    : '<span class="cell-muted">Unassigned</span>' ?>
                            </td>
                            <td>
                                <?= $bus['today_route'] !== null
                                    ? '<span class="chip">' . e($bus['today_route']) . '</span>'
                                    : '<span class="cell-muted">No trip today</span>' ?>
                            </td>
                            <td><?= status_badge($bus['status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/buses/view.php?id=' . (int) $bus['id'])) ?>"
                                   title="View bus" aria-label="View <?= e($bus['bus_number']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>

                                <?php if ($canManage): ?>
                                    <a class="row-action" href="<?= e(url('modules/buses/edit.php?id=' . (int) $bus['id'])) ?>"
                                       title="Edit bus" aria-label="Edit <?= e($bus['bus_number']) ?>">
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
