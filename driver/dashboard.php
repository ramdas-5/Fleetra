<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * driver/dashboard.php — Driver duty view
 *
 * Deliberately simple: what am I driving, what is my schedule today,
 * who is on board and what does the route look like.
 *
 * Trip actions (start, end, delay, breakdown, emergency alert) live in the
 * trips and incidents modules and are linked from the duty summary below.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

require_role('driver');

$user   = current_user();
$driver = db_one(
    'SELECT d.*, b.bus_number, b.registration_number, b.manufacturer, b.model,
            b.capacity, b.bus_type, b.fuel_type, b.status AS bus_status, b.current_mileage
       FROM drivers d
       LEFT JOIN buses b ON b.id = d.assigned_bus_id
      WHERE d.user_id = ?
      LIMIT 1',
    [user_id()]
);

$page_title       = 'Dashboard';
$active_nav       = 'dashboard';
$page_breadcrumbs = [['label' => 'Dashboard']];

if ($driver === null) {
    require __DIR__ . '/../includes/header.php';
    ?>
    <?= render_page_header('My duty', 'Driver account not linked to a driver record') ?>
    <div class="card-fl">
        <?= empty_state(
            'No driver profile linked to this account',
            'Your user account is not connected to a driver record yet. A Fleetra administrator needs to create the driver profile and link it to your login.',
            'bi-person-x'
        ) ?>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$driverId = (int) $driver['id'];

$todayTrips = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes, t.remarks,
            s.schedule_date, s.departure_time, s.arrival_time,
            b.bus_number, b.capacity,
            r.id AS route_id, r.route_code, r.route_name, r.source, r.destination, r.distance
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN buses b     ON b.id = t.bus_id
       JOIN routes r    ON r.id = t.route_id
      WHERE t.driver_id = ? AND s.schedule_date = CURDATE()
      ORDER BY s.departure_time',
    [$driverId]
);

$history = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes,
            s.schedule_date, s.departure_time, s.arrival_time,
            r.route_code, r.source, r.destination, b.bus_number
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN routes r    ON r.id = t.route_id
       JOIN buses b     ON b.id = t.bus_id
      WHERE t.driver_id = ? AND s.schedule_date < CURDATE()
      ORDER BY s.schedule_date DESC, s.departure_time DESC
      LIMIT 5',
    [$driverId]
);

// Stops for the route the driver is currently working on.
$currentTrip  = $todayTrips[0] ?? null;
$routeStops   = [];
if ($currentTrip !== null) {
    $routeStops = db_all(
        'SELECT stop_name, stop_order, arrival_offset, latitude, longitude
           FROM stops WHERE route_id = ? ORDER BY stop_order',
        [(int) $currentTrip['route_id']]
    );
}

$monthTrips = (int) db_value(
    'SELECT COUNT(*) FROM trips t JOIN schedules s ON s.id = t.schedule_id
      WHERE t.driver_id = ? AND s.schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
    [$driverId],
    0
);

$licenseExpiry  = (string) $driver['license_expiry'];
$licenseDaysLeft = (int) floor((strtotime($licenseExpiry) - time()) / 86400);

$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim((string) $user['name']))[0];

require __DIR__ . '/../includes/header.php';
?>

<?= render_page_header(
    $greeting . ', ' . $firstName,
    'Your duty sheet for ' . date('l, d M Y'),
    '<span class="chip">' . e($driver['employee_id']) . '</span>'
) ?>

<?php if ($licenseDaysLeft < 60): ?>
    <div class="alert alert-<?= $licenseDaysLeft < 0 ? 'danger' : 'warning' ?> app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <?= $licenseDaysLeft < 0
                ? 'Your driving licence expired on ' . e(format_date($licenseExpiry)) . '. Please contact your transport manager before operating a bus.'
                : 'Your driving licence expires on ' . e(format_date($licenseExpiry)) . ' (' . $licenseDaysLeft . ' days). Please arrange a renewal.' ?>
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card("Today's trips", count($todayTrips), 'bi-signpost-split', 'primary', 'Assigned services today') ?>
    <?= stat_card('Trips (30 days)', $monthTrips, 'bi-clock-history', 'info', 'Completed and scheduled') ?>
    <?= stat_card('Experience', (int) $driver['experience_years'] . ' yrs', 'bi-award', 'success', 'Driving experience') ?>
    <?= stat_card(
        'Licence',
        $licenseDaysLeft < 0 ? 'Expired' : $licenseDaysLeft . ' days',
        'bi-card-checklist',
        $licenseDaysLeft < 60 ? 'warning' : 'muted',
        'Valid until ' . e(format_date($licenseExpiry))
    ) ?>
