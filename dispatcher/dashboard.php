<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * dispatcher/dashboard.php — Daily operations board
 *
 * The dispatcher works from a single board: what is on the road,
 * what is delayed and what needs a decision.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

require_role('dispatcher');

$user = current_user();

$tripStatus = array_column(
    db_all(
        'SELECT t.trip_status, COUNT(*) AS total
           FROM trips t JOIN schedules s ON s.id = t.schedule_id
          WHERE s.schedule_date = CURDATE()
          GROUP BY t.trip_status'
    ),
    'total',
    'trip_status'
);

$totalTodayTrips  = array_sum(array_map('intval', $tripStatus));
$onRoad           = (int) ($tripStatus['running'] ?? 0) + (int) ($tripStatus['boarding'] ?? 0);
$delayedTrips     = (int) ($tripStatus['delayed'] ?? 0);
$completedTrips   = (int) ($tripStatus['completed'] ?? 0);

$openIncidents = (int) db_value("SELECT COUNT(*) FROM incidents WHERE status IN ('open','investigating')", [], 0);

$availableBuses = (int) db_value("SELECT COUNT(*) FROM buses WHERE status = 'active'", [], 0);

$liveBoard = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes, t.remarks,
            s.departure_time, s.arrival_time,
            b.bus_number, b.capacity, b.registration_number,
            u.name AS driver_name, u.phone AS driver_phone,
            r.route_code, r.route_name, r.source, r.destination
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN buses b     ON b.id = t.bus_id
       JOIN drivers d   ON d.id = t.driver_id
       JOIN users u     ON u.id = d.user_id
       JOIN routes r    ON r.id = t.route_id
      WHERE s.schedule_date = CURDATE()
      ORDER BY FIELD(t.trip_status, "delayed", "running", "boarding", "scheduled", "completed", "cancelled"), s.departure_time'
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
      LIMIT 5'
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
    'Dispatch board for ' . date('l, d M Y') . ' · all times in ' . APP_TIMEZONE,
    '<span class="chip">Buses available <strong>' . $availableBuses . '</strong></span>'
) ?>

<div class="stat-grid">
    <?= stat_card("Today's trips", $totalTodayTrips, 'bi-signpost-split', 'primary', 'All scheduled services') ?>
    <?= stat_card('On the road', $onRoad, 'bi-broadcast-pin', 'info', 'Boarding or running now') ?>
    <?= stat_card('Delayed', $delayedTrips, 'bi-clock-history', 'warning', 'Trips running behind schedule') ?>
    <?= stat_card('Completed', $completedTrips, 'bi-check2-circle', 'success', 'Services finished today') ?>
    <?= stat_card('Open incidents', $openIncidents, 'bi-exclamation-triangle', 'danger', 'Awaiting resolution') ?>
</div>

<?php if ($openIncidents > 0): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong><?= $openIncidents ?></strong> incident<?= $openIncidents === 1 ? '' : 's' ?>
            still open on today's services. Review the incident list below and update the affected trips.
        </span>
    </div>
<?php endif; ?>

<div class="card-fl">
    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title">Live operations board</h2>
            <p class="card-fl__subtitle">Delayed and in-progress services are listed first</p>
        </div>
    </div>

    <?php if ($liveBoard === []): ?>
        <?= empty_state(
            'No services scheduled today',
            'Schedules created for today will appear here so you can monitor and update each departure.',
            'bi-calendar-x'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Route</th>
                        <th>Bus</th>
                        <th>Driver</th>
                        <th>Departure</th>
                        <th>Load</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liveBoard as $trip): ?>
                        <tr>
                            <td>
                                <?= status_badge($trip['trip_status']) ?>
                                <?php if ((int) $trip['delay_minutes'] > 0): ?>
                                    <br><span class="cell-muted">+<?= (int) $trip['delay_minutes'] ?> min</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($trip['route_code']) ?></span> <?= e($trip['route_name']) ?><br>
                                <span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($trip['bus_number']) ?></span><br>
                                <span class="cell-muted"><?= e($trip['registration_number']) ?></span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($trip['driver_name']) ?></span><br>
                                <span class="cell-muted"><?= e($trip['driver_phone'] ?? 'No contact') ?></span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e(format_time($trip['departure_time'])) ?></span><br>
                                <span class="cell-muted">arr. <?= e(format_time($trip['arrival_time'])) ?></span>
                            </td>
                            <td class="cell-num"><?= (int) $trip['passenger_count'] ?> / <?= (int) $trip['capacity'] ?></td>
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
            <h2 class="card-fl__title">Incidents needing attention</h2>
            <p class="card-fl__subtitle">Highest severity first</p>
        </div>
    </div>

    <?php if ($incidentList === []): ?>
        <?= empty_state('No open incidents', 'Every reported incident has been resolved.', 'bi-shield-check') ?>
    <?php else: ?>
        <div class="card-fl__body stack-sm">
            <?php foreach ($incidentList as $incident): ?>
                <article class="alert-item alert-item--<?= e(status_variant($incident['severity'])) ?>">
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
                    <p class="alert-item__text"><?= e(truncate($incident['description'], 170)) ?></p>
                    <p class="alert-item__meta">
                        <?= e($incident['driver_name'] ?? 'Unassigned driver') ?>
                        &middot; reported <?= e(time_ago($incident['reported_at'])) ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
