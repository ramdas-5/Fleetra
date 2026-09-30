<?php
/**
 * Fleetra — Routes & Stops / All routes
 * ------------------------------------------------------------------
 * modules/routes/index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('routes.view');

$canManage = can('routes.manage');

$search       = get('q');
$statusFilter = get('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(r.route_code LIKE ? OR r.route_name LIKE ? OR r.source LIKE ? OR r.destination LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if (is_valid_option(route_status_options(), $statusFilter)) {
    $where[]  = 'r.status = ?';
    $params[] = $statusFilter;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$total = (int) db_value("SELECT COUNT(*) FROM routes r $whereSql", $params, 0);
$page  = paginate($total, 15);

$routes = db_all(
    "SELECT r.*,
            (SELECT COUNT(*) FROM stops s WHERE s.route_id = r.id) AS stop_count,
            (SELECT COUNT(*) FROM schedules sc WHERE sc.route_id = r.id) AS schedule_count,
            (SELECT COUNT(*) FROM bookings b
               JOIN schedules sc2 ON sc2.id = b.schedule_id
              WHERE sc2.route_id = r.id
                AND b.booking_status IN ('confirmed', 'completed')) AS booking_count
       FROM routes r
       $whereSql
      ORDER BY r.route_code
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

$summary = array_column(
    db_all("SELECT r.status, COUNT(*) AS total FROM routes r $whereSql GROUP BY r.status", $params),
    'total',
    'status'
);

$routesWithoutStops = (int) db_value(
    'SELECT COUNT(*) FROM routes r WHERE NOT EXISTS (SELECT 1 FROM stops s WHERE s.route_id = r.id)',
    [],
    0
);

$hasFilters = $search !== '' || is_valid_option(route_status_options(), $statusFilter);

$addButton = $canManage
    ? '<a class="btn btn-primary" href="' . e(url('modules/routes/create.php')) . '">
           <i class="bi bi-plus-lg" aria-hidden="true"></i> Add route
       </a>'
    : '';

$page_title       = 'Routes & stops';
$active_nav       = 'routes';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/routes/index.php')],
    ['label' => 'Routes'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Routes & stops',
    $total . ' route' . ($total === 1 ? '' : 's') . ' matching the current view',
    $addButton
) ?>

<div class="stat-grid">
    <?= stat_card('Total routes', $total, 'bi-map', 'primary', 'Routes in this view') ?>
    <?= stat_card('Active', (int) ($summary['active'] ?? 0), 'bi-check2-circle', 'success', 'Open for scheduling') ?>
    <?= stat_card('Inactive', (int) ($summary['inactive'] ?? 0), 'bi-slash-circle', 'muted', 'Not in service') ?>
    <?= stat_card('Missing stops', $routesWithoutStops, 'bi-geo-alt', $routesWithoutStops > 0 ? 'warning' : 'muted', 'Routes with no stops defined') ?>
</div>

<?php if ($routesWithoutStops > 0): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong><?= $routesWithoutStops ?></strong> route<?= $routesWithoutStops === 1 ? '' : 's' ?>
            have no stops yet. Passengers cannot board without stops, so add them before scheduling.
        </span>
    </div>
<?php endif; ?>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/routes/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search routes</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($search) ?>" placeholder="Search routes...">
            </div>

            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(route_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/routes/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($routes === []): ?>
        <?= empty_state(
            $hasFilters ? 'No routes match these filters' : 'No routes created yet',
            $hasFilters
                ? 'Try a different search term, or clear the filters to see every route.'
                : 'Create your first route to start building schedules and taking bookings.',
            'bi-map',
            $canManage ? $addButton : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Route</th>
                        <th>Code</th>
                        <th>Path</th>
                        <th>Distance</th>
                        <th>Duration</th>
                        <th>Base fare</th>
                        <th>Stops</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routes as $route): ?>
                        <tr>
                            <td>
                                <span class="cell-strong"><?= e($route['route_name']) ?></span><br>
                                <span class="cell-muted"><?= (int) $route['schedule_count'] ?> schedules · <?= (int) $route['booking_count'] ?> bookings</span>
                            </td>
                            <td><span class="chip"><?= e($route['route_code']) ?></span></td>
                            <td>
                                <?= e($route['source']) ?><br>
                                <span class="cell-muted">&rarr; <?= e($route['destination']) ?></span>
                            </td>
                            <td class="cell-num"><?= e(number_format((float) $route['distance'], 1)) ?> km</td>
                            <td class="cell-num"><?= e(format_duration((int) $route['estimated_duration'])) ?></td>
                            <td class="cell-num"><?= e(money($route['base_fare'])) ?></td>
                            <td class="cell-num">
                                <?= (int) $route['stop_count'] ?>
                                <?php if ((int) $route['stop_count'] === 0): ?>
                                    <br><span class="cell-muted" style="color:var(--fl-warning);">None</span>
                                <?php endif; ?>
                            </td>
                            <td><?= status_badge($route['status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/routes/view.php?id=' . (int) $route['id'])) ?>"
                                   title="View route" aria-label="View <?= e($route['route_code']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>

                                <?php if ($canManage): ?>
                                    <a class="row-action" href="<?= e(url('modules/routes/stops.php?route_id=' . (int) $route['id'])) ?>"
                                       title="Manage stops" aria-label="Manage stops for <?= e($route['route_code']) ?>">
                                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                    </a>
                                    <a class="row-action" href="<?= e(url('modules/routes/edit.php?id=' . (int) $route['id'])) ?>"
                                       title="Edit route" aria-label="Edit <?= e($route['route_code']) ?>">
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
