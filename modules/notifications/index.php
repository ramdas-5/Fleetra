<?php
/**
 * Fleetra — Notifications
 * ------------------------------------------------------------------
 * modules/notifications/index.php
 *
 * Every user's own notification centre: trip updates, delays, bookings,
 * maintenance reminders, emergencies and system messages. Staff holding
 * notifications.send can also broadcast an announcement.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('notifications.view');

$userId = user_id();

$search       = get('q');
$typeFilter   = get('type');
$readFilter   = get('read');

$where  = ['n.user_id = ?'];
$params = [$userId];

if ($search !== '') {
    $where[] = '(n.title LIKE ? OR n.message LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like);
}

if (is_valid_option(notification_type_options(), $typeFilter)) {
    $where[]  = 'n.notification_type = ?';
    $params[] = $typeFilter;
}

if ($readFilter === 'unread') {
    $where[] = 'n.is_read = 0';
} elseif ($readFilter === 'read') {
    $where[] = 'n.is_read = 1';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_value("SELECT COUNT(*) FROM notifications n $whereSql", $params, 0);
$page  = paginate($total, 20);

$notifications = db_all(
    "SELECT n.id, n.title, n.message, n.notification_type, n.reference_id, n.is_read, n.created_at
       FROM notifications n
       $whereSql
      ORDER BY n.is_read ASC, n.created_at DESC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

$summary  = notification_summary($userId);
$canSend  = can('notifications.send');
$hasFilters = $search !== '' || $typeFilter !== '' || $readFilter !== '';

$page_title       = 'Notifications';
$active_nav       = 'notifications';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Notifications'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Notifications',
    $summary['unread'] > 0
        ? $summary['unread'] . ' unread message' . ($summary['unread'] === 1 ? '' : 's')
        : 'You are all caught up',
    ($summary['unread'] > 0
        ? '<form method="post" action="' . e(url('modules/notifications/read.php')) . '" class="d-inline" id="markAllForm">'
            . csrf_field()
            . '<input type="hidden" name="action" value="all">
               <button type="submit" class="btn btn-outline-secondary">
                   <i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read
               </button>
           </form>'
        : '')
    . ($canSend
        ? '<a class="btn btn-primary" href="' . e(url('modules/notifications/send.php')) . '">
               <i class="bi bi-megaphone" aria-hidden="true"></i> Send announcement
           </a>'
        : '')
) ?>

<div class="stat-grid">
    <?= stat_card('Unread', $summary['unread'], 'bi-bell', $summary['unread'] > 0 ? 'primary' : 'muted', 'Waiting for you') ?>
    <?= stat_card('Last 7 days', $summary['week'], 'bi-calendar-week', 'info', 'Recent activity') ?>
    <?= stat_card('Emergencies', $summary['emergency'], 'bi-exclamation-octagon', $summary['emergency'] > 0 ? 'danger' : 'muted', 'All time') ?>
    <?= stat_card('Total messages', $summary['total'], 'bi-inbox', 'muted', 'Your whole inbox') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/notifications/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search notifications</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Search title or message...">
            </div>

            <label class="visually-hidden" for="type">Type</label>
            <select class="form-select" id="type" name="type" style="width:auto;">
                <?= option_tags(notification_type_options(), $typeFilter, 'All types') ?>
            </select>

            <label class="visually-hidden" for="read">Read state</label>
            <select class="form-select" id="read" name="read" style="width:auto;">
                <?= option_tags(['unread' => 'Unread only', 'read' => 'Read only'], $readFilter, 'All messages') ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filter
            </button>

            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/notifications/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($notifications === []): ?>
        <?= empty_state(
            $hasFilters ? 'No notifications match these filters' : 'No notifications yet',
            $hasFilters
                ? 'Try clearing the filters to see your whole inbox.'
                : 'Trip updates, booking confirmations, delays and maintenance reminders will arrive here.',
            'bi-bell'
        ) ?>
    <?php else: ?>
        <div class="card-fl__body stack-sm" data-notification-list>
            <?php foreach ($notifications as $notification): ?>
                <article class="alert-item alert-item--<?= e(status_variant($notification['notification_type'])) ?><?= (int) $notification['is_read'] === 0 ? ' notification--unread' : '' ?>"
                         data-notification-id="<?= (int) $notification['id'] ?>">
                    <div class="alert-item__head">
                        <span class="alert-item__title">
                            <?php if ((int) $notification['is_read'] === 0): ?>
                                <span class="live-dot" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?= e($notification['title']) ?>
                        </span>
                        <span class="d-flex gap-2 align-items-center flex-wrap">
                            <?= status_badge($notification['notification_type']) ?>
                            <?php if ((int) $notification['is_read'] === 0): ?>
                                <form method="post" action="<?= e(url('modules/notifications/read.php')) ?>" class="d-inline"
                                      data-ajax-read>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="one">
                                    <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        <i class="bi bi-check2" aria-hidden="true"></i> Mark read
                                    </button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </div>
                    <p class="alert-item__text" style="white-space:pre-line;"><?= e((string) $notification['message']) ?></p>
                    <p class="alert-item__meta"><?= e(time_ago((string) $notification['created_at'])) ?></p>
                </article>
            <?php endforeach; ?>
        </div>

        <?= render_pagination($page) ?>
    <?php endif; ?>
</div>

<script>
/* Progressive enhancement: mark a notification read without a page reload.
   The form still posts normally when JavaScript is unavailable. */
document.addEventListener('DOMContentLoaded', function () {
    var endpoint = <?= e_js(url('api/notifications.php')) ?>;

    document.querySelectorAll('form[data-ajax-read]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var id = form.querySelector('input[name="id"]').value;

            window.Fleetra.post(endpoint, { action: 'mark_read', id: id }).then(function (response) {
                if (!response.success) {
                    form.submit();
                    return;
                }

                var item = form.closest('[data-notification-id]');
                if (item) {
                    item.classList.remove('notification--unread');
                    var dot = item.querySelector('.live-dot');
                    if (dot) {
                        dot.remove();
                    }
                }

                form.remove();
                window.Fleetra.toast(response.message || 'Marked as read.', 'success');
            }).catch(function () {
                form.submit();
            });
        });
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
