<?php
/**
 * Fleetra — Tickets / PDF ticket
 * ------------------------------------------------------------------
 * modules/tickets/pdf.php?ticket=TKT-0001[&download=1]
 *
 * Streams a properly formatted, printable PDF bus ticket generated with
 * the dependency-free writer in includes/pdf.php. Passengers may download
 * their own tickets; staff holding tickets.manage can download any.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/pdf.php';
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
    fleetra_log('Permission denied: user #' . user_id() . ' tried to download ticket ' . $ticketNumber, 'WARNING');

    fleetra_fatal('This ticket belongs to another passenger.', 403);
}

/* ------------------------------------------------------------------
 | Layout
 ------------------------------------------------------------------ */

$pdf = new FleetraPdf();

$margin = 40.0;
$pageW  = PDF_A4_WIDTH;
$pageH  = PDF_A4_HEIGHT;

$brand   = [0.31, 0.27, 0.90];
$ink     = [0.043, 0.07, 0.125];
$muted   = [0.36, 0.40, 0.47];
$border  = [0.89, 0.91, 0.94];

$money = static fn (mixed $amount): string => 'INR ' . number_format((float) $amount, 2);

/* Brand header band */
$pdf->setColor($brand[0], $brand[1], $brand[2]);
$pdf->rect(0, 0, $pageW, 96, true);

$pdf->setColor(1, 1, 1);
$pdf->text($margin, 26, FLEETRA_NAME, 22, true);
$pdf->text($margin, 54, 'Electronic bus ticket · ' . FLEETRA_TAGLINE, 10);
$pdf->textRight($pageW - $margin, 26, 'E-TICKET', 13, true);
$pdf->textRight($pageW - $margin, 48, 'Issued ' . format_date((string) $ticket['issued_at'], 'd M Y'), 9);

/* Ticket number + status row */
$pdf->setColor($ink[0], $ink[1], $ink[2]);
$pdf->text($margin, 120, 'Ticket number', 9, false);
$pdf->setColor($muted[0], $muted[1], $muted[2]);
$pdf->text($margin, 134, 'Status', 9, false);

$pdf->setColor($ink[0], $ink[1], $ink[2]);
$pdf->text($margin, 144, (string) $ticket['ticket_number'], 16, true);
$pdf->text($margin, 166, strtoupper((string) $ticket['ticket_status']), 11, true);

/* Journey block */
$pdf->setColor($border[0], $border[1], $border[2]);
$pdf->rect($margin, 196, $pageW - $margin * 2, 92, false);

$pdf->setColor($muted[0], $muted[1], $muted[2]);
$pdf->text($margin + 16, 210, 'FROM', 8, true);
$pdf->text($pageW / 2 + 16, 210, 'TO', 8, true);

$pdf->setColor($ink[0], $ink[1], $ink[2]);
$pdf->text($margin + 16, 224, (string) ($ticket['boarding_stop'] ?? $ticket['source']), 13, true);
$pdf->text($pageW / 2 + 16, 224, (string) ($ticket['destination_stop'] ?? $ticket['destination']), 13, true);

$pdf->setColor($muted[0], $muted[1], $muted[2]);
$pdf->text($margin + 16, 250, 'Departure ' . format_time((string) $ticket['departure_time']), 10);
$pdf->text($pageW / 2 + 16, 250, 'Travel date ' . format_date((string) $ticket['schedule_date'], 'd M Y'), 10);

$pdf->setColor($ink[0], $ink[1], $ink[2]);
$pdf->text($margin + 16, 266, (string) $ticket['route_code'] . ' · ' . (string) $ticket['route_name'], 10);

/* Details grid */
$gridTop = 314.0;
$colW    = ($pageW - $margin * 2) / 2;
$rowH    = 46.0;

$fields = [
    ['Passenger name', (string) $ticket['passenger_name']],
    ['Seat number', (string) $ticket['seat_number']],
    ['Bus', (string) $ticket['bus_number'] . '  (' . (string) $ticket['registration_number'] . ')'],
    ['Fare paid', $money($ticket['fare'])],
    ['Booking reference', (string) $ticket['booking_number']],
    ['Booking status', labelize((string) $ticket['booking_status'])],
    ['Driver', (string) $ticket['driver_name']],
    ['Operator', FLEETRA_NAME],
];

foreach ($fields as $index => $field) {
    $col = $index % 2;
    $row = intdiv($index, 2);

    $x = $margin + $col * $colW;
    $t = $gridTop + $row * $rowH;

    $pdf->setColor($muted[0], $muted[1], $muted[2]);
    $pdf->text($x, $t, strtoupper($field[0]), 8, false);

    $pdf->setColor($ink[0], $ink[1], $ink[2]);
    $pdf->text($x, $t + 13, $field[1], 11, true);
}

/* Perforation line */
$pdf->setColor($border[0], $border[1], $border[2]);
$dashY = $gridTop + 4 * $rowH + 10;
for ($x = $margin; $x < $pageW - $margin; $x += 12) {
    $pdf->line($x, $dashY, $x + 6, $dashY, 0.7);
}

/* Barcode + verification code */
$pdf->setColor($ink[0], $ink[1], $ink[2]);
$pdf->text($margin, $dashY + 20, 'Scan at boarding', 8, true);

$pdf->setColor(0, 0, 0);
$pdf->barcodeCode39($margin, $dashY + 34, (string) $ticket['ticket_number'], 46, 1.5);

$pdf->setColor($muted[0], $muted[1], $muted[2]);
$pdf->text($margin, $dashY + 88, 'Ticket ' . (string) $ticket['ticket_number'] . '  ·  Booking ' . (string) $ticket['booking_number'], 8);

/* Notes */
$pdf->setColor($muted[0], $muted[1], $muted[2]);
$pdf->text(
    $margin,
    $dashY + 116,
    'One seat is reserved per ticket. Please arrive at the boarding point 15 minutes before departure.',
    9
);
$pdf->text($margin, $dashY + 132, 'Carry a valid photo ID. This ticket is non-transferable.', 9);

/* Footer */
$pdf->setColor($border[0], $border[1], $border[2]);
$pdf->line($margin, $pageH - 60, $pageW - $margin, $pageH - 60, 0.7);
$pdf->setColor($muted[0], $muted[1], $muted[2]);
$pdf->text($margin, $pageH - 52, FLEETRA_NAME . ' · support@fleetra.com · Generated ' . date('d M Y, H:i'), 8);
$pdf->textRight($pageW - $margin, $pageH - 52, 'Ticket ' . (string) $ticket['ticket_number'], 8);

/* ------------------------------------------------------------------
 | Stream
 ------------------------------------------------------------------ */

$bytes    = $pdf->output();
$filename = 'Fleetra-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $ticket['ticket_number']) . '.pdf';
$download = get('download') === '1';

log_activity(
    'Downloaded ticket PDF ' . $ticket['ticket_number'],
    'tickets',
    (int) $ticket['id'],
    'Passenger ' . $ticket['passenger_name']
);

if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
}

echo $bytes;
exit;
