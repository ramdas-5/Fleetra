<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/demo_booking.php
 *
 * Makes a demo service real the moment a passenger wants to book it.
 *
 * Demo services exist so a route is never an empty screen. They are
 * generated, not stored — which is why they have no schedule id and the
 * seat picker has nothing to attach a booking to. This file turns the
 * one demo service a passenger chose into ordinary database rows:
 *
 *   route (found by origin/destination, created when missing)
 *     -> stops (origin + destination when the route has none)
 *     -> a demo bus (matched on the demo bus number)
 *     -> a standby driver (an active one when free, created otherwise)
 *     -> a dated schedule at the demo departure time
 *
 * From then on it is an ordinary departure: it appears as a real
 * service on future searches and the standard booking, ticket and
 * cancellation flows apply unchanged. All of it is idempotent — booking
 * the same demo twice reuses the same route and schedule.
 *
 * Loading order: functions.php -> operations.php -> bus_catalog.php
 *                -> schedule_seeder.php -> here
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/bus_catalog.php';
require_once __DIR__ . '/schedule_seeder.php';

/**
 * Materialise a demo service and return its schedule id.
 *
 * @param string $origin      Raw origin shown on the demo card.
 * @param string $destination Raw destination shown on the demo card.
 * @param string $date        Y-m-d travel date.
 * @param string $time        Demo departure time (HH:MM or HH:MM:SS).
 * @throws RuntimeException When the request is invalid or cannot be stored.
 */
