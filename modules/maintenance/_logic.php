<?php
/**
 * Fleetra — Maintenance module logic
 * ------------------------------------------------------------------
 * modules/maintenance/_logic.php
 *
 * Shared by the maintenance list, add, edit and view screens.
 *
 * A maintenance record is not just paperwork: a bus that is in the
 * workshop must not be scheduled, so recording work as "in progress" moves
 * the bus to the Maintenance status and completing the work releases it
 * again — but only once no other job is still open on that vehicle.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

/**
 * Default form values. Pass an existing maintenance row when editing.
 *
 * @param array<string, mixed>|null $record
 * @return array<string, string>
 */
function maintenance_form_values(?array $record = null): array
{
    $defaults = [
        'bus_id'            => '',
        'maintenance_type'  => 'routine',
        'description'       => '',
        'service_date'      => date('Y-m-d'),
        'next_service_date' => '',
        'odometer_reading'  => '',
        'cost'              => '0',
        'service_provider'  => '',
        'status'            => 'scheduled',
        'remarks'           => '',
    ];

    if ($record === null) {
        // Pre-fill the odometer from the bus when one is chosen in the URL.
        $busId = get_int('bus_id');
        if ($busId > 0) {
            $defaults['bus_id'] = (string) $busId;
        }

        return $defaults;
    }

    foreach ($defaults as $field => $default) {
        if (array_key_exists($field, $record) && $record[$field] !== null) {
            $defaults[$field] = (string) $record[$field];
        }
    }

    foreach (['cost', 'odometer_reading'] as $numeric) {
        if ($defaults[$numeric] !== '') {
            $defaults[$numeric] = rtrim(rtrim(number_format((float) $defaults[$numeric], 2, '.', ''), '0'), '.');
        }
    }

    return $defaults;
}

/**
 * Validate a submitted maintenance form.
 *
 * @param array<string, mixed> $post
 * @return array{errors:array<string,string>, values:array<string,string>}
 */
