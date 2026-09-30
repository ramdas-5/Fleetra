<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/sidebar.php
 *
 * Primary navigation. Items come from includes/nav.php and are filtered
 * by the signed-in user's capabilities. Items whose module has not been
 * built yet render as disabled with a "Soon" tag instead of a dead link.
 */

declare(strict_types=1);

$navGroups      = fleetra_navigation();
$navUser        = current_user();
$unreadCount    = unread_notification_count();
$dashboardHref  = url(dashboard_path(current_role()));
?>
<aside class="sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <a class="brand" href="<?= e($dashboardHref) ?>">
            <span class="brand__mark" aria-hidden="true">FL</span>
            <span class="brand__text">
                <span class="brand__name"><?= e(FLEETRA_NAME) ?></span>
                <span class="brand__tagline"><?= e(FLEETRA_TAGLINE) ?></span>
            </span>
        </a>
        <button type="button" class="sidebar__collapse" id="sidebarCollapse"
                aria-label="Collapse sidebar" title="Collapse sidebar">
            <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="sidebar__nav">
        <?php foreach ($navGroups as $group): ?>
            <?php
            $visibleItems = array_values(array_filter(
                $group['items'],
                static fn (array $item): bool => nav_item_allowed($item)
            ));

            if ($visibleItems === []) {
                continue;
            }
            ?>
            <div class="nav-group">
                <span class="nav-group__label"><?= e($group['section']) ?></span>
                <ul class="nav-list">
                    <?php foreach ($visibleItems as $item): ?>
                        <?php
                        $href     = nav_resolve_href($item);
                        $isActive = $active_nav === $item['key'];
                        $icon     = (string) $item['icon'];
                        $label    = (string) $item['label'];
                        $badge    = null;

                        if (($item['badge'] ?? null) === 'unread_notifications' && $unreadCount > 0) {
                            $badge = $unreadCount > 99 ? '99+' : (string) $unreadCount;
                        }
                        ?>
                        <li>
                            <?php if ($href !== null): ?>
                                <a class="nav-link<?= $isActive ? ' is-active' : '' ?>" href="<?= e($href) ?>"
                                   <?= $isActive ? 'aria-current="page"' : '' ?>>
                                    <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                                    <span class="nav-link__text"><?= e($label) ?></span>
                                    <?php if ($badge !== null): ?>
                                        <span class="nav-badge"><?= e($badge) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php else: ?>
                                <span class="nav-link is-disabled" title="<?= e($label) ?> — planned for a later phase">
                                    <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                                    <span class="nav-link__text"><?= e($label) ?></span>
                                    <span class="nav-soon">Soon</span>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <div class="sidebar-user">
            <a class="sidebar-user__link" href="<?= e(url('profile.php')) ?>" title="Open my profile">
                <?= avatar_markup($navUser, 'sm') ?>
                <span class="sidebar-user__text">
                    <span class="sidebar-user__name"><?= e($navUser['name'] ?? 'Guest') ?></span>
                    <span class="sidebar-user__role"><?= e(role_label(current_role())) ?></span>
                </span>
            </a>
            <a class="sidebar-user__logout" href="<?= e(url('logout.php')) ?>" title="Sign out" aria-label="Sign out">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</aside>
