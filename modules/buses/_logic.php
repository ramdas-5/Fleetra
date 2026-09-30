<?php
/**
 * Fleetra — Bus module logic
 * ------------------------------------------------------------------
 * modules/buses/_logic.php
 *
 * Validation and value-shaping shared by the create and edit screens so
 * the rules only ever exist in one place.
 */

declare(strict_types=1);

/**
 * Default form values. Pass an existing bus row when editing.
 *
 * @param array<string, mixed>|null $bus
 * @return array<string, string>
 */
function bus_form_values(?array $bus = null): array
{
    $defaults = [
        'bus_number'          => '',
        'registration_number' => '',
        'manufacturer'        => '',
        'model'               => '',
        'manufacturing_year'  => '',
        'bus_type'            => 'seater',
        'capacity'            => '',
        'fuel_type'           => 'diesel',
        'current_mileage'     => '0',
        'status'              => 'active',
    ];

    if ($bus === null) {
        return $defaults;
    }

    foreach ($defaults as $field => $default) {
        if (array_key_exists($field, $bus) && $bus[$field] !== null) {
            $defaults[$field] = (string) $bus[$field];
        }
    }

    // Normalise the odometer for display (184320.50 rather than 184320.5).
    if ($defaults['current_mileage'] !== '') {
        $defaults['current_mileage'] = rtrim(rtrim(number_format((float) $defaults['current_mileage'], 2, '.', ''), '0'), '.');
    }

    return $defaults;
}

/**
 * Validate a submitted bus form.
 *
 * @param array<string, mixed> $post
 * @param int|null $busId Existing bus id when editing (excluded from uniqueness checks).
 * @return array{errors:array<string,string>, values:array<string,string>, newImage:?string, removeImage:bool}
 */
function validate_bus_request(array $post, ?int $busId = null): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'bus_number'          => $trim('bus_number'),
        'registration_number' => strtoupper($trim('registration_number')),
        'manufacturer'        => $trim('manufacturer'),
        'model'               => $trim('model'),
        'manufacturing_year'  => $trim('manufacturing_year'),
        'bus_type'            => $trim('bus_type'),
        'capacity'            => $trim('capacity'),
        'fuel_type'           => $trim('fuel_type'),
        'current_mileage'     => $trim('current_mileage'),
        'status'              => $trim('status'),
    ];

    // Bus number ------------------------------------------------------
    if ($values['bus_number'] === '') {
        $errors['bus_number'] = 'Enter the Fleetra bus number.';
    } elseif (mb_strlen($values['bus_number']) > 20) {
        $errors['bus_number'] = 'The bus number cannot be longer than 20 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9\-\/ ]+$/', $values['bus_number'])) {
        $errors['bus_number'] = 'Use letters, numbers, spaces, hyphens or slashes only.';
    } elseif (db_exists('buses', 'bus_number', $values['bus_number'], $busId)) {
        $errors['bus_number'] = 'Another bus already uses this bus number.';
    }

    // Registration number --------------------------------------------
    if ($values['registration_number'] === '') {
        $errors['registration_number'] = 'Enter the vehicle registration number.';
    } elseif (mb_strlen($values['registration_number']) > 30) {
        $errors['registration_number'] = 'The registration number cannot be longer than 30 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9\- ]+$/', $values['registration_number'])) {
        $errors['registration_number'] = 'Use letters, numbers, spaces or hyphens only.';
    } elseif (db_exists('buses', 'registration_number', $values['registration_number'], $busId)) {
        $errors['registration_number'] = 'Another bus is already registered with this number.';
    }

    // Text fields -----------------------------------------------------
    foreach (['manufacturer' => 'manufacturer', 'model' => 'model'] as $field => $label) {
        if (mb_strlen($values[$field]) > 80) {
            $errors[$field] = ucfirst($label) . ' cannot be longer than 80 characters.';
        }
    }

    // Manufacturing year ---------------------------------------------
    $currentYear = (int) date('Y');
    if ($values['manufacturing_year'] !== '') {
        if (!ctype_digit($values['manufacturing_year'])) {
            $errors['manufacturing_year'] = 'Enter a four digit year.';
        } else {
            $year = (int) $values['manufacturing_year'];
            if ($year < 1980 || $year > $currentYear + 1) {
                $errors['manufacturing_year'] = 'Enter a year between 1980 and ' . ($currentYear + 1) . '.';
            }
        }
    }

    // Enumerated fields ----------------------------------------------
    if (!is_valid_option(bus_type_options(), $values['bus_type'])) {
        $errors['bus_type'] = 'Choose a valid bus type.';
    }

    if (!is_valid_option(fuel_type_options(), $values['fuel_type'])) {
        $errors['fuel_type'] = 'Choose a valid fuel type.';
    }

    if (!is_valid_option(bus_status_options(), $values['status'])) {
        $errors['status'] = 'Choose a valid status.';
    }

    // Capacity --------------------------------------------------------
    if ($values['capacity'] === '') {
        $errors['capacity'] = 'Enter the seating capacity.';
    } elseif (!ctype_digit($values['capacity']) || (int) $values['capacity'] < 1 || (int) $values['capacity'] > 80) {
        $errors['capacity'] = 'Capacity must be a whole number between 1 and 80.';
    }

    // Odometer --------------------------------------------------------
    if ($values['current_mileage'] !== '') {
        if (!is_numeric($values['current_mileage']) || (float) $values['current_mileage'] < 0) {
            $errors['current_mileage'] = 'Enter the odometer reading as a positive number.';
        } elseif ((float) $values['current_mileage'] > 9999999) {
            $errors['current_mileage'] = 'That odometer reading looks unrealistic.';
        }
    }

    // Vehicle photo ---------------------------------------------------
    $newImage    = null;
    $removeImage = isset($post['remove_image']);

    if (isset($_FILES['image']) && is_array($_FILES['image'])
        && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        $upload = upload_image($_FILES['image'], 'buses', 'bus');

        if (!$upload['ok']) {
            $errors['image'] = (string) $upload['error'];
        } else {
            $newImage = (string) $upload['filename'];
        }
    }

    return [
        'errors'      => $errors,
        'values'      => $values,
        'newImage'    => $newImage,
        'removeImage' => $removeImage,
    ];
}

/**
 * Build the database payload from validated values.
 *
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function bus_db_payload(array $values): array
{
    return [
        'bus_number'          => $values['bus_number'],
        'registration_number' => $values['registration_number'],
        'manufacturer'        => $values['manufacturer'] === '' ? null : $values['manufacturer'],
        'model'               => $values['model'] === '' ? null : $values['model'],
        'manufacturing_year'  => $values['manufacturing_year'] === '' ? null : (int) $values['manufacturing_year'],
        'bus_type'            => $values['bus_type'],
        'capacity'            => (int) $values['capacity'],
        'fuel_type'           => $values['fuel_type'],
        'current_mileage'     => $values['current_mileage'] === '' ? 0 : (float) $values['current_mileage'],
        'status'              => $values['status'],
    ];
}
