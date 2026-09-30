<?php
/**
 * Fleetra — Notification module logic
 * ------------------------------------------------------------------
 * modules/notifications/_logic.php
 *
 * The notification centre is shared by every role. Each user only ever
 * sees their own messages; administrators and managers can additionally
 * broadcast an announcement to a chosen audience.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

/* Announcement broadcasts call notify_user(), which lives in the shared
   operations layer. Load it here so the composer always has it. */
require_once __DIR__ . '/../../includes/operations.php';

/**
 * Mark one notification as read, but only when it belongs to the user.
 * Returns true when the row was (or already is) read.
 */
function mark_notification_read(int $notificationId, int $userId): bool
{
    if ($notificationId <= 0 || $userId <= 0) {
        return false;
    }

    $owned = db_one(
        'SELECT id, is_read FROM notifications WHERE id = ? AND user_id = ? LIMIT 1',
        [$notificationId, $userId]
    );

    if ($owned === null) {
        return false;
    }

    if ((int) $owned['is_read'] === 1) {
        return true;
    }

    db_update('notifications', ['is_read' => 1], ['id' => $notificationId, 'user_id' => $userId]);

    return true;
}

/**
 * Mark every unread notification for a user as read.
 *
 * @return int Number of messages marked read.
 */
function mark_all_notifications_read(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    return db_execute('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$userId]);
}

/**
 * Audience options for the announcement composer.
 *
 * @return array<string, string>
 */
function announcement_audience_options(): array
{
    return [
        'all'        => 'Everyone',
        'staff'      => 'All staff (admin, managers, dispatch, drivers)',
        'passengers' => 'All passengers',
        'drivers'    => 'Drivers only',
        'admin'      => 'Administrators only',
        'manager'    => 'Transport managers only',
        'dispatcher' => 'Dispatchers only',
    ];
}

/**
 * Resolve an audience key to the user ids it contains.
 *
 * @return array<int, int>
 */
function announcement_recipients(string $audience): array
{
    $sql    = "SELECT id FROM users WHERE status = 'active'";
    $params = [];

    switch ($audience) {
        case 'all':
            break;
        case 'staff':
            $sql .= " AND role IN ('admin','manager','dispatcher','driver')";
            break;
        case 'passengers':
            $sql .= " AND role = 'passenger'";
            break;
        case 'drivers':
            $sql .= " AND role = 'driver'";
            break;
        case 'admin':
        case 'manager':
        case 'dispatcher':
            $sql .= ' AND role = ?';
            $params[] = $audience;
            break;
        default:
            return [];
    }

    return array_map('intval', array_column(db_all($sql, $params), 'id'));
}

/**
 * Send an announcement to an audience.
 *
 * @return array{sent:int, audience:int}
 */
function send_announcement(string $audience, string $title, string $message, string $type = 'system'): array
{
    $recipients = announcement_recipients($audience);
    $sent       = 0;

    foreach ($recipients as $userId) {
        notify_user($userId, $title, $message, $type);
        $sent++;
    }

    return ['sent' => $sent, 'audience' => count($recipients)];
}

/**
 * Unread / total counts for the signed-in user.
 *
 * @return array{unread:int, total:int, emergency:int, week:int}
 */
function notification_summary(int $userId): array
{
    if ($userId <= 0) {
        return ['unread' => 0, 'total' => 0, 'emergency' => 0, 'week' => 0];
    }

    return [
        'unread'    => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId], 0),
        'total'     => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId], 0),
        'emergency' => (int) db_value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND notification_type = 'emergency'",
            [$userId],
            0
        ),
        'week'      => (int) db_value(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            [$userId],
            0
        ),
    ];
}
