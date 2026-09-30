<?php
/**
 * Fleetra — Passengers / Passenger detail
 * ------------------------------------------------------------------
 * modules/passengers/view.php
 *
 * Everything Fleetra holds about one passenger: their profile, booking
 * history, payments and the notifications they were sent.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../bookings/_logic.php';

require_permission('passengers.view');

$passengerId = get_int('id');

if ($passengerId <= 0) {
    abort_not_found('No passenger was specified.');
}

$passenger = db_one(
    "SELECT id, name, email, phone, profile_image, status, last_login, created_at, updated_at
       FROM users
      WHERE id = ? AND role = 'passenger'
      LIMIT 1",
    [$passengerId]
);

if ($passenger === null) {
    abort_not_found('That passenger account does not exist.');
}

$totals = db_one(
    "SELECT COUNT(*) AS booking_count,
            COALESCE(SUM(CASE WHEN booking_status IN ('confirmed','completed') THEN 1 END), 0) AS active_count,
            COALESCE(SUM(CASE WHEN booking_status = 'cancelled' THEN 1 END), 0) AS cancelled_count,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN fare END), 0) AS spend,
            COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN fare END), 0) AS refunded
       FROM bookings
      WHERE user_id = ?",
    [$passengerId]
) ?? [];

$bookings = db_all(
    'SELECT b.id, b.booking_number, b.seat_number, b.fare, b.booking_status, b.payment_status, b.booking_date,
            s.schedule_date, s.departure_time, s.arrival_time,
            r.route_code, r.route_name, r.source, r.destination,
            bu.bus_number, t.ticket_number, t.status AS ticket_status
       FROM bookings b
       JOIN schedules s ON s.id = b.schedule_id
       JOIN routes r    ON r.id = s.route_id
       JOIN buses bu    ON bu.id = s.bus_id
       LEFT JOIN tickets t ON t.booking_id = b.id
      WHERE b.user_id = ?
      ORDER BY s.schedule_date DESC, s.departure_time DESC, b.id DESC
      LIMIT 25',
    [$passengerId]
);

$notifications = db_all(
    'SELECT id, title, message, notification_type, is_read, created_at
       FROM notifications
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 6',
    [$passengerId]
);

$upcoming = (int) db_value(
    "SELECT COUNT(*) FROM bookings b JOIN schedules s ON s.id = b.schedule_id
      WHERE b.user_id = ? AND b.booking_status IN ('pending','confirmed')
        AND TIMESTAMP(s.schedule_date, s.departure_time) > NOW()",
    [$passengerId],
    0
);

$bookingCount   = (int) ($totals['booking_count'] ?? 0);
$activeCount    = (int) ($totals['active_count'] ?? 0);
$cancelledCount = (int) ($totals['cancelled_count'] ?? 0);
$spend          = (float) ($totals['spend'] ?? 0);
$refunded       = (float) ($totals['refunded'] ?? 0);

$page_title       = $passenger['name'];
$active_nav       = 'passengers';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Passengers', 'url' => url('modules/passengers/index.php')],
    ['label' => (string) $passenger['name']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    (string) $passenger['name'],
    'Passenger since ' . format_date((string) $passenger['created_at'], 'd M Y')
        . ' · ' . $bookingCount . ' booking' . ($bookingCount === 1 ? '' : 's'),
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/passengers/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Passengers
     </a>
     <a class="btn btn-outline-secondary" href="' . e(url('modules/bookings/index.php?q=' . urlencode((string) $passenger['name']))) . '">
        <i class="bi bi-journal-check" aria-hidden="true"></i> All bookings
     </a>'
) ?>

<div class="stat-grid">
    <?= stat_card('Total bookings', $bookingCount, 'bi-journal-text', 'primary', 'Every reservation made') ?>
    <?= stat_card('Upcoming journeys', $upcoming, 'bi-calendar-check', $upcoming > 0 ? 'info' : 'muted', 'Not yet departed') ?>
    <?= stat_card('Cancelled', $cancelledCount, 'bi-x-circle', $cancelledCount > 0 ? 'danger' : 'muted', 'Released seats') ?>
    <?= stat_card('Fare paid', money($spend), 'bi-cash-coin', 'success', $refunded > 0 ? money($refunded) . ' refunded' : 'No refunds') ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Booking history</h2>
                    <p class="card-fl__subtitle">Most recent 25 reservations</p>
                </div>
            </div>

            <?php if ($bookings === []): ?>
                <?= empty_state('No bookings yet', 'This passenger has not booked a seat on any Fleetra service.', 'bi-journal-check') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                <th>Journey</th>
                                <th>Seat</th>
                                <th>Fare</th>
                                <th>Ticket</th>
                                <th>Status</th>
                                <th class="cell-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong"><?= e($booking['booking_number']) ?></span><br>
                                        <span class="cell-muted"><?= e(format_date($booking['booking_date'], 'd M Y')) ?></span>
                                    </td>
                                    <td>
                                        <span class="cell-strong"><?= e($booking['route_code']) ?> · <?= e($booking['bus_number']) ?></span><br>
                                        <span class="cell-muted">
                                            <?= e($booking['source']) ?> &rarr; <?= e($booking['destination']) ?><br>
                                            <?= e(format_date($booking['schedule_date'], 'd M Y')) ?>
                                            · <?= e(format_time($booking['departure_time'])) ?>
                                        </span>
                                    </td>
                                    <td class="cell-num"><?= e($booking['seat_number']) ?></td>
                                    <td class="cell-num"><?= e(money($booking['fare'])) ?></td>
                                    <td>
                                        <?php if ($booking['ticket_number'] !== null): ?>
                                            <a class="cell-mono" href="<?= e(url('modules/tickets/view.php?ticket=' . urlencode((string) $booking['ticket_number']))) ?>">
                                                <?= e($booking['ticket_number']) ?>
                                            </a><br>
                                            <?= status_badge($booking['ticket_status']) ?>
                                        <?php else: ?>
                                            <span class="cell-muted">Not issued</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= status_badge($booking['booking_status']) ?><br>
                                        <?= status_badge($booking['payment_status']) ?>
                                    </td>
                                    <td class="cell-actions">
                                        <a class="row-action" href="<?= e(url('modules/bookings/view.php?id=' . (int) $booking['id'])) ?>"
                                           title="Open booking" aria-label="Open booking <?= e($booking['booking_number']) ?>">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($bookingCount > count($bookings)): ?>
                    <div class="table-foot">
                        <span class="table-foot__info">
                            Showing the latest <?= count($bookings) ?> of <?= $bookingCount ?> bookings.
                            <a href="<?= e(url('modules/bookings/index.php?q=' . urlencode((string) $passenger['name']))) ?>">Open the full booking register</a>.
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Account</h2>
                    <p class="card-fl__subtitle">Profile and contact details</p>
                </div>
                <?= status_badge($passenger['status']) ?>
            </div>

            <div class="card-fl__body">
                <div class="profile-head">
                    <?= avatar_markup($passenger, 'lg') ?>
                    <div>
                        <p class="profile-head__name"><?= e($passenger['name']) ?></p>
                        <p class="profile-head__meta"><?= e(role_label('passenger')) ?> · #<?= (int) $passenger['id'] ?></p>
                    </div>
                </div>

                <ul class="fact-list mt-3">
                    <li>
                        <span class="fact-list__label">Email</span>
                        <span class="fact-list__value"><?= e($passenger['email']) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Phone</span>
                        <span class="fact-list__value"><?= e($passenger['phone'] ?? '—') ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Registered</span>
                        <span class="fact-list__value"><?= e(format_date((string) $passenger['created_at'], 'd M Y')) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Last sign-in</span>
                        <span class="fact-list__value">
                            <?= $passenger['last_login'] !== null ? e(time_ago((string) $passenger['last_login'])) : 'Never' ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Notifications</h2>
                    <p class="card-fl__subtitle">Latest messages sent to this passenger</p>
                </div>
            </div>

            <?php if ($notifications === []): ?>
                <?= empty_state('No notifications', 'Booking confirmations and trip updates will appear here.', 'bi-bell') ?>
            <?php else: ?>
                <div class="card-fl__body stack-sm">
                    <?php foreach ($notifications as $notification): ?>
                        <article class="alert-item<?= (int) $notification['is_read'] === 0 ? ' alert-item--info' : '' ?>">
                            <div class="alert-item__head">
                                <span class="alert-item__title"><?= e($notification['title']) ?></span>
                                <?= status_badge($notification['notification_type']) ?>
                            </div>
                            <p class="alert-item__text"><?= e(truncate($notification['message'], 140)) ?></p>
                            <p class="alert-item__meta"><?= e(time_ago($notification['created_at'])) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
