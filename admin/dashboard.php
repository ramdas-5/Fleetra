<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * admin/dashboard.php — Administrator operations overview
 *
 * Every figure on this page is read from the database; nothing is
 * hard-coded. Queries are grouped/aggregated so the dashboard stays
 * fast as the fleet grows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

require_role('admin');

$user = current_user();

/* ------------------------------------------------------------------
 | Metric cards
 ------------------------------------------------------------------ */

$fleetByStatus  = db_all('SELECT status, COUNT(*) AS total FROM buses GROUP BY status');
$fleetStatus    = array_column($fleetByStatus, 'total', 'status');

$driverByStatus = db_all('SELECT employment_status, COUNT(*) AS total FROM drivers GROUP BY employment_status');
$driverStatus   = array_column($driverByStatus, 'total', 'employment_status');

$todayTrips = db_all(
    'SELECT t.trip_status, COUNT(*) AS total
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
      WHERE s.schedule_date = CURDATE()
      GROUP BY t.trip_status'
);
$todayTripStatus = array_column($todayTrips, 'total', 'trip_status');

$totalBuses      = array_sum(array_map('intval', $fleetStatus));
$activeBuses     = (int) ($fleetStatus['active'] ?? 0);
$maintenanceBuses = (int) ($fleetStatus['maintenance'] ?? 0);
$inactiveBuses   = (int) ($fleetStatus['inactive'] ?? 0);

$activeDrivers   = (int) ($driverStatus['active'] ?? 0);
$totalDrivers    = array_sum(array_map('intval', $driverStatus));

$todayTripCount  = array_sum(array_map('intval', $todayTripStatus));
$activeTripCount = (int) ($todayTripStatus['running'] ?? 0)
    + (int) ($todayTripStatus['boarding'] ?? 0)
    + (int) ($todayTripStatus['delayed'] ?? 0);

$totalPassengers = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'passenger'");

$bookingsToday   = (int) db_value('SELECT COUNT(*) FROM bookings WHERE booking_date = CURDATE()');
$bookingsTotal   = (int) db_value('SELECT COUNT(*) FROM bookings');

$revenueToday    = (float) db_value(
    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid' AND DATE(payment_date) = CURDATE()",
    [],
    0
);

$openIncidents   = (int) db_value("SELECT COUNT(*) FROM incidents WHERE status IN ('open', 'investigating')");

$maintenanceDue  = (int) db_value(
    'SELECT COUNT(*) FROM maintenance
      WHERE status <> "cancelled"
        AND next_service_date IS NOT NULL
        AND next_service_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)',
    [],
    0
);

/* ------------------------------------------------------------------
 | Charts — trip activity and bookings over the last 7 days
 ------------------------------------------------------------------ */

$tripSeries = db_all(
    'SELECT s.schedule_date AS d, COUNT(*) AS total
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
      WHERE s.schedule_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
      GROUP BY s.schedule_date'
);
$tripsByDate = array_column($tripSeries, 'total', 'd');

$bookingSeries = db_all(
    'SELECT booking_date AS d, COUNT(*) AS total
       FROM bookings
      WHERE booking_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
      GROUP BY booking_date'
);
$bookingsByDate = array_column($bookingSeries, 'total', 'd');

$chartLabels     = [];
$chartTripData   = [];
$chartBookingData = [];

for ($offset = 6; $offset >= 0; $offset--) {
    $day             = date('Y-m-d', strtotime('-' . $offset . ' day'));
    $chartLabels[]   = date('D', strtotime($day));
    $chartTripData[]   = (int) ($tripsByDate[$day] ?? 0);
    $chartBookingData[] = (int) ($bookingsByDate[$day] ?? 0);
}

$chartFleetLabels = ['Active', 'Maintenance', 'Inactive'];
$chartFleetData   = [$activeBuses, $maintenanceBuses, $inactiveBuses];

/* ------------------------------------------------------------------
 | Route performance (last 30 days)
 ------------------------------------------------------------------ */

$routePerformance = db_all(
    'SELECT r.id, r.route_code, r.route_name, r.source, r.destination,
            COUNT(t.id) AS trip_count,
            COALESCE(SUM(t.passenger_count), 0) AS passenger_count,
            COALESCE(AVG(t.delay_minutes), 0) AS avg_delay,
            COALESCE(SUM(bk.revenue), 0) AS revenue
       FROM routes r
       LEFT JOIN schedules s
              ON s.route_id = r.id
             AND s.schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
       LEFT JOIN trips t ON t.schedule_id = s.id
       LEFT JOIN (
            SELECT schedule_id, SUM(fare) AS revenue
              FROM bookings
             WHERE booking_status IN ("confirmed", "completed")
             GROUP BY schedule_id
       ) bk ON bk.schedule_id = s.id
      GROUP BY r.id, r.route_code, r.route_name, r.source, r.destination
      ORDER BY passenger_count DESC
      LIMIT 5'
);

