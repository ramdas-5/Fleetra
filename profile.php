<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * profile.php — the signed-in user's own profile
 *
 * Any authenticated user can edit their display name, phone number,
 * sign-in email and profile photo. Changing the sign-in email requires
 * the current password, so a hijacked session cannot silently move the
 * account to an attacker-controlled address.
 *
 * Driver licence details and employment data are managed by the
 * transport office and are shown read-only here.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

require_login();

$user = current_user();

$errors  = [];
$values  = [
    'name'  => (string) $user['name'],
    'phone' => (string) ($user['phone'] ?? ''),
    'email' => (string) $user['email'],
];

if (is_post()) {
    require_csrf();

    $values['name']  = post('name');
    $values['phone'] = post('phone');
    $values['email'] = post('email');

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $removeImage     = isset($_POST['remove_image']);

    // Full name -------------------------------------------------------
    if ($values['name'] === '') {
        $errors['name'] = 'Enter your full name.';
    } elseif (mb_strlen($values['name']) < 3) {
        $errors['name'] = 'Your name must be at least 3 characters long.';
    } elseif (mb_strlen($values['name']) > 120) {
        $errors['name'] = 'Your name cannot be longer than 120 characters.';
    }

    // Phone -----------------------------------------------------------
    if ($values['phone'] !== '' && !is_valid_phone($values['phone'])) {
        $errors['phone'] = 'Enter a valid mobile number (10 to 15 digits), or leave this blank.';
    }

    // Email -----------------------------------------------------------
    $emailChanged = strcasecmp($values['email'], (string) $user['email']) !== 0;

    if ($values['email'] === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!is_valid_email($values['email'])) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif ($emailChanged) {
        if ($currentPassword === '') {
            $errors['current_password'] = 'Enter your current password to change your sign-in email.';
        } elseif (!password_verify($currentPassword, (string) $user['password'])) {
            $errors['current_password'] = 'That password is not correct.';
        } else {
            $taken = (int) db_value(
                'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?',
                [$values['email'], (int) $user['id']],
                0
            );

            if ($taken > 0) {
                $errors['email'] = 'Another Fleetra account already uses this email address.';
            }
        }
    }

    // Profile photo ---------------------------------------------------
    $newImage    = null;
    $fileWasSent = isset($_FILES['profile_image'])
        && (int) ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($fileWasSent) {
        $upload = upload_image($_FILES['profile_image'], 'profiles', 'avatar');

        if (!$upload['ok']) {
            $errors['profile_image'] = (string) $upload['error'];
        } else {
            $newImage = (string) $upload['filename'];
        }
    }

    if ($errors === []) {
        $previousImage = $user['profile_image'] !== null ? (string) $user['profile_image'] : null;

        $data = [
            'name'  => $values['name'],
            'phone' => $values['phone'] === '' ? null : $values['phone'],
            'email' => $values['email'],
        ];

        if ($newImage !== null) {
            $data['profile_image'] = $newImage;
        } elseif ($removeImage) {
            $data['profile_image'] = null;
        }

        db_update('users', $data, ['id' => (int) $user['id']]);

        // Clean up the replaced/removed file so uploads/ does not grow forever.
        if ($previousImage !== null && ($newImage !== null || $removeImage)) {
            delete_upload($previousImage, 'profiles');
        }

        log_activity(
            'Updated own profile',
            'users',
            (int) $user['id'],
            $emailChanged ? 'Profile details and sign-in email changed' : 'Profile details changed'
        );

        if ($emailChanged) {
            fleetra_log('User #' . $user['id'] . ' changed sign-in email to ' . $values['email'], 'INFO');
        }

        // Refresh the cached identity so the topbar and sidebar update.
        current_user(true);
        $_SESSION['user_name'] = $values['name'];

        flash('success', 'Your profile has been updated.');
        redirect('profile.php');
    }
}

// The row may have been refreshed above; always render from fresh data.
$user = current_user(true);

/* ------------------------------------------------------------------
 | Role specific read-only context
 ------------------------------------------------------------------ */

$driverRecord = null;
$passengerStats = null;

