<?php
/**
 * Fleetra — Tickets / Change ticket status
 * ------------------------------------------------------------------
 * modules/tickets/status.php
 *
 * Gate and desk control for staff holding tickets.manage:
 *   action=use   stamp a valid ticket as used
 *   action=void  cancel a ticket that must not be accepted
 *
 * Both are POST-only and CSRF protected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../bookings/_logic.php';

require_permission('tickets.manage');
require_csrf();

if (!is_post()) {
    redirect('modules/tickets/index.php');
}

$ticketId = get_int('id');
$action   = post('action');

if ($ticketId <= 0 || !in_array($action, ['use', 'void'], true)) {
    flash('danger', 'That ticket action is not valid.');
    redirect('modules/tickets/index.php');
}

$ticket = db_one(
    'SELECT t.id, t.ticket_number, t.status,
            b.id AS booking_id, b.booking_number, b.user_id, b.seat_number
       FROM tickets t
       JOIN bookings b ON b.id = t.booking_id
      WHERE t.id = ?
      LIMIT 1',
    [$ticketId]
);

if ($ticket === null) {
    abort_not_found('That ticket does not exist.');
}

$currentStatus = (string) $ticket['status'];
$ticketNumber  = (string) $ticket['ticket_number'];

if ($action === 'use') {
    if ($currentStatus === 'used') {
        flash('info', 'Ticket ' . $ticketNumber . ' had already been stamped as used.');
    } elseif ($currentStatus !== 'valid') {
        flash('warning', 'Only a valid ticket can be stamped as used. ' . $ticketNumber
            . ' is currently ' . labelize($currentStatus) . '.');
    } else {
        db_update('tickets', ['status' => 'used'], ['id' => $ticketId]);
        log_activity('Stamped ticket ' . $ticketNumber . ' as used', 'tickets', $ticketId, 'Gate check');
        notify_user(
            (int) $ticket['user_id'],
            'Ticket used — ' . $ticketNumber,
            'Your ticket for seat ' . $ticket['seat_number'] . ' was scanned at the boarding gate.',
            'booking',
            (int) $ticket['booking_id']
        );

        flash('success', 'Ticket ' . $ticketNumber . ' has been stamped as used.');
    }

    redirect('modules/tickets/view.php?ticket=' . urlencode($ticketNumber));
}

// Void the ticket without touching the booking: the seat stays sold and any
// refund is a separate decision for the desk to make.
if ($currentStatus === 'cancelled') {
    flash('info', 'Ticket ' . $ticketNumber . ' was already cancelled.');
} else {
    db_update('tickets', ['status' => 'cancelled'], ['id' => $ticketId]);
    log_activity('Voided ticket ' . $ticketNumber, 'tickets', $ticketId, 'Ticket cancelled by staff');
    notify_user(
        (int) $ticket['user_id'],
        'Ticket voided — ' . $ticketNumber,
        'Your ticket for booking ' . $ticket['booking_number'] . ' is no longer valid for travel. '
        . 'Please contact Fleetra support if this was not expected.',
        'booking',
        (int) $ticket['booking_id']
    );

    flash('success', 'Ticket ' . $ticketNumber . ' has been voided.');
}

redirect('modules/tickets/view.php?ticket=' . urlencode($ticketNumber));