$peakPassengers = 0;
foreach ($routePerformance as $route) {
    $peakPassengers = max($peakPassengers, (int) $route['passenger_count']);
}

/* ------------------------------------------------------------------
 | Operational lists
 ------------------------------------------------------------------ */

$todayTripsList = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes,
            s.departure_time, s.arrival_time,
            b.bus_number, b.capacity,
            u.name AS driver_name,
            r.route_code, r.route_name, r.source, r.destination
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN buses b     ON b.id = t.bus_id
       JOIN drivers d   ON d.id = t.driver_id
       JOIN users u     ON u.id = d.user_id
       JOIN routes r    ON r.id = t.route_id
      WHERE s.schedule_date = CURDATE()
      ORDER BY s.departure_time
      LIMIT 6'
);

$maintenanceList = db_all(
    'SELECT m.id, m.maintenance_type, m.next_service_date, m.status, m.cost,
            b.bus_number, b.registration_number
       FROM maintenance m
       JOIN buses b ON b.id = m.bus_id
      WHERE m.status <> "cancelled"
        AND m.next_service_date IS NOT NULL
        AND m.next_service_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
      ORDER BY m.next_service_date
      LIMIT 5'
);

$incidentList = db_all(
    'SELECT i.id, i.incident_type, i.severity, i.status, i.description, i.reported_at,
            b.bus_number, u.name AS driver_name
       FROM incidents i
       LEFT JOIN buses b   ON b.id = i.bus_id
       LEFT JOIN drivers d ON d.id = i.driver_id
       LEFT JOIN users u   ON u.id = d.user_id
      WHERE i.status NOT IN ("resolved", "closed")
      ORDER BY FIELD(i.severity, "critical", "high", "medium", "low"), i.reported_at DESC
      LIMIT 4'
);

$recentBookings = db_all(
    'SELECT b.booking_number, b.seat_number, b.fare, b.booking_status, b.payment_status, b.created_at,
            u.name AS passenger_name,
            r.route_code, r.route_name,
            s.schedule_date, s.departure_time
       FROM bookings b
       JOIN users u     ON u.id = b.user_id
       JOIN schedules s ON s.id = b.schedule_id
       JOIN routes r    ON r.id = s.route_id
      ORDER BY b.created_at DESC
      LIMIT 5'
);

/* ------------------------------------------------------------------
 | View
 ------------------------------------------------------------------ */

$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim((string) $user['name']))[0];

$page_title       = 'Dashboard';
$active_nav       = 'dashboard';
$page_breadcrumbs = [['label' => 'Dashboard']];
$extra_js         = [asset('vendor/chartjs/chart.umd.min.js')];

require __DIR__ . '/../includes/header.php';
?>

<?= render_page_header(
    $greeting . ', ' . $firstName,
    'Fleetra operations overview for ' . date('l, d M Y') . ' · ' . $activeBuses . ' of ' . $totalBuses . ' buses in service',
    '<span class="chip">Last refreshed <strong>' . e(date('h:i A')) . '</strong></span>'
) ?>

<div class="stat-grid">
    <?= stat_card(
        'Total buses',
        $totalBuses,
        'bi-bus-front',
        'primary',
        '<strong>' . $activeBuses . '</strong> active · <strong>' . $maintenanceBuses . '</strong> in workshop'
    ) ?>

    <?= stat_card(
        'Active buses',
        $activeBuses,
        'bi-check2-circle',
        'success',
        '<strong>' . $maintenanceBuses . '</strong> in workshop · <strong>' . $inactiveBuses . '</strong> out of service'
    ) ?>

    <?= stat_card(
        'Drivers',
        $activeDrivers,
        'bi-person-badge',
        'info',
        'of <strong>' . $totalDrivers . '</strong> registered drivers'
    ) ?>

    <?= stat_card(
        "Today's trips",
        $todayTripCount,
        'bi-signpost-split',
        'primary',
        '<strong>' . $activeTripCount . '</strong> currently on the road'
    ) ?>

    <?= stat_card(
        'Passengers',
        $totalPassengers,
        'bi-people',
        'muted',
        'Registered passenger accounts'
    ) ?>

    <?= stat_card(
        "Today's bookings",
        $bookingsToday,
        'bi-journal-check',
        'success',
        e(money($revenueToday)) . ' collected · <strong>' . $bookingsTotal . '</strong> all time'
    ) ?>
</div>

