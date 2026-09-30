<?php
/**
 * Fleetra — Bookings
 * ------------------------------------------------------------------
 * modules/bookings/index.php
 *
 * Passengers see their own bookings; dispatch, managers and administrators
 * see every booking in the system.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

if (!can('bookings.view') && !can('bookings.manage') && !can('bookings.own')) {
    require_permission('bookings.view');
}

$seesEveryBooking = can('bookings.view') || can('bookings.manage');

$search        = get('q');
$statusFilter  = get('status');
$paymentFilter = get('payment_status');
$dateFrom      = get('date_from');
$dateTo        = get('date_to');

$where  = [];
$params = [];

if (!$seesEveryBooking) {
    $where[]  = 'b.user_id = ?';
    $params[] = user_id();
}

if ($search !== '') {
    $where[] = '(b.booking_number LIKE ? OR b.seat_number LIKE ? OR u.name LIKE ? OR r.route_code LIKE ? OR r.route_name LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

if (is_valid_option(['pending' => '', 'confirmed' => '', 'completed' => '', 'cancelled' => '', 'no_show' => ''], $statusFilter)) {
    $where[]  = 'b.booking_status = ?';
    $params[] = $statusFilter;
}

if (is_valid_option(['unpaid' => '', 'pending' => '', 'paid' => '', 'refunded' => '', 'failed' => ''], $paymentFilter)) {
    $where[]  = 'b.payment_status = ?';
    $params[] = $paymentFilter;
}

if (is_valid_date($dateFrom)) {
    $where[]  = 's.schedule_date >= ?';
    $params[] = $dateFrom;
}

if (is_valid_date($dateTo)) {
    $where[]  = 's.schedule_date <= ?';
    $params[] = $dateTo;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$joins = 'FROM bookings b
          JOIN schedules s ON s.id = b.schedule_id
          JOIN routes r    ON r.id = s.route_id
          JOIN buses bu    ON bu.id = s.bus_id
          JOIN users u     ON u.id = b.user_id';

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params, 0);
$page  = paginate($total, 15);

$bookings = db_all(
    "SELECT b.*, s.schedule_date, s.departure_time, s.arrival_time, s.status AS schedule_status,
            r.route_code, r.route_name, r.source, r.destination,
            bu.bus_number, bu.capacity,
            u.name AS passenger_name, u.phone AS passenger_phone,
            bs.stop_name AS boarding_stop, ds.stop_name AS destination_stop,
            t.ticket_number, t.status AS ticket_status
       $joins
       LEFT JOIN stops bs ON bs.id = b.boarding_stop_id
       LEFT JOIN stops ds ON ds.id = b.destination_stop_id
       LEFT JOIN tickets t ON t.booking_id = b.id
       $whereSql
      ORDER BY s.schedule_date DESC, s.departure_time DESC, b.id DESC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

/** Headline numbers, scoped the same way as the list. */
$scopeWhere  = $seesEveryBooking ? '' : ' AND b.user_id = ?';
$scopeParams = $seesEveryBooking ? [] : [user_id()];

$upcomingCount = (int) db_value(
    "SELECT COUNT(*) FROM bookings b JOIN schedules s ON s.id = b.schedule_id
      WHERE b.booking_status IN ('pending','confirmed')
        AND TIMESTAMP(s.schedule_date, s.departure_time) > NOW()$scopeWhere",
    $scopeParams,
    0
);
$confirmedSeats = (int) db_value(
    "SELECT COUNT(*) FROM bookings b WHERE b.booking_status = 'confirmed'$scopeWhere",
    $scopeParams,
    0
);
$collected = (float) db_value(
    "SELECT COALESCE(SUM(b.fare), 0) FROM bookings b WHERE b.payment_status = 'paid'$scopeWhere",
    $scopeParams,
    0
);
$cancelledCount = (int) db_value(
    "SELECT COUNT(*) FROM bookings b WHERE b.booking_status = 'cancelled'$scopeWhere",
    $scopeParams,
    0
);

