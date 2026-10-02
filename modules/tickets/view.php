<?php
/**
 * Fleetra — Tickets / Ticket detail
 * ------------------------------------------------------------------
 * modules/tickets/view.php?ticket=TKT-0001
 *
 * Renders the printable travel ticket with its QR verification code.
 * Passengers may open their own tickets; staff holding tickets.manage can
 * open any ticket and change its status for gate control.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../bookings/_logic.php';

$ticketNumber = get('ticket');

if ($ticketNumber === '') {
    abort_not_found('No ticket was specified.');
}

$ticket = find_ticket_by_number($ticketNumber);

if ($ticket === null) {
    abort_not_found('That ticket does not exist.');
}

$canManage = can('tickets.manage');
$isOwner   = (int) $ticket['passenger_id'] === user_id();

if (!$canManage && !($isOwner && can('tickets.own'))) {
    fleetra_log('Permission denied: user #' . user_id() . ' tried to open ticket ' . $ticketNumber, 'WARNING');

    fleetra_fatal('This ticket belongs to another passenger.', 403);
}

$ticketCode = ticket_code((string) $ticket['ticket_number'], (string) $ticket['booking_number']);
$qrPayload  = (string) ($ticket['qr_code'] ?? '');

if ($qrPayload === '') {
    $qrPayload = ticket_payload((string) $ticket['ticket_number'], (string) $ticket['booking_number']);
}

$departureTs = strtotime((string) $ticket['schedule_date'] . ' ' . (string) $ticket['departure_time']);
$hasDeparted = $departureTs !== false && $departureTs <= time();
$status      = (string) $ticket['ticket_status'];

/* --------------------------------------------------------------
 | Status actions (staff only)
 -------------------------------------------------------------- */

$pdfUrl = url('modules/tickets/pdf.php?ticket=' . urlencode($ticketNumber) . '&download=1');

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/tickets/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Tickets
            </a>
            <a class="btn btn-outline-secondary" href="' . e($pdfUrl) . '">
                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Download PDF
            </a>
            <button type="button" class="btn btn-primary" data-print>
                <i class="bi bi-printer" aria-hidden="true"></i> Print ticket
            </button>';

if ($canManage && $status === 'valid') {
    $actions .= '<form method="post" action="' . e(url('modules/tickets/status.php?id=' . (int) $ticket['id'])) . '" class="d-inline"
              data-confirm="The ticket is stamped as used and cannot be scanned again."
              data-confirm-title="Mark ' . e((string) $ticket['ticket_number']) . ' as used?"
              data-confirm-button="Mark as used"
              data-confirm-variant="primary">'
        . csrf_field()
        . '<input type="hidden" name="action" value="use">
           <button type="submit" class="btn btn-outline-success">
               <i class="bi bi-check2-circle" aria-hidden="true"></i> Mark as used
           </button>
       </form>';

    $actions .= '<form method="post" action="' . e(url('modules/tickets/status.php?id=' . (int) $ticket['id'])) . '" class="d-inline"
              data-confirm="The ticket is cancelled and can no longer be used to travel."
              data-confirm-title="Void ' . e((string) $ticket['ticket_number']) . '?"
              data-confirm-button="Void ticket">'
        . csrf_field()
        . '<input type="hidden" name="action" value="void">
           <button type="submit" class="btn btn-outline-danger">
               <i class="bi bi-x-octagon" aria-hidden="true"></i> Void
           </button>
       </form>';
}

$page_title       = 'Ticket ' . $ticket['ticket_number'];
$active_nav       = 'tickets';
$extra_js         = [asset('vendor/qrcode/qrcode.min.js')];
$body_class       = 'page-printable';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Tickets', 'url' => url('modules/tickets/index.php')],
    ['label' => (string) $ticket['ticket_number']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Ticket ' . $ticket['ticket_number'],
    $ticket['route_code'] . ' · seat ' . $ticket['seat_number'] . ' · '
        . format_date((string) $ticket['schedule_date'], 'D, d M Y'),
    $actions
) ?>

<?php if ($status === 'cancelled'): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-x-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This ticket has been cancelled and is no longer valid for travel.
        </span>
    </div>
<?php elseif ($status === 'used'): ?>
    <div class="alert alert-info app-alert" role="alert">
        <i class="bi bi-info-circle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This ticket has already been scanned and stamped as used.
        </span>
    </div>
