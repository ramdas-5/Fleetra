<?php
/**
 * Fleetra — Administration / Users
 * ------------------------------------------------------------------
 * modules/users/index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('users.manage');

$search       = get('q');
$roleFilter   = get('role');
$statusFilter = get('status');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

if (is_valid_option(role_labels(), $roleFilter)) {
    $where[]  = 'u.role = ?';
    $params[] = $roleFilter;
}

if (is_valid_option(account_status_options(), $statusFilter)) {
    $where[]  = 'u.status = ?';
    $params[] = $statusFilter;
}

$whereSql = $where === [] ? 'WHERE u.status <> "deleted"' : 'WHERE u.status <> "deleted" AND ' . implode(' AND ', $where);

$total = (int) db_value("SELECT COUNT(*) FROM users u $whereSql", $params, 0);
$page  = paginate($total, 15);

$users = db_all(
    "SELECT u.*,
            (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id) AS booking_count,
            (SELECT d.employee_id FROM drivers d WHERE d.user_id = u.id LIMIT 1) AS employee_id
       FROM users u
       $whereSql
      ORDER BY u.role = 'admin' DESC, u.name
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

$roleTotals = array_column(
    db_all('SELECT role, COUNT(*) AS total FROM users WHERE status <> "deleted" GROUP BY role'),
    'total',
    'role'
);

$statusTotals = array_column(
    db_all('SELECT status, COUNT(*) AS total FROM users WHERE status <> "deleted" GROUP BY status'),
    'total',
    'status'
);

$hasFilters = $search !== '' || is_valid_option(role_labels(), $roleFilter)
    || is_valid_option(account_status_options(), $statusFilter);

$page_title       = 'Users';
$active_nav       = 'users';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Administration', 'url' => url('modules/users/index.php')],
    ['label' => 'Users'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Users',
    $total . ' account' . ($total === 1 ? '' : 's') . ' matching the current view',
    '<a class="btn btn-primary" href="' . e(url('modules/users/create.php')) . '">
        <i class="bi bi-person-plus" aria-hidden="true"></i> Add user
     </a>'
) ?>

<div class="stat-grid">
    <?= stat_card('Administrators', (int) ($roleTotals['admin'] ?? 0), 'bi-shield-lock', 'primary', 'Full system access') ?>
    <?= stat_card('Transport managers', (int) ($roleTotals['manager'] ?? 0), 'bi-person-badge', 'info', 'Fleet and operations') ?>
    <?= stat_card('Dispatchers', (int) ($roleTotals['dispatcher'] ?? 0), 'bi-broadcast-pin', 'info', 'Daily dispatch') ?>
    <?= stat_card('Drivers', (int) ($roleTotals['driver'] ?? 0), 'bi-truck', 'success', 'Driver accounts') ?>
    <?= stat_card('Passengers', (int) ($roleTotals['passenger'] ?? 0), 'bi-people', 'muted', 'Registered travellers') ?>
    <?= stat_card('Suspended', (int) ($statusTotals['suspended'] ?? 0), 'bi-slash-circle', (int) ($statusTotals['suspended'] ?? 0) > 0 ? 'danger' : 'muted', 'Access revoked') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/users/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search users</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($search) ?>" placeholder="Search users...">
            </div>

            <label class="visually-hidden" for="role">Role</label>
            <select class="form-select" id="role" name="role" style="width:auto;">
                <?= option_tags(role_labels(), $roleFilter, 'All roles') ?>
            </select>

            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(account_status_options(), $statusFilter, 'All statuses') ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/users/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($users === []): ?>
        <?= empty_state(
            $hasFilters ? 'No accounts match these filters' : 'No user accounts yet',
            $hasFilters
                ? 'Try a different search term, or clear the filters to see every account.'
                : 'Create an account to give someone access to Fleetra.',
            'bi-people',
            $hasFilters ? '' : '<a class="btn btn-primary" href="' . e(url('modules/users/create.php')) . '">Add user</a>'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Contact</th>
                        <th>Activity</th>
                        <th>Last sign in</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $account): ?>
                        <tr>
                            <td>
                                <div class="cell-user">
                                    <?= avatar_markup($account, 'sm') ?>
                                    <span class="cell-user__text">
                                        <span class="cell-user__name"><?= e($account['name']) ?></span>
                                        <span class="cell-user__meta">
                                            <?= e($account['email']) ?>
                                            <?php if ((int) $account['id'] === user_id()): ?>
                                                &middot; you
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?= role_badge($account['role']) ?>
                                <?php if ($account['employee_id'] !== null): ?>
                                    <br><span class="cell-muted"><?= e($account['employee_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($account['phone'] ?: '—') ?>
                            </td>
                            <td class="cell-num">
                                <?php if ((int) $account['booking_count'] > 0): ?>
                                    <?= (int) $account['booking_count'] ?> bookings
                                <?php else: ?>
                                    <span class="cell-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $account['last_login'] !== null
                                    ? e(time_ago((string) $account['last_login']))
                                    : '<span class="cell-muted">Never</span>' ?>
                            </td>
                            <td><?= status_badge($account['status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/users/view.php?id=' . (int) $account['id'])) ?>"
                                   title="View account" aria-label="View <?= e($account['name']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <a class="row-action" href="<?= e(url('modules/users/edit.php?id=' . (int) $account['id'])) ?>"
                                   title="Edit account" aria-label="Edit <?= e($account['name']) ?>">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
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
