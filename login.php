<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * login.php — sign in
 *
 * Security:
 *   - CSRF token verified on every submission.
 *   - Passwords verified with password_verify() against a bcrypt hash.
 *   - Failed attempts are throttled per browser + email address.
 *   - The session id is regenerated on success (anti-fixation).
 *   - Redirect targets are validated to block open redirects.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

guest_only();

// Friendly notices for visitors arriving from a guarded page or a reset.
$reason = get('reason');
if ($reason === 'timeout') {
    flash('warning', 'Your session expired after a period of inactivity. Please sign in again.');
} elseif ($reason === 'session') {
    flash('warning', 'Your session is no longer valid. Please sign in again.');
} elseif (get('registered') === '1') {
    flash('success', 'Your Fleetra passenger account is ready. Sign in to continue.');
} elseif (get('reset') === '1') {
    flash('success', 'Your password has been updated. Sign in with your new password.');
} elseif (get('logged_out') === '1') {
    flash('info', 'You have been signed out of Fleetra.');
}

$errors = [];
$email  = '';

if (is_post()) {
    require_csrf();

    $email    = post('email');
    $password = (string) ($_POST['password'] ?? '');
    $remember = post('remember') === '1';

    if ($email === '') {
        $errors['email'] = 'Enter the email address linked to your account.';
    } elseif (!is_valid_email($email)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }

    if ($errors === []) {
        $result = attempt_login($email, $password, $remember);

        if ($result['ok']) {
            /** @var array<string, mixed> $account */
            $account = $result['user'];

            log_activity('Signed in', 'auth', (int) $account['id'], 'Successful sign in');

            $target = safe_redirect_target($_SESSION['intended_url'] ?? null);
            unset($_SESSION['intended_url']);

            $firstName = explode(' ', trim((string) $account['name']))[0];
            flash('success', 'Welcome back, ' . $firstName . '.');

            redirect($target ?? dashboard_path((string) $account['role']));
        }

        $errors['form'] = (string) $result['message'];
    }
}

$demoAccounts = [
    ['Administrator',     'admin@fleetra.com'],
    ['Transport Manager', 'manager@fleetra.com'],
    ['Dispatcher',        'dispatcher@fleetra.com'],
    ['Driver',            'driver1@fleetra.com'],
    ['Passenger',         'passenger@fleetra.com'],
];

$auth_heading  = 'Sign in to ' . FLEETRA_NAME;
$auth_subtitle = 'Use your Fleetra account to open the operations console.';

require __DIR__ . '/includes/auth-header.php';
?>

<form method="post" action="<?= e(url('login.php')) ?>" novalidate autocomplete="on">
    <?= csrf_field() ?>

    <?php if (isset($errors['form'])): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text"><?= e($errors['form']) ?></span>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label" for="email">Email address <span class="req">*</span></label>
        <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
               id="email" name="email" value="<?= e($email) ?>"
               placeholder="you@fleetra.com" required autofocus
               autocomplete="username" aria-describedby="emailError">
        <?php if (isset($errors['email'])): ?>
            <p class="form-error" id="emailError"><?= e($errors['email']) ?></p>
        <?php endif; ?>
    </div>

    <div class="mb-2">
        <label class="form-label" for="password">Password <span class="req">*</span></label>
        <div class="input-group">
            <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                   id="password" name="password" required
                   placeholder="Enter your password" autocomplete="current-password"
                   aria-describedby="passwordError">
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password"
                    aria-label="Show password">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
        </div>
        <?php if (isset($errors['password'])): ?>
            <p class="form-error" id="passwordError"><?= e($errors['password']) ?></p>
        <?php endif; ?>
    </div>

    <div class="auth-row">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
            <label class="form-check-label" for="remember" style="font-size:13.5px;">Remember me for 30 days</label>
        </div>
        <a href="<?= e(url('forgot-password.php')) ?>" style="font-size:13.5px;font-weight:500;">Forgot password?</a>
    </div>

    <button type="submit" class="btn btn-primary btn-lg btn-block" data-loading-text="Signing in…">
        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign in
    </button>
</form>

<div class="auth-divider">New to <?= e(FLEETRA_NAME) ?>?</div>

<a href="<?= e(url('register.php')) ?>" class="btn btn-outline-secondary btn-block">
    <i class="bi bi-person-plus" aria-hidden="true"></i> Create a passenger account
</a>

<?php if (FLEETRA_SHOW_DEMO_CREDENTIALS): ?>
    <div class="demo-credentials">
        <p class="demo-credentials__title">
            <i class="bi bi-info-circle" aria-hidden="true"></i> Demo accounts — local development
        </p>
        <?php foreach ($demoAccounts as [$role, $mail]): ?>
            <div class="demo-credentials__row">
                <span>
                    <span class="demo-credentials__role"><?= e($role) ?></span><br>
                    <span class="demo-credentials__mail"><?= e($mail) ?></span>
                </span>
                <button type="button" class="demo-credentials__fill"
                        data-demo-fill="<?= e($mail) ?>" data-demo-password="Fleetra@123">
                    Use account
                </button>
            </div>
        <?php endforeach; ?>
        <p class="form-text mt-2 mb-0">
            Every demo account uses the password <code>Fleetra@123</code>.
            This panel is hidden when APP_ENV is set to <code>production</code>.
        </p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/auth-footer.php'; ?>