<div class="grid-main-side">
    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Trip activity</h2>
                <p class="card-fl__subtitle">Trips operated and bookings taken over the last 7 days</p>
            </div>
            <span class="chip">Revenue today <strong><?= e(money($revenueToday)) ?></strong></span>
        </div>
        <div class="card-fl__body">
            <div class="chart-box chart-box--md">
                <canvas id="tripActivityChart" aria-label="Trips and bookings over the last seven days" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Fleet status</h2>
                <p class="card-fl__subtitle">Availability across the fleet today</p>
            </div>
        </div>
        <div class="card-fl__body">
            <div class="chart-box chart-box--sm">
                <canvas id="fleetStatusChart" aria-label="Fleet availability by status" role="img"></canvas>
            </div>

            <ul class="meta-list mt-3">
                <li>
                    <span class="meta-list__label">In service</span>
                    <span class="meta-list__value"><?= (int) $activeBuses ?> buses</span>
                </li>
                <li>
                    <span class="meta-list__label">In workshop</span>
                    <span class="meta-list__value"><?= (int) $maintenanceBuses ?> buses</span>
                </li>
                <li>
                    <span class="meta-list__label">Out of service</span>
                    <span class="meta-list__value"><?= (int) $inactiveBuses ?> buses</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="card-fl">
    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title">Today's trips</h2>
            <p class="card-fl__subtitle">Scheduled departures for <?= e(date('d M Y')) ?></p>
        </div>
        <span class="chip"><?= $todayTripCount ?> scheduled</span>
    </div>

    <?php if ($todayTripsList === []): ?>
        <?= empty_state(
            'No trips scheduled today',
            'Once schedules are created for today, every departure will appear here with its live status.',
            'bi-calendar-x'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Trip</th>
                        <th>Route</th>
                        <th>Bus</th>
                        <th>Driver</th>
                        <th>Departure</th>
                        <th>Passengers</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todayTripsList as $trip): ?>
                        <tr>
                            <td class="cell-strong">#<?= (int) $trip['id'] ?></td>
                            <td>
                                <span class="cell-strong"><?= e($trip['route_code']) ?></span><br>
                                <span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($trip['bus_number']) ?></span><br>
                                <span class="cell-muted"><?= (int) $trip['capacity'] ?> seats</span>
                            </td>
                            <td><?= e($trip['driver_name']) ?></td>
                            <td>
                                <span class="cell-strong"><?= e(format_time($trip['departure_time'])) ?></span><br>
                                <span class="cell-muted">arr. <?= e(format_time($trip['arrival_time'])) ?></span>
                            </td>
                            <td class="cell-num">
                                <?= (int) $trip['passenger_count'] ?>
                                <?php if ((int) $trip['delay_minutes'] > 0): ?>
                                    <br><span class="cell-muted">+<?= (int) $trip['delay_minutes'] ?> min</span>
                                <?php endif; ?>
                            </td>
                            <td><?= status_badge($trip['trip_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Route performance</h2>
                <p class="card-fl__subtitle">Passengers carried in the last 30 days</p>
            </div>
        </div>
        <div class="card-fl__body">
            <?php if ($routePerformance === []): ?>
                <?= empty_state('No route data yet', 'Route performance appears once trips have been operated.', 'bi-bar-chart-line') ?>
            <?php else: ?>
                <ul class="route-list">
                    <?php foreach ($routePerformance as $route): ?>
                        <?php
                        $passengers = (int) $route['passenger_count'];
                        $share      = $peakPassengers > 0 ? (int) round(($passengers / $peakPassengers) * 100) : 0;
                        ?>
                        <li class="route-list__item">
                            <div class="route-list__head">
                                <span>
                                    <span class="route-list__code"><?= e($route['route_code']) ?></span>
                                    <span class="route-list__name"><?= e($route['route_name']) ?></span>
                                </span>
                                <span class="route-list__value"><?= $passengers ?> passengers</span>
                            </div>
                            <div class="progress-fl">
                                <div class="progress-fl__bar" style="width: <?= $share ?>%"></div>
                            </div>
                            <div class="route-list__meta">
                                <span><?= (int) $route['trip_count'] ?> trips</span>
                                <span><?= e(money($route['revenue'])) ?> revenue</span>
                                <span>Avg delay <?= format_duration((float) $route['avg_delay']) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Maintenance due</h2>
                <p class="card-fl__subtitle">Services falling due within 15 days</p>
            </div>
            <span class="chip"><?= $maintenanceDue ?> due</span>
        </div>

        <?php if ($maintenanceList === []): ?>
            <?= empty_state('Nothing due for service', 'No bus has a service falling due in the next 15 days.', 'bi-tools') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr>
                            <th>Bus</th>
                            <th>Service</th>
                            <th>Due</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maintenanceList as $record): ?>
                            <?php $overdue = $record['next_service_date'] < date('Y-m-d'); ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e($record['bus_number']) ?></span><br>
                                    <span class="cell-muted"><?= e($record['registration_number']) ?></span>
                                </td>
                                <td><?= e(labelize($record['maintenance_type'])) ?></td>
                                <td>
                                    <?= e(format_date($record['next_service_date'])) ?>
                                    <?php if ($overdue): ?>
                                        <br><span class="cell-muted" style="color:var(--fl-danger);">Overdue</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= status_badge($record['status']) ?></td>
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
                <h2 class="card-fl__title">Open alerts</h2>
                <p class="card-fl__subtitle">Incidents that still need attention</p>
            </div>
            <span class="chip"><?= $openIncidents ?> open</span>
        </div>

        <?php if ($incidentList === []): ?>
            <?= empty_state('No open incidents', 'Every reported incident has been resolved. Nice and quiet.', 'bi-shield-check') ?>
        <?php else: ?>
            <div class="card-fl__body stack-sm">
                <?php foreach ($incidentList as $incident): ?>
                    <article class="alert-item">
                        <div class="alert-item__head">
                            <span class="alert-item__title">
                                <?= e(labelize($incident['incident_type'])) ?>
                                <?php if ($incident['bus_number'] !== null): ?>
                                    &middot; <?= e($incident['bus_number']) ?>
                                <?php endif; ?>
                            </span>
                            <span class="d-flex gap-2 align-items-center">
                                <?= status_badge($incident['severity']) ?>
                                <?= status_badge($incident['status']) ?>
                            </span>
                        </div>
                        <p class="alert-item__text"><?= e(truncate($incident['description'], 150)) ?></p>
                        <p class="alert-item__meta">
                            <?= e($incident['driver_name'] !== null ? $incident['driver_name'] : 'Unassigned driver') ?>
                            &middot; reported <?= e(time_ago($incident['reported_at'])) ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Recent bookings</h2>
                <p class="card-fl__subtitle">Latest reservations across all routes</p>
            </div>
        </div>

        <?php if ($recentBookings === []): ?>
            <?= empty_state('No bookings yet', 'Passenger reservations will appear here as soon as they are made.', 'bi-journal-check') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Passenger</th>
                            <th>Trip</th>
                            <th>Seat</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBookings as $booking): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e($booking['booking_number']) ?></span><br>
                                    <span class="cell-muted"><?= e(money($booking['fare'])) ?></span>
                                </td>
                                <td><?= e($booking['passenger_name']) ?></td>
                                <td>
                                    <span class="cell-strong"><?= e($booking['route_code']) ?></span><br>
                                    <span class="cell-muted">
                                        <?= e(format_date($booking['schedule_date'], 'd M')) ?>
                                        · <?= e(format_time($booking['departure_time'])) ?>
                                    </span>
                                </td>
                                <td><?= e($booking['seat_number']) ?></td>
                                <td>
                                    <?= status_badge($booking['booking_status']) ?><br>
                                    <span class="cell-muted"><?= e(labelize($booking['payment_status'])) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
