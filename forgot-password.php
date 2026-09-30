<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * forgot-password.php — request a reset link
 *
 * The raw token is never stored: only its SHA-256 digest is written to
 * password_resets, so a database leak cannot be used to reset accounts.
 *
 * No mail transport is configured in this local build, so the generated
 * link is displayed on screen (development only). Set APP_ENV to
 * 'production' and wire up mail() / SMTP to send it by email instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

guest_only();

$errors    = [];
$email     = '';
$notice    = '';
$devLink   = null;

if (is_post()) {
    require_csrf();

    $email = post('email');

    if ($email === '') {
        $errors['email'] = 'Enter the email address linked to your account.';
    } elseif (!is_valid_email($email)) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        $user = db_one(
            'SELECT id, name, email, status FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        if ($user !== null && $user['status'] === 'active') {
            $userId = (int) $user['id'];

            // Any older unused link becomes invalid immediately.
            db_execute('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$userId]);

            $token = bin2hex(random_bytes(32));

            db_insert('password_resets', [
                'user_id'    => $userId,
                'email'      => (string) $user['email'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_SECONDS),
            ]);

            log_activity('Requested password reset', 'auth', $userId, 'Reset link generated for ' . $user['email']);
            fleetra_log('Password reset token issued for user #' . $userId, 'INFO');

            if (APP_ENV === 'local') {
                $devLink = url('reset-password.php?email=' . rawurlencode((string) $user['email']) . '&token=' . $token);
            }
        } else {
            fleetra_log('Password reset requested for unknown or inactive email: ' . $email, 'INFO');
        }

        // The same message is shown either way so the page cannot be used
        // to discover which email addresses have accounts.
        $notice = 'If an account exists for ' . $email . ', a password reset link has been generated.';
    }
}

$auth_heading  = 'Reset your password';
$auth_subtitle = 'Enter your account email and we will generate a secure reset link.';
$auth_aside_title = 'Locked out? It happens.';
$auth_aside_text  = 'Password resets in Fleetra are single-use, expire after 60 minutes and are stored only as a hashed token.';

require __DIR__ . '/includes/auth-header.php';
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success app-alert" role="alert">
        <i class="bi bi-check-circle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text"><?= e($notice) ?></span>
    </div>
<?php endif; ?>

<?php if ($devLink !== null): ?>
    <div class="demo-credentials">
        <p class="demo-credentials__title">
            <i class="bi bi-tools" aria-hidden="true"></i> Local development — email not configured
        </p>
        <p class="form-text mb-2">
            No mail service is set up on this machine, so the reset link is shown here instead of
            being emailed. It expires in 60 minutes and can be used once.
        </p>
        <a class="btn btn-outline-secondary btn-sm w-100" href="<?= e($devLink) ?>">
            <i class="bi bi-key" aria-hidden="true"></i> Open reset link
        </a>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('forgot-password.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-4">
        <label class="form-label" for="email">Email address <span class="req">*</span></label>
        <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
               id="email" name="email" value="<?= e($email) ?>" required autofocus
               placeholder="you@fleetra.com" autocomplete="username">
        <?php if (isset($errors['email'])): ?>
            <p class="form-error"><?= e($errors['email']) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-lg btn-block" data-loading-text="Generating link…">
        <i class="bi bi-send" aria-hidden="true"></i> Send reset link
    </button>
</form>

<p class="auth-foot">
    Remembered your password? <a href="<?= e(url('login.php')) ?>" class="fw-600">Back to sign in</a>
</p>

<?php require __DIR__ . '/includes/auth-footer.php'; ?>
