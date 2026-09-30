<?php
/**
 * Fleetra — Tickets / Gate check
 * ------------------------------------------------------------------
 * modules/tickets/validate.php
 *
 * The boarding-gate screen. Staff enter (or scan into) a ticket code and
 * Fleetra verifies it against the signed ticket row before anyone is let
 * on board. Nothing is trusted from the printed value alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../bookings/_logic.php';

require_permission('tickets.manage');

$code   = get('code');
$result = null;
$error  = '';

if ($code !== '') {
    $result = resolve_ticket_input($code);

    if ($result === null) {
        $error = 'No ticket matches that code. Check the number and try again — a ticket that has been altered will not verify.';
    }
}

$todayTickets = db_all(
    'SELECT t.id, t.ticket_number, t.status, b.booking_number, b.seat_number,
            u.name AS passenger_name, s.departure_time,
            r.route_code, r.source, r.destination
       FROM tickets t
       JOIN bookings b  ON b.id = t.booking_id
       JOIN schedules s ON s.id = b.schedule_id
       JOIN routes r    ON r.id = s.route_id
       JOIN users u     ON u.id = b.user_id
      WHERE s.schedule_date = CURDATE()
      ORDER BY FIELD(t.status, "valid", "used", "cancelled", "expired"), s.departure_time'
);

$page_title       = 'Gate check';
$active_nav       = 'tickets';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Tickets', 'url' => url('modules/tickets/index.php')],
    ['label' => 'Gate check'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Gate check',
    'Verify a ticket before a passenger boards',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/tickets/index.php')) . '">
        <i class="bi bi-ticket-perforated" aria-hidden="true"></i> All tickets
     </a>'
) ?>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Verify a ticket</h2>
                    <p class="card-fl__subtitle">Enter the verification code printed on the ticket, or paste a scanned QR payload</p>
                </div>
            </div>

            <div class="card-fl__body">
                <form method="get" action="<?= e(url('modules/tickets/validate.php')) ?>" class="toolbar">
                    <div class="toolbar__filters">
                        <div class="search-field">
                            <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
                            <label class="visually-hidden" for="code">Ticket code</label>
                            <input type="text" class="form-control" id="code" name="code"
                                   value="<?= e($code) ?>" autofocus autocomplete="off"
                                   placeholder="TKT-0001-ab12cd34ef">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-shield-check" aria-hidden="true"></i> Verify ticket
                        </button>
                        <?php if ($code !== ''): ?>
                            <a class="btn btn-ghost" href="<?= e(url('modules/tickets/validate.php')) ?>">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger app-alert mt-3" role="alert">
                        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
                        <span class="app-alert__text"><?= e($error) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($result !== null): ?>
            <?php
            $resultStatus = (string) $result['ticket_status'];
            $isValid      = $resultStatus === 'valid';
            ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Verification result</h2>
                        <p class="card-fl__subtitle">Checked against the Fleetra ticket register</p>
                    </div>
                    <?= status_badge($resultStatus) ?>
                </div>

                <div class="card-fl__body">
                    <div class="alert alert-<?= $isValid ? 'success' : ($resultStatus === 'used' ? 'info' : 'danger') ?> app-alert" role="alert">
                        <i class="bi <?= $isValid ? 'bi-check-circle' : ($resultStatus === 'used' ? 'bi-info-circle' : 'bi-x-octagon') ?> app-alert__icon" aria-hidden="true"></i>
                        <span class="app-alert__text">
                            <?php if ($isValid): ?>
                                Valid ticket — passenger may board.
                            <?php elseif ($resultStatus === 'used'): ?>
                                This ticket has already been used. Do not admit the passenger twice.
                            <?php else: ?>
                                This ticket is <?= e(strtolower(labelize($resultStatus))) ?> and cannot be accepted for travel.
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="detail-grid mt-3">
                        <div class="detail-item">
                            <span class="detail-item__label">Ticket</span>
                            <span class="detail-item__value cell-mono"><?= e($result['ticket_number']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Passenger</span>
                            <span class="detail-item__value"><?= e($result['passenger_name']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Booking</span>
                            <span class="detail-item__value"><?= e($result['booking_number']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Route</span>
                            <span class="detail-item__value">
                                <?= e($result['route_code']) ?> · <?= e($result['route_name']) ?>
                                <br><span class="cell-muted"><?= e($result['source']) ?> &rarr; <?= e($result['destination']) ?></span>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Travel date</span>
                            <span class="detail-item__value"><?= e(format_date((string) $result['schedule_date'], 'D, d M Y')) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Departure</span>
                            <span class="detail-item__value"><?= e(format_time((string) $result['departure_time'])) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Seat</span>
                            <span class="detail-item__value"><?= e($result['seat_number']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Bus</span>
                            <span class="detail-item__value"><?= e($result['bus_number']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Boarding</span>
                            <span class="detail-item__value"><?= e($result['boarding_stop'] ?? $result['source']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Destination</span>
                            <span class="detail-item__value"><?= e($result['destination_stop'] ?? $result['destination']) ?></span>
                        </div>
                    </div>

                    <?php if ($isValid): ?>
                        <form method="post" action="<?= e(url('modules/tickets/status.php?id=' . (int) $result['id'])) ?>"
                              class="mt-4"
                              data-confirm="The ticket is stamped as used and cannot be scanned again."
                              data-confirm-title="Mark <?= e((string) $result['ticket_number']) ?> as used?"
                              data-confirm-button="Mark as used"
                              data-confirm-variant="primary">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="use">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Admit passenger (mark as used)
                            </button>
                            <a class="btn btn-outline-secondary"
                               href="<?= e(url('modules/tickets/view.php?ticket=' . urlencode((string) $result['ticket_number']))) ?>">
                                Open full ticket
                            </a>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Today's tickets</h2>
                    <p class="card-fl__subtitle">Quick admission for services departing today</p>
                </div>
                <span class="chip"><?= count($todayTickets) ?> issued</span>
            </div>

            <?php if ($todayTickets === []): ?>
                <?= empty_state('No tickets for today', 'Tickets appear here as soon as passengers book seats on today\'s services.', 'bi-ticket-perforated') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr><th>Ticket</th><th>Passenger</th><th>Seat</th><th>Status</th><th class="cell-actions">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayTickets as $row): ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong cell-mono"><?= e($row['ticket_number']) ?></span><br>
                                        <span class="cell-muted"><?= e($row['route_code']) ?> · <?= e(format_time($row['departure_time'])) ?></span>
                                    </td>
                                    <td><?= e($row['passenger_name']) ?></td>
                                    <td class="cell-num"><?= e($row['seat_number']) ?></td>
                                    <td><?= status_badge($row['status']) ?></td>
                                    <td class="cell-actions">
                                        <a class="row-action"
                                           href="<?= e(url('modules/tickets/view.php?ticket=' . urlencode((string) $row['ticket_number']))) ?>"
                                           title="Open ticket" aria-label="Open ticket <?= e($row['ticket_number']) ?>">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </a>
                                        <?php if ((string) $row['status'] === 'valid'): ?>
                                            <form method="post" action="<?= e(url('modules/tickets/status.php?id=' . (int) $row['id'])) ?>"
                                                  class="d-inline"
                                                  data-confirm="The ticket is stamped as used."
                                                  data-confirm-title="Mark <?= e((string) $row['ticket_number']) ?> as used?"
                                                  data-confirm-button="Mark as used"
                                                  data-confirm-variant="primary">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="use">
                                                <button type="submit" class="row-action row-action--success"
                                                        title="Mark as used" aria-label="Mark ticket <?= e($row['ticket_number']) ?> as used">
                                                    <i class="bi bi-check2" aria-hidden="true"></i>
                                                </button>
                                            </form>
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
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
