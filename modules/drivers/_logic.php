<?php
/**
 * Fleetra — Driver module logic
 * ------------------------------------------------------------------
 * modules/drivers/_logic.php
 *
 * A driver is two records: a users row (the login) and a drivers row
 * (the operational profile). Both are validated here so create and edit
 * always apply identical rules.
 */

declare(strict_types=1);

/**
 * Default form values.
 *
 * @param array<string, mixed>|null $driver Driver row joined with its user row, when editing.
 * @return array<string, string>
 */
function driver_form_values(?array $driver = null): array
{
    $defaults = [
        'name'              => '',
        'email'             => '',
        'phone'             => '',
        'employee_id'       => '',
        'license_number'    => '',
        'license_expiry'    => '',
        'date_of_birth'     => '',
        'address'           => '',
        'experience_years'  => '0',
        'assigned_bus_id'   => '',
        'employment_status' => 'active',
        'joining_date'      => '',
    ];

    if ($driver === null) {
        return $defaults;
    }

    foreach ($defaults as $field => $default) {
        if (array_key_exists($field, $driver) && $driver[$field] !== null) {
            $defaults[$field] = (string) $driver[$field];
        }
    }

    return $defaults;
}

/**
 * Validate a submitted driver form.
 *
 * @param array<string, mixed> $post
 * @param bool $isEdit
 * @param int|null $driverId  Driver row id when editing.
 * @param int|null $userId    Linked users id when editing.
 * @return array{errors:array<string,string>, values:array<string,string>}
 */
