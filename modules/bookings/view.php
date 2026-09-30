<?php
/**
 * Fleetra — Bookings / Booking detail
 * ------------------------------------------------------------------
 * modules/bookings/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

$bookingId = get_int('id');

if ($bookingId <= 0) {
    abort_not_found('No booking was specified.');
}

$booking = find_booking($bookingId);

if ($booking === null) {
    abort_not_found('That booking does not exist.');
}

if (!booking_visible_to_current_user($booking)) {
    fleetra_log(
        'Permission denied: user #' . user_id() . ' tried to open booking #' . $bookingId,
        'WARNING'
    );

    fleetra_fatal('This booking belongs to another passenger.', 403);
}

$canManage = can('bookings.manage');
$isOwner   = (int) $booking['user_id'] === user_id();
$canCancel = booking_cancellable($booking) && ($canManage || ($isOwner && can('bookings.cancel')));

$payments = db_all(
    'SELECT id, transaction_reference, payment_method, amount, status, payment_date, created_at
       FROM payments WHERE booking_id = ? ORDER BY created_at DESC',
    [$bookingId]
);

$routeStops = db_all(
    'SELECT id, stop_name, stop_order, arrival_offset FROM stops WHERE route_id = ? ORDER BY stop_order',
    [(int) $booking['route_id']]
);

$departureTs = strtotime((string) $booking['schedule_date'] . ' ' . (string) $booking['departure_time']);
$hasDeparted = $departureTs !== false && $departureTs <= time();
$refundable  = (string) $booking['payment_status'] === 'paid';

$cancelForm = $canCancel
    ? '<form method="post" action="' . e(url('modules/bookings/cancel.php?id=' . $bookingId)) . '" class="d-inline"
              data-confirm="' . ($refundable
                    ? 'Seat ' . e((string) $booking['seat_number']) . ' is released for other passengers and the paid fare is refunded.'
                    : 'Seat ' . e((string) $booking['seat_number']) . ' is released for other passengers.')
              . '"
              data-confirm-title="Cancel ' . e((string) $booking['booking_number']) . '?"
              data-confirm-button="Cancel booking"
              data-confirm-variant="danger">'
        . csrf_field()
        . '<button type="submit" class="btn btn-outline-danger">
               <i class="bi bi-x-octagon" aria-hidden="true"></i> Cancel booking
           </button>
       </form>'
    : '';

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/bookings/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Bookings
            </a>';

if ($booking['ticket_number'] !== null) {
    $actions .= '<a class="btn btn-primary" href="'
        . e(url('modules/tickets/view.php?ticket=' . urlencode((string) $booking['ticket_number']))) . '">
            <i class="bi bi-ticket-perforated" aria-hidden="true"></i> Open ticket
        </a>';
}

$actions .= $cancelForm;

$page_title       = $booking['booking_number'];
$active_nav       = 'bookings';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Bookings', 'url' => url('modules/bookings/index.php')],
    ['label' => (string) $booking['booking_number']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $booking['booking_number'],
    $booking['route_code'] . ' · seat ' . $booking['seat_number'] . ' · '
        . format_date((string) $booking['schedule_date'], 'D, d M Y') . ' at '
        . format_time((string) $booking['departure_time']),
    $actions
) ?>

<?php if ((string) $booking['booking_status'] === 'cancelled'): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-x-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This booking was cancelled and the seat released.
            <?= $refundable ? ' The paid fare was refunded.' : '' ?>
        </span>
    </div>
<?php elseif ($hasDeparted && (string) $booking['booking_status'] === 'confirmed'): ?>
    <div class="alert alert-info app-alert" role="alert">
        <i class="bi bi-info-circle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This bus has already departed, so the booking can no longer be cancelled. It closes automatically once the
            trip is completed.
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('Seat', (string) $booking['seat_number'], 'bi-person-badge', 'primary', labelize((string) $booking['bus_type']) . ' · ' . (int) $booking['capacity'] . ' seats') ?>
    <?= stat_card('Fare', money($booking['fare']), 'bi-cash-coin', 'success', $booking['payment_status'] === 'paid' ? 'Paid' : labelize((string) $booking['payment_status'])) ?>
    <?= stat_card('Ticket', (string) ($booking['ticket_number'] ?? 'Not issued'), 'bi-ticket-perforated', $booking['ticket_number'] !== null ? 'info' : 'muted', $booking['ticket_number'] !== null ? labelize((string) $booking['ticket_status']) : 'No ticket') ?>
    <?= stat_card('Journey', format_duration(time_range_duration((string) $booking['departure_time'], (string) $booking['arrival_time'])), 'bi-stopwatch', 'info', number_format((float) $booking['distance'], 1) . ' km route') ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Journey details</h2>
                    <p class="card-fl__subtitle">Where and when you travel</p>
                </div>
                <div class="d-flex gap-2">
                    <?= status_badge($booking['booking_status']) ?>
                    <?= status_badge($booking['payment_status']) ?>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Route</span>
                        <span class="detail-item__value">
                            <?= e($booking['route_code']) ?> · <?= e($booking['route_name']) ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Travel date</span>
                        <span class="detail-item__value"><?= e(format_date((string) $booking['schedule_date'], 'D, d M Y')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Departs</span>
                        <span class="detail-item__value">
                            <?= e(format_time((string) $booking['departure_time'])) ?>
                            <br><span class="cell-muted"><?= e($booking['boarding_stop'] ?? $booking['source']) ?></span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Arrives</span>
                        <span class="detail-item__value">
                            <?= e(format_time((string) $booking['arrival_time'])) ?>
                            <br><span class="cell-muted"><?= e($booking['destination_stop'] ?? $booking['destination']) ?></span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Bus</span>
                        <span class="detail-item__value">
                            <?= e($booking['bus_number']) ?>
                            <br><span class="cell-muted"><?= e($booking['registration_number']) ?></span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Driver</span>
                        <span class="detail-item__value">
                            <?= e($booking['driver_name']) ?>
                            <br><span class="cell-muted"><?= e($booking['employee_id']) ?></span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Booked on</span>
                        <span class="detail-item__value"><?= e(format_date((string) $booking['created_at'], 'd M Y')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Last updated</span>
                        <span class="detail-item__value"><?= e(time_ago((string) $booking['updated_at'])) ?></span>
                    </div>
                    <?php if ($canManage): ?>
                        <div class="detail-item">
                            <span class="detail-item__label">Passenger</span>
                            <span class="detail-item__value">
                                <?= e($booking['passenger_name']) ?>
                                <br><span class="cell-muted">
                                    <?= e($booking['passenger_email']) ?>
                                    <?= $booking['passenger_phone'] !== null ? ' · ' . e($booking['passenger_phone']) : '' ?>
                                </span>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Schedule status</span>
                            <span class="detail-item__value"><?= status_badge($booking['schedule_status']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Payments</h2>
                    <p class="card-fl__subtitle">Every payment recorded against this booking</p>
                </div>
            </div>

            <?php if ($payments === []): ?>
                <?= empty_state('No payment recorded', 'A payment record is created with every booking.', 'bi-cash-coin') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Paid on</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td class="cell-mono"><?= e($payment['transaction_reference']) ?></td>
                                    <td><?= e(labelize((string) $payment['payment_method'])) ?></td>
                                    <td class="cell-num"><?= e(money($payment['amount'])) ?></td>
                                    <td>
                                        <?= $payment['payment_date'] !== null
                                            ? e(format_datetime((string) $payment['payment_date']))
                                            : '<span class="cell-muted">Awaiting collection</span>' ?>
                                    </td>
                                    <td><?= status_badge($payment['status']) ?></td>
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
                    <h2 class="card-fl__title">Ticket</h2>
                    <p class="card-fl__subtitle">Show this at the boarding gate</p>
                </div>
            </div>

            <?php if ($booking['ticket_number'] === null): ?>
                <?= empty_state('No ticket issued', 'Tickets are issued automatically with every booking.', 'bi-ticket-perforated') ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="fact-list">
                        <li>
                            <span class="fact-list__label">Ticket number</span>
                            <span class="fact-list__value cell-mono"><?= e($booking['ticket_number']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Status</span>
                            <span class="fact-list__value"><?= status_badge($booking['ticket_status']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Issued</span>
                            <span class="fact-list__value"><?= e(format_datetime((string) $booking['issued_at'])) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Verification code</span>
                            <span class="fact-list__value cell-mono">
                                <?= e(ticket_code((string) $booking['ticket_number'], (string) $booking['booking_number'])) ?>
                            </span>
                        </li>
                    </ul>

                    <a class="btn btn-primary w-100 mt-3"
                       href="<?= e(url('modules/tickets/view.php?ticket=' . urlencode((string) $booking['ticket_number']))) ?>">
                        <i class="bi bi-printer" aria-hidden="true"></i> View &amp; print ticket
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Stops on this route</h2>
                    <p class="card-fl__subtitle">Your boarding stop and destination are highlighted</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="timeline">
                    <?php foreach ($routeStops as $index => $stop): ?>
                        <?php
                        $dueAt   = time_to_minutes((string) $booking['departure_time']) + (int) $stop['arrival_offset'];
                        $isMine  = (int) $stop['id'] === (int) $booking['boarding_stop_id']
                            || (int) $stop['id'] === (int) $booking['destination_stop_id'];
                        $isStart = (int) $stop['id'] === (int) $booking['boarding_stop_id'];
                        $isEnd   = (int) $stop['id'] === (int) $booking['destination_stop_id'];
                        ?>
                        <li class="timeline__item<?= $isMine ? ' timeline__item--done' : '' ?>">
                            <p class="timeline__title">
                                <?= e($stop['stop_name']) ?>
                                <?php if ($isStart): ?><span class="badge-status badge-primary">You board</span><?php endif; ?>
                                <?php if ($isEnd): ?><span class="badge-status badge-success">You alight</span><?php endif; ?>
                            </p>
                            <p class="timeline__meta">Due <?= e(format_time(minutes_to_time($dueAt))) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Cancelling this booking</h2>
                        <p class="card-fl__subtitle">What happens to your seat and fare</p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <?php if ($canCancel): ?>
                        <p class="form-text mb-3">
                            The seat is released for other passengers immediately.
                            <?= $refundable
                                ? 'The ' . e(money($booking['fare'])) . ' you paid is marked refunded on the payment record.'
                                : 'Nothing has been collected for this seat, so there is nothing to refund.' ?>
                        </p>
                        <?= $cancelForm ?>
                    <?php else: ?>
                        <p class="form-text mb-0">
                            <?php if (in_array((string) $booking['booking_status'], ['cancelled'], true)): ?>
                                This booking has already been cancelled.
                            <?php elseif ($hasDeparted): ?>
                                The bus has left, so this seat can no longer be cancelled online. Speak to the conductor
                                or a Fleetra desk about a no-show.
                            <?php elseif ((string) $booking['schedule_status'] === 'cancelled'): ?>
                                The operator cancelled this service. Nothing further is needed from you.
                            <?php else: ?>
                                Your account cannot cancel this booking.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
