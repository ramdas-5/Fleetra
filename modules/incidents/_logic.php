<?php
/**
 * Fleetra — Incident module logic
 * ------------------------------------------------------------------
 * modules/incidents/_logic.php
 *
 * Incident reporting and resolution. A driver raises an incident from the
 * road, dispatch and management triage it, and the record keeps the whole
 * audit trail.
 *
 * High and critical incidents immediately notify every administrator,
 * transport manager and dispatcher, so an emergency is never left for
 * someone to notice later.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

/*
 * Notifications are raised when an incident is reported or triaged, and
 * notify_user() lives in the shared operations layer. Load it here so every
 * entry point into this module (list, detail, create and status) has it.
 */
require_once __DIR__ . '/../../includes/operations.php';

/**
 * Default form values. Pass an existing incident row when editing.
 *
 * @param array<string, mixed>|null $incident
 * @return array<string, string>
 */
function incident_form_values(?array $incident = null): array
{
    $defaults = [
        'trip_id'       => '',
        'bus_id'        => '',
        'driver_id'     => '',
        'incident_type' => 'breakdown',
        'severity'      => 'medium',
        'description'   => '',
        'latitude'      => '',
        'longitude'     => '',
        'status'        => 'open',
    ];

    if ($incident !== null) {
        foreach ($defaults as $field => $default) {
            if (array_key_exists($field, $incident) && $incident[$field] !== null) {
                $defaults[$field] = (string) $incident[$field];
            }
        }

        return $defaults;
    }

    // A driver's report is pre-linked to their current duty where possible.
    $prefillTripId = get_int('trip_id');
    $prefillBusId  = get_int('bus_id');

    if ($prefillTripId > 0) {
        $defaults['trip_id'] = (string) $prefillTripId;
    }
    if ($prefillBusId > 0) {
        $defaults['bus_id'] = (string) $prefillBusId;
    }

    return $defaults;
}

/**
 * Validate a submitted incident form.
 *
 * @param array<string, mixed> $post
 * @param int $forcedDriverId Driver record id pinned by the caller (0 = free choice).
 * @return array{errors:array<string,string>, values:array<string,string>}
 */
