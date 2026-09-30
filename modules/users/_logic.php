<?php
/**
 * Fleetra — User module logic
 * ------------------------------------------------------------------
 * modules/users/_logic.php
 *
 * Administrators manage every account type here. Two safety rules are
 * enforced server-side because breaking either one locks the whole
 * system out:
 *
 *   1. You cannot deactivate or demote the last remaining active admin.
 *   2. You cannot deactivate, suspend or delete your own account.
 */

declare(strict_types=1);

/** Selectable account statuses (the 'deleted' marker is internal only). */
function account_status_options(): array
{
    return [
        'active'    => 'Active',
        'inactive'  => 'Inactive',
        'suspended' => 'Suspended',
    ];
}

/**
 * Roles an administrator may pick in this module.
 * Driver accounts are created from the Drivers module so that the driver
 * profile, licence and employment record are created alongside the login.
 *
 * @param string|null $currentRole Keeps the existing role selectable when editing.
 * @return array<string, string>
 */
function assignable_role_options(?string $currentRole = null): array
{
    $options = role_labels();
    unset($options['driver']);

    if ($currentRole === 'driver') {
        $options = ['driver' => role_labels()['driver']] + $options;
    }

    return $options;
}

/** Number of active administrators in the system. */
function active_admin_count(): int
{
    return (int) db_value(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'",
        [],
        0
    );
}

/**
 * @param array<string, mixed>|null $user
 * @return array<string, string>
 */
function user_form_values(?array $user = null): array
{
    $defaults = [
        'name'   => '',
        'email'  => '',
        'phone'  => '',
        'role'   => 'passenger',
        'status' => 'active',
    ];

    if ($user === null) {
        return $defaults;
    }

    foreach ($defaults as $field => $default) {
        if (array_key_exists($field, $user) && $user[$field] !== null) {
            $defaults[$field] = (string) $user[$field];
        }
    }

    return $defaults;
}

/**
 * Validate a user create/update submission.
 *
 * @param array<string, mixed> $post
 * @param bool $isEdit
 * @param int|null $userId     Target account when editing.
 * @param int|null $currentUserId Signed-in administrator.
 * @return array{errors:array<string,string>, values:array<string,string>}
 */