function validate_maintenance_request(array $post): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'bus_id'            => $trim('bus_id'),
        'maintenance_type'  => $trim('maintenance_type'),
        'description'       => $trim('description'),
        'service_date'      => $trim('service_date'),
        'next_service_date' => $trim('next_service_date'),
        'odometer_reading'  => $trim('odometer_reading'),
        'cost'              => $trim('cost'),
        'service_provider'  => $trim('service_provider'),
        'status'            => $trim('status'),
        'remarks'           => $trim('remarks'),
    ];

    // Bus --------------------------------------------------------------
    $bus = null;

    if ($values['bus_id'] === '') {
        $errors['bus_id'] = 'Choose the bus this work is for.';
    } else {
        $bus = db_one(
            'SELECT id, bus_number, current_mileage FROM buses WHERE id = ? LIMIT 1',
            [(int) $values['bus_id']]
        );

        if ($bus === null) {
            $errors['bus_id'] = 'That bus does not exist in the fleet.';
        }
    }

    // Type and status --------------------------------------------------
    if (!is_valid_option(maintenance_type_options(), $values['maintenance_type'])) {
        $errors['maintenance_type'] = 'Choose a valid maintenance type.';
    }

    if (!is_valid_option(maintenance_status_options(), $values['status'])) {
        $errors['status'] = 'Choose a valid maintenance status.';
    }

    // Description ------------------------------------------------------
    if ($values['description'] === '') {
        $errors['description'] = 'Describe the work carried out or required.';
    } elseif (mb_strlen($values['description']) > 1000) {
        $errors['description'] = 'Keep the description under 1000 characters.';
    }

    // Dates ------------------------------------------------------------
    if (!is_valid_date($values['service_date'])) {
        $errors['service_date'] = 'Enter a valid service date.';
    }

    if ($values['next_service_date'] !== '') {
        if (!is_valid_date($values['next_service_date'])) {
            $errors['next_service_date'] = 'Enter a valid next service date.';
        } elseif (is_valid_date($values['service_date'])
            && $values['next_service_date'] <= $values['service_date']
        ) {
            $errors['next_service_date'] = 'The next service must fall after the service date.';
        }
    }

    // Money and mileage ------------------------------------------------
    if ($values['cost'] === '') {
        $values['cost'] = '0';
    }

    if (!is_numeric($values['cost']) || (float) $values['cost'] < 0) {
        $errors['cost'] = 'Enter the cost as a positive amount.';
    } elseif ((float) $values['cost'] > 9999999) {
        $errors['cost'] = 'That cost looks unrealistic.';
    }

    if ($values['odometer_reading'] !== '') {
        if (!is_numeric($values['odometer_reading']) || (float) $values['odometer_reading'] < 0) {
            $errors['odometer_reading'] = 'Enter the odometer reading as a positive number.';
        } elseif ((float) $values['odometer_reading'] > 9999999) {
            $errors['odometer_reading'] = 'That odometer reading looks unrealistic.';
        } elseif ($bus !== null && (float) $values['odometer_reading'] < (float) $bus['current_mileage']) {
            $errors['odometer_reading'] = 'This is lower than the bus odometer (' 
                . number_format((float) $bus['current_mileage'], 0) . ' km). Check the reading.';
        }
    }

    if (mb_strlen($values['service_provider']) > 140) {
        $errors['service_provider'] = 'The provider name cannot be longer than 140 characters.';
    }

    if (mb_strlen($values['remarks']) > 255) {
        $errors['remarks'] = 'Remarks cannot be longer than 255 characters.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Build the database payload from validated values.
 *
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function maintenance_db_payload(array $values): array
{
    return [
        'bus_id'            => (int) $values['bus_id'],
        'maintenance_type'  => $values['maintenance_type'],
        'description'       => $values['description'],
        'service_date'      => $values['service_date'],
        'next_service_date' => $values['next_service_date'] === '' ? null : $values['next_service_date'],
        'odometer_reading'  => $values['odometer_reading'] === '' ? null : (float) $values['odometer_reading'],
        'cost'              => (float) $values['cost'],
        'service_provider'  => $values['service_provider'] === '' ? null : $values['service_provider'],
        'status'            => $values['status'],
        'remarks'           => $values['remarks'] === '' ? null : $values['remarks'],
    ];
}

/* ------------------------------------------------------------------
 | Effects on the fleet
 ------------------------------------------------------------------ */

/** True when a maintenance record represents work still in the workshop. */
function maintenance_is_open(array $record): bool
{
    return in_array((string) $record['status'], ['scheduled', 'in_progress', 'overdue'], true);
}

/**
 * Keep the bus status in step with its open maintenance jobs.
 *
 * A bus with an in-progress job is placed in maintenance; once the last
 * job closes the bus returns to service, unless an operator has taken it
 * out of service deliberately (Inactive is never overwritten).
 */
function reconcile_bus_maintenance_state(int $busId): void
{
    if ($busId <= 0) {
        return;
    }

    $bus = db_one('SELECT id, bus_number, status FROM buses WHERE id = ? LIMIT 1', [$busId]);

    if ($bus === null || (string) $bus['status'] === 'inactive') {
        return;
    }

    $inProgress = (int) db_value(
        "SELECT COUNT(*) FROM maintenance WHERE bus_id = ? AND status = 'in_progress'",
        [$busId],
        0
    );

    $openJobs = (int) db_value(
        "SELECT COUNT(*) FROM maintenance WHERE bus_id = ? AND status IN ('scheduled','in_progress','overdue')",
        [$busId],
        0
    );

    if ($inProgress > 0) {
        if ((string) $bus['status'] !== 'maintenance') {
            db_update('buses', ['status' => 'maintenance'], ['id' => $busId]);
            fleetra_log('Bus ' . $bus['bus_number'] . ' moved to maintenance by user #' . user_id(), 'INFO');
        }

        return;
    }

    // No job is being worked on. Release the bus only when every job is closed.
    if ($openJobs === 0 && (string) $bus['status'] === 'maintenance') {
        db_update('buses', ['status' => 'active'], ['id' => $busId]);
        fleetra_log('Bus ' . $bus['bus_number'] . ' returned to service by user #' . user_id(), 'INFO');
    }
}

/**
 * Raise the bus odometer when a service was carried out at a higher
 * reading, so fleet mileage never goes backwards.
 */
function sync_bus_odometer(int $busId, ?float $reading): void
{
    if ($busId <= 0 || $reading === null) {
        return;
    }

    $current = (float) db_value('SELECT current_mileage FROM buses WHERE id = ?', [$busId], 0);

    if ($reading > $current) {
        db_update('buses', ['current_mileage' => $reading], ['id' => $busId]);
    }
}

/* ------------------------------------------------------------------
 | Lookups used by the list, form and detail screens
 ------------------------------------------------------------------ */

/**
 * Buses as id => "FLT-101 · KA-01-AB-1234", optionally including retired ones.
 *
 * @return array<int, string>
 */
function maintenance_bus_options(bool $includeInactive = true): array
{
    $sql = 'SELECT id, bus_number, registration_number, status FROM buses';
    $sql .= $includeInactive ? '' : " WHERE status <> 'inactive'";
    $sql .= ' ORDER BY bus_number';

    $options = [];

    foreach (db_all($sql) as $bus) {
        $suffix = (string) $bus['status'] === 'maintenance' ? ' (in workshop)' : '';
        $options[(int) $bus['id']] = $bus['bus_number'] . ' · ' . $bus['registration_number'] . $suffix;
    }

    return $options;
}

/**
 * Maintenance record joined to its bus.
 *
 * @return array<string, mixed>|null
 */
function find_maintenance_record(int $id): ?array
{
    return db_one(
        'SELECT m.*, b.bus_number, b.registration_number, b.manufacturer, b.model,
                b.capacity, b.bus_type, b.current_mileage AS bus_mileage, b.status AS bus_status
           FROM maintenance m
           JOIN buses b ON b.id = m.bus_id
          WHERE m.id = ?
          LIMIT 1',
        [$id]
    );
}
