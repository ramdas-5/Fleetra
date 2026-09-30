<?php
/**
 * Fleetra — Scheduling module logic
 * ------------------------------------------------------------------
 * modules/schedules/_logic.php
 *
 * A schedule is a dated departure of a route with a bus and a driver
 * attached. Creating one also creates its trip, because a schedule that
 * nobody operates is not a real answer to "what is running today?".
 *
 * Two rules are enforced here rather than trusted to the database:
 *
 *   1. The same bus — or the same driver — can never be in two places at
 *      once. The unique keys only catch an identical departure time, so
 *      the overlap check in find_schedule_conflicts() does the real work.
 *   2. Only buses that are active, and only drivers who are on the books
 *      with a valid licence, can be put on a schedule at all.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/operations.php';

/** Planning states for a schedule. */
function schedule_status_options(): array
{
    return [
        'scheduled' => 'Scheduled',
        'running'   => 'Running',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}

/**
 * Routes that can be scheduled.
 *
 * @return array<int, string>
 */
function schedule_route_options(bool $includeInactive = false): array
{
    $sql = 'SELECT id, route_code, route_name, source, destination, estimated_duration, status
              FROM routes '
        . ($includeInactive ? '' : 'WHERE status = "active" ')
        . 'ORDER BY route_code';

    $options = [];

    foreach (db_all($sql) as $route) {
        $options[(int) $route['id']] = $route['route_code'] . ' · ' . $route['route_name']
            . ' (' . $route['source'] . ' → ' . $route['destination'] . ')'
            . ($route['status'] !== 'active' ? ' — inactive' : '');
    }

    return $options;
}

/**
 * Typical running time per route, in minutes, keyed by route id. Powers the
 * arrival-time suggestion in the schedule form.
 *
 * @return array<int, int>
 */
function schedule_route_durations(): array
{
    $durations = [];

    foreach (db_all('SELECT id, estimated_duration FROM routes') as $route) {
        $durations[(int) $route['id']] = (int) $route['estimated_duration'];
    }

    return $durations;
}

/**
 * Buses that may be assigned to a departure. Only active vehicles are
 * offered; a bus in the workshop or retired must not be scheduled.
 *
 * @return array<int, string>
 */
function schedulable_bus_options(bool $includeUnavailable = false): array
{
    $sql = 'SELECT id, bus_number, registration_number, capacity, bus_type, status
              FROM buses '
        . ($includeUnavailable ? '' : 'WHERE status = "active" ')
        . 'ORDER BY bus_number';

    $options = [];

    foreach (db_all($sql) as $bus) {
        $options[(int) $bus['id']] = $bus['bus_number'] . ' · ' . $bus['registration_number']
            . ' · ' . (int) $bus['capacity'] . ' seats'
            . ($bus['status'] !== 'active' ? ' — ' . labelize((string) $bus['status']) : '');
    }

    return $options;
}

/**
 * Drivers who may be put on duty: active employment, active login and a
 * licence that has not expired.
 *
 * @return array<int, string>
 */
function schedulable_driver_options(bool $includeUnavailable = false): array
{
    $rows = db_all(
        'SELECT d.id, d.employee_id, d.license_expiry, d.employment_status, u.name, u.status AS user_status
           FROM drivers d
           JOIN users u ON u.id = d.user_id
          ORDER BY u.name'
    );

    $options = [];

    foreach ($rows as $driver) {
        $available = (string) $driver['employment_status'] === 'active'
            && (string) $driver['user_status'] === 'active'
            && strtotime((string) $driver['license_expiry']) >= strtotime(date('Y-m-d'));

        if (!$available && !$includeUnavailable) {
            continue;
        }

        $options[(int) $driver['id']] = $driver['name'] . ' · ' . $driver['employee_id']
            . ($available ? '' : ' — ' . labelize((string) $driver['employment_status'])
                . (strtotime((string) $driver['license_expiry']) < strtotime(date('Y-m-d')) ? ', licence expired' : ''));
    }

    return $options;
}

/**
 * @param array<string, mixed>|null $schedule
 * @return array<string, string>
 */
function schedule_form_values(?array $schedule = null, ?array $prefill = null): array
{
    $defaults = [
        'route_id'       => '',
        'bus_id'         => '',
        'driver_id'      => '',
        'schedule_date'  => date('Y-m-d'),
        'departure_time' => '',
        'arrival_time'   => '',
        'status'         => 'scheduled',
        'remarks'        => '',
    ];

    if (is_array($prefill)) {
        foreach ($prefill as $key => $value) {
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = (string) $value;
            }
        }
    }

    if ($schedule === null) {
        return $defaults;
    }

    foreach ($defaults as $field => $default) {
        if (!array_key_exists($field, $schedule) || $schedule[$field] === null) {
            continue;
        }

        $value = (string) $schedule[$field];

        // TIME columns come back as HH:MM:SS — the form wants HH:MM.
        if ($field === 'departure_time' || $field === 'arrival_time') {
            $value = substr($value, 0, 5);
        }

        $defaults[$field] = $value;
    }

    return $defaults;
}

