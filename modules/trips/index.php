<?php
/**
 * Fleetra — Trips
 * ------------------------------------------------------------------
 * modules/trips/index.php
 *
 * Drivers see their own duty roster; dispatch, managers and administrators
 * see the whole operation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

if (!can('trips.view') && !can('trips.manage') && !can('trips.own')) {
    require_permission('trips.view');
}

$seesEveryTrip = can('trips.view') || can('trips.manage');
$myDriverId    = current_driver_id();

if (!$seesEveryTrip && $myDriverId <= 0) {
    fleetra_fatal('This account is not linked to a driver record, so there are no trips to show.', 403);
}

$search       = get('q');
$dateFrom     = get('date_from');
$dateTo       = get('date_to');
$statusFilter = get('status');
$routeFilter  = get('route_id');
$busFilter    = get('bus_id');
$driverFilter = get('driver_id');

$where  = [];
$params = [];

if (!$seesEveryTrip) {
    $where[]  = 't.driver_id = ?';
    $params[] = $myDriverId;
    $driverFilter = '';
} elseif ($driverFilter !== '' && is_valid_option(schedulable_driver_options(true), $driverFilter)) {
    $where[]  = 't.driver_id = ?';
    $params[] = (int) $driverFilter;
}

if ($search !== '') {
    $where[] = '(r.route_code LIKE ? OR r.route_name LIKE ? OR b.bus_number LIKE ? OR u.name LIKE ? OR r.source LIKE ? OR r.destination LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if (is_valid_date($dateFrom)) {
    $where[]  = 's.schedule_date >= ?';
    $params[] = $dateFrom;
}

if (is_valid_date($dateTo)) {
    $where[]  = 's.schedule_date <= ?';
    $params[] = $dateTo;
}

if (is_valid_option(trip_status_options(), $statusFilter)) {
    $where[]  = 't.trip_status = ?';
    $params[] = $statusFilter;
}

if ($routeFilter !== '' && is_valid_option(schedule_route_options(true), $routeFilter)) {
    $where[]  = 't.route_id = ?';
    $params[] = (int) $routeFilter;
}

if ($busFilter !== '' && is_valid_option(schedulable_bus_options(true), $busFilter)) {
    $where[]  = 't.bus_id = ?';
    $params[] = (int) $busFilter;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$joins = 'FROM trips t
          JOIN schedules s ON s.id = t.schedule_id
          JOIN routes r    ON r.id = t.route_id
          JOIN buses b     ON b.id = t.bus_id
          JOIN drivers d   ON d.id = t.driver_id
          JOIN users u     ON u.id = d.user_id';

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params, 0);
$page  = paginate($total, 15);

$trips = db_all(
    "SELECT t.*,
            s.schedule_date, s.departure_time, s.arrival_time,
            r.route_code, r.route_name, r.source, r.destination,
            b.bus_number, b.registration_number, b.capacity,
            u.name AS driver_name, d.employee_id,
            (SELECT COUNT(*) FROM bookings bk
              WHERE bk.schedule_id = t.schedule_id AND bk.booking_status IN ('pending','confirmed','completed')) AS seats_booked
       $joins
       $whereSql
      ORDER BY (s.schedule_date < CURDATE()) ASC, s.schedule_date ASC, s.departure_time ASC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

/** Scoped by role so a driver's headline numbers are their own duty. */
$scopeWhere  = $seesEveryTrip ? '' : ' AND t.driver_id = ?';
$scopeParams = $seesEveryTrip ? [] : [$myDriverId];

$todayCount = (int) db_value(
    "SELECT COUNT(*) FROM trips t JOIN schedules s ON s.id = t.schedule_id
      WHERE s.schedule_date = CURDATE()$scopeWhere",
    $scopeParams,
    0
);
$boardingCount = (int) db_value(
    "SELECT COUNT(*) FROM trips t JOIN schedules s ON s.id = t.schedule_id
      WHERE t.trip_status = 'boarding'$scopeWhere",
    $scopeParams,
    0
);
$runningCount = (int) db_value(
    "SELECT COUNT(*) FROM trips t WHERE t.trip_status = 'running'$scopeWhere",
    $scopeParams,
    0
);
$delayedCount = (int) db_value(
    "SELECT COUNT(*) FROM trips t JOIN schedules s ON s.id = t.schedule_id
      WHERE t.trip_status = 'delayed' AND s.schedule_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)$scopeWhere",
    $scopeParams,
    0
);
$completedToday = (int) db_value(
    "SELECT COUNT(*) FROM trips t JOIN schedules s ON s.id = t.schedule_id
      WHERE s.schedule_date = CURDATE() AND t.trip_status = 'completed'$scopeWhere",
    $scopeParams,
    0
);

