<?php
/**
 * Fleetra — Routes / Route details
 * ------------------------------------------------------------------
 * modules/routes/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('routes.view');

$routeId = get_int('id');

if ($routeId <= 0) {
    abort_not_found('No route was specified.');
}

$route = db_one(
    'SELECT r.*,
            (SELECT COUNT(*) FROM schedules s WHERE s.route_id = r.id) AS schedule_count,
            (SELECT COUNT(*) FROM stops st WHERE st.route_id = r.id) AS stop_count
       FROM routes r
      WHERE r.id = ?
      LIMIT 1',
    [$routeId]
);

if ($route === null) {
    abort_not_found('That route does not exist.');
}

$canManage = can('routes.manage');

$stops = route_stops($routeId);

$performance = db_one(
    'SELECT COUNT(*) AS trip_count,
            COALESCE(SUM(t.passenger_count), 0) AS passengers,
            COALESCE(AVG(NULLIF(t.delay_minutes, 0)), 0) AS avg_delay
       FROM trips t
      WHERE t.route_id = ?',
    [$routeId]
) ?? ['trip_count' => 0, 'passengers' => 0, 'avg_delay' => 0];

$revenue = (float) db_value(
    'SELECT COALESCE(SUM(b.fare), 0)
       FROM bookings b
       JOIN schedules s ON s.id = b.schedule_id
      WHERE s.route_id = ? AND b.booking_status IN ("confirmed", "completed")',
    [$routeId],
    0
);

$upcomingSchedules = db_all(
    'SELECT s.id, s.schedule_date, s.departure_time, s.arrival_time, s.status,
            b.bus_number, u.name AS driver_name
       FROM schedules s
       JOIN buses b   ON b.id = s.bus_id
       JOIN drivers d ON d.id = s.driver_id
       JOIN users u   ON u.id = d.user_id
      WHERE s.route_id = ? AND s.schedule_date >= CURDATE()
      ORDER BY s.schedule_date, s.departure_time
      LIMIT 5',
    [$routeId]
);

$changeHistory = db_all(
    'SELECT al.action, al.description, al.created_at, u.name AS user_name
       FROM activity_logs al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.module = ? AND al.record_id = ?
      ORDER BY al.created_at DESC
      LIMIT 5',
    ['routes', $routeId]
);

$deleteForm = $canManage
    ? '<form method="post" action="' . e(url('modules/routes/delete.php?id=' . $routeId)) . '" class="d-inline"
              data-confirm="Routes with schedules or bookings are retired instead of deleted."
              data-confirm-title="Delete route ' . e($route['route_code']) . '?"
              data-confirm-button="Delete route">
           ' . csrf_field() . '
           <button type="submit" class="btn btn-outline-secondary">
               <i class="bi bi-trash" aria-hidden="true"></i> Delete
           </button>
       </form>'
    : '';

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/routes/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Routes
            </a>';

if ($canManage) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('modules/routes/stops.php?route_id=' . $routeId)) . '">
                     <i class="bi bi-geo-alt" aria-hidden="true"></i> Manage stops
                 </a>
                 <a class="btn btn-primary" href="' . e(url('modules/routes/edit.php?id=' . $routeId)) . '">
                     <i class="bi bi-pencil" aria-hidden="true"></i> Edit route
                 </a>' . $deleteForm;
}

$page_title       = $route['route_code'] . ' · ' . $route['route_name'];
$active_nav       = 'routes';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/routes/index.php')],
    ['label' => 'Routes', 'url' => url('modules/routes/index.php')],
    ['label' => (string) $route['route_code']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $route['route_code'] . ' · ' . $route['route_name'],
    $route['source'] . ' → ' . $route['destination'],
    $actions
) ?>

<div class="stat-grid">
    <?= stat_card('Stops', (int) $route['stop_count'], 'bi-geo-alt', 'primary', 'Boarding points on this route') ?>
    <?= stat_card('Schedules', (int) $route['schedule_count'], 'bi-calendar3', 'info', 'Departures created') ?>
    <?= stat_card('Trips operated', (int) $performance['trip_count'], 'bi-signpost-split', 'primary', 'All time') ?>
    <?= stat_card('Passengers carried', (int) $performance['passengers'], 'bi-people', 'info', 'Across all trips') ?>
    <?= stat_card('Revenue', money($revenue), 'bi-cash-stack', 'success', 'Confirmed and completed bookings') ?>
    <?= stat_card('Average delay', format_duration((float) $performance['avg_delay']), 'bi-clock-history', 'warning', 'On delayed trips only') ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Stops in travel order</h2>
                    <p class="card-fl__subtitle">Offsets are minutes after departure from the first stop</p>
                </div>
                <?php if ($canManage): ?>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= e(url('modules/routes/stops.php?route_id=' . $routeId)) ?>">
                        <i class="bi bi-gear" aria-hidden="true"></i> Manage
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($stops === []): ?>
                <?= empty_state(
                    'No stops defined yet',
                    'Add boarding points so passengers know where they can join this service.',
                    'bi-geo-alt',
                    $canManage
                        ? '<a class="btn btn-primary btn-sm" href="' . e(url('modules/routes/stops.php?route_id=' . $routeId)) . '">Add stops</a>'
                        : ''
                ) ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="timeline">
                        <?php foreach ($stops as $index => $stop): ?>
                            <li class="timeline__item<?= $index === 0 ? ' timeline__item--done' : '' ?>">
                                <p class="timeline__title"><?= e($stop['stop_name']) ?></p>
                                <p class="timeline__meta">
                                    Stop <?= (int) $stop['stop_order'] ?> &middot;
                                    <?= (int) $stop['arrival_offset'] === 0
                                        ? 'Departure point'
                                        : '+' . e(format_duration((int) $stop['arrival_offset'])) . ' from origin' ?>
                                    <?php if ($stop['latitude'] !== null): ?>
                                        &middot; <?= e(number_format((float) $stop['latitude'], 4)) ?>,
                                        <?= e(number_format((float) $stop['longitude'], 4)) ?>
                                    <?php endif; ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Upcoming departures</h2>
                    <p class="card-fl__subtitle">Scheduled services for today and later</p>
                </div>
            </div>

            <?php if ($upcomingSchedules === []): ?>
                <?= empty_state('No upcoming departures', 'Create schedules for this route to start operating it.', 'bi-calendar3') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Departure</th>
                                <th>Bus</th>
                                <th>Driver</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingSchedules as $schedule): ?>
                                <tr>
                                    <td class="cell-strong"><?= e(format_date($schedule['schedule_date'], 'd M Y')) ?></td>
                                    <td>
                                        <span class="cell-strong"><?= e(format_time($schedule['departure_time'])) ?></span><br>
                                        <span class="cell-muted">arr. <?= e(format_time($schedule['arrival_time'])) ?></span>
                                    </td>
                                    <td><?= e($schedule['bus_number']) ?></td>
                                    <td><?= e($schedule['driver_name']) ?></td>
                                    <td><?= status_badge($schedule['status']) ?></td>
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
                    <h2 class="card-fl__title">Service details</h2>
                    <p class="card-fl__subtitle">Route parameters</p>
                </div>
                <?= status_badge($route['status']) ?>
            </div>
            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Route code</span>
                        <span class="fact-list__value"><?= e($route['route_code']) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Starting point</span>
                        <span class="fact-list__value"><?= e($route['source']) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Destination</span>
                        <span class="fact-list__value"><?= e($route['destination']) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Distance</span>
                        <span class="fact-list__value"><?= e(number_format((float) $route['distance'], 1)) ?> km</span>
                    </li>
                    <li>
                        <span class="fact-list__label">Estimated duration</span>
                        <span class="fact-list__value"><?= e(format_duration((int) $route['estimated_duration'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Base fare</span>
                        <span class="fact-list__value"><?= e(money($route['base_fare'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Created</span>
                        <span class="fact-list__value"><?= e(format_date((string) $route['created_at'])) ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Change history</h2>
                    <p class="card-fl__subtitle">Audit trail for this route</p>
                </div>
            </div>

            <?php if ($changeHistory === []): ?>
                <?= empty_state('No recorded changes', 'Updates to this route will be logged here.', 'bi-clock-history') ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="timeline">
                        <?php foreach ($changeHistory as $entry): ?>
                            <li class="timeline__item timeline__item--done">
                                <p class="timeline__title"><?= e($entry['action']) ?></p>
                                <p class="timeline__meta">
                                    <?= e($entry['user_name'] ?? 'System') ?> &middot; <?= e(time_ago($entry['created_at'])) ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
