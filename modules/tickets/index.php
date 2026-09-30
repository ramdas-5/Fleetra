<?php
/**
 * Fleetra — Tickets
 * ------------------------------------------------------------------
 * modules/tickets/index.php
 *
 * Every booking issues exactly one ticket. Passengers see the tickets on
 * their own bookings; administrators (tickets.manage) see the whole
 * register and can open the gate-check screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../bookings/_logic.php';

if (!can('tickets.own') && !can('tickets.manage')) {
    require_permission('tickets.own');
}

$seesEveryTicket = can('tickets.manage');

$search       = get('q');
$statusFilter = get('status');
$dateFrom     = get('date_from');
$dateTo       = get('date_to');

$where  = [];
$params = [];

if (!$seesEveryTicket) {
    $where[]  = 'b.user_id = ?';
    $params[] = user_id();
}

if ($search !== '') {
    $where[] = '(t.ticket_number LIKE ? OR b.booking_number LIKE ? OR b.seat_number LIKE ? OR u.name LIKE ? OR r.route_code LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

if (is_valid_option(['valid' => '', 'used' => '', 'cancelled' => '', 'expired' => ''], $statusFilter)) {
    $where[]  = 't.status = ?';
    $params[] = $statusFilter;
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

$joins = 'FROM tickets t
          JOIN bookings b  ON b.id = t.booking_id
          JOIN schedules s ON s.id = b.schedule_id
          JOIN routes r    ON r.id = s.route_id
          JOIN buses bu    ON bu.id = s.bus_id
          JOIN users u     ON u.id = b.user_id';

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params, 0);
$page  = paginate($total, 15);

$tickets = db_all(
    "SELECT t.id, t.ticket_number, t.issued_at, t.status AS ticket_status, t.qr_code,
            b.id AS booking_id, b.booking_number, b.seat_number, b.fare,
            b.booking_status, b.payment_status,
            s.schedule_date, s.departure_time, s.arrival_time,
            r.route_code, r.route_name, r.source, r.destination,
            bu.bus_number, bu.bus_type, bu.capacity,
            u.name AS passenger_name
       $joins
       $whereSql
      ORDER BY s.schedule_date DESC, s.departure_time DESC, t.id DESC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

/** Headline numbers, scoped the same way as the list. */
$scopeWhere  = $seesEveryTicket ? '' : ' AND b.user_id = ?';
$scopeParams = $seesEveryTicket ? [] : [user_id()];

$validCount = (int) db_value(
    "SELECT COUNT(*) FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE t.status = 'valid'$scopeWhere",
    $scopeParams,
    0
);
$usedCount = (int) db_value(
    "SELECT COUNT(*) FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE t.status = 'used'$scopeWhere",
    $scopeParams,
    0
);
$voidCount = (int) db_value(
    "SELECT COUNT(*) FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE t.status IN ('cancelled','expired')$scopeWhere",
    $scopeParams,
    0
);
$travelValue = (float) db_value(
    "SELECT COALESCE(SUM(b.fare), 0) FROM tickets t JOIN bookings b ON b.id = t.booking_id
      WHERE t.status IN ('valid','used')$scopeWhere",
    $scopeParams,
    0
);

$hasFilters = $search !== '' || $statusFilter !== ''
    || is_valid_date($dateFrom) || is_valid_date($dateTo);

$page_title       = 'Tickets';
$active_nav       = 'tickets';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Passenger services'],
    ['label' => 'Tickets'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $seesEveryTicket ? 'Tickets' : 'My tickets',
    $total . ' ticket' . ($total === 1 ? '' : 's') . ($seesEveryTicket ? ' issued across the network' : ' on your bookings'),
    $seesEveryTicket
        ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/tickets/validate.php')) . '">
               <i class="bi bi-qr-code-scan" aria-hidden="true"></i> Gate check
           </a>'
        : ''
) ?>

<div class="stat-grid">
    <?= stat_card('Valid tickets', $validCount, 'bi-check2-circle', $validCount > 0 ? 'success' : 'muted', 'Ready to travel') ?>
    <?= stat_card('Used', $usedCount, 'bi-person-check', 'info', 'Already scanned at the gate') ?>
    <?= stat_card('Cancelled / expired', $voidCount, 'bi-x-circle', $voidCount > 0 ? 'danger' : 'muted', 'No longer travel-valid') ?>
    <?= stat_card('Value of tickets', money($travelValue), 'bi-cash-stack', 'primary', 'Valid and used tickets only') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/tickets/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search tickets</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Ticket, booking, seat, passenger or route...">
            </div>

            <label class="visually-hidden" for="status">Ticket status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(
                    [
                        'valid'     => 'Valid',
                        'used'      => 'Used',
                        'cancelled' => 'Cancelled',
                        'expired'   => 'Expired',
                    ],
                    $statusFilter,
                    'All ticket statuses'
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
                <a class="btn btn-ghost" href="<?= e(url('modules/tickets/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($tickets === []): ?>
        <?= empty_state(
            $hasFilters ? 'No tickets match these filters' : 'No tickets yet',
            $hasFilters
                ? 'Try a different search term, or clear the filters to see every ticket.'
                : 'A ticket is issued automatically for every seat you book.',
            'bi-ticket-perforated',
            $hasFilters
                ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/tickets/index.php')) . '">Show all tickets</a>'
                : (can('bookings.create')
                    ? '<a class="btn btn-primary" href="' . e(url('modules/search/index.php')) . '">Find a bus</a>'
                    : '')
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <?php if ($seesEveryTicket): ?><th>Passenger</th><?php endif; ?>
                        <th>Journey</th>
                        <th>Seat</th>
                        <th>Fare</th>
                        <th>Issued</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td>
                                <span class="cell-strong cell-mono"><?= e($ticket['ticket_number']) ?></span><br>
                                <span class="cell-muted"><?= e($ticket['booking_number']) ?></span>
                            </td>
                            <?php if ($seesEveryTicket): ?>
                                <td><?= e($ticket['passenger_name']) ?></td>
                            <?php endif; ?>
                            <td>
                                <span class="cell-strong"><?= e($ticket['route_code']) ?> · <?= e($ticket['bus_number']) ?></span><br>
                                <span class="cell-muted">
                                    <?= e($ticket['source']) ?> &rarr; <?= e($ticket['destination']) ?><br>
                                    <?= e(format_date($ticket['schedule_date'], 'd M Y')) ?>
                                    · <?= e(format_time($ticket['departure_time'])) ?>
                                </span>
                            </td>
                            <td class="cell-num"><?= e($ticket['seat_number']) ?></td>
                            <td class="cell-num"><?= e(money($ticket['fare'])) ?></td>
                            <td>
                                <?= e(format_date((string) $ticket['issued_at'], 'd M Y')) ?><br>
                                <span class="cell-muted"><?= e(time_ago((string) $ticket['issued_at'])) ?></span>
                            </td>
                            <td><?= status_badge($ticket['ticket_status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action"
                                   href="<?= e(url('modules/tickets/view.php?ticket=' . urlencode((string) $ticket['ticket_number']))) ?>"
                                   title="Open ticket" aria-label="Open ticket <?= e($ticket['ticket_number']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <a class="row-action"
                                   href="<?= e(url('modules/bookings/view.php?id=' . (int) $ticket['booking_id'])) ?>"
                                   title="Open booking" aria-label="Open booking <?= e($ticket['booking_number']) ?>">
                                    <i class="bi bi-journal-check" aria-hidden="true"></i>
                                </a>
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
