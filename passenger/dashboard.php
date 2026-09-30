<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * passenger/dashboard.php — Passenger home
 *
 * Shows the passenger's own data only: upcoming journeys, booking
 * history and notifications. Bus search and seat selection are part of
 * the passenger booking phase and are surfaced once that module ships.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

require_role('passenger');

$user   = current_user();
$userId = user_id();

$upcoming = db_all(
    'SELECT b.id, b.booking_number, b.seat_number, b.fare, b.booking_status, b.payment_status,
            s.schedule_date, s.departure_time, s.arrival_time,
            r.route_code, r.route_name, r.source, r.destination, r.distance,
            bs.stop_name AS boarding_stop, ds.stop_name AS destination_stop,
            bus.bus_number, bus.bus_type, bus.capacity,
            du.name AS driver_name, du.phone AS driver_phone
       FROM bookings b
       JOIN schedules s ON s.id = b.schedule_id
       JOIN routes r    ON r.id = s.route_id
       JOIN buses bus   ON bus.id = s.bus_id
       JOIN drivers d   ON d.id = s.driver_id
       JOIN users du    ON du.id = d.user_id
       LEFT JOIN stops bs ON bs.id = b.boarding_stop_id
       LEFT JOIN stops ds ON ds.id = b.destination_stop_id
      WHERE b.user_id = ?
        AND b.booking_status IN ("pending", "confirmed")
        AND s.schedule_date >= CURDATE()
      ORDER BY s.schedule_date, s.departure_time
      LIMIT 5',
    [$userId]
);

$history = db_all(
    'SELECT b.id, b.booking_number, b.seat_number, b.fare, b.booking_status, b.payment_status, b.booking_date,
            s.schedule_date, s.departure_time,
            r.route_code, r.source, r.destination
       FROM bookings b
       JOIN schedules s ON s.id = b.schedule_id
       JOIN routes r    ON r.id = s.route_id
      WHERE b.user_id = ?
      ORDER BY b.created_at DESC
      LIMIT 6',
    [$userId]
);

$notifications = db_all(
    'SELECT id, title, message, notification_type, is_read, created_at
       FROM notifications
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 5',
    [$userId]
);

$totalBookings  = (int) db_value('SELECT COUNT(*) FROM bookings WHERE user_id = ?', [$userId], 0);
$ticketsIssued  = (int) db_value(
    'SELECT COUNT(*) FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE b.user_id = ?',
    [$userId],
    0
);
$unreadCount    = unread_notification_count($userId);
$totalSpend     = (float) db_value(
    "SELECT COALESCE(SUM(fare), 0) FROM bookings
      WHERE user_id = ? AND booking_status <> 'cancelled'",
    [$userId],
    0
);

$nextJourney = $upcoming[0] ?? null;

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
    'Your journeys with Fleetra',
    '<span class="chip">Unread alerts <strong>' . $unreadCount . '</strong></span>'
) ?>

<div class="stat-grid">
    <?= stat_card('Upcoming journeys', count($upcoming), 'bi-calendar-event', 'primary', 'Confirmed and pending bookings') ?>
    <?= stat_card('Total bookings', $totalBookings, 'bi-journal-check', 'info', 'All time') ?>
    <?= stat_card('Tickets issued', $ticketsIssued, 'bi-ticket-perforated', 'success', 'Ready to travel') ?>
    <?= stat_card('Total spend', money($totalSpend), 'bi-cash-stack', 'muted', 'Excluding cancelled bookings') ?>
</div>

<div class="alert alert-info app-alert" role="alert">
    <i class="bi bi-info-circle app-alert__icon" aria-hidden="true"></i>
    <span class="app-alert__text">
        Bus search and seat booking arrive in the next development phase. Your existing bookings,
        tickets and journey details are already live here.
    </span>
</div>

