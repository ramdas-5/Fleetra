<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/topbar.php
 *
 * Context bar above the content area: sidebar toggle, breadcrumbs,
 * notification bell and the account dropdown.
 *
 * Every control here points at a page that exists on disk, so the
 * topbar never contains dead links while modules are still being built.
 */

declare(strict_types=1);

$topbarUser     = current_user();
$topbarUnread   = unread_notification_count();
// The bell is only shown to roles that can actually open the notification
// centre — an inaccessible control is never rendered.
$topbarNotificationsUrl = can('notifications.view') && file_exists(BASE_PATH . '/modules/notifications/index.php')
    ? url('modules/notifications/index.php')
    : null;
$topbarProfileUrl = file_exists(BASE_PATH . '/profile.php') ? url('profile.php') : null;
$topbarPasswordUrl = file_exists(BASE_PATH . '/change-password.php') ? url('change-password.php') : null;
?>
<header class="topbar">
    <div class="topbar__left">
        <button type="button" class="icon-btn topbar__menu" id="sidebarToggle"
                aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        <?= render_breadcrumbs($page_breadcrumbs) ?>
    </div>

    <div class="topbar__right">
        <?php if ($topbarNotificationsUrl !== null): ?>
            <a class="icon-btn" href="<?= e($topbarNotificationsUrl) ?>" aria-label="Notifications"
               title="Notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <?php if ($topbarUnread > 0): ?>
                    <span class="icon-btn__dot" aria-hidden="true"></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <div class="dropdown">
            <button class="user-menu" type="button" data-bs-toggle="dropdown"
                    aria-expanded="false" aria-haspopup="true">
                <?= avatar_markup($topbarUser, 'sm') ?>
                <span class="user-menu__text">
                    <span class="user-menu__name"><?= e($topbarUser['name'] ?? 'Guest') ?></span>
                    <span class="user-menu__role"><?= e(role_label(current_role())) ?></span>
                </span>
                <i class="bi bi-chevron-down user-menu__caret" aria-hidden="true"></i>
            </button>

            <ul class="dropdown-menu dropdown-menu-end user-menu__dropdown">
                <li class="user-menu__header">
                    <span class="user-menu__header-name"><?= e($topbarUser['name'] ?? 'Guest') ?></span>
                    <span class="user-menu__header-mail"><?= e($topbarUser['email'] ?? '') ?></span>
                </li>
                <?php if ($topbarProfileUrl !== null): ?>
                    <li><a class="dropdown-item" href="<?= e($topbarProfileUrl) ?>">
                        <i class="bi bi-person" aria-hidden="true"></i> My profile
                    </a></li>
                <?php endif; ?>
                <?php if ($topbarPasswordUrl !== null): ?>
                    <li><a class="dropdown-item" href="<?= e($topbarPasswordUrl) ?>">
                        <i class="bi bi-key" aria-hidden="true"></i> Change password
                    </a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item dropdown-item--danger" href="<?= e(url('logout.php')) ?>">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
                </a></li>
            </ul>
        </div>
    </div>
</header>