</div>

<div class="grid-narrow-side">
    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Assigned bus</h2>
                <p class="card-fl__subtitle">Your current vehicle assignment</p>
            </div>
            <?= $driver['bus_number'] !== null ? status_badge($driver['bus_status']) : '' ?>
        </div>

        <?php if ($driver['bus_number'] === null): ?>
            <?= empty_state('No bus assigned', 'Your transport manager has not assigned a bus to you yet.', 'bi-bus-front') ?>
        <?php else: ?>
            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Bus number</span>
                        <span class="detail-item__value"><?= e($driver['bus_number']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Registration</span>
                        <span class="detail-item__value"><?= e($driver['registration_number']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Vehicle</span>
                        <span class="detail-item__value"><?= e(join_parts([$driver['manufacturer'], $driver['model']], ' ')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Capacity</span>
                        <span class="detail-item__value"><?= (int) $driver['capacity'] ?> seats</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Type</span>
                        <span class="detail-item__value"><?= e(labelize((string) $driver['bus_type'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Odometer</span>
                        <span class="detail-item__value"><?= e(number_format((float) $driver['current_mileage'], 0)) ?> km</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Today's schedule</h2>
                <p class="card-fl__subtitle">Departures assigned to you today</p>
            </div>
            <span class="chip"><?= count($todayTrips) ?> trip<?= count($todayTrips) === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($todayTrips === []): ?>
            <?= empty_state('No trips assigned today', 'Enjoy the rest day — your next assignment will appear here.', 'bi-calendar-check') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr>
                            <th>Departure</th>
                            <th>Route</th>
                            <th>Passengers</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayTrips as $trip): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e(format_time($trip['departure_time'])) ?></span><br>
                                    <span class="cell-muted">arr. <?= e(format_time($trip['arrival_time'])) ?></span>
                                </td>
                                <td>
                                    <span class="cell-strong"><?= e($trip['route_code']) ?></span> <?= e($trip['route_name']) ?><br>
                                    <span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                                </td>
                                <td class="cell-num"><?= (int) $trip['passenger_count'] ?> / <?= (int) $trip['capacity'] ?></td>
                                <td>
                                    <?= status_badge($trip['trip_status']) ?>
                                    <?php if ((int) $trip['delay_minutes'] > 0): ?>
                                        <br><span class="cell-muted">+<?= (int) $trip['delay_minutes'] ?> min</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Stops on your route</h2>
                <p class="card-fl__subtitle">
                    <?= $currentTrip !== null
                        ? e($currentTrip['route_code'] . ' · ' . $currentTrip['route_name'])
                        : 'No route assigned today' ?>
                </p>
            </div>
        </div>

        <?php if ($routeStops === []): ?>
            <?= empty_state('No stops configured', 'Stops for this route have not been added yet.', 'bi-geo-alt') ?>
        <?php else: ?>
            <div class="card-fl__body">
                <ul class="timeline">
                    <?php foreach ($routeStops as $index => $stop): ?>
                        <li class="timeline__item<?= $index === 0 ? ' timeline__item--done' : '' ?>">
                            <p class="timeline__title"><?= e($stop['stop_name']) ?></p>
                            <p class="timeline__meta">
                                Stop <?= (int) $stop['stop_order'] ?> &middot;
                                <?= $index === 0 ? 'Departure point' : '+' . format_duration((int) $stop['arrival_offset']) . ' from origin' ?>
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
                <h2 class="card-fl__title">Recent trip history</h2>
                <p class="card-fl__subtitle">Your last five completed services</p>
            </div>
        </div>

        <?php if ($history === []): ?>
            <?= empty_state('No past trips yet', 'Your completed services will be listed here.', 'bi-clock-history') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr><th>Date</th><th>Route</th><th>Passengers</th><th>Delay</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $trip): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e(format_date($trip['schedule_date'], 'd M Y')) ?></span><br>
                                    <span class="cell-muted"><?= e(format_time($trip['departure_time'])) ?></span>
                                </td>
                                <td>
                                    <span class="cell-strong"><?= e($trip['route_code']) ?></span><br>
                                    <span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                                </td>
                                <td class="cell-num"><?= (int) $trip['passenger_count'] ?></td>
                                <td class="cell-num">
                                    <?= (int) $trip['delay_minutes'] > 0
                                        ? '+' . (int) $trip['delay_minutes'] . ' min'
                                        : 'On time' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
