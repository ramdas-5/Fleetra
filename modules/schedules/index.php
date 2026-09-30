<?php
/**
 * Fleetra — Schedules
 * ------------------------------------------------------------------
 * modules/schedules/index.php
 *
 * The planning board: every dated departure, upcoming first so today's
 * operation is always at the top of the list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('schedules.view');

$canManage = can('schedules.manage');

$search      = get('q');
$dateFrom    = get('date_from');
$dateTo      = get('date_to');
$routeFilter = get('route_id');
$busFilter   = get('bus_id');
$driverFilter = get('driver_id');
$statusFilter = get('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(r.route_code LIKE ? OR r.route_name LIKE ? OR r.source LIKE ? OR r.destination LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if (is_valid_date($dateFrom)) {
    $where[]  = 's.schedule_date >= ?';
    $params[] = $dateFrom;
}

if (is_valid_date($dateTo)) {
    $where[]  = 's.schedule_date <= ?';
    $params[] = $dateTo;
}

if ($routeFilter !== '' && is_valid_option(schedule_route_options(true), $routeFilter)) {
    $where[]  = 's.route_id = ?';
    $params[] = (int) $routeFilter;
}

if ($busFilter !== '' && is_valid_option(schedulable_bus_options(true), $busFilter)) {
    $where[]  = 's.bus_id = ?';
    $params[] = (int) $busFilter;
}

if ($driverFilter !== '' && is_valid_option(schedulable_driver_options(true), $driverFilter)) {
    $where[]  = 's.driver_id = ?';
    $params[] = (int) $driverFilter;
}

if (is_valid_option(schedule_status_options(), $statusFilter)) {
    $where[]  = 's.status = ?';
    $params[] = $statusFilter;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$total = (int) db_value(
    "SELECT COUNT(*)
       FROM schedules s
       JOIN routes r  ON r.id = s.route_id
       JOIN buses b   ON b.id = s.bus_id
       JOIN drivers d ON d.id = s.driver_id
       JOIN users u   ON u.id = d.user_id
       $whereSql",
    $params,
    0
);

$page = paginate($total, 15);

$schedules = db_all(
    "SELECT s.*,
            r.route_code, r.route_name, r.source, r.destination, r.base_fare,
            b.bus_number, b.registration_number, b.capacity, b.status AS bus_status,
            u.name AS driver_name, d.employee_id,
            t.id AS trip_id, t.trip_status, t.passenger_count,
            (SELECT COUNT(*) FROM bookings bk
              WHERE bk.schedule_id = s.id AND bk.booking_status IN ('pending','confirmed','completed')) AS seats_sold
       FROM schedules s
       JOIN routes r  ON r.id = s.route_id
       JOIN buses b   ON b.id = s.bus_id
       JOIN drivers d ON d.id = s.driver_id
       JOIN users u   ON u.id = d.user_id
       LEFT JOIN trips t ON t.schedule_id = s.id
       $whereSql
      ORDER BY (s.schedule_date < CURDATE()) ASC, s.schedule_date ASC, s.departure_time ASC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

$todayCount = (int) db_value(
    'SELECT COUNT(*) FROM schedules WHERE schedule_date = CURDATE() AND status <> "cancelled"',
    [],
    0
);
$runningCount = (int) db_value('SELECT COUNT(*) FROM schedules WHERE status = "running"', [], 0);
$upcomingCount = (int) db_value(
    'SELECT COUNT(*) FROM schedules
      WHERE schedule_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND status = "scheduled"',
    [],
    0
);
$cancelledCount = (int) db_value(
    'SELECT COUNT(*) FROM schedules
      WHERE status = "cancelled" AND schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
    [],
    0
);
$seatsToday = (int) db_value(
    'SELECT COUNT(*) FROM bookings b JOIN schedules s ON s.id = b.schedule_id
      WHERE s.schedule_date = CURDATE() AND b.booking_status IN ("pending","confirmed","completed")',
    [],
    0
);

$hasFilters = $search !== '' || is_valid_date($dateFrom) || is_valid_date($dateTo)
    || (is_valid_option(schedule_route_options(true), $routeFilter))
    || (is_valid_option(schedulable_bus_options(true), $busFilter))
    || (is_valid_option(schedulable_driver_options(true), $driverFilter))
    || is_valid_option(schedule_status_options(), $statusFilter);

/** Quick date filter link, preserving the other filters. */
$quickLink = static function (string $label, array $overrides, string $activeKey, ?string $activeValue = null): string {
    $query = array_merge($_GET, $overrides);
    unset($query['page']);

    $isActive = ($query[$activeKey] ?? '') === (string) $activeValue;

    return '<a class="btn btn-sm ' . ($isActive ? 'btn-primary' : 'btn-outline-secondary') . '"
               href="' . e('?' . http_build_query($query)) . '">' . e($label) . '</a>';
};

$page_title       = 'Schedules';
$active_nav       = 'schedules';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Operations'],
    ['label' => 'Schedules'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Schedules',
    $total . ' departure' . ($total === 1 ? '' : 's') . ' matching the current view',
    $canManage
        ? '<a class="btn btn-primary" href="' . e(url('modules/schedules/create.php')) . '">
               <i class="bi bi-plus-lg" aria-hidden="true"></i> Add departure
           </a>'
        : ''
) ?>

