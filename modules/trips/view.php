<?php
/**
 * Fleetra — Trips / Trip sheet
 * ------------------------------------------------------------------
 * modules/trips/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

$tripId = get_int('id');

if ($tripId <= 0) {
    abort_not_found('No trip was specified.');
}

$trip = find_trip($tripId);

if ($trip === null) {
    abort_not_found('That trip does not exist.');
}

if (!trip_visible_to_current_user($trip)) {
    fleetra_fatal('This trip is not assigned to your account.', 403);
}

$canUpdate   = trip_status_change_allowed($trip);
$transitions = available_trip_transitions((string) $trip['trip_status']);

$manifest = db_all(
    'SELECT b.id, b.booking_number, b.seat_number, b.fare, b.booking_status, b.payment_status,
            u.name AS passenger_name, u.phone AS passenger_phone,
            bs.stop_name AS boarding_stop, ds.stop_name AS destination_stop,
            t.ticket_number, t.status AS ticket_status
       FROM bookings b
       JOIN users u ON u.id = b.user_id
       LEFT JOIN stops bs ON bs.id = b.boarding_stop_id
       LEFT JOIN stops ds ON ds.id = b.destination_stop_id
       LEFT JOIN tickets t ON t.booking_id = b.id
      WHERE b.schedule_id = ?
      ORDER BY b.seat_number',
    [(int) $trip['schedule_id']]
);

$seatsBooked = (int) db_value(
    'SELECT COUNT(*) FROM bookings WHERE schedule_id = ? AND booking_status IN ("pending","confirmed","completed")',
    [(int) $trip['schedule_id']],
    0
);

$collected = (float) db_value(
    'SELECT COALESCE(SUM(fare), 0) FROM bookings WHERE schedule_id = ? AND payment_status = "paid"',
    [(int) $trip['schedule_id']],
    0
);

$history = db_all(
    'SELECT al.action, al.description, al.created_at, u.name AS actor_name
       FROM activity_logs al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.module = "trips" AND al.record_id = ?
      ORDER BY al.created_at DESC
      LIMIT 8',
    [$tripId]
);

$incidents = db_all(
    'SELECT id, incident_type, severity, description, status, reported_at
       FROM incidents WHERE trip_id = ? ORDER BY reported_at DESC LIMIT 5',
    [$tripId]
);

$licenceDays = (int) floor((strtotime((string) $trip['license_expiry']) - time()) / 86400);

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/trips/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Trips
            </a>
            <a class="btn btn-outline-secondary" href="'
    . e(url('modules/schedules/view.php?id=' . (int) $trip['schedule_id'])) . '">
                <i class="bi bi-calendar3" aria-hidden="true"></i> Departure
            </a>';

$page_title       = 'TRP-' . str_pad((string) $tripId, 4, '0', STR_PAD_LEFT);
$active_nav       = 'trips';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Trips', 'url' => url('modules/trips/index.php')],
    ['label' => 'TRP-' . str_pad((string) $tripId, 4, '0', STR_PAD_LEFT)],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'TRP-' . str_pad((string) $tripId, 4, '0', STR_PAD_LEFT) . ' · ' . $trip['route_code'] . ' ' . $trip['route_name'],
    format_date((string) $trip['schedule_date'], 'D, d M Y') . ' · '
        . time_range_label((string) $trip['departure_time'], (string) $trip['arrival_time'])
        . ' · ' . $trip['bus_number'] . ' · ' . $trip['driver_name'],
    $actions
) ?>

<?php if ((string) $trip['trip_status'] === 'cancelled'): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-x-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This trip was cancelled. Every seat on it was released and paid fares were refunded.
            <?php if (!empty($trip['remarks'])): ?><br>Reason: <?= e($trip['remarks']) ?><?php endif; ?>
        </span>
    </div>
<?php elseif ((int) $trip['delay_minutes'] > 0 || (string) $trip['trip_status'] === 'delayed'): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-clock-history app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            Running <strong><?= (int) $trip['delay_minutes'] ?> minute<?= (int) $trip['delay_minutes'] === 1 ? '' : 's' ?></strong>
            behind schedule. Booked passengers have been notified.
            <?php if (!empty($trip['remarks'])): ?><br><?= e($trip['remarks']) ?><?php endif; ?>
        </span>
    </div>
<?php endif; ?>

<?php if ($licenceDays < 0): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <?= e($trip['driver_name']) ?>'s licence expired on <?= e(format_date((string) $trip['license_expiry'])) ?>.
            This driver must not be put back on duty until it is renewed.
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('Seats booked', $seatsBooked . ' / ' . (int) $trip['capacity'], 'bi-people', 'primary', $seatsBooked >= (int) $trip['capacity'] ? 'Bus is full' : ((int) $trip['capacity'] - $seatsBooked) . ' seats free') ?>
    <?= stat_card('Passengers recorded', (int) $trip['passenger_count'], 'bi-person-check', 'info', 'Counted on the trip sheet') ?>
    <?= stat_card('Fare collected', money($collected), 'bi-cash-coin', 'success', 'Paid bookings on this trip') ?>
    <?= stat_card('Delay', (int) $trip['delay_minutes'] > 0 ? '+' . (int) $trip['delay_minutes'] . ' min' : 'On time', 'bi-stopwatch', (int) $trip['delay_minutes'] > 0 ? 'warning' : 'success', 'Against the planned departure') ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Trip details</h2>
                    <p class="card-fl__subtitle">What is running, and with whom</p>
                </div>
                <?= status_badge((string) $trip['trip_status']) ?>
            </div>

            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Route</span>
                        <span class="detail-item__value">
                            <?= e($trip['route_code']) ?> · <?= e($trip['route_name']) ?>
                            <br><span class="cell-muted"><?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?></span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Service date</span>
                        <span class="detail-item__value"><?= e(format_date((string) $trip['schedule_date'], 'D, d M Y')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Planned timing</span>
                        <span class="detail-item__value">
                            <?= e(time_range_label((string) $trip['departure_time'], (string) $trip['arrival_time'])) ?>
                            <br><span class="cell-muted">
                                <?= e(format_duration(time_range_duration((string) $trip['departure_time'], (string) $trip['arrival_time']))) ?>
                                · <?= e(number_format((float) $trip['distance'], 1)) ?> km
                            </span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Actual start</span>
                        <span class="detail-item__value">
                            <?= $trip['actual_start_time'] !== null
                                ? e(format_datetime((string) $trip['actual_start_time']))
                                : '<span class="cell-muted">Not started</span>' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Actual finish</span>
                        <span class="detail-item__value">
                            <?= $trip['actual_end_time'] !== null
                                ? e(format_datetime((string) $trip['actual_end_time']))
                                : '<span class="cell-muted">Not finished</span>' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Schedule status</span>
                        <span class="detail-item__value"><?= status_badge((string) $trip['schedule_status']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Bus</span>
                        <span class="detail-item__value">
                            <?= e($trip['bus_number']) ?> <?= status_badge($trip['bus_status']) ?>
                            <br><span class="cell-muted">
                                <?= e($trip['registration_number']) ?> · <?= e(labelize((string) $trip['bus_type'])) ?>
                                · <?= (int) $trip['capacity'] ?> seats
                            </span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Driver</span>
                        <span class="detail-item__value">
                            <?= e($trip['driver_name']) ?> <?= status_badge($trip['employment_status']) ?>
                            <br><span class="cell-muted">
                                <?= e($trip['employee_id']) ?>
                                <?= $trip['driver_phone'] !== null ? ' · ' . e($trip['driver_phone']) : '' ?>
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
                    <p class="card-fl__subtitle"><?= count($manifest) ?> booking(s) on this trip</p>
                </div>
            </div>

            <?php if ($manifest === []): ?>
                <?= empty_state('Nobody booked yet', 'Seats booked by passengers will appear here with their boarding stop.', 'bi-people') ?>
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
                                        <span class="cell-muted">
                                            <?= e($booking['booking_number']) ?>
                                            <?= $booking['passenger_phone'] !== null ? ' · ' . e($booking['passenger_phone']) : '' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= e($booking['boarding_stop'] ?? $trip['source']) ?>
                                        &rarr;
                                        <?= e($booking['destination_stop'] ?? $trip['destination']) ?>
                                    </td>
                                    <td class="cell-num"><?= e(money($booking['fare'])) ?></td>
                                    <td>
                                        <?php if ($booking['ticket_number'] !== null): ?>
                                            <?= e($booking['ticket_number']) ?><br>
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

        <?php if ($incidents !== []): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Incidents on this trip</h2>
                        <p class="card-fl__subtitle">Reported during the run</p>
                    </div>
                </div>

                <div class="card-fl__body stack-sm">
                    <?php foreach ($incidents as $incident): ?>
                        <?php
                        $severityVariant = status_variant((string) $incident['severity']);
                        $severityClass   = in_array($severityVariant, ['info', 'warning', 'danger'], true)
                            ? ' alert-item--' . $severityVariant
                            : '';
                        ?>
                        <article class="alert-item<?= e($severityClass) ?>">
                            <div class="alert-item__head">
                                <span class="alert-item__title"><?= e(labelize((string) $incident['incident_type'])) ?></span>
                                <?= status_badge($incident['severity']) ?>
                            </div>
                            <p class="alert-item__text"><?= e(truncate($incident['description'], 160)) ?></p>
                            <p class="alert-item__meta">
                                <?= status_badge($incident['status']) ?>
                                · <?= e(time_ago($incident['reported_at'])) ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($canUpdate): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Update trip status</h2>
                        <p class="card-fl__subtitle">
                            Current: <?= e(labelize((string) $trip['trip_status'])) ?>
                        </p>
                    </div>
                </div>

                <?php if ($transitions === []): ?>
                    <?= empty_state(
                        'This trip is closed',
                        'A completed trip cannot be changed. If it needs correcting, reopen the departure from the schedule.',
                        'bi-lock'
                    ) ?>
                <?php else: ?>
                    <form method="post" action="<?= e(url('modules/trips/status.php?id=' . $tripId)) ?>" novalidate>
                        <?= csrf_field() ?>

                        <div class="card-fl__body">
                            <div class="form-row">
                                <div>
                                    <label class="form-label" for="delay_minutes">Delay (minutes)</label>
                                    <input type="number" class="form-control" id="delay_minutes" name="delay_minutes"
                                           min="0" max="600" value="<?= (int) $trip['delay_minutes'] > 0 ? (int) $trip['delay_minutes'] : '' ?>"
                                           placeholder="e.g. 15">
                                    <p class="form-text">Required when reporting a delay.</p>
                                </div>

                                <div>
                                    <label class="form-label" for="passenger_count">Passengers carried</label>
                                    <input type="number" class="form-control" id="passenger_count" name="passenger_count"
                                           min="0" max="<?= (int) $trip['capacity'] ?>" value=""
                                           placeholder="<?= $seatsBooked ?> booked">
                                    <p class="form-text">Leave blank to use the booked count.</p>
                                </div>

                                <div class="span-2">
                                    <label class="form-label" for="remarks">Note</label>
                                    <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255"
                                           placeholder="e.g. Heavy traffic near Bidadi, passengers informed">
                                </div>
                            </div>

                            <p class="form-text mt-3 mb-2">
                                Choose what happened. The next legal steps from
                                <strong><?= e(labelize((string) $trip['trip_status'])) ?></strong>:
                            </p>

                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($transitions as $status => $label): ?>
                                    <button type="submit" name="status" value="<?= e($status) ?>"
                                            class="btn <?= $status === 'cancelled' ? 'btn-outline-danger' : 'btn-primary' ?>"
                                            data-loading-text="Saving…">
                                        <i class="bi <?= e(trip_status_action_icon($status)) ?>" aria-hidden="true"></i>
                                        <?= e(trip_status_action_label($status)) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Trip status</h2>
                        <p class="card-fl__subtitle">Read-only for your account</p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <p class="form-text mb-0">
                        Only the assigned driver, a dispatcher, a transport manager or an administrator can change a
                        trip's status.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Trip timestamps</h2>
                    <p class="card-fl__subtitle">Created from its schedule</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Trip reference</span>
                        <span class="fact-list__value">TRP-<?= str_pad((string) $tripId, 4, '0', STR_PAD_LEFT) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Opened</span>
                        <span class="fact-list__value"><?= e(time_ago((string) $trip['created_at'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Last updated</span>
                        <span class="fact-list__value"><?= e(time_ago((string) $trip['updated_at'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Planned duration</span>
                        <span class="fact-list__value">
                            <?= e(format_duration((int) $trip['estimated_duration'])) ?>
                            <br><span class="cell-muted">
                                actual
                                <?= e(format_duration(time_range_duration((string) $trip['departure_time'], (string) $trip['arrival_time']))) ?>
                            </span>
                        </span>
                    </li>
                    <li>
                        <span class="fact-list__label">Base fare</span>
                        <span class="fact-list__value"><?= e(money($trip['base_fare'])) ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Status history</h2>
                    <p class="card-fl__subtitle">Recorded changes to this trip</p>
                </div>
            </div>

            <?php if ($history === []): ?>
                <?= empty_state('No status changes yet', 'Every status update is recorded here.', 'bi-clock-history') ?>
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
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