/** Suggested arrival time for a route leaving at the given time. */
function suggest_arrival_time(int $routeId, string $departure): string
{
    $minutes = time_to_minutes($departure);

    if ($minutes < 0) {
        return '';
    }

    $duration = (int) db_value('SELECT estimated_duration FROM routes WHERE id = ?', [$routeId], 0);

    if ($duration <= 0) {
        return '';
    }

    return substr(minutes_to_time($minutes + $duration), 0, 5);
}

/**
 * Validate a schedule submission, including the overlap check.
 *
 * @param array<string, mixed> $post
 * @return array{errors:array<string,string>, values:array<string,string>, conflicts:array<string,mixed>}
 */
function validate_schedule_request(array $post, bool $isEdit = false, ?int $scheduleId = null): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'route_id'       => $trim('route_id'),
        'bus_id'         => $trim('bus_id'),
        'driver_id'      => $trim('driver_id'),
        'schedule_date'  => $trim('schedule_date'),
        'departure_time' => $trim('departure_time'),
        'arrival_time'   => $trim('arrival_time'),
        'status'         => $trim('status') === '' ? 'scheduled' : $trim('status'),
        'remarks'        => $trim('remarks'),
    ];

    /* ---------------- Route, bus and driver ---------------- */

    if (!is_valid_option(schedule_route_options(true), $values['route_id'])) {
        $errors['route_id'] = 'Choose the route this departure runs on.';
    } else {
        $routeStatus = (string) db_value('SELECT status FROM routes WHERE id = ?', [(int) $values['route_id']], '');

        if ($routeStatus !== 'active') {
            $errors['route_id'] = 'That route is inactive. Reactivate it before scheduling new departures.';
        }
    }

    if (!is_valid_option(schedulable_bus_options(true), $values['bus_id'])) {
        $errors['bus_id'] = 'Choose the bus that will operate this departure.';
    } else {
        $bus = db_one('SELECT bus_number, status FROM buses WHERE id = ? LIMIT 1', [(int) $values['bus_id']]);

        if ($bus !== null && (string) $bus['status'] !== 'active') {
            $errors['bus_id'] = 'Bus ' . $bus['bus_number'] . ' is ' . strtolower(labelize((string) $bus['status']))
                . ' and cannot be scheduled. Only active buses are assignable.';
        }
    }

    if (!is_valid_option(schedulable_driver_options(true), $values['driver_id'])) {
        $errors['driver_id'] = 'Choose the driver for this departure.';
    } else {
        $driver = db_one(
            'SELECT d.employee_id, d.license_expiry, d.employment_status, u.name, u.status AS user_status
               FROM drivers d
               JOIN users u ON u.id = d.user_id
              WHERE d.id = ?
              LIMIT 1',
            [(int) $values['driver_id']]
        );

        if ($driver !== null) {
            if (strtotime((string) $driver['license_expiry']) < strtotime(date('Y-m-d'))) {
                $errors['driver_id'] = $driver['name'] . '\'s licence expired on '
                    . format_date((string) $driver['license_expiry']) . '. Renew it before assigning this driver.';
            } elseif ((string) $driver['employment_status'] !== 'active') {
                $errors['driver_id'] = $driver['name'] . ' is marked as '
                    . strtolower(labelize((string) $driver['employment_status'])) . ' and cannot be put on duty.';
            } elseif ((string) $driver['user_status'] !== 'active') {
                $errors['driver_id'] = $driver['name'] . '\'s Fleetra account is '
                    . strtolower(labelize((string) $driver['user_status'])) . ', so they cannot receive a duty.';
            }
        }
    }

    /* ---------------- Date and times ---------------- */

    if (!is_valid_date($values['schedule_date'])) {
        $errors['schedule_date'] = 'Choose a valid service date.';
    } elseif (!$isEdit && $values['schedule_date'] < date('Y-m-d')) {
        $errors['schedule_date'] = 'A new departure cannot be scheduled in the past. Pick today or a later date.';
    }

    $departure = time_to_minutes($values['departure_time']);
    $arrival   = time_to_minutes($values['arrival_time']);

    if ($departure < 0) {
        $errors['departure_time'] = 'Enter a valid departure time.';
    }

    if ($arrival < 0) {
        $errors['arrival_time'] = 'Enter a valid arrival time.';
    }

    if ($departure >= 0 && $arrival >= 0) {
        $duration = time_range_duration($values['departure_time'], $values['arrival_time']);

        if ($departure === $arrival) {
            $errors['arrival_time'] = 'The arrival time must be different from the departure time.';
        } elseif ($duration < 5) {
            $errors['arrival_time'] = 'A service must run for at least 5 minutes.';
        } elseif ($duration > 1440) {
            $errors['arrival_time'] = 'A service cannot run for longer than 24 hours.';
        }
    }

    if (!is_valid_option(schedule_status_options(), $values['status'])) {
        $errors['status'] = 'Choose a valid schedule status.';
    }

    /* ---------------- Double booking ---------------- */

    $conflicts = ['bus' => [], 'driver' => []];

    if ($errors === [] && $values['status'] !== 'cancelled') {
        $conflicts = find_schedule_conflicts([
            'bus_id'         => (int) $values['bus_id'],
            'driver_id'      => (int) $values['driver_id'],
            'schedule_date'  => $values['schedule_date'],
            'departure_time' => $values['departure_time'],
            'arrival_time'   => $values['arrival_time'],
        ], $scheduleId);

        $message = describe_schedule_conflicts($conflicts);

        if ($message !== '') {
            if ($conflicts['bus'] !== []) {
                $errors['bus_id'] = $message;
            }

            if ($conflicts['driver'] !== []) {
                $errors['driver_id'] = $message;
            }
        }
    }

    return ['errors' => $errors, 'values' => $values, 'conflicts' => $conflicts];
}