<?php elseif ($hasDeparted): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This service departed on <?= e(format_date((string) $ticket['schedule_date'], 'd M Y')) ?>
            at <?= e(format_time((string) $ticket['departure_time'])) ?>. The ticket is still shown as valid —
            gate staff can stamp it as used if the passenger travelled.
        </span>
    </div>
<?php endif; ?>

<div class="ticket<?= $status === 'cancelled' ? ' ticket--void' : '' ?>" id="printArea">
    <div class="ticket__head">
        <div class="ticket__brand">
            <?= e(FLEETRA_NAME) ?>
            <span><?= e(FLEETRA_TAGLINE) ?></span>
        </div>
        <div class="ticket__number">
            Travel ticket<br>
            <span class="ticket__value--mono"><?= e($ticket['ticket_number']) ?></span>
        </div>
    </div>

    <div class="ticket__body">
        <div class="ticket__fields">
            <div class="ticket__field">
                <span class="ticket__label">Passenger name</span>
                <p class="ticket__value"><?= e($ticket['passenger_name']) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Status</span>
                <p class="ticket__value"><?= status_badge($status) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Route</span>
                <p class="ticket__value"><?= e($ticket['route_code']) ?> · <?= e($ticket['route_name']) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Bus</span>
                <p class="ticket__value">
                    <?= e($ticket['bus_number']) ?>
                    <span class="cell-muted">(<?= e($ticket['registration_number']) ?>)</span>
                </p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Travel date</span>
                <p class="ticket__value"><?= e(format_date((string) $ticket['schedule_date'], 'D, d M Y')) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Departure</span>
                <p class="ticket__value"><?= e(format_time((string) $ticket['departure_time'])) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Boarding point</span>
                <p class="ticket__value"><?= e($ticket['boarding_stop'] ?? $ticket['source']) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Destination</span>
                <p class="ticket__value"><?= e($ticket['destination_stop'] ?? $ticket['destination']) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Seat</span>
                <p class="ticket__value"><?= e($ticket['seat_number']) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Fare</span>
                <p class="ticket__value"><?= e(money($ticket['fare'])) ?></p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Booking</span>
                <p class="ticket__value">
                    <?= e($ticket['booking_number']) ?>
                    <span class="cell-muted">· <?= e(labelize((string) $ticket['booking_status'])) ?></span>
                </p>
            </div>
            <div class="ticket__field">
                <span class="ticket__label">Issued</span>
                <p class="ticket__value"><?= e(format_datetime((string) $ticket['issued_at'])) ?></p>
            </div>
        </div>

        <div class="ticket__qr">
            <div id="ticketQr" data-qr="<?= e($qrPayload) ?>"></div>
            <span class="ticket__qr-code"><?= e($ticketCode) ?></span>
            <span class="ticket__label">Scan at the gate</span>
        </div>
    </div>

    <div class="ticket__stub">
        One seat is reserved per ticket. Present this ticket (printed or on screen) when boarding.
        Driver: <?= e($ticket['driver_name']) ?> ·
        Booking <?= e($ticket['booking_number']) ?> ·
        <?= e(FLEETRA_NAME) ?> <?= e($ticket['route_code']) ?> service.
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mt-3 no-print">
    <a class="btn btn-outline-secondary" href="<?= e(url('modules/bookings/view.php?id=' . (int) $ticket['booking_id'])) ?>">
        <i class="bi bi-journal-check" aria-hidden="true"></i> Open booking <?= e($ticket['booking_number']) ?>
    </a>
    <a class="btn btn-outline-secondary" href="<?= e(url('modules/tickets/index.php')) ?>">
        <i class="bi bi-ticket-perforated" aria-hidden="true"></i> All tickets
    </a>
    <a class="btn btn-outline-secondary" href="<?= e(url('modules/tickets/pdf.php?ticket=' . urlencode($ticketNumber))) ?>">
        <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> View PDF
    </a>
    <a class="btn btn-primary" href="<?= e(url('modules/tickets/pdf.php?ticket=' . urlencode($ticketNumber) . '&download=1')) ?>">
        <i class="bi bi-download" aria-hidden="true"></i> Download PDF
    </a>
</div>

<script>
/* The QR image is drawn by the local QRCode library (no network call). */
document.addEventListener('DOMContentLoaded', function () {
    var target = document.getElementById('ticketQr');

    if (!target || typeof QRCode === 'undefined') {
        return;
    }

    new QRCode(target, {
        text: target.getAttribute('data-qr') || '',
        width: 168,
        height: 168,
        colorDark: '#0A3F2C',
        colorLight: '#FFFFFF',
        correctLevel: QRCode.CorrectLevel.M
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
