<?php
/**
 * Fleetra — Location directory / logic
 * ------------------------------------------------------------------
 * modules/locations/_logic.php
 *
 * Validation and helpers for the admin "Locations & Terminals" module.
 * The location table is public reference data used by passenger search;
 * administrators curate it here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/locations.php';

/**
 * Collect and normalise a location form submission.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function location_form_values(array $source): array
{
    $get = static fn (string $key): string => trim((string) ($source[$key] ?? ''));

    return [
        'name'          => $get('name'),
        'city'          => $get('city'),
        'district'      => $get('district'),
        'state'         => $get('state'),
        'state_code'    => strtoupper($get('state_code')),
        'location_type' => $get('location_type'),
        'latitude'      => $get('latitude'),
        'longitude'     => $get('longitude'),
        'pincode'       => $get('pincode'),
        'aliases'       => $get('aliases'),
    ];
}

/**
 * Validate a location submission.
 *
 * @param array<string, mixed> $values
 * @return array{errors:array<int,string>, values:array<string,mixed>, data:array<string,mixed>}
 */
function validate_location_form(array $values, ?int $excludeId = null): array
{
    $errors = [];

    if ($values['name'] === '') {
        $errors[] = 'A location name is required.';
    } elseif (mb_strlen($values['name']) > 180) {
        $errors[] = 'The location name is too long.';
    }

    if ($values['city'] === '') {
        $errors[] = 'A city is required.';
    }

    if ($values['state'] === '') {
        $errors[] = 'A state or union territory is required.';
    }

    if (!is_valid_option(location_type_options(), $values['location_type'])) {
        $errors[] = 'Choose a valid location type.';
    }

    if ($values['state_code'] !== '' && !preg_match('/^[A-Z]{2,3}$/', $values['state_code'])) {
        $errors[] = 'The state code must be 2–3 letters (e.g. WB).';
    }

    if ($values['pincode'] !== '' && !preg_match('/^[0-9]{4,10}$/', $values['pincode'])) {
        $errors[] = 'The PIN code must be 4–10 digits.';
    }

    $latitude  = null;
    $longitude = null;

    if ($values['latitude'] !== '') {
        $latitude = filter_var($values['latitude'], FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $latitude < -90 || $latitude > 90) {
            $errors[] = 'Latitude must be a number between -90 and 90.';
        }
    }

    if ($values['longitude'] !== '') {
        $longitude = filter_var($values['longitude'], FILTER_VALIDATE_FLOAT);
        if ($longitude === false || $longitude < -180 || $longitude > 180) {
            $errors[] = 'Longitude must be a number between -180 and 180.';
        }
    }

    // Reject duplicates (same city + name + state) rather than silently merging.
    if ($values['name'] !== '' && $values['city'] !== '' && $values['state'] !== '') {
        $duplicate = db_value(
            'SELECT COUNT(*) FROM locations WHERE city = ? AND name = ? AND state = ? AND id <> ?',
            [$values['city'], $values['name'], $values['state'], $excludeId ?? 0],
            0
        );

        if ((int) $duplicate > 0) {
            $errors[] = 'A location with this name already exists in that city and state.';
        }
    }

    return [
        'errors' => $errors,
        'values' => $values,
        'data'   => [
            'name'          => $values['name'],
            'city'          => $values['city'],
            'district'      => $values['district'] !== '' ? $values['district'] : null,
            'state'         => $values['state'],
            'state_code'    => $values['state_code'] !== '' ? $values['state_code'] : null,
            'location_type' => $values['location_type'],
            'latitude'      => $latitude !== false && $latitude !== null ? round((float) $latitude, 7) : null,
            'longitude'     => $longitude !== false && $longitude !== null ? round((float) $longitude, 7) : null,
            'pincode'       => $values['pincode'] !== '' ? $values['pincode'] : null,
            'aliases'       => $values['aliases'] !== '' ? $values['aliases'] : null,
        ],
    ];
}

/** Distinct states present in the location table, for the filter dropdown. */
function location_state_list(): array
{
    return db_all('SELECT DISTINCT state, state_code FROM locations ORDER BY state');
}
