<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * reset-password.php — complete a password reset
 *
 * The token from the link is compared against the stored SHA-256 digest,
 * must be unused and must not have expired. On success the token is
 * burned and every other outstanding token for that user is deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

guest_only();

$email    = get('email');
$token    = get('token');
$errors   = [];
$resetRow = null;

if ($email !== '' && $token !== '') {
    $resetRow = db_one(
        'SELECT pr.*, u.status AS user_status
           FROM password_resets pr
           JOIN users u ON u.id = pr.user_id
          WHERE pr.email = ?
            AND pr.token_hash = ?
            AND pr.used_at IS NULL
            AND pr.expires_at > NOW()
          ORDER BY pr.id DESC
          LIMIT 1',
        [$email, hash('sha256', $token)]
    );
}

$linkIsValid = $resetRow !== null;

if (is_post()) {
    require_csrf();

    $password        = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!$linkIsValid) {
        $errors['form'] = 'This reset link is invalid, already used or expired. Please request a new one.';
    } else {
        if ($password === '') {
            $errors['password'] = 'Enter your new password.';
        } elseif (($issue = password_issues($password)) !== null) {
            $errors['password'] = $issue;
        } elseif ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'The passwords you entered do not match.';
        }

        if ($errors === []) {
            $userId = (int) $resetRow['user_id'];

            db_update(
                'users',
                ['password' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active'],
                ['id' => $userId]
            );

            // Burn this token and any other outstanding links.
            db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);

            log_activity('Reset account password', 'auth', $userId, 'Password changed through a reset link');
            fleetra_log('Password reset completed for user #' . $userId, 'INFO');

            flash('success', 'Your password has been updated. Sign in with your new password.');
            redirect('login.php?reset=1');
        }
    }
}

$auth_heading  = 'Choose a new password';
$auth_subtitle = $linkIsValid
    ? 'Set a new password for ' . $email . '. This link can only be used once.'
    : 'This reset link cannot be used.';
$auth_aside_title = 'A fresh password, in seconds.';
$auth_aside_text  = 'Passwords are stored using PHP password_hash(), never in plain text, and every reset link is single-use.';

require __DIR__ . '/includes/auth-header.php';
?>

<?php if (!$linkIsValid): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This password reset link is invalid, has already been used, or has expired.
            Reset links are valid for 60 minutes.
        </span>
    </div>

    <a href="<?= e(url('forgot-password.php')) ?>" class="btn btn-primary btn-lg btn-block">
        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Request a new link
    </a>
<?php else: ?>
    <?php if (isset($errors['form'])): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text"><?= e($errors['form']) ?></span>
        </div>
    <?php endif; ?>

    <form method="post"
          action="<?= e(url('reset-password.php?email=' . rawurlencode($email) . '&token=' . rawurlencode($token))) ?>"
          novalidate>
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label" for="password">New password <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                       id="password" name="password" required autofocus autocomplete="new-password"
                       placeholder="At least 8 characters">
                <button class="btn btn-outline-secondary" type="button" data-toggle-password="password"
                        aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <?php if (isset($errors['password'])): ?>
                <p class="form-error"><?= e($errors['password']) ?></p>
            <?php else: ?>
                <p class="form-text">Use at least 8 characters including a letter and a number.</p>
            <?php endif; ?>
        </div>

        <div class="mb-4">
            <label class="form-label" for="password_confirm">Confirm new password <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>"
                       id="password_confirm" name="password_confirm" required autocomplete="new-password"
                       placeholder="Re-enter the new password">
                <button class="btn btn-outline-secondary" type="button" data-toggle-password="password_confirm"
                        aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <?php if (isset($errors['password_confirm'])): ?>
                <p class="form-error"><?= e($errors['password_confirm']) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block" data-loading-text="Updating password…">
            <i class="bi bi-shield-check" aria-hidden="true"></i> Update password
        </button>
    </form>
<?php endif; ?>

<p class="auth-foot">
    <a href="<?= e(url('login.php')) ?>">Back to sign in</a>
</p>

<?php require __DIR__ . '/includes/auth-footer.php'; ?>