<?php if ($nextJourney !== null): ?>
    <div class="focus-card">
        <div class="section-head">
            <div>
                <h2 class="section-title">Your next journey</h2>
                <p class="section-subtitle"><?= e($nextJourney['booking_number']) ?> &middot; <?= status_badge($nextJourney['booking_status']) ?></p>
            </div>
            <span class="chip">Seat <strong><?= e($nextJourney['seat_number']) ?></strong></span>
        </div>

        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-item__label">Route</span>
                <span class="detail-item__value"><?= e($nextJourney['route_code'] . ' · ' . $nextJourney['route_name']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-item__label">From</span>
                <span class="detail-item__value"><?= e($nextJourney['boarding_stop'] ?? $nextJourney['source']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-item__label">To</span>
                <span class="detail-item__value"><?= e($nextJourney['destination_stop'] ?? $nextJourney['destination']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-item__label">Travel date</span>
                <span class="detail-item__value"><?= e(format_date($nextJourney['schedule_date'], 'd M Y')) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-item__label">Departure</span>
                <span class="detail-item__value"><?= e(format_time($nextJourney['departure_time'])) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-item__label">Bus</span>
                <span class="detail-item__value"><?= e($nextJourney['bus_number']) ?> · <?= e(labelize((string) $nextJourney['bus_type'])) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-item__label">Driver</span>
                <span class="detail-item__value"><?= e($nextJourney['driver_name']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-item__label">Fare paid</span>
                <span class="detail-item__value"><?= e(money($nextJourney['fare'])) ?></span>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card-fl">
    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title">Upcoming journeys</h2>
            <p class="card-fl__subtitle">Bookings for today and later</p>
        </div>
    </div>

    <?php if ($upcoming === []): ?>
        <?= empty_state(
            'No upcoming journeys',
            'When you book a seat, your journey details will appear here with the bus, driver and boarding point.',
            'bi-calendar-event'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Travel date</th>
                        <th>Route</th>
                        <th>Bus</th>
                        <th>Seat</th>
                        <th>Fare</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcoming as $booking): ?>
                        <tr>
                            <td>
                                <span class="cell-strong"><?= e(format_date($booking['schedule_date'], 'd M Y')) ?></span><br>
                                <span class="cell-muted"><?= e(format_time($booking['departure_time'])) ?></span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($booking['route_code']) ?></span><br>
                                <span class="cell-muted"><?= e($booking['source']) ?> &rarr; <?= e($booking['destination']) ?></span>
                            </td>
                            <td>
                                <span class="cell-strong"><?= e($booking['bus_number']) ?></span><br>
                                <span class="cell-muted"><?= e($booking['driver_name']) ?></span>
                            </td>
                            <td><?= e($booking['seat_number']) ?></td>
                            <td class="cell-num"><?= e(money($booking['fare'])) ?></td>
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

<div class="grid-2">
    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Booking history</h2>
                <p class="card-fl__subtitle">Your most recent reservations</p>
            </div>
        </div>

        <?php if ($history === []): ?>
            <?= empty_state('No bookings yet', 'Your booking history will build up here.', 'bi-journal-check') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr><th>Booking</th><th>Journey</th><th>Seat</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $booking): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e($booking['booking_number']) ?></span><br>
                                    <span class="cell-muted"><?= e(format_date($booking['booking_date'], 'd M Y')) ?></span>
                                </td>
                                <td>
                                    <span class="cell-strong"><?= e($booking['route_code']) ?></span><br>
                                    <span class="cell-muted"><?= e(format_date($booking['schedule_date'], 'd M')) ?></span>
                                </td>
                                <td><?= e($booking['seat_number']) ?></td>
                                <td><?= status_badge($booking['booking_status']) ?></td>
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
                <h2 class="card-fl__title">Notifications</h2>
                <p class="card-fl__subtitle">Trip updates, delays and booking alerts</p>
            </div>
            <?php if ($unreadCount > 0): ?>
                <span class="chip">Unread <strong><?= $unreadCount ?></strong></span>
            <?php endif; ?>
        </div>

        <?php if ($notifications === []): ?>
            <?= empty_state('No notifications', 'Trip updates and booking confirmations will appear here.', 'bi-bell') ?>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
