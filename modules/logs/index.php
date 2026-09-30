<?php
/**
 * Fleetra — Activity logs
 * ------------------------------------------------------------------
 * modules/logs/index.php
 *
 * The audit trail: who changed what, in which module, from which address
 * and when. Every important write in Fleetra passes through log_activity().
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

require_permission('logs.view');

$search       = get('q');
$moduleFilter = get('module');
$userFilter   = get('user_id');
$dateFrom     = get('date_from');
$dateTo       = get('date_to');

/** Distinct module keys actually present in the trail. */
$moduleOptions = [];
foreach (db_all('SELECT module, COUNT(*) AS entries FROM activity_logs GROUP BY module ORDER BY module') as $row) {
    $moduleOptions[(string) $row['module']] = labelize((string) $row['module']) . ' (' . (int) $row['entries'] . ')';
}

/** Users that have entries in the trail. */
$userOptions = [];
foreach (db_all(
    'SELECT DISTINCT u.id, u.name, u.role
       FROM activity_logs l JOIN users u ON u.id = l.user_id
      ORDER BY u.name'
) as $row) {
    $userOptions[(int) $row['id']] = $row['name'] . ' · ' . role_label((string) $row['role']);
}

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(l.action LIKE ? OR l.description LIKE ? OR u.name LIKE ? OR l.ip_address LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($moduleFilter !== '' && is_valid_option($moduleOptions, $moduleFilter)) {
    $where[]  = 'l.module = ?';
    $params[] = $moduleFilter;
}

if ($userFilter !== '' && is_valid_option($userOptions, $userFilter)) {
    $where[]  = 'l.user_id = ?';
    $params[] = (int) $userFilter;
}

if (is_valid_date($dateFrom)) {
    $where[]  = 'DATE(l.created_at) >= ?';
    $params[] = $dateFrom;
}

if (is_valid_date($dateTo)) {
    $where[]  = 'DATE(l.created_at) <= ?';
    $params[] = $dateTo;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
$joins    = 'FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id';

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params, 0);
$page  = paginate($total, 25);

$logs = db_all(
    "SELECT l.id, l.action, l.module, l.record_id, l.description, l.ip_address, l.created_at,
            u.name AS user_name, u.role AS user_role
       $joins
       $whereSql
      ORDER BY l.created_at DESC, l.id DESC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

$todayCount = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()', [], 0);
$weekCount  = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)', [], 0);
$totalCount = (int) db_value('SELECT COUNT(*) FROM activity_logs', [], 0);
$actorCount = (int) db_value('SELECT COUNT(DISTINCT user_id) FROM activity_logs', [], 0);

$hasFilters = $search !== '' || $moduleFilter !== '' || $userFilter !== ''
    || is_valid_date($dateFrom) || is_valid_date($dateTo);

$page_title       = 'Activity logs';
$active_nav       = 'logs';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Administration'],
    ['label' => 'Activity logs'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Activity logs',
    number_format($total) . ' entr' . ($total === 1 ? 'y' : 'ies') . ' matching the current view'
) ?>

<div class="stat-grid">
    <?= stat_card('Total entries', $totalCount, 'bi-clock-history', 'primary', 'Since the log began') ?>
    <?= stat_card('Today', $todayCount, 'bi-calendar-day', 'info', 'Actions recorded today') ?>
    <?= stat_card('Last 7 days', $weekCount, 'bi-calendar-week', 'success', 'Recent activity') ?>
    <?= stat_card('Staff involved', $actorCount, 'bi-people', 'muted', 'Distinct accounts with entries') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/logs/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search activity</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Action, description, user or IP...">
            </div>

            <label class="visually-hidden" for="module">Module</label>
            <select class="form-select" id="module" name="module" style="width:auto;">
                <?= option_tags($moduleOptions, $moduleFilter, 'All modules') ?>
            </select>

            <label class="visually-hidden" for="user_id">User</label>
            <select class="form-select" id="user_id" name="user_id" style="width:auto;">
                <?= option_tags($userOptions, $userFilter, 'All users') ?>
            </select>

            <label class="visually-hidden" for="date_from">From date</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>" style="width:auto;">

            <label class="visually-hidden" for="date_to">To date</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>" style="width:auto;">

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/logs/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($logs === []): ?>
        <?= empty_state(
            $hasFilters ? 'No log entries match these filters' : 'No activity recorded yet',
            $hasFilters
                ? 'Try a wider date range, or clear the filters to see the whole audit trail.'
                : 'Creating a bus, updating a schedule or sending an announcement will be recorded here.',
            'bi-clock-history'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Record</th>
                        <th>Description</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $entry): ?>
                        <tr>
                            <td>
                                <span class="cell-strong"><?= e(format_date((string) $entry['created_at'], 'd M Y')) ?></span><br>
                                <span class="cell-muted">
                                    <?= e(format_time((string) $entry['created_at'])) ?>
                                    · <?= e(time_ago((string) $entry['created_at'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($entry['user_name'] !== null): ?>
                                    <?= e($entry['user_name']) ?><br>
                                    <span class="cell-muted"><?= e(role_label((string) $entry['user_role'])) ?></span>
                                <?php else: ?>
                                    <span class="cell-muted">System</span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-strong"><?= e($entry['action']) ?></td>
                            <td><?= status_badge((string) $entry['module'], labelize((string) $entry['module'])) ?></td>
                            <td><?= $entry['record_id'] !== null ? '#' . (int) $entry['record_id'] : '—' ?></td>
                            <td><?= e((string) ($entry['description'] ?? '—')) ?></td>
                            <td class="cell-mono"><?= e((string) ($entry['ip_address'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= render_pagination($page) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
