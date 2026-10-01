<?php
/**
 * Fleetra — Incidents
 * ------------------------------------------------------------------
 * modules/incidents/index.php
 *
 * The incident register. Critical and high severity incidents are sorted
 * to the top and flagged, because those are the ones a dispatcher has to
 * act on immediately.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

/* Staff with incidents.view see the whole register. A driver who can only
   report (incidents.report) may open the same page but is scoped, on the
   server, to the incidents they raised or are the assigned driver on. */
if (!can('incidents.view') && !can('incidents.manage') && !can('incidents.report')) {
    require_permission('incidents.view');
}

$canManage = can('incidents.manage');
$canReport = can('incidents.report');
$seesEveryIncident = can('incidents.view') || can('incidents.manage');

$search         = get('q');
$typeFilter     = get('type');
$severityFilter = get('severity');
$statusFilter   = get('status');
$dateFrom       = get('date_from');
$dateTo         = get('date_to');
$openOnly       = get('view') === 'open';

$where  = [];
$params = [];

// Report-only callers (drivers) can only ever see their own incidents.
if (!$seesEveryIncident) {
    $where[]  = '(i.reported_by = ? OR i.driver_id = (SELECT id FROM drivers WHERE user_id = ?))';
    $params[] = user_id();
    $params[] = user_id();
}

if ($openOnly) {
    $where[] = "i.status IN ('open','investigating')";
}

if ($search !== '') {
    $where[] = '(i.description LIKE ? OR b.bus_number LIKE ? OR r.route_code LIKE ? OR du.name LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if (is_valid_option(incident_type_options(), $typeFilter)) {
    $where[]  = 'i.incident_type = ?';
    $params[] = $typeFilter;
}

if (is_valid_option(incident_severity_options(), $severityFilter)) {
    $where[]  = 'i.severity = ?';
    $params[] = $severityFilter;
}

if (is_valid_option(incident_status_options(), $statusFilter)) {
    $where[]  = 'i.status = ?';
    $params[] = $statusFilter;
}

if (is_valid_date($dateFrom)) {
    $where[]  = 'DATE(i.reported_at) >= ?';
    $params[] = $dateFrom;
}

if (is_valid_date($dateTo)) {
    $where[]  = 'DATE(i.reported_at) <= ?';
    $params[] = $dateTo;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$joins = 'FROM incidents i
          LEFT JOIN trips t     ON t.id = i.trip_id
          LEFT JOIN schedules s ON s.id = t.schedule_id
          LEFT JOIN routes r    ON r.id = t.route_id
          LEFT JOIN buses b     ON b.id = i.bus_id
          LEFT JOIN drivers d   ON d.id = i.driver_id
          LEFT JOIN users du    ON du.id = d.user_id';

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params, 0);
$page  = paginate($total, 15);