if (current_role() === 'driver') {
    $driverRecord = db_one(
        'SELECT d.employee_id, d.license_number, d.license_expiry, d.experience_years,
                d.employment_status, d.joining_date, b.bus_number
           FROM drivers d
           LEFT JOIN buses b ON b.id = d.assigned_bus_id
          WHERE d.user_id = ?
          LIMIT 1',
        [(int) $user['id']]
    );
}

if (current_role() === 'passenger') {
    $passengerStats = [
        'bookings' => (int) db_value('SELECT COUNT(*) FROM bookings WHERE user_id = ?', [(int) $user['id']], 0),
        'tickets'  => (int) db_value(
            'SELECT COUNT(*) FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE b.user_id = ?',
            [(int) $user['id']],
            0
        ),
        'upcoming' => (int) db_value(
            'SELECT COUNT(*) FROM bookings b JOIN schedules s ON s.id = b.schedule_id
              WHERE b.user_id = ? AND b.booking_status IN ("pending","confirmed") AND s.schedule_date >= CURDATE()',
            [(int) $user['id']],
            0
        ),
    ];
}

$hasImage = !empty($user['profile_image']);

$page_title       = 'My profile';
$active_nav       = '';
$page_breadcrumbs = [['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))], ['label' => 'My profile']];

require __DIR__ . '/includes/header.php';
?>

<?= render_page_header(
    'My profile',
    'Manage your personal details, sign-in email and profile photo',
    '<a class="btn btn-outline-secondary" href="' . e(url('change-password.php')) . '">
        <i class="bi bi-key" aria-hidden="true"></i> Change password
     </a>'
) ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">Please correct the highlighted fields and save again.</span>
    </div>
<?php endif; ?>

<div class="grid-wide-side">
    <div>
        <form method="post" action="<?= e(url('profile.php')) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Profile photo</h2>
                        <p class="card-fl__subtitle">JPG, PNG or WEBP up to 2 MB</p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <div class="avatar-editor">
                        <div class="avatar-editor__preview">
                            <?= avatar_markup($user, 'xl') ?>
                        </div>

                        <div class="avatar-editor__fields">
                            <label class="form-label" for="profile_image">Upload a new photo</label>
                            <input type="file" class="form-control<?= isset($errors['profile_image']) ? ' is-invalid' : '' ?>"
                                   id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp">
                            <?php if (isset($errors['profile_image'])): ?>
                                <p class="form-error"><?= e($errors['profile_image']) ?></p>
                            <?php endif; ?>

                            <?php if ($hasImage): ?>
                                <div class="form-check mt-3">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="remove_image" name="remove_image">
                                    <label class="form-check-label" for="remove_image" style="font-size:13.5px;">
                                        Remove my current photo
                                    </label>
                                </div>
                            <?php endif; ?>

                            <p class="avatar-editor__hint">
                                Your photo appears in the top bar, the sidebar and alongside actions you record.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Personal information</h2>
                        <p class="card-fl__subtitle">Used for booking and trip notifications</p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <div class="form-row">
                        <div>
                            <label class="form-label" for="name">Full name <span class="req">*</span></label>
                            <input type="text" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                                   id="name" name="name" value="<?= e($values['name']) ?>" required
                                   autocomplete="name">
                            <?php if (isset($errors['name'])): ?>
                                <p class="form-error"><?= e($errors['name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="form-label" for="phone">Mobile number</label>
                            <input type="tel" class="form-control<?= isset($errors['phone']) ? ' is-invalid' : '' ?>"
                                   id="phone" name="phone" value="<?= e($values['phone']) ?>"
                                   placeholder="+91 98765 43210" autocomplete="tel">
                            <?php if (isset($errors['phone'])): ?>
                                <p class="form-error"><?= e($errors['phone']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="span-2">
                            <label class="form-label" for="email">Email address <span class="req">*</span></label>
                            <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                                   id="email" name="email" value="<?= e($values['email']) ?>" required
                                   autocomplete="email" aria-describedby="emailHelp">
                            <?php if (isset($errors['email'])): ?>
                                <p class="form-error"><?= e($errors['email']) ?></p>
                            <?php endif; ?>
                            <p class="form-text" id="emailHelp">
                                This is the address you sign in with. Changing it requires your current password.
                            </p>
                        </div>

                        <div class="span-2">
                            <label class="form-label" for="current_password">
                                Current password
                                <?php if (strcasecmp($values['email'], (string) $user['email']) !== 0): ?>
                                    <span class="req">*</span>
                                <?php endif; ?>
                            </label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                                       id="current_password" name="current_password"
                                       autocomplete="current-password"
                                       placeholder="Required only when changing your email address">
                                <button class="btn btn-outline-secondary" type="button"
                                        data-toggle-password="current_password" aria-label="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['current_password'])): ?>
                                <p class="form-error"><?= e($errors['current_password']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a class="btn btn-outline-secondary" href="<?= e(url(dashboard_path(current_role()))) ?>">Cancel</a>
                        <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                            <i class="bi bi-check2" aria-hidden="true"></i> Save changes
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Account</h2>
                    <p class="card-fl__subtitle">Your Fleetra identity</p>
                </div>
            </div>
            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Role</span>
                        <span class="fact-list__value"><?= role_badge(current_role()) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Account status</span>
                        <span class="fact-list__value"><?= status_badge((string) $user['status']) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Last sign in</span>
                        <span class="fact-list__value">
                            <?= $user['last_login'] !== null
                                ? e(time_ago((string) $user['last_login']))
                                : 'This is your first sign in' ?>
                        </span>
                    </li>
                    <li>
                        <span class="fact-list__label">Member since</span>
                        <span class="fact-list__value"><?= e(format_date((string) $user['created_at'])) ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <?php if ($driverRecord !== null): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Driver record</h2>
                        <p class="card-fl__subtitle">Maintained by the transport office</p>
                    </div>
                </div>
                <div class="card-fl__body">
                    <ul class="fact-list">
                        <li>
                            <span class="fact-list__label">Employee ID</span>
                            <span class="fact-list__value"><?= e($driverRecord['employee_id']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Licence number</span>
                            <span class="fact-list__value"><?= e($driverRecord['license_number']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Licence expiry</span>
                            <span class="fact-list__value"><?= e(format_date($driverRecord['license_expiry'])) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Assigned bus</span>
                            <span class="fact-list__value"><?= e($driverRecord['bus_number'] ?? 'Unassigned') ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Experience</span>
                            <span class="fact-list__value"><?= (int) $driverRecord['experience_years'] ?> years</span>
                        </li>
                        <li>
                            <span class="fact-list__label">Employment</span>
                            <span class="fact-list__value"><?= status_badge($driverRecord['employment_status']) ?></span>
                        </li>
                    </ul>
                    <p class="form-text mt-3 mb-0">
                        Licence and employment details are managed by your transport office. Contact them if
                        anything here needs correcting.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($passengerStats !== null): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Travel summary</h2>
                        <p class="card-fl__subtitle">Your activity with Fleetra</p>
                    </div>
                </div>
                <div class="card-fl__body">
                    <ul class="fact-list">
                        <li>
                            <span class="fact-list__label">Upcoming journeys</span>
                            <span class="fact-list__value"><?= $passengerStats['upcoming'] ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Total bookings</span>
                            <span class="fact-list__value"><?= $passengerStats['bookings'] ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Tickets issued</span>
                            <span class="fact-list__value"><?= $passengerStats['tickets'] ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Security</h2>
                    <p class="card-fl__subtitle">Keep your account protected</p>
                </div>
            </div>
            <div class="card-fl__body">
                <p class="form-text mb-3">
                    Use a unique password of at least 8 characters. You will be signed out automatically
                    after <?= (int) round(SESSION_IDLE_TIMEOUT / 60) ?> minutes of inactivity.
                </p>
                <a class="btn btn-outline-secondary w-100" href="<?= e(url('change-password.php')) ?>">
                    <i class="bi bi-key" aria-hidden="true"></i> Change my password
                </a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