/* Dashboard charts. Chart.js is loaded in the footer, so the charts are
   created once the document (and therefore Chart) is ready. */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        return;
    }

    var labels  = <?= e_js($chartLabels) ?>;
    var trips   = <?= e_js($chartTripData) ?>;
    var bookings = <?= e_js($chartBookingData) ?>;

    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.color = '#5F736B';
    Chart.defaults.font.size = 12;

    var activityCanvas = document.getElementById('tripActivityChart');
    if (activityCanvas) {
        new Chart(activityCanvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Trips operated',
                        data: trips,
                        backgroundColor: '#086347',
                        borderRadius: 5,
                        maxBarThickness: 26
                    },
                    {
                        label: 'Bookings',
                        data: bookings,
                        backgroundColor: '#C8E2D7',
                        borderRadius: 5,
                        maxBarThickness: 26
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } },
                    tooltip: { backgroundColor: '#0A3F2C', padding: 10, displayColors: false }
                },
                scales: {
                    x: { grid: { display: false }, border: { color: '#DCEBE3' } },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: '#EAF3EE' },
                        border: { display: false }
                    }
                }
            }
        });
    }

    var fleetCanvas = document.getElementById('fleetStatusChart');
    if (fleetCanvas) {
        new Chart(fleetCanvas, {
            type: 'doughnut',
            data: {
                labels: <?= e_js($chartFleetLabels) ?>,
                datasets: [{
                    data: <?= e_js($chartFleetData) ?>,
                    backgroundColor: ['#12885E', '#B4791A', '#93A69D'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } },
                    tooltip: { backgroundColor: '#0A3F2C', padding: 10 }
                }
            }
        });
    }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
