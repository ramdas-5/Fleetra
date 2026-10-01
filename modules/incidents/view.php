<?php
/**
 * Fleetra — Incidents / Incident detail
 * ------------------------------------------------------------------
 * modules/incidents/view.php
 *
 * The full incident record: what was reported, which service was
 * affected, where it happened and how it was resolved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_login();

$incidentId = get_int('id');

if ($incidentId <= 0) {
    abort_not_found('No incident was specified.');
}

$incident = find_incident($incidentId);

if ($incident === null) {
    abort_not_found('That incident does not exist.');
}

/* Authorisation: staff with incidents.view/manage can open any incident;
   a driver may open an incident they reported or are assigned to. */
$canViewEvery = can('incidents.view') || can('incidents.manage');
$isReporter   = (int) ($incident['reported_by'] ?? 0) === user_id();
$isAssigned   = (int) ($incident['driver_id'] ?? 0) > 0 && (int) db_value(
    'SELECT COUNT(*) FROM drivers WHERE id = ? AND user_id = ?',
    [(int) $incident['driver_id'], user_id()],
    0
) > 0;

if (!$canViewEvery && !(can('incidents.report') && ($isReporter || $isAssigned))) {
    fleetra_log(
        'Permission denied: user #' . user_id() . ' tried to open incident #' . $incidentId,
        'WARNING'
    );

    fleetra_fatal('This incident was not reported by you and is not part of your duty.', 403);
}

$canManage = can('incidents.manage');
$label     = 'INC-' . str_pad((string) $incidentId, 4, '0', STR_PAD_LEFT);
$status    = (string) $incident['status'];
$isOpen    = in_array($status, ['open', 'investigating'], true);

$hasLocation = $incident['latitude'] !== null && $incident['longitude'] !== null;

/* --------------------------------------------------------------
 | Triage actions
 -------------------------------------------------------------- */

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/incidents/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Incidents
            </a>';

if ($canManage) {
    $triage = [
        'investigate' => ['Mark investigating', 'bi-search', 'btn-outline-secondary'],
        'resolve'     => ['Mark resolved', 'bi-check2-circle', 'btn-success'],
        'close'       => ['Close without action', 'bi-x-circle', 'btn-outline-secondary'],
        'reopen'      => ['Re-open incident', 'bi-arrow-counterclockwise', 'btn-outline-secondary'],
    ];

    foreach ($triage as $action => $meta) {
        $visible = $action === 'reopen' ? !$isOpen : $isOpen;

        // Resolving and closing are the same thing for an open incident, so
        // only offer "close" once it is being investigated.
        if ($action === 'close' && $status === 'open') {
            $visible = false;
        }

        if (!$visible) {
            continue;
        }

        $actions .= '<form method="post" action="' . e(url('modules/incidents/status.php?id=' . $incidentId)) . '" class="d-inline"
                      data-confirm="' . e($label) . ' will be marked as ' . strtolower($meta[0]) . '. The reporter is notified."
                      data-confirm-title="' . e($meta[0]) . '?"
                      data-confirm-button="' . e($meta[0]) . '"
                      data-confirm-variant="' . ($action === 'resolve' ? 'primary' : 'primary') . '">'
            . csrf_field()
            . '<input type="hidden" name="action" value="' . e($action) . '">
               <button type="submit" class="btn ' . e($meta[2]) . '">
                   <i class="bi ' . e($meta[1]) . '" aria-hidden="true"></i> ' . e($meta[0]) . '
               </button>
           </form>';
    }
}

$page_title       = $label;
$active_nav       = 'incidents';
$extra_css        = $hasLocation
    ? [asset('vendor/leaflet/leaflet.css')]
    : [];
$extra_js         = $hasLocation
    ? [asset('vendor/leaflet/leaflet.js')]
    : [];
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Incidents', 'url' => url('modules/incidents/index.php')],
    ['label' => $label],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $label . ' · ' . labelize((string) $incident['incident_type']),
    'Reported ' . time_ago((string) $incident['reported_at'])
        . ' by ' . ($incident['reporter_name'] ?? 'the system'),
    $actions
) ?>

<?php if (incident_needs_alert($incident) && $isOpen): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This is a <?= e(strtolower(labelize((string) $incident['severity']))) ?> severity incident and is still open.
            Dispatch and management were notified when it was reported.
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('Severity', labelize((string) $incident['severity']), 'bi-exclamation-triangle', status_variant($incident['severity']), labelize((string) $incident['incident_type'])) ?>
    <?= stat_card('Status', labelize($status), 'bi-clipboard-check', status_variant($status), $isOpen ? 'Still needs attention' : 'Closed out') ?>
    <?= stat_card(
        'Reported',
        time_ago((string) $incident['reported_at']),
        'bi-clock-history',
        'info',
        format_datetime((string) $incident['reported_at'])
    ) ?>
    <?= stat_card(
        'Resolved',
        $incident['resolved_at'] !== null ? time_ago((string) $incident['resolved_at']) : 'Still open',
        'bi-shield-check',
        $incident['resolved_at'] !== null ? 'success' : 'muted',
        $incident['resolved_at'] !== null ? format_datetime((string) $incident['resolved_at']) : 'No resolution recorded'
    ) ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Reported details</h2>
                    <p class="card-fl__subtitle">The description supplied by the reporter</p>
                </div>
                <div class="d-flex gap-2">
                    <?= status_badge($incident['severity']) ?>
                    <?= status_badge($status) ?>
                </div>
            </div>

            <div class="card-fl__body">
                <p class="alert-item__text" style="white-space:pre-line;font-size:14.5px;color:var(--fl-text);">
