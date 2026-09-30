<?php
/**
 * Fleetra — Administration / Edit user
 * ------------------------------------------------------------------
 * modules/users/edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('users.manage');

$userId = get_int('id');

if ($userId <= 0) {
    abort_not_found('No account was specified.');
}

$account = db_one('SELECT * FROM users WHERE id = ? AND status <> "deleted" LIMIT 1', [$userId]);

if ($account === null) {
    abort_not_found('That account does not exist.');
}

$errors = [];
$values = user_form_values($account);

if (is_post()) {
    require_csrf();

    $result = validate_user_request($_POST, true, $userId, user_id());
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        db_update('users', user_db_payload($values), ['id' => $userId]);

        $newPassword = (string) ($_POST['password'] ?? '');
        if ($newPassword !== '') {
            db_update('users', ['password' => password_hash($newPassword, PASSWORD_DEFAULT)], ['id' => $userId]);
            db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
            fleetra_log('Password reset for user #' . $userId . ' by admin #' . user_id(), 'INFO');
        }

        log_activity(
            'Updated user account for ' . $values['name'],
            'users',
            $userId,
            role_label($values['role']) . ' · status ' . $values['status']
                . ($newPassword !== '' ? ' · password reset' : '')
        );

        // Keep the signed-in identity fresh if an administrator edited themselves.
        if ($userId === user_id()) {
            current_user(true);
            $_SESSION['user_name'] = $values['name'];
        }

        flash('success', $values['name'] . '\'s account was updated.');
        redirect('modules/users/view.php?id=' . $userId);
    }
}

$formAction = url('modules/users/edit.php?id=' . $userId);
$isEdit     = true;

$page_title       = 'Edit ' . $account['name'];
$active_nav       = 'users';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Administration', 'url' => url('modules/users/index.php')],
    ['label' => 'Users', 'url' => url('modules/users/index.php')],
    ['label' => (string) $account['name'], 'url' => url('modules/users/view.php?id=' . $userId)],
    ['label' => 'Edit'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Edit ' . $account['name'],
    role_label((string) $account['role']) . ' · ' . $account['email'],
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/users/view.php?id=' . $userId)) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to account
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
