<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * change-password.php — change your own password
 *
 * Requires the current password, so an unattended session cannot be used
 * to lock the owner out. Any outstanding password-reset links for the
 * account are revoked and the session id is rotated afterwards.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

require_login();

$user   = current_user();
$errors = [];

if (is_post()) {
    require_csrf();

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword     = (string) ($_POST['password'] ?? '');
    $confirmation    = (string) ($_POST['password_confirm'] ?? '');

    if ($currentPassword === '') {
        $errors['current_password'] = 'Enter your current password.';
    } elseif (!password_verify($currentPassword, (string) $user['password'])) {
        $errors['current_password'] = 'Your current password is not correct.';
    }

    if ($newPassword === '') {
        $errors['password'] = 'Enter a new password.';
    } elseif (($issue = password_issues($newPassword)) !== null) {
        $errors['password'] = $issue;
    } elseif (password_verify($newPassword, (string) $user['password'])) {
        $errors['password'] = 'Your new password must be different from your current password.';
    } elseif ($newPassword !== $confirmation) {
        $errors['password_confirm'] = 'The new passwords do not match.';
    }

    if ($errors === []) {
        $userId = (int) $user['id'];

        db_update('users', ['password' => password_hash($newPassword, PASSWORD_DEFAULT)], ['id' => $userId]);

        // Old reset links must not survive a password change.
        db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);

        log_activity('Changed own password', 'auth', $userId, 'Password updated from the profile area');
        fleetra_log('User #' . $userId . ' changed their password', 'INFO');

        // Rotate the session id so any previously captured id is useless.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        flash('success', 'Your password has been updated. Use it the next time you sign in.');
        redirect('profile.php');
    }
}

$page_title       = 'Change password';
$active_nav       = '';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'My profile', 'url' => url('profile.php')],
    ['label' => 'Change password'],
];

require __DIR__ . '/includes/header.php';
?>

<?= render_page_header(
    'Change password',
    'Choose a strong password you do not use anywhere else',
    '<a class="btn btn-outline-secondary" href="' . e(url('profile.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to profile
     </a>'
) ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">Your password could not be changed. Please review the messages below.</span>
    </div>
<?php endif; ?>

<div class="grid-wide-side">
    <form method="post" action="<?= e(url('change-password.php')) ?>" novalidate>
        <?= csrf_field() ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Password</h2>
                    <p class="card-fl__subtitle">
                        Signed in as <strong><?= e($user['name']) ?></strong>
                        (<?= e($user['email']) ?>)
                    </p>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="mb-3">
                    <label class="form-label" for="current_password">Current password <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password"
                               class="form-control<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                               id="current_password" name="current_password" required autofocus
                               autocomplete="current-password" placeholder="Enter your current password">
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-password="current_password" aria-label="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['current_password'])): ?>
                        <p class="form-error"><?= e($errors['current_password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">New password <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                               id="password" name="password" required autocomplete="new-password"
                               placeholder="At least 8 characters">
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-password="password" aria-label="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <p class="form-error"><?= e($errors['password']) ?></p>
                    <?php else: ?>
                        <p class="form-text">At least 8 characters, including a letter and a number.</p>
                    <?php endif; ?>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="password_confirm">Confirm new password <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password"
                               class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>"
                               id="password_confirm" name="password_confirm" required autocomplete="new-password"
                               placeholder="Re-enter the new password">
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-password="password_confirm" aria-label="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password_confirm'])): ?>
                        <p class="form-error"><?= e($errors['password_confirm']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <a class="btn btn-outline-secondary" href="<?= e(url('profile.php')) ?>">Cancel</a>
                    <button type="submit" class="btn btn-primary" data-loading-text="Updating…">
                        <i class="bi bi-shield-check" aria-hidden="true"></i> Update password
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Password guidance</h2>
                <p class="card-fl__subtitle">What makes a Fleetra password strong</p>
            </div>
        </div>
        <div class="card-fl__body">
            <ul class="fact-list">
                <li>
                    <span class="fact-list__label">Minimum length</span>
                    <span class="fact-list__value">8 characters</span>
                </li>
                <li>
                    <span class="fact-list__label">Must include</span>
                    <span class="fact-list__value">A letter and a number</span>
                </li>
                <li>
                    <span class="fact-list__label">Storage</span>
                    <span class="fact-list__value">Bcrypt hashed</span>
                </li>
            </ul>

            <div class="divider"></div>

            <p class="form-text mb-0">
                Avoid names, vehicle numbers and dates of birth. When you change your password, any
                outstanding password-reset links for your account are cancelled immediately.
            </p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