function validate_user_request(array $post, bool $isEdit, ?int $userId = null, ?int $currentUserId = null): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'name'   => $trim('name'),
        'email'  => $trim('email'),
        'phone'  => $trim('phone'),
        'role'   => $trim('role'),
        'status' => $trim('status'),
    ];

    /* ---------------- Basics ---------------- */

    if ($values['name'] === '') {
        $errors['name'] = 'Enter the account holder\'s full name.';
    } elseif (mb_strlen($values['name']) < 3 || mb_strlen($values['name']) > 120) {
        $errors['name'] = 'The name must be between 3 and 120 characters.';
    }

    if ($values['email'] === '') {
        $errors['email'] = 'Enter the email address used to sign in.';
    } elseif (!is_valid_email($values['email'])) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        $duplicate = (int) db_value(
            'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?',
            [$values['email'], $userId ?? 0],
            0
        );

        if ($duplicate > 0) {
            $errors['email'] = 'Another account already uses this email address.';
        }
    }

    if ($values['phone'] !== '' && !is_valid_phone($values['phone'])) {
        $errors['phone'] = 'Enter a valid mobile number (10 to 15 digits).';
    }

    /* ---------------- Role and status ---------------- */

    $roleOptions = assignable_role_options($isEdit && $userId !== null
        ? (string) (db_value('SELECT role FROM users WHERE id = ?', [$userId], '') ?? '')
        : null);

    if (!is_valid_option($roleOptions, $values['role'])) {
        $errors['role'] = 'Choose a valid role for this account.';
    }

    if (!is_valid_option(account_status_options(), $values['status'])) {
        $errors['status'] = 'Choose a valid account status.';
    }

    /* ---------------- Password ---------------- */

    $password = (string) ($post['password'] ?? '');

    if (!$isEdit) {
        if ($password === '') {
            $errors['password'] = 'Set an initial password for this account.';
        } elseif (($issue = password_issues($password)) !== null) {
            $errors['password'] = $issue;
        }
    } elseif ($password !== '' && ($issue = password_issues($password)) !== null) {
        $errors['password'] = $issue;
    }

    /* ---------------- Lock-out protection ---------------- */

    if ($isEdit && $userId !== null && $userId === $currentUserId) {
        if ($values['status'] !== 'active') {
            $errors['status'] = 'You cannot change your own account status. Ask another administrator to do it.';
        }

        if ($values['role'] !== 'admin' && (string) db_value('SELECT role FROM users WHERE id = ?', [$userId], '') === 'admin') {
            $errors['role'] = 'You cannot remove the administrator role from your own account.';
        }
    }

    // Never allow the last active administrator to be demoted or disabled.
    if ($isEdit && $userId !== null && !isset($errors['role']) && !isset($errors['status'])) {
        $target = db_one('SELECT role, status FROM users WHERE id = ? LIMIT 1', [$userId]);

        if ($target !== null
            && $target['role'] === 'admin'
            && $target['status'] === 'active'
            && ($values['role'] !== 'admin' || $values['status'] !== 'active')
            && active_admin_count() <= 1
        ) {
            $message = 'This is the only active administrator. Promote another account to administrator first, '
                . 'otherwise nobody would be able to manage Fleetra.';

            if ($values['role'] !== 'admin') {
                $errors['role'] = $message;
            } else {
                $errors['status'] = $message;
            }
        }
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function user_db_payload(array $values): array
{
    return [
        'name'   => $values['name'],
        'email'  => $values['email'],
        'phone'  => $values['phone'] === '' ? null : $values['phone'],
        'role'   => $values['role'],
        'status' => $values['status'],
    ];
}

/**
 * Plain-English description of every capability the application defines.
 * Used by the account detail page so "what can this person do?" is
 * answered in words rather than in permission keys.
 *
 * @return array<string, string>
 */
function capability_labels(): array
{
    return [
        '*'                      => 'Full access to every module',
        'dashboard.view'         => 'Open their own dashboard',
        'fleet.*'                => 'Add, edit and remove buses',
        'fleet.view'             => 'View buses and fleet records',
        'drivers.*'              => 'Add, edit and remove drivers',
        'drivers.view'           => 'View driver profiles',
        'routes.*'               => 'Create and edit routes and stops',
        'routes.view'            => 'View routes and their stops',
        'schedules.*'            => 'Create, edit and cancel schedules',
        'schedules.view'         => 'Use the schedule planner',
        'trips.view'             => 'View trips and their status',
        'trips.manage'           => 'Update trips and record delays',
        'trips.own'              => 'See the trips assigned to them',
        'trips.update_status'    => 'Start, delay and complete their own trips',
        'trips.search'           => 'Search buses and available services',
        'bookings.view'          => 'View passenger bookings',
        'bookings.manage'        => 'Confirm, rebook and cancel passenger seats',
        'bookings.create'        => 'Reserve seats for themselves',
        'bookings.own'           => 'See their own bookings',
        'bookings.cancel'        => 'Cancel their own bookings',
        'tickets.own'            => 'Download their own tickets',
        'passengers.view'        => 'View the passenger register',
        'tracking.view'          => 'Follow live bus locations',
        'tracking.update'        => 'Send their bus location while driving',
        'maintenance.*'          => 'Log and schedule vehicle maintenance',
        'maintenance.report'     => 'Report a vehicle fault',
        'incidents.view'         => 'View reported incidents',
        'incidents.manage'       => 'Resolve and close incidents',
        'incidents.report'       => 'Report an incident',
        'notifications.view'     => 'Read notifications',
        'notifications.send'     => 'Send announcements',
        'reports.view'           => 'Open operational reports',
        'profile.manage'         => 'Edit their own profile and password',
    ];
}

/**
 * Readable capability list for a role, e.g. ['View buses and fleet records', ...].
 *
 * @return array<int, string>
 */
function user_access_summary(string $role): array
{
    $labels = capability_labels();
    $granted = role_permissions()[$role] ?? [];

    return array_map(
        static fn (string $capability): string => $labels[$capability] ?? labelize($capability),
        $granted
    );
}

/**
 * Counts used by the user detail page to explain what a delete would break.
 *
 * @return array<string, int>
 */
function user_reference_counts(int $userId): array
{
    return [
        'bookings'      => (int) db_value('SELECT COUNT(*) FROM bookings WHERE user_id = ?', [$userId], 0),
        'driver_record' => (int) db_value('SELECT COUNT(*) FROM drivers WHERE user_id = ?', [$userId], 0),
        'notifications' => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId], 0),
        'logs'          => (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE user_id = ?', [$userId], 0),
    ];
}
