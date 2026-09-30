<?php
/**
 * Fleetra — Notifications API
 * ------------------------------------------------------------------
 * api/notifications.php
 *
 * JSON endpoint used by the notification centre and the topbar bell.
 * Authentication, permissions and the CSRF token are all verified here on
 * the server — the browser is never trusted.
 *
 * POST actions
 *   action=mark_read   id=<notification id>
 *   action=mark_all
 *
 * GET
 *   ?action=count      -> the unread badge count
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../modules/notifications/_logic.php';

require_permission('notifications.view');

$userId = user_id();
$action = is_post() ? post('action', 'mark_read') : get('action', 'count');

/* CSRF is required for every write. A JSON error is returned rather than
   the HTML error page, because the caller is a script. */
if (is_post() && !csrf_verify($_POST['csrf_token'] ?? null)) {
    fleetra_log('CSRF validation failed for api/notifications.php (user #' . $userId . ')', 'WARNING');
    json_response([
        'success' => false,
        'message' => 'Your session expired. Reload the page and try again.',
    ], 419);
}

switch ($action) {
    case 'mark_read':
        $notificationId = post_int('id');

        if (!mark_notification_read($notificationId, $userId)) {
            json_response([
                'success' => false,
                'message' => 'That notification could not be found.',
            ], 404);
        }

        json_response([
            'success' => true,
            'message' => 'Notification marked as read.',
            'unread'  => unread_notification_count($userId),
        ]);
        // no break — json_response() exits.

    case 'mark_all':
        $marked = mark_all_notifications_read($userId);

        json_response([
            'success' => true,
            'message' => $marked > 0
                ? $marked . ' notification' . ($marked === 1 ? '' : 's') . ' marked as read.'
                : 'There was nothing unread.',
            'marked'  => $marked,
            'unread'  => unread_notification_count($userId),
        ]);

    case 'count':
        json_response([
            'success' => true,
            'unread'  => unread_notification_count($userId),
        ]);

    default:
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}
