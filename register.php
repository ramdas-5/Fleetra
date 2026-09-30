<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * register.php — passenger self-registration
 *
 * Public sign-up creates Passenger accounts only. Drivers and staff are
 * created by an administrator, so the role is never taken from the
 * submitted form.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

guest_only();

$errors = [];
$values = ['name' => '', 'email' => '', 'phone' => ''];

if (is_post()) {
    require_csrf();

    $values['name']  = post('name');
    $values['email'] = post('email');
    $values['phone'] = post('phone');

    $password        = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    // Full name -------------------------------------------------------
    if ($values['name'] === '') {
        $errors['name'] = 'Enter your full name.';
    } elseif (mb_strlen($values['name']) < 3) {
        $errors['name'] = 'Your name must be at least 3 characters long.';
    } elseif (mb_strlen($values['name']) > 120) {
        $errors['name'] = 'Your name cannot be longer than 120 characters.';
    }

    // Email -----------------------------------------------------------
    if ($values['email'] === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!is_valid_email($values['email'])) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        $existing = db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$values['email']], 0);
        if ((int) $existing > 0) {
            $errors['email'] = 'An account already exists with this email address.';
        }
    }

    // Phone -----------------------------------------------------------
    if ($values['phone'] === '') {
        $errors['phone'] = 'Enter your mobile number.';
    } elseif (!is_valid_phone($values['phone'])) {
        $errors['phone'] = 'Enter a valid mobile number (10 to 15 digits).';
    }

    // Password --------------------------------------------------------
    if ($password === '') {
        $errors['password'] = 'Choose a password.';
    } elseif (($issue = password_issues($password)) !== null) {
        $errors['password'] = $issue;
    } elseif ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'The passwords you entered do not match.';
    }

    if (!isset($_POST['terms'])) {
        $errors['terms'] = 'Please accept the terms to create an account.';
    }

    if ($errors === []) {
        $userId = db_insert('users', [
            'name'     => $values['name'],
            'email'    => $values['email'],
            'phone'    => $values['phone'],
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role'     => 'passenger',
            'status'   => 'active',
        ]);

        log_activity('Registered passenger account', 'users', $userId, 'Self-registration for ' . $values['email']);
        fleetra_log('New passenger account #' . $userId . ' created for ' . $values['email'], 'INFO');

        flash('success', 'Your account has been created. Sign in with your new credentials.');
        redirect('login.php?registered=1');
    }
}

$auth_heading  = 'Create your ' . FLEETRA_NAME . ' account';
$auth_subtitle = 'Book seats, follow your bus live and keep every ticket in one place.';
$auth_aside_title = 'Travel smarter with Fleetra.';
$auth_aside_text  = 'Register once to search routes, reserve a seat and keep your tickets ready on any device.';

require __DIR__ . '/includes/auth-header.php';
?>

<form method="post" action="<?= e(url('register.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text">Please correct the highlighted fields and try again.</span>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label" for="name">Full name <span class="req">*</span></label>
        <input type="text" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
               id="name" name="name" value="<?= e($values['name']) ?>" required
               placeholder="e.g. Ananya Sharma" autocomplete="name" autofocus>
        <?php if (isset($errors['name'])): ?><p class="form-error"><?= e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="email">Email address <span class="req">*</span></label>
        <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
               id="email" name="email" value="<?= e($values['email']) ?>" required
               placeholder="you@example.com" autocomplete="email">
        <?php if (isset($errors['email'])): ?><p class="form-error"><?= e($errors['email']) ?></p><?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="phone">Mobile number <span class="req">*</span></label>
        <input type="tel" class="form-control<?= isset($errors['phone']) ? ' is-invalid' : '' ?>"
               id="phone" name="phone" value="<?= e($values['phone']) ?>" required
               placeholder="+91 98765 43210" autocomplete="tel">
        <?php if (isset($errors['phone'])): ?><p class="form-error"><?= e($errors['phone']) ?></p><?php endif; ?>
        <p class="form-text">Used for ticket updates and trip notifications.</p>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">Password <span class="req">*</span></label>
        <div class="input-group">
            <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                   id="password" name="password" required autocomplete="new-password"
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

    <div class="mb-3">
        <label class="form-label" for="password_confirm">Confirm password <span class="req">*</span></label>
        <div class="input-group">
            <input type="password" class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>"
                   id="password_confirm" name="password_confirm" required autocomplete="new-password"
                   placeholder="Re-enter your password">
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password_confirm"
                    aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
        </div>
        <?php if (isset($errors['password_confirm'])): ?>
            <p class="form-error"><?= e($errors['password_confirm']) ?></p>
        <?php endif; ?>
    </div>

    <div class="form-check mb-4">
        <input class="form-check-input<?= isset($errors['terms']) ? ' is-invalid' : '' ?>"
               type="checkbox" value="1" id="terms" name="terms">
        <label class="form-check-label" for="terms" style="font-size:13.5px;">
            I agree to the Fleetra terms of service and privacy policy.
        </label>
        <?php if (isset($errors['terms'])): ?><p class="form-error"><?= e($errors['terms']) ?></p><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-lg btn-block" data-loading-text="Creating account…">
        <i class="bi bi-person-plus" aria-hidden="true"></i> Create account
    </button>
</form>

<p class="auth-foot">
    Already have an account? <a href="<?= e(url('login.php')) ?>" class="fw-600">Sign in</a>
</p>

<?php require __DIR__ . '/includes/auth-footer.php'; ?>
