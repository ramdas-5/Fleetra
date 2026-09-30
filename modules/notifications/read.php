<?php
/**
 * Fleetra — Notifications / Mark as read
 * ------------------------------------------------------------------
 * modules/notifications/read.php
 *
 * Handles the plain HTML form posts for "mark read" and "mark all read".
 * The AJAX equivalent lives in api/notifications.php so the interface can
 * respond without a reload, but this path always works.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('notifications.view');
require_csrf();

if (!is_post()) {
    redirect('modules/notifications/index.php');
}

$action = post('action');
$userId = user_id();

if ($action === 'all') {
    $marked = mark_all_notifications_read($userId);

    flash('success', $marked > 0
        ? $marked . ' notification' . ($marked === 1 ? '' : 's') . ' marked as read.'
        : 'There was nothing unread.');

    redirect('modules/notifications/index.php');
}

if ($action === 'one') {
    $notificationId = get_int('id');

    if (mark_notification_read($notificationId, $userId)) {
        flash('success', 'Notification marked as read.');
    } else {
        flash('warning', 'That notification could not be found.');
    }

    redirect('modules/notifications/index.php');
}

flash('danger', 'That notification action is not valid.');
redirect('modules/notifications/index.php');