<div class="stat-grid">
    <?= stat_card('Departures today', $todayCount, 'bi-calendar3', 'primary', 'Excludes cancelled services') ?>
    <?= stat_card('Running now', $runningCount, 'bi-broadcast', $runningCount > 0 ? 'info' : 'muted', 'Buses currently on the road') ?>
    <?= stat_card('Next 7 days', $upcomingCount, 'bi-calendar-week', 'success', 'Still to be operated') ?>
    <?= stat_card('Cancelled', $cancelledCount, 'bi-x-octagon', $cancelledCount > 0 ? 'danger' : 'muted', 'In the last 30 days') ?>
    <?= stat_card('Seats booked today', $seatsToday, 'bi-people', 'info', 'Across all of today\'s services') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/schedules/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search schedules</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Route code, name or city...">
            </div>

            <label class="visually-hidden" for="date_from">From date</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>" style="width:auto;">

            <label class="visually-hidden" for="date_to">To date</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>" style="width:auto;">

            <label class="visually-hidden" for="route_id">Route</label>
            <select class="form-select" id="route_id" name="route_id" style="width:auto;">
                <?= option_tags(schedule_route_options(true), $routeFilter, 'All routes') ?>
            </select>

            <label class="visually-hidden" for="bus_id">Bus</label>
            <select class="form-select" id="bus_id" name="bus_id" style="width:auto;">
                <?= option_tags(schedulable_bus_options(true), $busFilter, 'All buses') ?>
            </select>

            <label class="visually-hidden" for="driver_id">Driver</label>
            <select class="form-select" id="driver_id" name="driver_id" style="width:auto;">
                <?= option_tags(schedulable_driver_options(true), $driverFilter, 'All drivers') ?>
            </select>

            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(schedule_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/schedules/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="toolbar" style="border-top:1px solid var(--fl-border);">
        <div class="toolbar__filters">
            <?= $quickLink('Today', ['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d')], 'date_from', date('Y-m-d')) ?>
            <?= $quickLink('Tomorrow', ['date_from' => date('Y-m-d', strtotime('+1 day')), 'date_to' => date('Y-m-d', strtotime('+1 day'))], 'date_from', date('Y-m-d', strtotime('+1 day'))) ?>
            <?= $quickLink('Next 7 days', ['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d', strtotime('+6 days'))], 'date_from', date('Y-m-d')) ?>
            <?= $quickLink('All dates', ['date_from' => '', 'date_to' => ''], 'date_from', '') ?>
        </div>
    </div>

    <?php if ($schedules === []): ?>
        <?= empty_state(
            $hasFilters ? 'No departures match these filters' : 'No departures scheduled yet',
            $hasFilters
                ? 'Try a wider date range, or clear the filters to see every departure.'
                : 'A schedule pairs a route with a bus and a driver, and creates the trip that operates it.',
            'bi-calendar3',
            $canManage && !$hasFilters
                ? '<a class="btn btn-primary" href="' . e(url('modules/schedules/create.php')) . '">Add departure</a>'
                : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Departure</th>
                        <th>Route</th>
                        <th>Bus</th>
                        <th>Driver</th>
                        <th>Seats</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <?php
                        $isToday = (string) $schedule['schedule_date'] === date('Y-m-d');
                        $seats   = (int) $schedule['seats_sold'];
                        $capacity = (int) $schedule['capacity'];
                        ?>
                        <tr>
                            <td>
                                <span class="cell-strong"><?= e(format_date($schedule['schedule_date'], 'd M Y')) ?></span>
                                <?php if ($isToday): ?><span class="badge-status badge-primary">Today</span><?php endif; ?>
                                <br>
                                <span class="cell-muted">
                                    <?= e(time_range_label((string) $schedule['departure_time'], (string) $schedule['arrival_time'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($schedule['route_code']) ?></span><br>
                                <span class="cell-muted"><?= e($schedule['source']) ?> &rarr; <?= e($schedule['destination']) ?></span>
                            </td>
                            <td>
                                <?= e($schedule['bus_number']) ?><br>
                                <span class="cell-muted"><?= e($schedule['registration_number']) ?></span>
                            </td>
                            <td>
                                <?= e($schedule['driver_name']) ?><br>
                                <span class="cell-muted"><?= e($schedule['employee_id']) ?></span>
                            </td>
                            <td class="cell-num">
                                <?= $seats ?> / <?= $capacity ?>
                                <div class="progress-fl mt-1" aria-hidden="true">
                                    <div class="progress-fl__bar<?= $capacity > 0 && $seats >= $capacity ? ' progress-fl__bar--warning' : '' ?>"
                                         style="width: <?= $capacity > 0 ? min(100, (int) round(($seats / $capacity) * 100)) : 0 ?>%"></div>
                                </div>
                            </td>
                            <td>
                                <?= status_badge($schedule['status']) ?>
                                <?php if ($schedule['trip_status'] !== null): ?>
                                    <br><span class="cell-muted">Trip: <?= e(labelize((string) $schedule['trip_status'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/schedules/view.php?id=' . (int) $schedule['id'])) ?>"
                                   title="View departure" aria-label="View departure">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <?php if ($canManage): ?>
                                    <a class="row-action" href="<?= e(url('modules/schedules/edit.php?id=' . (int) $schedule['id'])) ?>"
                                       title="Edit departure" aria-label="Edit departure">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($schedule['trip_id'] !== null): ?>
                                    <a class="row-action" href="<?= e(url('modules/trips/view.php?id=' . (int) $schedule['trip_id'])) ?>"
                                       title="Open trip" aria-label="Open trip">
                                        <i class="bi bi-signpost-split" aria-hidden="true"></i>
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
