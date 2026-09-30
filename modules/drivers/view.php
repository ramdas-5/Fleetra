<?php
/**
 * Fleetra — Fleet / Driver profile
 * ------------------------------------------------------------------
 * modules/drivers/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('drivers.view');

$driverId = get_int('id');

if ($driverId <= 0) {
    abort_not_found('No driver was specified.');
}

$driver = db_one(
    'SELECT d.*, u.name, u.email, u.phone, u.status AS user_status, u.profile_image, u.last_login,
            b.id AS bus_id, b.bus_number, b.registration_number, b.bus_type, b.capacity, b.status AS bus_status
       FROM drivers d
       JOIN users u ON u.id = d.user_id
       LEFT JOIN buses b ON b.id = d.assigned_bus_id
      WHERE d.id = ?
      LIMIT 1',
    [$driverId]
);

if ($driver === null) {
    abort_not_found('That driver does not exist.');
}

$canManage = can('drivers.manage');

$todayTrips = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes,
            s.departure_time, s.arrival_time,
            r.route_code, r.route_name, r.source, r.destination, b.bus_number
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN routes r    ON r.id = t.route_id
       JOIN buses b     ON b.id = t.bus_id
      WHERE t.driver_id = ? AND s.schedule_date = CURDATE()
      ORDER BY s.departure_time',
    [$driverId]
);

$tripHistory = db_all(
    'SELECT t.id, t.trip_status, t.passenger_count, t.delay_minutes,
            s.schedule_date, s.departure_time,
            r.route_code, r.source, r.destination, b.bus_number
       FROM trips t
       JOIN schedules s ON s.id = t.schedule_id
       JOIN routes r    ON r.id = t.route_id
       JOIN buses b     ON b.id = t.bus_id
      WHERE t.driver_id = ?
      ORDER BY s.schedule_date DESC, s.departure_time DESC
      LIMIT 8',
    [$driverId]
);

$changeHistory = db_all(
    'SELECT al.action, al.description, al.created_at, u.name AS user_name
       FROM activity_logs al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.module = ? AND al.record_id = ?
      ORDER BY al.created_at DESC
      LIMIT 6',
    ['drivers', $driverId]
);

$stats = db_one(
    'SELECT COUNT(*) AS trip_count,
            COALESCE(SUM(t.passenger_count), 0) AS passengers,
            COALESCE(SUM(t.delay_minutes), 0) AS delay_total,
            COALESCE(SUM(CASE WHEN t.trip_status = "completed" THEN 1 ELSE 0 END), 0) AS completed
       FROM trips t
      WHERE t.driver_id = ?',
    [$driverId]
) ?? ['trip_count' => 0, 'passengers' => 0, 'delay_total' => 0, 'completed' => 0];

$licenseExpiry = strtotime((string) $driver['license_expiry']);
$daysToExpiry  = (int) floor(($licenseExpiry - time()) / 86400);

$openIncidents = (int) db_value(
    'SELECT COUNT(*) FROM incidents WHERE driver_id = ? AND status NOT IN ("resolved", "closed")',
    [$driverId],
    0
);

$deleteForm = $canManage
    ? '<form method="post" action="' . e(url('modules/drivers/delete.php?id=' . $driverId)) . '" class="d-inline"
              data-confirm="This removes the driver profile and their Fleetra login."
              data-confirm-title="Delete ' . e($driver['name']) . '?"
              data-confirm-button="Delete driver">
           ' . csrf_field() . '
           <button type="submit" class="btn btn-outline-secondary">
               <i class="bi bi-trash" aria-hidden="true"></i> Delete
           </button>
       </form>'
    : '';

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/drivers/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Drivers
            </a>';

if ($canManage) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('modules/drivers/edit.php?id=' . $driverId)) . '">
                     <i class="bi bi-pencil" aria-hidden="true"></i> Edit driver
                 </a>' . $deleteForm;
}

$page_title       = $driver['name'];
$active_nav       = 'drivers';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/drivers/index.php')],
    ['label' => 'Drivers', 'url' => url('modules/drivers/index.php')],
    ['label' => (string) $driver['name']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    (string) $driver['name'],
    'Employee ' . $driver['employee_id'] . ' · ' . role_label('driver'),
    $actions
) ?>

<?php if ($daysToExpiry < 0): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This driver's licence expired on <?= e(format_date($driver['license_expiry'])) ?>.
            They cannot be assigned to a bus until it is renewed.
        </span>
    </div>
<?php elseif ($daysToExpiry < 60): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            Licence expires on <?= e(format_date($driver['license_expiry'])) ?>
            (<?= $daysToExpiry ?> days). Please arrange a renewal.
        </span>
    </div>
<?php endif; ?>

<?php if ($openIncidents > 0): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong><?= $openIncidents ?></strong> unresolved incident<?= $openIncidents === 1 ? '' : 's' ?>
            recorded against this driver.
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('Trips operated', (int) $stats['trip_count'], 'bi-signpost-split', 'primary', (int) $stats['completed'] . ' completed') ?>
    <?= stat_card('Passengers carried', (int) $stats['passengers'], 'bi-people', 'info', 'Across all trips') ?>
    <?= stat_card('Delays recorded', format_duration((int) $stats['delay_total']), 'bi-clock-history', 'warning', 'Total time behind schedule') ?>
    <?= stat_card('Experience', (int) $driver['experience_years'] . ' yrs', 'bi-award', 'success', 'Joined ' . e(format_date($driver['joining_date'], 'M Y'))) ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Driver details</h2>
                    <p class="card-fl__subtitle">Contact and licence information</p>
                </div>
                <?= status_badge($driver['employment_status']) ?>
            </div>

            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Full name</span>
                        <span class="detail-item__value"><?= e($driver['name']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Employee ID</span>
                        <span class="detail-item__value"><?= e($driver['employee_id']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Email</span>
                        <span class="detail-item__value"><?= e($driver['email']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Mobile</span>
                        <span class="detail-item__value"><?= e($driver['phone'] ?: '—') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Licence number</span>
                        <span class="detail-item__value"><?= e($driver['license_number']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Licence expiry</span>
                        <span class="detail-item__value"><?= e(format_date($driver['license_expiry'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Date of birth</span>
                        <span class="detail-item__value"><?= e(format_date($driver['date_of_birth'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Joining date</span>
                        <span class="detail-item__value"><?= e(format_date($driver['joining_date'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Experience</span>
                        <span class="detail-item__value"><?= (int) $driver['experience_years'] ?> years</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Account status</span>
                        <span class="detail-item__value"><?= status_badge((string) $driver['user_status']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Last sign in</span>
                        <span class="detail-item__value">
                            <?= $driver['last_login'] !== null ? e(time_ago((string) $driver['last_login'])) : 'Never signed in' ?>
                        </span>
                    </div>
                    <div class="detail-item span-2">
                        <span class="detail-item__label">Address</span>
                        <span class="detail-item__value"><?= e($driver['address'] ?: '—') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Trip history</h2>
                    <p class="card-fl__subtitle">The eight most recent services for this driver</p>
                </div>
            </div>

            <?php if ($tripHistory === []): ?>
                <?= empty_state('No trips recorded', 'Trips appear here once this driver is assigned to a schedule.', 'bi-signpost-split') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Route</th>
                                <th>Bus</th>
                                <th>Passengers</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tripHistory as $trip): ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong"><?= e(format_date($trip['schedule_date'], 'd M Y')) ?></span><br>
                                        <span class="cell-muted"><?= e(format_time($trip['departure_time'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="cell-strong"><?= e($trip['route_code']) ?></span><br>
                                        <span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                                    </td>
                                    <td><?= e($trip['bus_number']) ?></td>
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
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Today's duty</h2>
                    <p class="card-fl__subtitle"><?= count($todayTrips) ?> trip<?= count($todayTrips) === 1 ? '' : 's' ?> assigned today</p>
                </div>
            </div>

            <?php if ($todayTrips === []): ?>
                <?= empty_state('No trips today', 'This driver has no departure scheduled for today.', 'bi-calendar-check') ?>
            <?php else: ?>
                <div class="card-fl__body stack-sm">
                    <?php foreach ($todayTrips as $trip): ?>
                        <article class="alert-item">
                            <div class="alert-item__head">
                                <span class="alert-item__title">
                                    <?= e($trip['route_code']) ?> · <?= e($trip['route_name']) ?>
                                </span>
                                <?= status_badge($trip['trip_status']) ?>
                            </div>
                            <p class="alert-item__text">
                                <?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?>
                            </p>
                            <p class="alert-item__meta">
                                Departs <?= e(format_time($trip['departure_time'])) ?>
                                · arrives <?= e(format_time($trip['arrival_time'])) ?>
                                · <?= e($trip['bus_number']) ?>
                                · <?= (int) $trip['passenger_count'] ?> passengers
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Assigned bus</h2>
                    <p class="card-fl__subtitle">Current vehicle pairing</p>
                </div>
            </div>

            <?php if ($driver['bus_id'] === null): ?>
                <?= empty_state(
                    'No bus assigned',
                    'Assign a bus on the edit screen to pair this driver with a vehicle.',
                    'bi-bus-front',
                    $canManage
                        ? '<a class="btn btn-primary btn-sm" href="' . e(url('modules/drivers/edit.php?id=' . $driverId)) . '">Assign a bus</a>'
                        : ''
                ) ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="fact-list">
                        <li>
                            <span class="fact-list__label">Bus number</span>
                            <span class="fact-list__value"><?= e($driver['bus_number']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Registration</span>
                            <span class="fact-list__value"><?= e($driver['registration_number']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Type</span>
                            <span class="fact-list__value"><?= e(labelize((string) $driver['bus_type'])) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Capacity</span>
                            <span class="fact-list__value"><?= (int) $driver['capacity'] ?> seats</span>
                        </li>
                        <li>
                            <span class="fact-list__label">Bus status</span>
                            <span class="fact-list__value"><?= status_badge($driver['bus_status']) ?></span>
                        </li>
                    </ul>

                    <a class="btn btn-outline-secondary w-100 mt-3"
                       href="<?= e(url('modules/buses/view.php?id=' . (int) $driver['bus_id'])) ?>">
                        <i class="bi bi-bus-front" aria-hidden="true"></i> Open bus record
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Change history</h2>
                    <p class="card-fl__subtitle">Audit trail for this driver</p>
                </div>
            </div>

            <?php if ($changeHistory === []): ?>
                <?= empty_state('No recorded changes', 'Updates to this driver will be logged here.', 'bi-clock-history') ?>
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
