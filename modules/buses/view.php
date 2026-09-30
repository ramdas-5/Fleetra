<?php
/**
 * Fleetra — Fleet / Bus details
 * ------------------------------------------------------------------
 * modules/buses/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('fleet.view');

$busId = get_int('id');

if ($busId <= 0) {
    abort_not_found('No bus was specified.');
}

$bus = db_one(
    'SELECT b.*,
            (SELECT COUNT(*) FROM schedules s WHERE s.bus_id = b.id) AS schedule_count
       FROM buses b
      WHERE b.id = ?
      LIMIT 1',
    [$busId]
);

if ($bus === null) {
    abort_not_found('That bus does not exist in the fleet.');
}

$canManage = can('fleet.manage');

/* ------------------------------------------------------------------
 | Related records
 ------------------------------------------------------------------ */

$assignedDrivers = db_all(
    'SELECT d.id, d.employee_id, d.license_expiry, d.employment_status, d.experience_years,
            u.name, u.phone, u.email
       FROM drivers d
       JOIN users u ON u.id = d.user_id
      WHERE d.assigned_bus_id = ?',
    [$busId]
);

$maintenanceHistory = db_all(
    'SELECT id, maintenance_type, service_date, next_service_date, cost, service_provider, status
       FROM maintenance
      WHERE bus_id = ?
      ORDER BY service_date DESC
      LIMIT 5',
    [$busId]
);

$recentTrips = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes,
            s.schedule_date, s.departure_time, s.arrival_time,
            r.route_code, r.source, r.destination, u.name AS driver_name
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN routes r    ON r.id = t.route_id
       JOIN drivers d   ON d.id = t.driver_id
       JOIN users u     ON u.id = d.user_id
      WHERE t.bus_id = ?
      ORDER BY s.schedule_date DESC, s.departure_time DESC
      LIMIT 5',
    [$busId]
);

$changeHistory = db_all(
    'SELECT al.action, al.description, al.created_at, u.name AS user_name
       FROM activity_logs al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.module = ? AND al.record_id = ?
      ORDER BY al.created_at DESC
      LIMIT 6',
    ['buses', $busId]
);

/* ------------------------------------------------------------------
 | Totals
 ------------------------------------------------------------------ */

$totals = db_one(
    'SELECT COUNT(*) AS trip_count,
            COALESCE(SUM(t.passenger_count), 0) AS passengers,
            COALESCE(SUM(CASE WHEN t.trip_status = "completed" THEN r.distance ELSE 0 END), 0) AS distance
       FROM trips t
       JOIN routes r ON r.id = t.route_id
      WHERE t.bus_id = ?',
    [$busId]
) ?? ['trip_count' => 0, 'passengers' => 0, 'distance' => 0];

$maintenanceSpend = (float) db_value(
    'SELECT COALESCE(SUM(cost), 0) FROM maintenance WHERE bus_id = ?',
    [$busId],
    0
);

$maintenanceDue = db_value(
    'SELECT next_service_date FROM maintenance
      WHERE bus_id = ? AND next_service_date IS NOT NULL AND status <> "cancelled"
      ORDER BY next_service_date LIMIT 1',
    [$busId]
);

$deleteForm = $canManage
    ? '<form method="post" action="' . e(url('modules/buses/delete.php?id=' . $busId)) . '" class="d-inline"
              data-confirm="This will permanently remove ' . e($bus['bus_number']) . ' from the fleet."
              data-confirm-title="Delete bus ' . e($bus['bus_number']) . '?"
              data-confirm-button="Delete bus">
           ' . csrf_field() . '
           <button type="submit" class="btn btn-outline-secondary">
               <i class="bi bi-trash" aria-hidden="true"></i> Delete
           </button>
       </form>'
    : '';

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/buses/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Fleet
            </a>';

if ($canManage) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('modules/buses/edit.php?id=' . $busId)) . '">
                     <i class="bi bi-pencil" aria-hidden="true"></i> Edit bus
                 </a>' . $deleteForm;
}

$page_title       = $bus['bus_number'];
$active_nav       = 'buses';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/buses/index.php')],
    ['label' => (string) $bus['bus_number']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $bus['bus_number'] . ' · ' . $bus['registration_number'],
    join_parts([$bus['manufacturer'], $bus['model']], ' ') ?: 'Fleet vehicle record',
    $actions
) ?>