function validate_incident_request(array $post, int $forcedDriverId = 0): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'trip_id'       => $trim('trip_id'),
        'bus_id'        => $trim('bus_id'),
        'driver_id'     => $trim('driver_id'),
        'incident_type' => $trim('incident_type'),
        'severity'      => $trim('severity'),
        'description'   => $trim('description'),
        'latitude'      => $trim('latitude'),
        'longitude'     => $trim('longitude'),
        'status'        => $trim('status'),
    ];

    if ($forcedDriverId > 0) {
        $values['driver_id'] = (string) $forcedDriverId;
    }

    // A driver's report form does not carry a status field, and a newly
    // reported incident is always open, so an absent value defaults rather
    // than failing validation with a hidden error.
    if ($values['status'] === '') {
        $values['status'] = 'open';
    }

    // Enumerated fields -------------------------------------------------
    if (!is_valid_option(incident_type_options(), $values['incident_type'])) {
        $errors['incident_type'] = 'Choose a valid incident type.';
    }

    if (!is_valid_option(incident_severity_options(), $values['severity'])) {
        $errors['severity'] = 'Choose a valid severity.';
    }

    if (!is_valid_option(incident_status_options(), $values['status'])) {
        $errors['status'] = 'Choose a valid status.';
    }

    // Description -------------------------------------------------------
    if (mb_strlen($values['description']) < 10) {
        $errors['description'] = 'Describe what happened in at least 10 characters.';
    } elseif (mb_strlen($values['description']) > 2000) {
        $errors['description'] = 'Keep the description under 2000 characters.';
    }

    // Optional references ----------------------------------------------
    if ($values['trip_id'] !== '') {
        $trip = db_one('SELECT id, bus_id, driver_id FROM trips WHERE id = ? LIMIT 1', [(int) $values['trip_id']]);

        if ($trip === null) {
            $errors['trip_id'] = 'That trip does not exist.';
        } else {
            // A bus or driver is only logged against the trip they belong to.
            if ($values['bus_id'] === '') {
                $values['bus_id'] = (string) $trip['bus_id'];
            }
            if ($values['driver_id'] === '' && $forcedDriverId === 0) {
                $values['driver_id'] = (string) $trip['driver_id'];
            }
        }
    }

    if ($values['bus_id'] !== '' && !db_exists('buses', 'id', (int) $values['bus_id'])) {
        $errors['bus_id'] = 'That bus does not exist in the fleet.';
    }

    if ($values['driver_id'] !== '' && !db_exists('drivers', 'id', (int) $values['driver_id'])) {
        $errors['driver_id'] = 'That driver does not exist.';
    }

    // Coordinates --------------------------------------------------------
    if ($values['latitude'] !== '') {
        if (!is_numeric($values['latitude']) || (float) $values['latitude'] < -90 || (float) $values['latitude'] > 90) {
            $errors['latitude'] = 'Latitude must be a number between -90 and 90.';
        }
    }

    if ($values['longitude'] !== '') {
        if (!is_numeric($values['longitude']) || (float) $values['longitude'] < -180 || (float) $values['longitude'] > 180) {
            $errors['longitude'] = 'Longitude must be a number between -180 and 180.';
        }
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Build the incidents table payload from validated values.
 *
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function incident_db_payload(array $values): array
{
    return [
        'trip_id'       => $values['trip_id'] === '' ? null : (int) $values['trip_id'],
        'bus_id'        => $values['bus_id'] === '' ? null : (int) $values['bus_id'],
        'driver_id'     => $values['driver_id'] === '' ? null : (int) $values['driver_id'],
        'incident_type' => $values['incident_type'],
        'severity'      => $values['severity'],
        'description'   => $values['description'],
        'latitude'      => $values['latitude'] === '' ? null : (float) $values['latitude'],
        'longitude'     => $values['longitude'] === '' ? null : (float) $values['longitude'],
        'status'        => $values['status'],
    ];
}

/**
 * Severity levels that must page the operations team immediately.
 *
 * @return array<int, string>
 */
function incident_alert_severities(): array
{
    return ['high', 'critical'];
}

/** True when this incident should raise an emergency notification. */
function incident_needs_alert(array $incident): bool
{
    return in_array((string) $incident['severity'], incident_alert_severities(), true);
}

/**
 * Notify every administrator, transport manager and dispatcher about an
 * incident.
 *
 * @return int Number of staff notified.
 */
function notify_incident_staff(array $incident, string $headline): int
{
    $staff = db_all(
        "SELECT id FROM users
          WHERE role IN ('admin','manager','dispatcher') AND status = 'active'"
    );

    $severity = (string) $incident['severity'];
    $type     = incident_needs_alert($incident) ? 'emergency' : 'system';
    $referenceId = (int) ($incident['id'] ?? 0);

    $message = $headline
        . ' Type: ' . labelize((string) $incident['incident_type'])
        . '. Severity: ' . labelize($severity) . '.'
        . "\n" . truncate((string) $incident['description'], 240);

    $notified = 0;

    foreach ($staff as $user) {
        notify_user((int) $user['id'], 'Incident reported — ' . labelize($severity) . ' severity', $message, $type, $referenceId ?: null);
        $notified++;
    }

    return $notified;
}

/* ------------------------------------------------------------------
 | Lookups for forms, filters and the detail screen
 ------------------------------------------------------------------ */

/**
 * Trips that can be linked to an incident: today's services plus anything
 * currently running or recently operated. Restrict to one driver when a
 * driver is reporting their own incident.
 *
 * @return array<int, string>
 */
function incident_trip_options(?int $driverId = null): array
{
    $sql = 'SELECT t.id, t.trip_status, s.schedule_date, s.departure_time,
                   r.route_code, b.bus_number
              FROM trips t
              JOIN schedules s ON s.id = t.schedule_id
              JOIN routes r    ON r.id = t.route_id
              JOIN buses b     ON b.id = t.bus_id
             WHERE (s.schedule_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    OR t.trip_status IN ("boarding","running","delayed"))';

    $params = [];

    if ($driverId !== null && $driverId > 0) {
        $sql .= ' AND t.driver_id = ?';
        $params[] = $driverId;
    }

    $sql .= ' ORDER BY s.schedule_date DESC, s.departure_time DESC LIMIT 60';

    $options = [];

    foreach (db_all($sql, $params) as $trip) {
        $options[(int) $trip['id']] = 'TRP-' . str_pad((string) $trip['id'], 4, '0', STR_PAD_LEFT)
            . ' · ' . $trip['route_code'] . ' · ' . $trip['bus_number']
            . ' · ' . format_date((string) $trip['schedule_date'], 'd M')
            . ' ' . format_time((string) $trip['departure_time'])
            . ' · ' . labelize((string) $trip['trip_status']);
    }

    return $options;
}

/**
 * Every bus as id => label (a fault can be reported on any vehicle).
 *
 * @return array<int, string>
 */
function incident_bus_options(): array
{
    $options = [];

    foreach (db_all('SELECT id, bus_number, registration_number FROM buses ORDER BY bus_number') as $bus) {
        $options[(int) $bus['id']] = $bus['bus_number'] . ' · ' . $bus['registration_number'];
    }

    return $options;
}

/**
 * Every driver as id => name.
 *
 * @return array<int, string>
 */
function incident_driver_options(): array
{
    $options = [];

    foreach (db_all(
        'SELECT d.id, d.employee_id, u.name
           FROM drivers d JOIN users u ON u.id = d.user_id
          ORDER BY u.name'
    ) as $driver) {
        $options[(int) $driver['id']] = $driver['name'] . ' (' . $driver['employee_id'] . ')';
    }

    return $options;
}

/**
 * Incident joined to its trip, bus, driver and reporter.
 *
 * @return array<string, mixed>|null
 */
function find_incident(int $incidentId): ?array
{
    return db_one(
        'SELECT i.*,
                t.trip_status, t.passenger_count,
                s.schedule_date, s.departure_time, s.arrival_time,
                r.route_code, r.route_name, r.source, r.destination,
                b.bus_number, b.registration_number, b.status AS bus_status,
                d.employee_id,
                du.id AS driver_user_id, du.name AS driver_name, du.phone AS driver_phone,
                ru.name AS reporter_name, ru.role AS reporter_role
           FROM incidents i
           LEFT JOIN trips t     ON t.id = i.trip_id
           LEFT JOIN schedules s ON s.id = t.schedule_id
           LEFT JOIN routes r    ON r.id = t.route_id
           LEFT JOIN buses b     ON b.id = i.bus_id
           LEFT JOIN drivers d   ON d.id = i.driver_id
           LEFT JOIN users du    ON du.id = d.user_id
           LEFT JOIN users ru    ON ru.id = i.reported_by
          WHERE i.id = ?
          LIMIT 1',
        [$incidentId]
    );
}