$hasFilters = $search !== '' || is_valid_date($dateFrom) || is_valid_date($dateTo)
    || is_valid_option(trip_status_options(), $statusFilter)
    || ($routeFilter !== '' && is_valid_option(schedule_route_options(true), $routeFilter))
    || ($busFilter !== '' && is_valid_option(schedulable_bus_options(true), $busFilter))
    || ($driverFilter !== '' && is_valid_option(schedulable_driver_options(true), $driverFilter));

$page_title       = 'Trips';
$active_nav       = 'trips';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Operations'],
    ['label' => 'Trips'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $seesEveryTrip ? 'Trips' : 'My duty roster',
    $total . ' trip' . ($total === 1 ? '' : 's') . ($seesEveryTrip ? ' matching the current view' : ' assigned to you')
) ?>

<div class="stat-grid">
    <?= stat_card('Trips today', $todayCount, 'bi-signpost-split', 'primary', 'Scheduled for today') ?>
    <?= stat_card('Boarding now', $boardingCount, 'bi-people', $boardingCount > 0 ? 'info' : 'muted', 'Passengers getting on') ?>
    <?= stat_card('Running', $runningCount, 'bi-broadcast', $runningCount > 0 ? 'success' : 'muted', 'Currently on the road') ?>
    <?= stat_card('Delayed', $delayedCount, 'bi-clock-history', $delayedCount > 0 ? 'warning' : 'muted', 'Reported in the last 7 days') ?>
    <?= stat_card('Completed today', $completedToday, 'bi-check2-all', 'info', 'Trips closed out') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/trips/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search trips</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Route, bus, driver or city...">
            </div>

            <label class="visually-hidden" for="date_from">From date</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>" style="width:auto;">

            <label class="visually-hidden" for="date_to">To date</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>" style="width:auto;">

            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(trip_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <?php if ($seesEveryTrip): ?>
                <label class="visually-hidden" for="route_id">Route</label>
                <select class="form-select" id="route_id" name="route_id" style="width:auto;">
                    <?= option_tags(schedule_route_options(true), $routeFilter, 'All routes') ?>
                </select>

                <label class="visually-hidden" for="driver_id">Driver</label>
                <select class="form-select" id="driver_id" name="driver_id" style="width:auto;">
                    <?= option_tags(schedulable_driver_options(true), $driverFilter, 'All drivers') ?>
                </select>
            <?php endif; ?>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/trips/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($trips === []): ?>
        <?= empty_state(
            $hasFilters ? 'No trips match these filters' : 'No trips yet',
            $hasFilters
                ? 'Try a wider date range, or clear the filters to see every trip.'
                : 'Trips are created automatically when a departure is scheduled.',
            'bi-signpost-split',
            $seesEveryTrip && can('schedules.manage')
                ? '<a class="btn btn-primary" href="' . e(url('modules/schedules/create.php')) . '">Schedule a departure</a>'
                : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Trip</th>
                        <th>Route</th>
                        <th>Bus</th>
                        <?php if ($seesEveryTrip): ?><th>Driver</th><?php endif; ?>
                        <th>Passengers</th>
                        <th>Delay</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trips as $trip): ?>
                        <?php $isToday = (string) $trip['schedule_date'] === date('Y-m-d'); ?>
                        <tr>
                            <td>
                                <span class="cell-strong">TRP-<?= str_pad((string) $trip['id'], 4, '0', STR_PAD_LEFT) ?></span><br>
                                <span class="cell-muted">
                                    <?= e(format_date($trip['schedule_date'], 'd M Y')) ?>
                                    <?= $isToday ? '(today)' : '' ?>
                                    · <?= e(format_time($trip['departure_time'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($trip['route_code']) ?></span><br>
                                <span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                            </td>
                            <td>
                                <?= e($trip['bus_number']) ?><br>
                                <span class="cell-muted"><?= e($trip['registration_number']) ?></span>
                            </td>
                            <?php if ($seesEveryTrip): ?>
                                <td>
                                    <?= e($trip['driver_name']) ?><br>
                                    <span class="cell-muted"><?= e($trip['employee_id']) ?></span>
                                </td>
                            <?php endif; ?>
                            <td class="cell-num">
                                <?= (int) $trip['passenger_count'] ?>
                                <br><span class="cell-muted"><?= (int) $trip['seats_booked'] ?> booked of <?= (int) $trip['capacity'] ?></span>
                            </td>
                            <td>
                                <?php if ((int) $trip['delay_minutes'] > 0): ?>
                                    <span class="badge-status badge-warning">+<?= (int) $trip['delay_minutes'] ?> min</span>
                                <?php else: ?>
                                    <span class="cell-muted">On time</span>
                                <?php endif; ?>
                            </td>
                            <td><?= status_badge($trip['trip_status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/trips/view.php?id=' . (int) $trip['id'])) ?>"
                                   title="Open trip sheet" aria-label="Open trip TRP-<?= (int) $trip['id'] ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <a class="row-action" href="<?= e(url('modules/schedules/view.php?id=' . (int) $trip['schedule_id'])) ?>"
                                   title="Open departure" aria-label="Open departure">
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                </a>
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
