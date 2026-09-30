<?php
/**
 * Fleetra — Administration / Delete or archive a user account
 * ------------------------------------------------------------------
 * modules/users/delete.php
 *
 * POST only, CSRF protected.
 *
 * Two accounts can never be removed: the one you are signed in with, and
 * the last active administrator. Both would lock the business out of
 * Fleetra, so they are refused outright rather than silently ignored.
 *
 * An account with history (bookings, a driver profile, notifications or
 * audit entries) is archived: the row is kept with status "deleted" so
 * every past record still resolves to a name, sign-in is disabled and the
 * email address is released for reuse. An account with no history at all
 * is removed completely.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('users.manage');

if (!is_post()) {
    flash('warning', 'Deleting a user account requires a confirmed form submission.');
    redirect('modules/users/index.php');
}

require_csrf();

$userId = get_int('id');

if ($userId <= 0) {
    abort_not_found('No account was specified.');
}

$account = db_one('SELECT * FROM users WHERE id = ? AND status <> "deleted" LIMIT 1', [$userId]);

if ($account === null) {
    abort_not_found('That account does not exist.');
}

$name = (string) $account['name'];
$role = (string) $account['role'];

/* ------------------------------------------------------------------
 | Lock-out protection
 ------------------------------------------------------------------ */

if ($userId === user_id()) {
    flash('danger', 'You cannot delete the account you are signed in with. Ask another administrator to do it.');
    redirect('modules/users/view.php?id=' . $userId);
}

if ($role === 'admin' && (string) $account['status'] === 'active' && active_admin_count() <= 1) {
    flash(
        'danger',
        $name . ' is the only active administrator. Promote another account to administrator before deleting this one, '
        . 'otherwise nobody would be able to manage Fleetra.'
    );
    redirect('modules/users/view.php?id=' . $userId);
}

$references = user_reference_counts($userId);
$hasHistory = array_sum($references) > 0;

$driverRecord = db_one('SELECT id, employee_id, assigned_bus_id FROM drivers WHERE user_id = ? LIMIT 1', [$userId]);

/* ------------------------------------------------------------------
 | Archive: keep the history, cut off access
 ------------------------------------------------------------------ */

if ($hasHistory) {
    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Tombstone the email so the address can be reused, and drop the
        // avatar. The row itself stays so every historic record still
        // resolves to a real name.
        db_execute(
            'UPDATE users
                SET status = "deleted",
                    email  = LEFT(CONCAT("deleted-", id, "-", email), 160),
                    profile_image = NULL
              WHERE id = ?',
            [$userId]
        );

        db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);

        // A driver losing their login must not stay bookable on a schedule.
        if ($driverRecord !== null) {
            db_update('drivers', ['assigned_bus_id' => null, 'employment_status' => 'resigned'], ['id' => (int) $driverRecord['id']]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fleetra_log('User archive failed: ' . $exception->getMessage());
        fleetra_fatal('Something went wrong while deleting the account. Please try again.');
    }

    if (!empty($account['profile_image'])) {
        delete_upload((string) $account['profile_image'], 'profiles');
    }

    log_activity(
        'Archived user account for ' . $name,
        'users',
        $userId,
        sprintf(
            'Account archived with %d bookings, %d notifications and %d audit entries kept',
            $references['bookings'],
            $references['notifications'],
            $references['logs']
        )
    );
    fleetra_log('User #' . $userId . ' archived by admin #' . user_id(), 'INFO');

    $logCount = $references['logs'];

    flash(
        'warning',
        $name . '\'s account was archived rather than erased: ' . $references['bookings'] . ' booking(s) and '
        . $logCount . ' audit ' . ($logCount === 1 ? 'entry' : 'entries') . ' were kept on record. Sign-in is disabled '
        . 'and the email address is now free to use again.'
        . ($driverRecord !== null ? ' Their driver profile was released from any bus and marked as resigned.' : '')
    );

    redirect('modules/users/index.php');
}

/* ------------------------------------------------------------------
 | No history — remove the account completely
 ------------------------------------------------------------------ */

if (!empty($account['profile_image'])) {
    delete_upload((string) $account['profile_image'], 'profiles');
}

$pdo = db();

try {
    $pdo->beginTransaction();

    db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
    db_execute('DELETE FROM notifications WHERE user_id = ?', [$userId]);
    db_execute('DELETE FROM users WHERE id = ?', [$userId]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fleetra_log('User deletion failed: ' . $exception->getMessage());
    fleetra_fatal('Something went wrong while deleting the account. Please try again.');
}

log_activity('Deleted user account for ' . $name, 'users', $userId, role_label($role) . ' account removed completely');
fleetra_log('User #' . $userId . ' deleted by admin #' . user_id(), 'INFO');

flash('success', $name . '\'s account was deleted.');
redirect('modules/users/index.php');
