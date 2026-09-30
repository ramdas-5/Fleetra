<?php
/**
 * Fleetra — Schedules / Departure detail
 * ------------------------------------------------------------------
 * modules/schedules/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('schedules.view');

$scheduleId = get_int('id');

if ($scheduleId <= 0) {
    abort_not_found('No schedule was specified.');
}

$schedule = find_schedule($scheduleId);

if ($schedule === null) {
    abort_not_found('That departure does not exist.');
}

$canManage = can('schedules.manage');

$counts = schedule_booking_counts($scheduleId);
$capacity = (int) $schedule['capacity'];
$isCancelled = (string) $schedule['status'] === 'cancelled';
$isPast = (string) $schedule['schedule_date'] < date('Y-m-d');

$manifest = db_all(
    'SELECT b.id, b.booking_number, b.seat_number, b.fare, b.booking_status, b.payment_status, b.booking_date,
            u.id AS passenger_id, u.name AS passenger_name, u.phone AS passenger_phone,
            bs.stop_name AS boarding_stop, ds.stop_name AS destination_stop,
            t.id AS ticket_id, t.ticket_number, t.status AS ticket_status
       FROM bookings b
       JOIN users u ON u.id = b.user_id
       LEFT JOIN stops bs ON bs.id = b.boarding_stop_id
       LEFT JOIN stops ds ON ds.id = b.destination_stop_id
       LEFT JOIN tickets t ON t.booking_id = b.id
      WHERE b.schedule_id = ?
      ORDER BY b.seat_number',
    [$scheduleId]
);

$collected = (float) db_value(
    'SELECT COALESCE(SUM(fare), 0) FROM bookings WHERE schedule_id = ? AND payment_status = "paid"',
    [$scheduleId],
    0
);

$routeStops = db_all(
    'SELECT stop_name, stop_order, arrival_offset, latitude, longitude
       FROM stops WHERE route_id = ? ORDER BY stop_order',
    [(int) $schedule['route_id']]
);

$history = db_all(
    'SELECT al.action, al.description, al.created_at, u.name AS actor_name
       FROM activity_logs al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.module = "schedules" AND al.record_id = ?
      ORDER BY al.created_at DESC
      LIMIT 8',
    [$scheduleId]
);

$driverLicenceDays = (int) floor((strtotime((string) $schedule['license_expiry']) - time()) / 86400);
$driverUnavailable = (string) $schedule['employment_status'] !== 'active' || $driverLicenceDays < 0;
$busUnavailable = (string) $schedule['bus_status'] !== 'active';

$cancelForm = '';
$deleteForm = '';

if ($canManage && !$isCancelled) {
    $cancelForm = sprintf(
        '<form method="post" action="%s" class="d-inline"
              data-confirm="%s"
              data-confirm-title="%s"
              data-confirm-button="%s"
              data-confirm-variant="danger">%s
             <button type="submit" class="btn btn-outline-danger">
                 <i class="bi bi-x-octagon" aria-hidden="true"></i> Cancel service
             </button>
         </form>',
        e(url('modules/schedules/cancel.php?id=' . $scheduleId)),
        e('Every seat on this departure is released, paid fares are refunded, tickets are voided and all booked passengers are notified.'),
        e('Cancel this departure?'),
        e('Cancel service'),
        csrf_field()
    );
}

if ($canManage) {
    $deleteForm = sprintf(
        '<form method="post" action="%s" class="d-inline"
              data-confirm="%s"
              data-confirm-title="%s"
              data-confirm-button="%s">%s
             <button type="submit" class="btn btn-outline-secondary">
                 <i class="bi bi-trash" aria-hidden="true"></i> Delete
             </button>
         </form>',
        e(url('modules/schedules/delete.php?id=' . $scheduleId)),
        e($counts['total'] > 0
            ? 'This departure has bookings on record, so it cannot be deleted. Cancel the service instead to release the seats.'
            : 'This removes the departure and the trip created for it.'),
        e('Delete this departure?'),
        e('Delete departure'),
        csrf_field()
    );
}

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/schedules/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Schedules
            </a>';

if ($schedule['trip_id'] !== null) {
    $actions .= '<a class="btn btn-outline-secondary" href="'
        . e(url('modules/trips/view.php?id=' . (int) $schedule['trip_id'])) . '">
            <i class="bi bi-signpost-split" aria-hidden="true"></i> Open trip
        </a>';
}

if ($canManage) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('modules/schedules/edit.php?id=' . $scheduleId)) . '">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit departure
                 </a>' . $cancelForm . $deleteForm;
}

$page_title       = $schedule['route_code'] . ' · ' . format_time($schedule['departure_time']);
$active_nav       = 'schedules';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Schedules', 'url' => url('modules/schedules/index.php')],
    ['label' => $schedule['route_code'] . ' ' . format_date($schedule['schedule_date'], 'd M')],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $schedule['route_code'] . ' · ' . $schedule['route_name'],
    format_date($schedule['schedule_date'], 'D, d M Y') . ' · '
        . time_range_label((string) $schedule['departure_time'], (string) $schedule['arrival_time'])
        . ' · ' . $schedule['source'] . ' to ' . $schedule['destination'],
    $actions
) ?>

<?php if ($isCancelled): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-x-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This service was cancelled. Every seat on it has been released and paid fares were refunded.
            <?php if (!empty($schedule['trip_remarks'])): ?>
                <br>Reason recorded: <?= e($schedule['trip_remarks']) ?>
            <?php endif; ?>
        </span>
    </div>
<?php elseif ($busUnavailable || $driverUnavailable): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This departure is not currently operational:
            <?php if ($busUnavailable): ?>
                <br>Bus <?= e($schedule['bus_number']) ?> is <?= e(labelize((string) $schedule['bus_status'])) ?>.
            <?php endif; ?>
            <?php if ($driverUnavailable): ?>
                <br>Driver <?= e($schedule['driver_name']) ?>
                <?= $driverLicenceDays < 0
                    ? 'has an expired licence (expired ' . e(format_date((string) $schedule['license_expiry'])) . ')'
                    : 'is ' . e(labelize((string) $schedule['employment_status'])) ?>.
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('Seats sold', $counts['active'] . ' / ' . $capacity, 'bi-people', 'primary', 'Pending and confirmed') ?>
    <?= stat_card('Passengers carried', (int) ($schedule['passenger_count'] ?? 0), 'bi-person-check', 'info', 'Counted on the trip sheet') ?>
    <?= stat_card('Fare collected', money($collected), 'bi-cash-coin', 'success', 'Paid bookings only') ?>
    <?= stat_card(
        'Delay',
        (int) ($schedule['delay_minutes'] ?? 0) > 0 ? '+' . (int) $schedule['delay_minutes'] . ' min' : 'On time',
        'bi-clock-history',
        (int) ($schedule['delay_minutes'] ?? 0) > 0 ? 'warning' : 'success',
        'Reported against the trip'
    ) ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Departure details</h2>
                    <p class="card-fl__subtitle">Planned schedule and the vehicle assigned to it</p>
                </div>
                <?= status_badge((string) $schedule['status']) ?>
            </div>

            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Route</span>
                        <span class="detail-item__value">
                            <?= e($schedule['route_code']) ?> · <?= e($schedule['route_name']) ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Service date</span>
                        <span class="detail-item__value"><?= e(format_date((string) $schedule['schedule_date'], 'D, d M Y')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Departure</span>
                        <span class="detail-item__value"><?= e(format_time((string) $schedule['departure_time'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Arrival</span>
                        <span class="detail-item__value">
                            <?= e(format_time((string) $schedule['arrival_time'])) ?>
                            <br><span class="cell-muted">
                                <?= e(format_duration(time_range_duration((string) $schedule['departure_time'], (string) $schedule['arrival_time']))) ?>
                                running time
                            </span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Distance</span>
                        <span class="detail-item__value"><?= e(number_format((float) $schedule['distance'], 1)) ?> km</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Base fare</span>
                        <span class="detail-item__value"><?= e(money($schedule['base_fare'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Bus</span>
                        <span class="detail-item__value">
                            <?= e($schedule['bus_number']) ?> <?= status_badge($schedule['bus_status']) ?>
                            <br><span class="cell-muted">
                                <?= e($schedule['registration_number']) ?> ·
                                <?= e(labelize((string) $schedule['bus_type'])) ?> · <?= $capacity ?> seats
                            </span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Driver</span>
                        <span class="detail-item__value">
                            <?= e($schedule['driver_name']) ?> <?= status_badge($schedule['employment_status']) ?>
                            <br><span class="cell-muted">
                                <?= e($schedule['employee_id']) ?> · licence valid to
                                <?= e(format_date((string) $schedule['license_expiry'])) ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Passenger manifest</h2>
                    <p class="card-fl__subtitle">
                        <?= count($manifest) ?> seat<?= count($manifest) === 1 ? '' : 's' ?> booked on this departure
                    </p>
                </div>
            </div>

            <?php if ($manifest === []): ?>
                <?= empty_state(
                    'No seats booked yet',
                    'Every seat on this departure is still available.',
                    'bi-ticket-perforated'
                ) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Seat</th>
                                <th>Passenger</th>
                                <th>Journey</th>
                                <th>Fare</th>
                                <th>Ticket</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manifest as $booking): ?>
                                <tr>
                                    <td><span class="cell-strong"><?= e($booking['seat_number']) ?></span></td>
                                    <td>
                                        <?= e($booking['passenger_name']) ?><br>
                                        <span class="cell-muted"><?= e($booking['booking_number']) ?></span>
                                    </td>
                                    <td>
                                        <?= e($booking['boarding_stop'] ?? $schedule['source']) ?>
                                        &rarr;
                                        <?= e($booking['destination_stop'] ?? $schedule['destination']) ?>
                                    </td>
                                    <td class="cell-num"><?= e(money($booking['fare'])) ?></td>
                                    <td>
                                        <?php if ($booking['ticket_number'] !== null): ?>
                                            <?= e($booking['ticket_number']) ?>
                                            <?= status_badge($booking['ticket_status']) ?>
                                        <?php else: ?>
                                            <span class="cell-muted">Not issued</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= status_badge($booking['booking_status']) ?><br>
                                        <?= status_badge($booking['payment_status']) ?>
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
                    <h2 class="card-fl__title">Stops on this route</h2>
                    <p class="card-fl__subtitle">Where this service stops, and when it is due</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="timeline">
                    <?php foreach ($routeStops as $index => $stop): ?>
                        <?php
                        $dueAt = time_to_minutes((string) $schedule['departure_time']) + (int) $stop['arrival_offset'];
                        $isLast = $index === count($routeStops) - 1;
                        ?>
                        <li class="timeline__item<?= $isLast ? ' timeline__item--done' : '' ?>">
                            <p class="timeline__title">
                                <?= (int) $stop['stop_order'] ?>. <?= e($stop['stop_name']) ?>
                                <?php if ($stop['arrival_offset'] === 0 || $index === 0): ?>
                                    <span class="badge-status badge-primary">Departs here</span>
                                <?php elseif ($isLast): ?>
                                    <span class="badge-status badge-success">Terminates</span>
                                <?php endif; ?>
                            </p>
                            <p class="timeline__meta">
                                Due <?= e(format_time(minutes_to_time($dueAt))) ?>
                                · <?= (int) $stop['arrival_offset'] ?> min after departure
                                <?php if ($stop['latitude'] !== null): ?>
                                    · <span class="cell-muted"><?= e((string) $stop['latitude']) ?>, <?= e((string) $stop['longitude']) ?></span>
                                <?php endif; ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Trip sheet</h2>
                    <p class="card-fl__subtitle">Operational record created from this schedule</p>
                </div>
                <?php if ($schedule['trip_status'] !== null): ?>
                    <?= status_badge((string) $schedule['trip_status']) ?>
                <?php endif; ?>
            </div>

            <?php if ($schedule['trip_id'] === null): ?>
                <?= empty_state('No trip created', 'A trip is created automatically with every schedule.', 'bi-signpost-split') ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="fact-list">
                        <li>
                            <span class="fact-list__label">Trip reference</span>
                            <span class="fact-list__value">TRP-<?= str_pad((string) $schedule['trip_id'], 4, '0', STR_PAD_LEFT) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Operational status</span>
                            <span class="fact-list__value"><?= status_badge((string) $schedule['trip_status']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Passengers recorded</span>
                            <span class="fact-list__value"><?= (int) $schedule['passenger_count'] ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Delay reported</span>
                            <span class="fact-list__value">
                                <?= (int) $schedule['delay_minutes'] > 0
                                    ? '+' . (int) $schedule['delay_minutes'] . ' minutes'
                                    : 'None' ?>
                            </span>
                        </li>
                    </ul>

                    <?php if (!empty($schedule['trip_remarks'])): ?>
                        <p class="form-text mt-3"><?= e($schedule['trip_remarks']) ?></p>
                    <?php endif; ?>

                    <a class="btn btn-outline-secondary w-100 mt-3"
                       href="<?= e(url('modules/trips/view.php?id=' . (int) $schedule['trip_id'])) ?>">
                        <i class="bi bi-signpost-split" aria-hidden="true"></i> Open trip sheet
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Change history</h2>
                    <p class="card-fl__subtitle">Audit trail for this departure</p>
                </div>
            </div>

            <?php if ($history === []): ?>
                <?= empty_state('No recorded changes', 'Edits and status changes will be logged here.', 'bi-clock-history') ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="timeline">
                        <?php foreach ($history as $entry): ?>
                            <li class="timeline__item timeline__item--done">
                                <p class="timeline__title"><?= e($entry['action']) ?></p>
                                <p class="timeline__meta">
                                    <?= e($entry['actor_name'] ?? 'System') ?> &middot; <?= e(time_ago($entry['created_at'])) ?>
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

        <?php if ($canManage): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Managing this departure</h2>
                        <p class="card-fl__subtitle">Cancelling and deleting do different things</p>
                    </div>
                </div>

                <div class="card-fl__body stack-sm">
                    <p class="form-text mb-0">
                        <strong>Cancel</strong> keeps the record, releases every seat, refunds paid fares and tells the
                        passengers. Use it whenever the service will not run.
                    </p>
                    <p class="form-text mb-0">
                        <strong>Delete</strong> removes the departure and its trip for good. It is only possible while
                        no seats have been booked on it.
                        <?= $counts['total'] > 0
                            ? ' This departure has ' . $counts['total'] . ' booking(s), so the system will ask you to cancel instead.'
                            : '' ?>
                    </p>
                    <?php if ($isPast && !$isCancelled): ?>
                        <p class="form-text mb-0">
                            This service date is in the past, so deleting it would remove operational history.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