$hasFilters = $search !== '' || $statusFilter !== '' || $paymentFilter !== ''
    || is_valid_date($dateFrom) || is_valid_date($dateTo);

$page_title       = 'Bookings';
$active_nav       = 'bookings';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Passenger services'],
    ['label' => 'Bookings'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $seesEveryBooking ? 'Bookings' : 'My bookings',
    $total . ' booking' . ($total === 1 ? '' : 's') . ($seesEveryBooking ? ' matching the current view' : ' on your account'),
    can('bookings.create')
        ? '<a class="btn btn-primary" href="' . e(url('modules/search/index.php')) . '">
               <i class="bi bi-search" aria-hidden="true"></i> Book a seat
           </a>' . (can('tickets.own') || can('tickets.manage')
                ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/tickets/index.php')) . '">
                       <i class="bi bi-ticket-perforated" aria-hidden="true"></i> My tickets
                   </a>'
                : '')
        : ''
) ?>

<div class="stat-grid">
    <?= stat_card('Upcoming journeys', $upcomingCount, 'bi-calendar-check', $upcomingCount > 0 ? 'primary' : 'muted', 'Not yet departed') ?>
    <?= stat_card('Confirmed seats', $confirmedSeats, 'bi-person-check', 'info', $seesEveryBooking ? 'Across all passengers' : 'On your bookings') ?>
    <?= stat_card($seesEveryBooking ? 'Revenue collected' : 'Fare paid', money($collected), 'bi-cash-coin', 'success', 'Paid bookings only') ?>
    <?= stat_card('Cancelled', $cancelledCount, 'bi-x-circle', $cancelledCount > 0 ? 'danger' : 'muted', 'Released seats') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/bookings/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search bookings</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Booking number, seat, passenger or route...">
            </div>

            <label class="visually-hidden" for="status">Booking status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(
                    [
                        'pending'   => 'Pending',
                        'confirmed' => 'Confirmed',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'no_show'   => 'No show',
                    ],
                    $statusFilter,
                    'All booking statuses'
                ) ?>
            </select>

            <label class="visually-hidden" for="payment_status">Payment status</label>
            <select class="form-select" id="payment_status" name="payment_status" style="width:auto;">
                <?= option_tags(
                    [
                        'unpaid'   => 'Unpaid',
                        'pending'  => 'Payment pending',
                        'paid'     => 'Paid',
                        'refunded' => 'Refunded',
                        'failed'   => 'Failed',
                    ],
                    $paymentFilter,
                    'All payment statuses'
                ) ?>
            </select>

            <label class="visually-hidden" for="date_from">Travel date from</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>" style="width:auto;">

            <label class="visually-hidden" for="date_to">Travel date to</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>" style="width:auto;">

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/bookings/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($bookings === []): ?>
        <?= empty_state(
            $hasFilters ? 'No bookings match these filters' : 'No bookings yet',
            $hasFilters
                ? 'Try a different search term, or clear the filters to see every booking.'
                : 'Search for a service and pick your seats to make the first booking.',
            'bi-journal-check',
            can('bookings.create')
                ? '<a class="btn btn-primary" href="' . e(url('modules/search/index.php')) . '">Find a bus</a>'
                : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <?php if ($seesEveryBooking): ?><th>Passenger</th><?php endif; ?>
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
                            <?php if ($seesEveryBooking): ?>
                                <td>
                                    <?= e($booking['passenger_name']) ?><br>
                                    <span class="cell-muted"><?= e($booking['passenger_phone'] ?? '—') ?></span>
                                </td>
                            <?php endif; ?>
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
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/bookings/view.php?id=' . (int) $booking['id'])) ?>"
                                   title="View booking" aria-label="View booking <?= e($booking['booking_number']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <?php if ($booking['ticket_number'] !== null): ?>
                                    <a class="row-action" href="<?= e(url('modules/tickets/view.php?ticket=' . urlencode((string) $booking['ticket_number']))) ?>"
                                       title="Open ticket" aria-label="Open ticket">
                                        <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= render_pagination($page) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