function validate_driver_request(array $post, bool $isEdit, ?int $driverId = null, ?int $userId = null): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'name'              => $trim('name'),
        'email'             => $trim('email'),
        'phone'             => $trim('phone'),
        'employee_id'       => strtoupper($trim('employee_id')),
        'license_number'    => strtoupper($trim('license_number')),
        'license_expiry'    => $trim('license_expiry'),
        'date_of_birth'     => $trim('date_of_birth'),
        'address'           => $trim('address'),
        'experience_years'  => $trim('experience_years'),
        'assigned_bus_id'   => $trim('assigned_bus_id'),
        'employment_status' => $trim('employment_status'),
        'joining_date'      => $trim('joining_date'),
    ];

    /* ---------------- Account details ---------------- */

    if ($values['name'] === '') {
        $errors['name'] = 'Enter the driver\'s full name.';
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

    if (!$isEdit) {
        $password = (string) ($post['password'] ?? '');

        if ($password === '') {
            $errors['password'] = 'Set an initial password for the driver account.';
        } elseif (($issue = password_issues($password)) !== null) {
            $errors['password'] = $issue;
        }
    }

    /* ---------------- Licence and profile ---------------- */

    if ($values['employee_id'] === '') {
        $errors['employee_id'] = 'Enter the employee ID.';
    } elseif (mb_strlen($values['employee_id']) > 20) {
        $errors['employee_id'] = 'The employee ID cannot be longer than 20 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9\-\/]+$/', $values['employee_id'])) {
        $errors['employee_id'] = 'Use letters, numbers, hyphens or slashes only.';
    } elseif (db_exists('drivers', 'employee_id', $values['employee_id'], $driverId)) {
        $errors['employee_id'] = 'Another driver already has this employee ID.';
    }

    if ($values['license_number'] === '') {
        $errors['license_number'] = 'Enter the driving licence number.';
    } elseif (mb_strlen($values['license_number']) > 40) {
        $errors['license_number'] = 'The licence number cannot be longer than 40 characters.';
    } elseif (db_exists('drivers', 'license_number', $values['license_number'], $driverId)) {
        $errors['license_number'] = 'Another driver already has this licence number.';
    }

    if ($values['license_expiry'] === '') {
        $errors['license_expiry'] = 'Enter the licence expiry date.';
    } elseif (!is_valid_date($values['license_expiry'])) {
        $errors['license_expiry'] = 'Enter the expiry date as a valid calendar date.';
    }

    if ($values['date_of_birth'] !== '') {
        if (!is_valid_date($values['date_of_birth'])) {
            $errors['date_of_birth'] = 'Enter the date of birth as a valid calendar date.';
        } elseif (strtotime($values['date_of_birth']) > strtotime('-18 years')) {
            $errors['date_of_birth'] = 'A driver must be at least 18 years old.';
        }
    }

    if ($values['joining_date'] !== '' && !is_valid_date($values['joining_date'])) {
        $errors['joining_date'] = 'Enter the joining date as a valid calendar date.';
    }

    if (mb_strlen($values['address']) > 255) {
        $errors['address'] = 'Keep the address under 255 characters.';
    }

    if ($values['experience_years'] !== '') {
        if (!ctype_digit($values['experience_years']) || (int) $values['experience_years'] > 60) {
            $errors['experience_years'] = 'Enter the experience in whole years (0 to 60).';
        }
    }

    if (!is_valid_option(employment_status_options(), $values['employment_status'])) {
        $errors['employment_status'] = 'Choose a valid employment status.';
    }

    /* ---------------- Bus assignment ---------------- */

    if ($values['assigned_bus_id'] !== '') {
        if (!ctype_digit($values['assigned_bus_id'])) {
            $errors['assigned_bus_id'] = 'Choose a valid bus.';
        } else {
            $bus = db_one('SELECT id, bus_number, status FROM buses WHERE id = ? LIMIT 1', [(int) $values['assigned_bus_id']]);

            if ($bus === null) {
                $errors['assigned_bus_id'] = 'That bus does not exist.';
            } elseif ($bus['status'] !== 'active') {
                // A bus already in the workshop or retired must not take a driver.
                $errors['assigned_bus_id'] = 'Bus ' . $bus['bus_number']
                    . ' is ' . str_replace('_', ' ', (string) $bus['status'])
                    . ' and cannot be assigned. Only active buses are assignable.';
            } else {
                $busId = (int) $bus['id'];

                $otherDriver = db_one(
                    'SELECT u.name FROM drivers d JOIN users u ON u.id = d.user_id
                      WHERE d.assigned_bus_id = ? AND d.id <> ? LIMIT 1',
                    [$busId, $driverId ?? 0]
                );

                if ($otherDriver !== null) {
                    $errors['assigned_bus_id'] = 'Bus ' . $bus['bus_number']
                        . ' is already assigned to ' . $otherDriver['name'] . '. Release that driver first.';
                }
            }
        }
    }

    /* ---------------- Cross-field integrity ---------------- */

    // An expired licence blocks assignment, mirroring the operational rule.
    if ($values['license_expiry'] !== '' && is_valid_date($values['license_expiry'])
        && $values['assigned_bus_id'] !== '' && !isset($errors['assigned_bus_id'])
        && strtotime($values['license_expiry']) < strtotime('today')
    ) {
        $errors['assigned_bus_id'] = 'This licence expired on '
            . date('d M Y', strtotime($values['license_expiry']))
            . ', so the driver cannot be assigned to a bus. Renew the licence or leave the bus unassigned.';
    }

    if (in_array($values['employment_status'], ['terminated', 'resigned'], true) && $values['assigned_bus_id'] !== '') {
        $errors['assigned_bus_id'] = 'Release the bus assignment before marking this driver as '
            . $values['employment_status'] . '.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Build the users payload for a driver account.
 *
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function driver_user_payload(array $values): array
{
    return [
        'name'   => $values['name'],
        'email'  => $values['email'],
        'phone'  => $values['phone'] === '' ? null : $values['phone'],
        'role'   => 'driver',
        'status' => in_array($values['employment_status'], ['terminated', 'resigned'], true) ? 'inactive' : 'active',
    ];
}

/**
 * Build the drivers payload.
 *
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function driver_db_payload(array $values): array
{
    return [
        'employee_id'       => $values['employee_id'],
        'license_number'    => $values['license_number'],
        'license_expiry'    => $values['license_expiry'],
        'date_of_birth'     => $values['date_of_birth'] === '' ? null : $values['date_of_birth'],
        'address'           => $values['address'] === '' ? null : $values['address'],
        'experience_years'  => $values['experience_years'] === '' ? 0 : (int) $values['experience_years'],
        'assigned_bus_id'   => $values['assigned_bus_id'] === '' ? null : (int) $values['assigned_bus_id'],
        'employment_status' => $values['employment_status'],
        'joining_date'      => $values['joining_date'] === '' ? null : $values['joining_date'],
    ];
}

/**
 * Selectable buses for the assignment dropdown: active buses, plus the
 * driver's own bus if it has since become unavailable (so saving another
 * field never silently drops the assignment).
 *
 * @return array<int, array<string, mixed>>
 */
function assignable_buses(?int $currentBusId = null): array
{
    return db_all(
        'SELECT id, bus_number, registration_number, capacity, status
           FROM buses
          WHERE status = "active" OR id = ?
          ORDER BY bus_number',
        [$currentBusId ?? 0]
    );
}
