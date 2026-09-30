<?php
/**
 * Fleetra — Passengers
 * ------------------------------------------------------------------
 * modules/passengers/index.php
 *
 * The passenger directory for operations staff. Every passenger is a user
 * account with role "passenger", enriched here with their booking and
 * spend totals from the booking register.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

require_permission('passengers.view');

$search       = get('q');
$statusFilter = get('status');
$sort         = get('sort');

$where  = ["u.role = 'passenger'"];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

if (is_valid_option(['active' => '', 'inactive' => '', 'suspended' => ''], $statusFilter)) {
    $where[]  = 'u.status = ?';
    $params[] = $statusFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$orderBy = match ($sort) {
    'name'      => 'u.name ASC',
    'spend'     => 'total_spend DESC',
    'bookings'  => 'booking_count DESC',
    'recent'    => 'u.last_login DESC',
    default     => 'u.created_at DESC',
};

$total = (int) db_value("SELECT COUNT(*) FROM users u $whereSql", $params, 0);
$page  = paginate($total, 15);

$passengers = db_all(
    "SELECT u.id, u.name, u.email, u.phone, u.profile_image, u.status, u.last_login, u.created_at,
            COUNT(DISTINCT b.id) AS booking_count,
            COALESCE(SUM(CASE WHEN b.booking_status IN ('confirmed','completed') THEN b.fare END), 0) AS total_spend,
            MAX(b.created_at) AS last_booking_at
       FROM users u
       LEFT JOIN bookings b ON b.user_id = u.id
       $whereSql
      GROUP BY u.id, u.name, u.email, u.phone, u.profile_image, u.status, u.last_login, u.created_at
      ORDER BY $orderBy
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

/** Headline numbers for all passenger accounts, independent of the filters. */
$totalPassengers = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'passenger'", [], 0);
$newThisMonth    = (int) db_value(
    "SELECT COUNT(*) FROM users WHERE role = 'passenger' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    [],
    0
);
$bookedCount = (int) db_value(
    "SELECT COUNT(DISTINCT user_id) FROM bookings WHERE booking_status IN ('confirmed','completed')",
    [],
    0
);
$collected = (float) db_value(
    "SELECT COALESCE(SUM(fare), 0) FROM bookings WHERE payment_status = 'paid'",
    [],
    0
);

$hasFilters = $search !== '' || $statusFilter !== '' || $sort !== '';

$page_title       = 'Passengers';
$active_nav       = 'passengers';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Passenger services'],
    ['label' => 'Passengers'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Passengers',
    number_format($total) . ' passenger account' . ($total === 1 ? '' : 's') . ' matching the current view'
) ?>

<div class="stat-grid">
    <?= stat_card('Registered passengers', $totalPassengers, 'bi-people', 'primary', 'All passenger accounts') ?>
    <?= stat_card('Booked at least once', $bookedCount, 'bi-journal-check', 'success', 'Confirmed or completed bookings') ?>
    <?= stat_card('New this month', $newThisMonth, 'bi-person-plus', 'info', 'Joined since the 1st') ?>
    <?= stat_card('Fare collected', money($collected), 'bi-cash-coin', 'muted', 'Paid bookings, all time') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/passengers/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search passengers</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Name, email or phone...">
            </div>

            <label class="visually-hidden" for="status">Account status</label>
            <select class="form-select" id="status" name="status" style="width:auto;">
                <?= option_tags(
                    ['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'],
                    $statusFilter,
                    'All account statuses'
                ) ?>
            </select>

            <label class="visually-hidden" for="sort">Sort by</label>
            <select class="form-select" id="sort" name="sort" style="width:auto;">
                <?= option_tags(
                    [
                        'name'     => 'Name (A–Z)',
                        'spend'    => 'Highest spend',
                        'bookings' => 'Most bookings',
                        'recent'   => 'Most recent sign-in',
                        'newest'   => 'Newest accounts',
                    ],
                    $sort,
                    'Newest accounts'
                ) ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/passengers/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($passengers === []): ?>
        <?= empty_state(
            $hasFilters ? 'No passengers match these filters' : 'No passenger accounts yet',
            $hasFilters
                ? 'Try a different search term, or clear the filters to list every passenger.'
                : 'Passengers appear here as soon as they register on the Fleetra website.',
            'bi-people'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Passenger</th>
                        <th>Contact</th>
                        <th>Bookings</th>
                        <th>Fare paid</th>
                        <th>Last booking</th>
                        <th>Last sign-in</th>
                        <th>Status</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($passengers as $passenger): ?>
                        <tr>
                            <td>
                                <div class="cell-person">
                                    <?= avatar_markup($passenger, 'sm') ?>
                                    <span>
                                        <span class="cell-strong"><?= e($passenger['name']) ?></span><br>
                                        <span class="cell-muted">#<?= (int) $passenger['id'] ?></span>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?= e($passenger['email']) ?><br>
                                <span class="cell-muted"><?= e($passenger['phone'] ?? '—') ?></span>
                            </td>
                            <td class="cell-num"><?= (int) $passenger['booking_count'] ?></td>
                            <td class="cell-num"><?= e(money($passenger['total_spend'])) ?></td>
                            <td>
                                <?= $passenger['last_booking_at'] !== null
                                    ? e(format_date((string) $passenger['last_booking_at'], 'd M Y'))
                                    : '<span class="cell-muted">Never</span>' ?>
                            </td>
                            <td>
                                <?= $passenger['last_login'] !== null
                                    ? e(time_ago((string) $passenger['last_login']))
                                    : '<span class="cell-muted">Never</span>' ?>
                            </td>
                            <td><?= status_badge($passenger['status']) ?></td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/passengers/view.php?id=' . (int) $passenger['id'])) ?>"
                                   title="Open passenger" aria-label="Open <?= e($passenger['name']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <a class="row-action" href="<?= e(url('modules/bookings/index.php?q=' . urlencode((string) $passenger['name']))) ?>"
                                   title="Their bookings" aria-label="Bookings for <?= e($passenger['name']) ?>">
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
