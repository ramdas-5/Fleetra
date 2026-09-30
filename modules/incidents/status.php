<?php
/**
 * Fleetra — Incidents / Update status
 * ------------------------------------------------------------------
 * modules/incidents/status.php
 *
 * Triage actions for staff holding incidents.manage:
 *   action=investigate  mark as being worked on
 *   action=resolve      close the incident as resolved
 *   action=close        close without further action
 *   action=reopen       put a closed incident back into triage
 *
 * POST only and CSRF protected. The reporter and the driver are notified
 * whenever the outcome changes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('incidents.manage');
require_csrf();

if (!is_post()) {
    redirect('modules/incidents/index.php');
}

$incidentId = get_int('id');
$action     = post('action');

$targets = [
    'investigate' => 'investigating',
    'resolve'     => 'resolved',
    'close'       => 'closed',
    'reopen'      => 'open',
];

if ($incidentId <= 0 || !isset($targets[$action])) {
    flash('danger', 'That incident action is not valid.');
    redirect('modules/incidents/index.php');
}

$incident = find_incident($incidentId);

if ($incident === null) {
    abort_not_found('That incident does not exist.');
}

$newStatus = $targets[$action];
$label     = 'INC-' . str_pad((string) $incidentId, 4, '0', STR_PAD_LEFT);

$payload = ['status' => $newStatus];

// resolved_at is only meaningful while the incident is closed.
if ($newStatus === 'resolved' || $newStatus === 'closed') {
    $payload['resolved_at'] = date('Y-m-d H:i:s');
} else {
    $payload['resolved_at'] = null;
}

db_update('incidents', $payload, ['id' => $incidentId]);

log_activity(
    'Incident ' . $label . ' marked ' . labelize($newStatus),
    'incidents',
    $incidentId,
    'Status changed to ' . labelize($newStatus) . ' by ' . current_user_name()
);

/* Notify the people who need to know about the outcome. */
$audience = [];

if ((int) $incident['reported_by'] > 0) {
    $audience[(int) $incident['reported_by']] = true;
}

if ($incident['driver_user_id'] ?? null) {
    $audience[(int) $incident['driver_user_id']] = true;
}

$message = $label . ' (' . labelize((string) $incident['incident_type']) . ' on '
    . ($incident['bus_number'] !== null ? 'bus ' . $incident['bus_number'] : 'the network') . ') is now '
    . strtolower(labelize($newStatus)) . '.';

foreach (array_keys($audience) as $userId) {
    notify_user(
        (int) $userId,
        'Incident ' . $label . ' — ' . labelize($newStatus),
        $message,
        $newStatus === 'resolved' || $newStatus === 'closed' ? 'system' : 'emergency',
        $incidentId
    );
}

flash('success', 'Incident ' . $label . ' is now ' . strtolower(labelize($newStatus)) . '.');

if (post('return') === 'list') {
    redirect('modules/incidents/index.php');
}

redirect('modules/incidents/view.php?id=' . $incidentId);