function fleetra_materialize_demo_service(string $origin, string $destination, string $date, string $time): int
{
    $origin      = trim(mb_substr($origin, 0, 120));
    $destination = trim(mb_substr($destination, 0, 120));
    $time        = fleetra_demo_normalize_time($time);

    if ($origin === '' || $destination === '' || !is_valid_date($date) || $time === null) {
        throw new RuntimeException('A valid demo origin, destination, date and time are required.');
    }

    // Rebuild the service from its own key so fare, distance and duration
    // can never be tampered with from the browser.
    $service  = bus_catalog_demo_service($origin, $destination, $date, substr($time, 0, 5));
    $duration = max(1, (int) $service['duration_minutes']);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $routeId = fleetra_demo_find_route($origin, $destination);

        if ($routeId === null) {
            $routeId = fleetra_demo_create_route($origin, $destination, $service);
        }

        fleetra_demo_ensure_stops($routeId, $origin, $destination, $duration);

        $existing = db_value(
            'SELECT id FROM schedules
              WHERE route_id = ? AND schedule_date = ? AND departure_time = ? AND status <> "cancelled"
              LIMIT 1',
            [$routeId, $date, $time]
        );

        if ($existing !== null) {
            $pdo->commit();

            return (int) $existing;
        }

        $busId    = fleetra_demo_bus($service);
        $driverId = fleetra_demo_driver($date, $time);

        $scheduleId = db_insert('schedules', [
            'route_id'       => $routeId,
            'bus_id'         => $busId,
            'driver_id'      => $driverId,
            'schedule_date'  => $date,
            'departure_time' => $time,
            'arrival_time'   => minutes_to_time(time_to_minutes($time) + $duration),
            'status'         => 'scheduled',
        ]);

        $pdo->commit();

        fleetra_log(
            'Materialised demo service ' . $origin . ' -> ' . $destination . ' on ' . $date . ' ' . $time
            . ' as schedule #' . $scheduleId,
            'INFO'
        );

        return $scheduleId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

/* ------------------------------------------------------------------
 | Route
 ------------------------------------------------------------------ */

/** The active route already serving this exact origin/destination pair. */
function fleetra_demo_find_route(string $origin, string $destination): ?int
{
    $originToken = bus_catalog_place_token($origin);
    $destToken   = bus_catalog_place_token($destination);

    if ($originToken === '' || $destToken === '') {
        return null;
    }

    foreach (bus_catalog_known_routes() as $route) {
        $from = bus_catalog_place_token((string) ($route['origin_city'] ?? $route['source']));
        $to   = bus_catalog_place_token((string) ($route['destination_city'] ?? $route['destination']));

        if ($from === $originToken && $to === $destToken) {
            return (int) $route['id'];
        }
    }

    return null;
}

/**
 * Create a demo route for a pair the catalogue does not cover.
 *
 * @param array<string, mixed> $service
 */
function fleetra_demo_create_route(string $origin, string $destination, array $service): int
{
    $code = fleetra_demo_route_code($origin, $destination);

    $data = [
        'route_code'         => $code,
        'route_name'         => $origin . ' to ' . $destination,
        'source'             => $origin,
        'destination'        => $destination,
        'distance'           => (float) $service['distance'],
        'estimated_duration' => (int) $service['duration_minutes'],
        'base_fare'          => round((float) $service['fare'], 2),
        'status'             => 'active',
    ];

    // Only write the imported-catalogue columns when they exist, so the
    // seeder works on a database that has not been migrated yet.
    if (bus_catalog_routes_have_catalog_columns()) {
        $data['operator_name']    = (string) $service['operator'];
        $data['route_type']       = 'Demo';
        $data['service_type']     = 'Demo';
        $data['origin_city']      = $origin;
        $data['destination_city'] = $destination;
        $data['external_ref']     = $code;
        $data['data_source']      = 'demo';
    }

    return db_insert('routes', $data);
}

/** A stable, unique, 20-character-or-less code for a demo pair. */
function fleetra_demo_route_code(string $origin, string $destination): string
{
    $originToken = preg_replace('/[^a-z0-9]/', '', bus_catalog_place_token($origin)) ?? '';
    $destToken   = preg_replace('/[^a-z0-9]/', '', bus_catalog_place_token($destination)) ?? '';

    $seed = bus_catalog_seed('demo-route|' . $originToken . '|' . $destToken);

    return sprintf(
        'DEMO-%s-%s-%03d',
        strtoupper(substr($originToken !== '' ? $originToken : 'from', 0, 4)),
        strtoupper(substr($destToken !== '' ? $destToken : 'to', 0, 4)),
        $seed % 1000
    );
}

/**
 * Guarantee a route has at least two ordered stops so a journey can be
 * selected and a fare pro-rated.
 */
function fleetra_demo_ensure_stops(int $routeId, string $origin, string $destination, int $durationMinutes): void
{
    $stops = db_all(
        'SELECT id, stop_order FROM stops WHERE route_id = ? ORDER BY stop_order, id',
        [$routeId]
    );

    if (count($stops) >= 2) {
        fleetra_backfill_stop_offsets($routeId, $durationMinutes);

        return;
    }

    $nextOrder = 1;
    foreach ($stops as $stop) {
        $nextOrder = max($nextOrder, (int) $stop['stop_order'] + 1);
    }

    db_insert('stops', [
        'route_id'       => $routeId,
        'stop_name'      => $origin,
        'stop_order'     => $nextOrder,
        'arrival_offset' => 0,
    ]);

    db_insert('stops', [
        'route_id'       => $routeId,
        'stop_name'      => $destination,
        'stop_order'     => $nextOrder + 1,
        'arrival_offset' => $durationMinutes,
    ]);
}

/* ------------------------------------------------------------------
 | Bus and driver
 ------------------------------------------------------------------ */

/**
 * The demo bus for this service, created on first use so the seat map
 * capacity and bus number match what the demo card advertised.
 *
 * @param array<string, mixed> $service
 */
function fleetra_demo_bus(array $service): int
{
    $busNumber = trim((string) ($service['bus_number'] ?? ''));

    if ($busNumber === '') {
        $busNumber = 'DM-' . strtoupper(substr(hash('crc32b', (string) $service['key']), 0, 4));
    }

    $existing = db_one('SELECT id, status FROM buses WHERE bus_number = ? LIMIT 1', [$busNumber]);

    if ($existing !== null) {
        if ((string) $existing['status'] !== 'active') {
            db_update('buses', ['status' => 'active'], ['id' => (int) $existing['id']]);
        }

        return (int) $existing['id'];
    }

    $types = ['seater', 'semi_sleeper', 'sleeper', 'ac_seater', 'ac_sleeper', 'mini'];
    $type  = in_array((string) $service['bus_type'], $types, true) ? (string) $service['bus_type'] : 'seater';

    return db_insert('buses', [
        'bus_number'          => $busNumber,
        'registration_number' => 'DEMO-' . strtoupper(substr(md5($busNumber), 0, 8)),
        'manufacturer'        => 'Fleetra',
        'model'               => 'Demo Service',
        'bus_type'            => $type,
        'capacity'            => max(18, (int) $service['capacity']),
        'fuel_type'           => 'diesel',
        'current_mileage'     => 0,
        'status'              => 'active',
    ]);
}

/** An active driver who is free at this date/time, or a standby demo driver. */
function fleetra_demo_driver(string $date, string $time): int
{
    $slot = substr($time, 0, 5);

    fleetra_schedule_occupancy($date);
    $occupancy = &fleetra_schedule_occupancy_ref();

    foreach (fleetra_schedule_driver_pool() as $driverId) {
        if (!isset($occupancy[$date]['driver'][$driverId][$slot])) {
            $occupancy[$date]['driver'][$driverId][$slot] = true;

            return $driverId;
        }
    }

    $driverId = fleetra_demo_create_driver();

    $occupancy[$date]['driver'][$driverId][$slot] = true;

    return $driverId;
}

/** Create (once) a standby driver account used only by demo services. */
function fleetra_demo_create_driver(): int
{
    $email = 'standby.driver@fleetra.local';

    $user = db_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);

    if ($user === null) {
        $userId = db_insert('users', [
            'name'     => 'Standby Driver',
            'email'    => $email,
            'password' => password_hash('Fleetra@123', PASSWORD_DEFAULT),
            'role'     => 'driver',
            'status'   => 'active',
        ]);
    } else {
        $userId = (int) $user['id'];
    }

    $driver = db_one('SELECT id FROM drivers WHERE user_id = ? LIMIT 1', [$userId]);

    if ($driver !== null) {
        return (int) $driver['id'];
    }

    return db_insert('drivers', [
        'user_id'           => $userId,
        'employee_id'       => 'DEMO-DRV-01',
        'license_number'    => 'DEMO-LIC-' . strtoupper(substr(md5($email), 0, 10)),
        'license_expiry'    => date('Y-m-d', strtotime('+5 years')),
        'experience_years'  => 5,
        'employment_status' => 'active',
        'joining_date'      => date('Y-m-d'),
    ]);
}

/* ------------------------------------------------------------------
 | Input
 ------------------------------------------------------------------ */

/** Normalise HH:MM / HH:MM:SS to HH:MM:SS, or null when invalid. */
function fleetra_demo_normalize_time(string $time): ?string
{
    $time = trim($time);

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $matches)) {
        return null;
    }

    $hours   = (int) $matches[1];
    $minutes = (int) $matches[2];
    $seconds = (int) ($matches[3] ?? 0);

    if ($hours > 23 || $minutes > 59 || $seconds > 59) {
        return null;
    }

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}
