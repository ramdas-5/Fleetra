<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * manager/dashboard.php — Transport Manager overview
 *
 * Managers run the daily operation: fleet availability, driver roster,
 * today's services and maintenance that is falling due. System
 * administration (users, logs, settings) stays with administrators.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

require_role('manager');

$user = current_user();

$fleetStatus  = array_column(db_all('SELECT status, COUNT(*) AS total FROM buses GROUP BY status'), 'total', 'status');
$driverStatus = array_column(db_all('SELECT employment_status, COUNT(*) AS total FROM drivers GROUP BY employment_status'), 'total', 'employment_status');
$tripStatus   = array_column(
    db_all(
        'SELECT t.trip_status, COUNT(*) AS total
           FROM trips t JOIN schedules s ON s.id = t.schedule_id
          WHERE s.schedule_date = CURDATE()
          GROUP BY t.trip_status'
    ),
    'total',
    'trip_status'
);

$totalBuses   = array_sum(array_map('intval', $fleetStatus));
$activeBuses  = (int) ($fleetStatus['active'] ?? 0);
$activeDrivers = (int) ($driverStatus['active'] ?? 0);
$todayTrips   = array_sum(array_map('intval', $tripStatus));
$onRoad       = (int) ($tripStatus['running'] ?? 0) + (int) ($tripStatus['boarding'] ?? 0) + (int) ($tripStatus['delayed'] ?? 0);

$maintenanceDue = (int) db_value(
    'SELECT COUNT(*) FROM maintenance
      WHERE status <> "cancelled" AND next_service_date IS NOT NULL
        AND next_service_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)',
    [],
    0
);

$revenue30Days = (float) db_value(
    "SELECT COALESCE(SUM(amount), 0) FROM payments
      WHERE status = 'paid' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
    [],
    0
);

$todayTripsList = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes,
            s.departure_time, s.arrival_time, b.bus_number, b.capacity,
            u.name AS driver_name, r.route_code, r.source, r.destination
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN buses b     ON b.id = t.bus_id
       JOIN drivers d   ON d.id = t.driver_id
       JOIN users u     ON u.id = d.user_id
       JOIN routes r    ON r.id = t.route_id
      WHERE s.schedule_date = CURDATE()
      ORDER BY s.departure_time
      LIMIT 8'
);

$maintenanceList = db_all(
    'SELECT m.id, m.maintenance_type, m.next_service_date, m.status,
            b.bus_number, b.registration_number
       FROM maintenance m JOIN buses b ON b.id = m.bus_id
      WHERE m.status <> "cancelled" AND m.next_service_date IS NOT NULL
        AND m.next_service_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
      ORDER BY m.next_service_date
      LIMIT 5'
);

$driverRoster = db_all(
    'SELECT d.id, d.employee_id, d.license_expiry, d.employment_status, d.experience_years,
            u.name, u.email, u.phone, b.bus_number
       FROM drivers d
       JOIN users u ON u.id = d.user_id
       LEFT JOIN buses b ON b.id = d.assigned_bus_id
      ORDER BY d.employee_id
      LIMIT 6'
);

$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim((string) $user['name']))[0];

$page_title       = 'Dashboard';
$active_nav       = 'dashboard';
$page_breadcrumbs = [['label' => 'Dashboard']];

require __DIR__ . '/../includes/header.php';
?>

<?= render_page_header(
    $greeting . ', ' . $firstName,
    'Transport operations for ' . date('l, d M Y'),
    '<span class="chip">Fleet ready <strong>' . $activeBuses . '/' . $totalBuses . '</strong></span>'
) ?>

<div class="stat-grid">
    <?= stat_card('Fleet', $totalBuses, 'bi-bus-front', 'primary', '<strong>' . $activeBuses . '</strong> available for service') ?>
    <?= stat_card('Drivers on duty', $activeDrivers, 'bi-person-badge', 'success', 'Active employment records') ?>
    <?= stat_card("Today's trips", $todayTrips, 'bi-signpost-split', 'info', '<strong>' . $onRoad . '</strong> on the road now') ?>
    <?= stat_card('Maintenance due', $maintenanceDue, 'bi-tools', 'warning', 'Services due within 15 days') ?>
    <?= stat_card('Revenue (30 days)', money($revenue30Days), 'bi-cash-stack', 'primary', 'Payments received') ?>
</div>

<?php if ($maintenanceDue > 0): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong><?= $maintenanceDue ?></strong> service<?= $maintenanceDue === 1 ? ' is' : 's are' ?> due within
            the next 15 days. Review the maintenance schedule before assigning those buses.
        </span>
    </div>
<?php endif; ?>

<div class="card-fl">
    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title">Today's services</h2>
            <p class="card-fl__subtitle">Every scheduled departure for today with live status</p>
        </div>
        <span class="chip"><?= $todayTrips ?> scheduled</span>
    </div>

    <?php if ($todayTripsList === []): ?>
        <?= empty_state(
            'Nothing scheduled for today',
            'Create schedules for today to see them tracked here with their operational status.',
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
                        <th>Load</th>
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
                            <td><?= e($trip['bus_number']) ?></td>
                            <td><?= e($trip['driver_name']) ?></td>
                            <td>
                                <span class="cell-strong"><?= e(format_time($trip['departure_time'])) ?></span><br>
                                <span class="cell-muted">arr. <?= e(format_time($trip['arrival_time'])) ?></span>
                            </td>
                            <td class="cell-num">
                                <?= (int) $trip['passenger_count'] ?> / <?= (int) $trip['capacity'] ?>
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
                <h2 class="card-fl__title">Maintenance schedule</h2>
                <p class="card-fl__subtitle">Services due within 15 days</p>
            </div>
        </div>

        <?php if ($maintenanceList === []): ?>
            <?= empty_state('Nothing due for service', 'No bus requires attention in the next 15 days.', 'bi-tools') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr><th>Bus</th><th>Service</th><th>Due</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maintenanceList as $record): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e($record['bus_number']) ?></span><br>
                                    <span class="cell-muted"><?= e($record['registration_number']) ?></span>
                                </td>
                                <td><?= e(labelize($record['maintenance_type'])) ?></td>
                                <td>
                                    <?= e(format_date($record['next_service_date'])) ?>
                                    <?php if ($record['next_service_date'] < date('Y-m-d')): ?>
                                        <br><span style="color:var(--fl-danger);font-size:12.5px;">Overdue</span>
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

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Driver roster</h2>
                <p class="card-fl__subtitle">Licence status and current vehicle assignment</p>
            </div>
        </div>

        <?php if ($driverRoster === []): ?>
            <?= empty_state('No drivers registered', 'Add drivers to assign them to buses and schedules.', 'bi-person-badge') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr><th>Driver</th><th>Licence expires</th><th>Bus</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($driverRoster as $driver): ?>
                            <?php
                            $expiringSoon = strtotime((string) $driver['license_expiry']) < strtotime('+60 days');
                            ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e($driver['name']) ?></span><br>
                                    <span class="cell-muted"><?= e($driver['employee_id']) ?> · <?= (int) $driver['experience_years'] ?> yrs</span>
                                </td>
                                <td>
                                    <?= e(format_date($driver['license_expiry'])) ?>
                                    <?php if ($expiringSoon): ?>
                                        <br><span style="color:var(--fl-warning);font-size:12.5px;">Renew soon</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($driver['bus_number'] ?? 'Unassigned') ?></td>
                                <td><?= status_badge($driver['employment_status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