<div class="stat-grid">
    <?= stat_card('Trips operated', (int) $totals['trip_count'], 'bi-signpost-split', 'primary', 'All time') ?>
    <?= stat_card('Passengers carried', (int) $totals['passengers'], 'bi-people', 'info', 'Across all trips') ?>
    <?= stat_card('Distance completed', number_format((float) $totals['distance'], 0) . ' km', 'bi-speedometer2', 'success', 'Completed trips only') ?>
    <?= stat_card('Maintenance spend', money($maintenanceSpend), 'bi-tools', 'warning', 'All recorded services') ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Vehicle details</h2>
                    <p class="card-fl__subtitle">Registered specification and current state</p>
                </div>
                <?= status_badge($bus['status']) ?>
            </div>

            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Bus number</span>
                        <span class="detail-item__value"><?= e($bus['bus_number']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Registration</span>
                        <span class="detail-item__value"><?= e($bus['registration_number']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Manufacturer</span>
                        <span class="detail-item__value"><?= e($bus['manufacturer'] ?: '—') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Model</span>
                        <span class="detail-item__value"><?= e($bus['model'] ?: '—') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Manufacturing year</span>
                        <span class="detail-item__value"><?= $bus['manufacturing_year'] !== null ? (int) $bus['manufacturing_year'] : '—' ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Bus type</span>
                        <span class="detail-item__value"><?= e(labelize((string) $bus['bus_type'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Capacity</span>
                        <span class="detail-item__value"><?= (int) $bus['capacity'] ?> seats</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Fuel type</span>
                        <span class="detail-item__value"><?= e(labelize((string) $bus['fuel_type'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Odometer</span>
                        <span class="detail-item__value"><?= e(number_format((float) $bus['current_mileage'], 2)) ?> km</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Schedules created</span>
                        <span class="detail-item__value"><?= (int) $bus['schedule_count'] ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Next service due</span>
                        <span class="detail-item__value">
                            <?= $maintenanceDue !== false && $maintenanceDue !== null
                                ? e(format_date((string) $maintenanceDue))
                                : 'Not scheduled' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Added to fleet</span>
                        <span class="detail-item__value"><?= e(format_date((string) $bus['created_at'])) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Recent trips</h2>
                    <p class="card-fl__subtitle">The last five services operated by this bus</p>
                </div>
            </div>

            <?php if ($recentTrips === []): ?>
                <?= empty_state('No trips operated yet', 'This bus has not been assigned to a schedule yet.', 'bi-signpost-split') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Route</th>
                                <th>Driver</th>
                                <th>Passengers</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTrips as $trip): ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong"><?= e(format_date($trip['schedule_date'], 'd M Y')) ?></span><br>
                                        <span class="cell-muted"><?= e(format_time($trip['departure_time'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="cell-strong"><?= e($trip['route_code']) ?></span><br>
                                        <span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                                    </td>
                                    <td><?= e($trip['driver_name']) ?></td>
                                    <td class="cell-num"><?= (int) $trip['passenger_count'] ?></td>
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

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Maintenance history</h2>
                    <p class="card-fl__subtitle">Most recent workshop records for this vehicle</p>
                </div>
            </div>

            <?php if ($maintenanceHistory === []): ?>
                <?= empty_state('No maintenance recorded', 'Service records for this bus will appear here.', 'bi-tools') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Date</th>
                                <th>Provider</th>
                                <th>Cost</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenanceHistory as $record): ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong"><?= e(labelize($record['maintenance_type'])) ?></span><br>
                                        <span class="cell-muted">
                                            next <?= $record['next_service_date'] !== null
                                                ? e(format_date($record['next_service_date']))
                                                : 'not scheduled' ?>
                                        </span>
                                    </td>
                                    <td><?= e(format_date($record['service_date'])) ?></td>
                                    <td><?= e($record['service_provider'] ?: '—') ?></td>
                                    <td class="cell-num"><?= e(money($record['cost'])) ?></td>
                                    <td><?= status_badge($record['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <?php if (!empty($bus['image'])): ?>
            <div class="card-fl">
                <div class="card-fl__body" style="padding:0;">
                    <img src="<?= e(upload_url($bus['image'], 'buses')) ?>" alt="<?= e($bus['bus_number']) ?>"
                         style="display:block;width:100%;height:auto;border-radius:var(--fl-radius);">
                </div>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Assigned driver</h2>
                    <p class="card-fl__subtitle">Currently linked to this vehicle</p>
                </div>
            </div>

            <?php if ($assignedDrivers === []): ?>
                <?= empty_state('No driver assigned', 'Assign a driver from the driver module to pair them with this bus.', 'bi-person-badge') ?>
            <?php else: ?>
                <div class="card-fl__body stack-sm">
                    <?php foreach ($assignedDrivers as $driver): ?>
                        <div class="cell-user">
                            <span class="avatar avatar--md" aria-hidden="true"><?= e(initials($driver['name'])) ?></span>
                            <span class="cell-user__text">
                                <span class="cell-user__name"><?= e($driver['name']) ?></span>
                                <span class="cell-user__meta">
                                    <?= e($driver['employee_id']) ?> · <?= (int) $driver['experience_years'] ?> yrs experience
                                </span>
                                <span class="cell-user__meta"><?= e($driver['phone'] ?: 'No phone on record') ?></span>
                            </span>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <?= status_badge($driver['employment_status']) ?>
                            <span class="chip">Licence <?= e(format_date($driver['license_expiry'], 'd M Y')) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Assignment &amp; change history</h2>
                    <p class="card-fl__subtitle">Audit trail for this vehicle</p>
                </div>
            </div>

            <?php if ($changeHistory === []): ?>
                <?= empty_state('No recorded changes', 'Updates to this bus will be logged here.', 'bi-clock-history') ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="timeline">
                        <?php foreach ($changeHistory as $entry): ?>
                            <li class="timeline__item timeline__item--done">
                                <p class="timeline__title"><?= e($entry['action']) ?></p>
                                <p class="timeline__meta">
                                    <?= e($entry['user_name'] ?? 'System') ?>
                                    &middot; <?= e(time_ago($entry['created_at'])) ?>
                                    <?php if (!empty($entry['description'])): ?>
                                        <br><?= e($entry['description']) ?>
                                    <?php endif; ?>
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