<?= e((string) $incident['description']) ?>
                </p>

                <div class="divider"></div>

                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Incident type</span>
                        <span class="detail-item__value"><?= e(labelize((string) $incident['incident_type'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Reported by</span>
                        <span class="detail-item__value">
                            <?= e($incident['reporter_name'] ?? 'System') ?>
                            <?php if ($incident['reporter_role'] !== null): ?>
                                <br><span class="cell-muted"><?= e(role_label((string) $incident['reporter_role'])) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Last updated</span>
                        <span class="detail-item__value"><?= e(time_ago((string) $incident['reported_at'])) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($hasLocation): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Location</h2>
                        <p class="card-fl__subtitle">
                            <?= e(number_format((float) $incident['latitude'], 5)) ?>,
                            <?= e(number_format((float) $incident['longitude'], 5)) ?>
                        </p>
                    </div>
                    <span class="chip">Map imagery needs internet</span>
                </div>

                <div class="card-fl__body card-fl__body--flush">
                    <div class="map-shell map-shell--sm" id="incidentMap"
                         data-lat="<?= e((string) $incident['latitude']) ?>"
                         data-lng="<?= e((string) $incident['longitude']) ?>"
                         data-label="<?= e($label . ' · ' . labelize((string) $incident['incident_type'])) ?>"></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Related records</h2>
                    <p class="card-fl__subtitle">The service, vehicle and driver involved</p>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Trip</span>
                        <span class="detail-item__value">
                            <?php if ($incident['trip_id'] !== null): ?>
                                <a href="<?= e(url('modules/trips/view.php?id=' . (int) $incident['trip_id'])) ?>">
                                    TRP-<?= str_pad((string) $incident['trip_id'], 4, '0', STR_PAD_LEFT) ?>
                                </a>
                                <?php if ($incident['trip_status'] !== null): ?>
                                    <br><?= status_badge($incident['trip_status']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-muted">Not linked to a trip</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Route</span>
                        <span class="detail-item__value">
                            <?= $incident['route_code'] !== null
                                ? e($incident['route_code'] . ' · ' . $incident['route_name'])
                                : '<span class="cell-muted">—</span>' ?>
                            <?php if ($incident['schedule_date'] !== null): ?>
                                <br><span class="cell-muted">
                                    <?= e(format_date((string) $incident['schedule_date'], 'd M Y')) ?>
                                    · <?= e(format_time((string) $incident['departure_time'])) ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Bus</span>
                        <span class="detail-item__value">
                            <?php if ($incident['bus_id'] !== null): ?>
                                <a href="<?= e(url('modules/buses/view.php?id=' . (int) $incident['bus_id'])) ?>">
                                    <?= e($incident['bus_number']) ?>
                                </a>
                                <br><span class="cell-muted"><?= e($incident['registration_number']) ?></span>
                            <?php else: ?>
                                <span class="cell-muted">No bus recorded</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Driver</span>
                        <span class="detail-item__value">
                            <?php if ($incident['driver_id'] !== null): ?>
                                <a href="<?= e(url('modules/drivers/view.php?id=' . (int) $incident['driver_id'])) ?>">
                                    <?= e($incident['driver_name']) ?>
                                </a>
                                <br><span class="cell-muted">
                                    <?= e($incident['employee_id']) ?>
                                    <?= $incident['driver_phone'] !== null ? ' · ' . e($incident['driver_phone']) : '' ?>
                                </span>
                            <?php else: ?>
                                <span class="cell-muted">No driver recorded</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Incident tracking</h2>
                    <p class="card-fl__subtitle">How this report has moved through triage</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="timeline">
                    <li class="timeline__item timeline__item--done">
                        <p class="timeline__title">Reported</p>
                        <p class="timeline__meta">
                            <?= e(format_datetime((string) $incident['reported_at'])) ?>
                            · <?= e($incident['reporter_name'] ?? 'System') ?>
                        </p>
                    </li>
                    <li class="timeline__item<?= in_array($status, ['investigating', 'resolved', 'closed'], true) ? ' timeline__item--done' : '' ?>">
                        <p class="timeline__title">Investigating</p>
                        <p class="timeline__meta">
                            <?= in_array($status, ['investigating', 'resolved', 'closed'], true)
                                ? 'Dispatch picked this up'
                                : 'Not started yet' ?>
                        </p>
                    </li>
                    <li class="timeline__item<?= $incident['resolved_at'] !== null ? ' timeline__item--done' : '' ?>">
                        <p class="timeline__title">Resolved</p>
                        <p class="timeline__meta">
                            <?= $incident['resolved_at'] !== null
                                ? e(format_datetime((string) $incident['resolved_at']))
                                : 'Still open' ?>
                        </p>
                    </li>
                </ul>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Emergency guidance</h2>
                    <p class="card-fl__subtitle">What Fleetra does for each severity</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Low</span>
                        <span class="fact-list__value">Logged for the daily report</span>
                    </li>
                    <li>
                        <span class="fact-list__label">Medium</span>
                        <span class="fact-list__value">Appears on the dispatch board</span>
                    </li>
                    <li>
                        <span class="fact-list__label">High</span>
                        <span class="fact-list__value">Alerts admin, managers and dispatch</span>
                    </li>
                    <li>
                        <span class="fact-list__label">Critical</span>
                        <span class="fact-list__value">Alerts everyone immediately</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($hasLocation): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var shell = document.getElementById('incidentMap');

    if (!shell || typeof L === 'undefined') {
        return;
    }

    var lat = parseFloat(shell.getAttribute('data-lat'));
    var lng = parseFloat(shell.getAttribute('data-lng'));

    if (isNaN(lat) || isNaN(lng)) {
        return;
    }

    var map = L.map(shell, { zoomControl: true, attributionControl: true }).setView([lat, lng], 13);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    L.marker([lat, lng]).addTo(map).bindPopup(shell.getAttribute('data-label') || 'Incident location');
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