$incidents = db_all(
    "SELECT i.*, t.trip_status,
            s.schedule_date, s.departure_time,
            r.route_code, r.route_name, r.source, r.destination,
            b.bus_number, b.registration_number,
            du.name AS driver_name,
            ru.name AS reporter_name
       $joins
       LEFT JOIN users ru ON ru.id = i.reported_by
       $whereSql
      ORDER BY (i.status IN ('open','investigating')) DESC,
               FIELD(i.severity, 'critical', 'high', 'medium', 'low'),
               i.reported_at DESC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

/* Headline numbers across the whole register. */
$openCount     = (int) db_value("SELECT COUNT(*) FROM incidents WHERE status = 'open'", [], 0);
$investigating = (int) db_value("SELECT COUNT(*) FROM incidents WHERE status = 'investigating'", [], 0);
$severeOpen    = (int) db_value(
    "SELECT COUNT(*) FROM incidents WHERE status IN ('open','investigating') AND severity IN ('high','critical')",
    [],
    0
);
$resolvedWeek  = (int) db_value(
    "SELECT COUNT(*) FROM incidents WHERE status IN ('resolved','closed')
        AND reported_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    [],
    0
);

$hasFilters = $search !== '' || $typeFilter !== '' || $severityFilter !== '' || $statusFilter !== ''
    || is_valid_date($dateFrom) || is_valid_date($dateTo) || $openOnly;

$page_title       = 'Incidents';
$active_nav       = 'incidents';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Operations'],
    ['label' => 'Incidents'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Incidents',
    number_format($total) . ' incident' . ($total === 1 ? '' : 's') . ' matching the current view',
    $canReport
        ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/incidents/index.php?view=open')) . '">
               <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Open only
           </a>
           <a class="btn btn-primary" href="' . e(url('modules/incidents/create.php')) . '">
               <i class="bi bi-plus-lg" aria-hidden="true"></i> Report incident
           </a>'
        : ''
) ?>

<?php if ($severeOpen > 0): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong><?= $severeOpen ?></strong> high or critical incident<?= $severeOpen === 1 ? '' : 's' ?>
            still open. Review the affected trips and update passengers.
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('Open', $openCount, 'bi-exclamation-circle', $openCount > 0 ? 'warning' : 'muted', 'Awaiting triage') ?>
    <?= stat_card('Investigating', $investigating, 'bi-search', $investigating > 0 ? 'info' : 'muted', 'Being worked on now') ?>
    <?= stat_card('High / critical open', $severeOpen, 'bi-exclamation-octagon', $severeOpen > 0 ? 'danger' : 'muted', 'Needs immediate action') ?>
    <?= stat_card('Resolved (7 days)', $resolvedWeek, 'bi-shield-check', 'success', 'Closed in the last week') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/incidents/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search incidents</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Description, bus, route or driver...">
            </div>

            <label class="visually-hidden" for="type">Incident type</label>
            <select class="form-select" id="type" name="type" style="width:auto;">
                <?= option_tags(incident_type_options(), $typeFilter, 'All types') ?>
            </select>

            <label class="visually-hidden" for="severity">Severity</label>
            <select class="form-select" id="severity" name="severity" style="width:auto;">
                <?= option_tags(incident_severity_options(), $severityFilter, 'All severities') ?>
            </select>

            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(incident_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <label class="visually-hidden" for="date_from">Reported from</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>" style="width:auto;">

            <label class="visually-hidden" for="date_to">Reported to</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>" style="width:auto;">

            <?php if ($openOnly): ?>
                <input type="hidden" name="view" value="open">
            <?php endif; ?>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/incidents/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($incidents === []): ?>
        <?= empty_state(
            $hasFilters ? 'No incidents match these filters' : 'No incidents reported',
            $hasFilters
                ? 'Try a wider date range, or clear the filters to see the whole incident register.'
                : 'Nothing has been reported by drivers or dispatchers. This page stays empty while operations run smoothly.',
            'bi-shield-check',
            $canReport
                ? '<a class="btn btn-primary" href="' . e(url('modules/incidents/create.php')) . '">Report an incident</a>'
                : ''
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Incident</th>
                        <th>Severity</th>
                        <th>Bus / driver</th>
                        <th>Trip</th>
                        <th>Reported</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incidents as $incident): ?>
                        <tr>
                            <td>
                                <span class="cell-strong">INC-<?= str_pad((string) $incident['id'], 4, '0', STR_PAD_LEFT) ?></span><br>
                                <span class="cell-muted"><?= e(labelize((string) $incident['incident_type'])) ?></span>
                            </td>
                            <td><?= status_badge($incident['severity']) ?></td>
                            <td>
                                <?= $incident['bus_number'] !== null ? e($incident['bus_number']) : '<span class="cell-muted">No bus</span>' ?><br>
                                <span class="cell-muted"><?= e($incident['driver_name'] ?? 'Unassigned') ?></span>
                            </td>
                            <td>
                                <?php if ($incident['trip_id'] !== null): ?>
                                    <a href="<?= e(url('modules/trips/view.php?id=' . (int) $incident['trip_id'])) ?>">
                                        TRP-<?= str_pad((string) $incident['trip_id'], 4, '0', STR_PAD_LEFT) ?>
                                    </a><br>
                                    <span class="cell-muted">
                                        <?= e($incident['route_code'] ?? '—') ?>
                                        <?= $incident['schedule_date'] !== null
                                            ? '· ' . e(format_date((string) $incident['schedule_date'], 'd M'))
                                            : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="cell-muted">Not linked</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e(time_ago((string) $incident['reported_at'])) ?><br>
                                <span class="cell-muted"><?= e($incident['reporter_name'] ?? 'System') ?></span>
                            </td>
                            <td><?= status_badge($incident['status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/incidents/view.php?id=' . (int) $incident['id'])) ?>"
                                   title="Open incident" aria-label="Open incident INC-<?= (int) $incident['id'] ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <?php if ($canManage && in_array((string) $incident['status'], ['open', 'investigating'], true)): ?>
                                    <form method="post" action="<?= e(url('modules/incidents/status.php?id=' . (int) $incident['id'])) ?>"
                                          class="d-inline"
                                          data-confirm="The incident is closed with no further action."
                                          data-confirm-title="Resolve INC-<?= str_pad((string) $incident['id'], 4, '0', STR_PAD_LEFT) ?>?"
                                          data-confirm-button="Mark resolved"
                                          data-confirm-variant="primary">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="resolve">
                                        <button type="submit" class="row-action row-action--success"
                                                title="Mark resolved" aria-label="Mark incident resolved">
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

        <?= render_pagination($page) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