/**
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function schedule_db_payload(array $values): array
{
    return [
        'route_id'       => (int) $values['route_id'],
        'bus_id'         => (int) $values['bus_id'],
        'driver_id'      => (int) $values['driver_id'],
        'schedule_date'  => $values['schedule_date'],
        'departure_time' => minutes_to_time(time_to_minutes($values['departure_time'])),
        'arrival_time'   => minutes_to_time(time_to_minutes($values['arrival_time'])),
        'status'         => $values['status'],
    ];
}

/* ------------------------------------------------------------------
 | Reading schedules back
 ------------------------------------------------------------------ */

/** Full joined schedule row, or null. */
function find_schedule(int $scheduleId): ?array
{
    return db_one(
        'SELECT s.*,
                r.route_code, r.route_name, r.source, r.destination, r.distance, r.estimated_duration, r.base_fare,
                r.status AS route_status,
                b.bus_number, b.registration_number, b.capacity, b.bus_type, b.status AS bus_status,
                d.employee_id, d.license_expiry, d.employment_status,
                u.name AS driver_name, u.phone AS driver_phone, u.id AS driver_user_id,
                t.id AS trip_id, t.trip_status, t.passenger_count, t.delay_minutes, t.remarks AS trip_remarks
           FROM schedules s
           JOIN routes r  ON r.id = s.route_id
           JOIN buses b   ON b.id = s.bus_id
           JOIN drivers d ON d.id = s.driver_id
           JOIN users u   ON u.id = d.user_id
           LEFT JOIN trips t ON t.schedule_id = s.id
          WHERE s.id = ?
          LIMIT 1',
        [$scheduleId]
    );
}

/** The trip created for a schedule. */
function schedule_trip(int $scheduleId): ?array
{
    return db_one('SELECT * FROM trips WHERE schedule_id = ? LIMIT 1', [$scheduleId]);
}

/**
 * How many seats on a schedule are already sold, so a schedule can never be
 * deleted while passengers are holding those seats.
 *
 * @return array<string, int>
 */
function schedule_booking_counts(int $scheduleId): array
{
    return [
        'total'     => (int) db_value('SELECT COUNT(*) FROM bookings WHERE schedule_id = ?', [$scheduleId], 0),
        'active'    => (int) db_value(
            'SELECT COUNT(*) FROM bookings WHERE schedule_id = ? AND booking_status IN ("pending", "confirmed")',
            [$scheduleId],
            0
        ),
        'completed' => (int) db_value(
            'SELECT COUNT(*) FROM bookings WHERE schedule_id = ? AND booking_status = "completed"',
            [$scheduleId],
            0
        ),
    ];
}

/**
 * Keep the schedule and its trip in step after an edit.
 *
 * Cancelling runs the full cancellation cascade. Re-opening a cancelled
 * service reopens the schedule and the trip, but deliberately does not
 * reinstate the bookings that were cancelled and refunded at the time.
 *
 * @return array{bookings:int, tickets:int, refunded:float, notified:int}|null
 */
function apply_schedule_status(int $scheduleId, string $previousStatus, string $newStatus, string $reason = ''): ?array
{
    if ($newStatus === $previousStatus) {
        return null;
    }

    if ($newStatus === 'cancelled') {
        return cancel_schedule_service($scheduleId, $reason !== '' ? $reason : 'Service cancelled by the operator.');
    }

    if ($previousStatus === 'cancelled') {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            db_execute(
                'UPDATE trips SET trip_status = ?, actual_end_time = NULL WHERE schedule_id = ?',
                [$newStatus === 'completed' ? 'completed' : 'scheduled', $scheduleId]
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            fleetra_log('Schedule reopen failed: ' . $exception->getMessage(), 'WARNING');
        }
    }

    return null;
}
