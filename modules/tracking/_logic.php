<?php
/**
 * Fleetra — Live tracking logic
 * ------------------------------------------------------------------
 * modules/tracking/_logic.php
 *
 * Two clearly separated sources feed the live map:
 *
 *   source = 'device'     coordinates posted by a real GPS unit or the
 *                         driver's phone (api/tracking.php).
 *   source = 'simulated'  coordinates produced by Fleetra's own demo
 *                         simulation, because a local XAMPP install has no
 *                         GPS hardware attached.
 *
 * Nothing here pretends a simulated position is a real one: the source
 * travels with every row and the interface labels it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/operations.php';

// current_driver_id() and the trip lookups live with the trip module.
require_once __DIR__ . '/../trips/_logic.php';

/** Default poll interval in seconds when the setting is missing. */
const TRACKING_DEFAULT_REFRESH = 15;

/** Read a settings row, falling back to a default. */
function tracking_setting(string $key, string $default = ''): string
{
    static $cache = [];

    if (!array_key_exists($key, $cache)) {
        $value = db_value('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1', [$key], null);
        $cache[$key] = $value === null ? $default : (string) $value;
    }

    return $cache[$key];
}

/** How often the map should refresh, in seconds. */
function tracking_refresh_seconds(): int
{
    $seconds = (int) tracking_setting('tracking_refresh_seconds', (string) TRACKING_DEFAULT_REFRESH);

    return max(5, min(120, $seconds > 0 ? $seconds : TRACKING_DEFAULT_REFRESH));
}

/* ------------------------------------------------------------------
 | Geography helpers
 ------------------------------------------------------------------ */

/** Great-circle distance between two points, in kilometres. */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Compass heading in degrees from one point to another. */
function bearing_degrees(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dLng = deg2rad($lng2 - $lng1);

    $x = sin($dLng) * cos($phi2);
    $y = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dLng);

    $bearing = rad2deg(atan2($x, $y));

    return (int) round(fmod($bearing + 360, 360));
}

/**
 * Stops of a route that can be plotted, with the running distance from the
 * origin already worked out.
 *
 * @return array<int, array{name:string, lat:float, lng:float, distance:float}>
 */
function route_polyline(int $routeId): array
{
    $rows = db_all(
        'SELECT stop_name, latitude, longitude
           FROM stops
          WHERE route_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL
          ORDER BY stop_order',
        [$routeId]
    );

    $points   = [];
    $distance = 0.0;
    $previous = null;

    foreach ($rows as $row) {
        $lat = (float) $row['latitude'];
        $lng = (float) $row['longitude'];

        if ($previous !== null) {
            $distance += haversine_km($previous[0], $previous[1], $lat, $lng);
        }

        $points[] = [
            'name'     => (string) $row['stop_name'],
            'lat'      => $lat,
            'lng'      => $lng,
            'distance' => $distance,
        ];

        $previous = [$lat, $lng];
    }

    return $points;
}

/**
 * Point at a fraction (0..1) along a polyline.
 *
 * @param array<int, array{name:string, lat:float, lng:float, distance:float}> $points
 * @return array{lat:float, lng:float, heading:int, distance:float}|null
 */
function point_along_polyline(array $points, float $fraction): ?array
{
    if (count($points) < 2) {
        return null;
    }

    $total = $points[count($points) - 1]['distance'];

    if ($total <= 0) {
        return null;
    }

    $fraction = max(0.0, min(1.0, $fraction));
    $target   = $fraction * $total;

    for ($index = 1; $index < count($points); $index++) {
        $from = $points[$index - 1];
        $to   = $points[$index];

        if ($target > $to['distance']) {
            continue;
        }

        $segment = $to['distance'] - $from['distance'];
        $ratio   = $segment > 0 ? ($target - $from['distance']) / $segment : 0.0;

        return [
            'lat'      => $from['lat'] + (($to['lat'] - $from['lat']) * $ratio),
            'lng'      => $from['lng'] + (($to['lng'] - $from['lng']) * $ratio),
            'heading'  => bearing_degrees($from['lat'], $from['lng'], $to['lat'], $to['lng']),
            'distance' => $target,
        ];
    }

    $last = $points[count($points) - 1];

    return [
        'lat'      => $last['lat'],
        'lng'      => $last['lng'],
        'heading'  => 0,
        'distance' => $total,
    ];
}

/* ------------------------------------------------------------------
 | Live state
 ------------------------------------------------------------------ */

/**
 * Today's operated trips that still have a bus on the road, keyed by bus id.
 *
 * @return array<int, array<string, mixed>>
 */
function active_trips_by_bus(): array
{
    $rows = db_all(
        'SELECT t.id AS trip_id, t.bus_id, t.trip_status, t.passenger_count, t.delay_minutes, t.remarks,
                t.driver_id,
                s.schedule_date, s.departure_time, s.arrival_time,
                r.id AS route_id, r.route_code, r.route_name, r.source, r.destination,
                d.employee_id, u.name AS driver_name, u.phone AS driver_phone
           FROM trips t
           JOIN schedules s ON s.id = t.schedule_id
           JOIN routes r    ON r.id = t.route_id
           JOIN drivers d   ON d.id = t.driver_id
           JOIN users u     ON u.id = d.user_id
          WHERE s.schedule_date = CURDATE()
            AND t.trip_status IN ("boarding","running","delayed")
          ORDER BY s.departure_time'
    );

    $byBus = [];

    foreach ($rows as $row) {
        $byBus[(int) $row['bus_id']] = $row;
    }

    return $byBus;
}

/**
 * Every tracked bus: its assigned bus record, its active trip (if any) and
 * the most recent position Fleetra has for it.
 *
 * @return array<int, array<string, mixed>>
 */
function tracking_buses(): array
{
    $buses = db_all(
        "SELECT b.id AS bus_id, b.bus_number, b.registration_number, b.bus_type, b.capacity,
                b.status AS bus_status, b.current_mileage,
                bl.latitude, bl.longitude, bl.speed, bl.heading, bl.source, bl.recorded_at
           FROM buses b
           LEFT JOIN bus_locations bl ON bl.id = (
                SELECT x.id FROM bus_locations x
                 WHERE x.bus_id = b.id
                 ORDER BY x.recorded_at DESC, x.id DESC
                 LIMIT 1
           )
          WHERE b.status <> 'inactive'
          ORDER BY b.bus_number"
    );

    $activeTrips = active_trips_by_bus();
    $tracked     = [];

    foreach ($buses as $bus) {
        $busId = (int) $bus['bus_id'];
        $trip  = $activeTrips[$busId] ?? null;

        $hasFix = $bus['latitude'] !== null && $bus['longitude'] !== null;

        $latitude  = $hasFix ? (float) $bus['latitude'] : null;
        $longitude = $hasFix ? (float) $bus['longitude'] : null;

        /* A bus is only ever plotted at a position it actually reported.
           Fleetra does NOT invent a location for a bus without a fix — a
           bus that has never reported simply has no marker, and one whose
           fix has aged is labelled live / recent / stale / offline. */
        $freshness = tracking_freshness($hasFix ? (string) $bus['recorded_at'] : null);

        $tracked[] = array_merge($bus, [
            'trip'        => $trip,
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'has_fix'     => $hasFix,
            'live_status' => $freshness['status'],
            'live_label'  => $freshness['label'],
            'age_seconds' => $freshness['seconds'],
        ]);
    }

    return $tracked;
}

/**
 * A row from tracking_buses() shaped for the map (also used by the JSON
 * endpoint, so the page and the poll can never drift apart).
 *
 * @param array<string, mixed> $bus
 * @return array<string, mixed>|null Null when the bus has no usable position.
 */
function tracking_marker_payload(array $bus): ?array
{
    if ($bus['latitude'] === null || $bus['longitude'] === null) {
        return null;
    }

    $trip = $bus['trip'] ?? null;

    $freshness = tracking_freshness((string) ($bus['recorded_at'] ?? ''));

    return [
        'bus_id'       => (int) $bus['bus_id'],
        'bus_number'   => (string) $bus['bus_number'],
        'registration' => (string) $bus['registration_number'],
        'capacity'     => (int) $bus['capacity'],
        'bus_status'   => (string) $bus['bus_status'],
        'lat'          => (float) $bus['latitude'],
        'lng'          => (float) $bus['longitude'],
        'speed'        => $bus['speed'] !== null ? (float) $bus['speed'] : null,
        'heading'      => $bus['heading'] !== null ? (int) $bus['heading'] : null,
        'source'       => $bus['source'] !== null ? (string) $bus['source'] : null,
        'recorded_at'  => (string) ($bus['recorded_at'] ?? ''),
        'live_status'  => $freshness['status'],
        'live_label'   => $freshness['label'],
        'age_seconds'  => $freshness['seconds'],
        'variant'      => tracking_marker_variant($trip['trip_status'] ?? null, $freshness['status']),
        'trip_id'      => $trip !== null ? (int) $trip['trip_id'] : null,
        'trip_status'  => $trip['trip_status'] ?? null,
        'route_code'   => $trip['route_code'] ?? null,
        'route_name'   => $trip['route_name'] ?? null,
        'route_from'   => $trip['source'] ?? null,
        'route_to'     => $trip['destination'] ?? null,
        'driver'       => $trip['driver_name'] ?? null,
        'departure'    => $trip['departure_time'] ?? null,
        'arrival'      => $trip['arrival_time'] ?? null,
        'passengers'   => $trip !== null ? (int) $trip['passenger_count'] : null,
        'delay'        => $trip !== null ? (int) $trip['delay_minutes'] : null,
    ];
}

/** Human label for a location source. */
function tracking_source_label(?string $source): string
{
    return match ($source) {
        'device'    => 'Live device',
        'simulated' => 'Simulated demo',
        default     => 'No position yet',
    };
}

/**
 * Marker variant for the map: freshness wins over trip status, so an aged
 * position can never masquerade as a live one.
 *
 *   live     fresh fix (<= 2 min)
 *   recent   fix <= 10 min old
 *   stale    fix <= 30 min old
 *   offline  an old fix (or none) — last known position only
 *   delayed  fresh fix on a delayed service
 */
function tracking_marker_variant(?string $tripStatus, string $liveStatus): string
{
    if ($liveStatus === 'offline' || $liveStatus === 'no_location') {
        return 'offline';
    }

    if ($liveStatus === 'stale') {
        return 'stale';
    }

    if ($tripStatus === 'delayed') {
        return 'delayed';
    }

    return $liveStatus === 'recent' ? 'recent' : 'live';
}

/* ------------------------------------------------------------------
 | Simulation
 ------------------------------------------------------------------ */

/**
 * Advance the demo simulation by one step.
 *
 * Each bus on today's active services is moved along its route based on how
 * far through its planned journey it should be right now, and a new
 * bus_locations row is written with source = 'simulated'.
 *
 * @return array{moved:int, skipped:int}
 */
function simulate_live_positions(bool $force = false): array
{
    $moved   = 0;
    $skipped = 0;

    // Guard against double-clicks writing two points in the same second.
    if (!$force) {
        $recent = (int) db_value(
            "SELECT COUNT(*) FROM bus_locations WHERE source = 'simulated' AND recorded_at > DATE_SUB(NOW(), INTERVAL 2 SECOND)",
            [],
            0
        );

        if ($recent > 0) {
            return ['moved' => 0, 'skipped' => 1];
        }
    }

    foreach (active_trips_by_bus() as $busId => $trip) {
        $points = route_polyline((int) $trip['route_id']);

        if (count($points) < 2) {
            $skipped++;
            continue;
        }

        $start = strtotime((string) $trip['schedule_date'] . ' ' . (string) $trip['departure_time']);
        $end   = strtotime((string) $trip['schedule_date'] . ' ' . (string) $trip['arrival_time']);
        $now   = time();

        if ($start === false || $end === false) {
            $skipped++;
            continue;
        }

        // A service that arrives after midnight runs past 24:00.
        if ($end <= $start) {
            $end += 86400;
        }

        $duration = max(60, $end - $start);
        $elapsed  = max(0, min($duration, $now - $start));
        $fraction = $elapsed / $duration;

        $point = point_along_polyline($points, $fraction);

        if ($point === null) {
            $skipped++;
            continue;
        }

        // Average speed over the route, and zero once the bus has arrived.
        $totalKm   = $points[count($points) - 1]['distance'];
        $speed     = $fraction >= 1.0 ? 0.0 : round($totalKm / ($duration / 3600), 1);

        try {
            db_insert('bus_locations', [
                'bus_id'      => $busId,
                'trip_id'     => (int) $trip['trip_id'],
                'latitude'    => round($point['lat'], 7),
                'longitude'   => round($point['lng'], 7),
                'speed'       => min(120.0, $speed),
                'heading'     => $point['heading'],
                'source'      => 'simulated',
                'recorded_at' => date('Y-m-d H:i:s'),
            ]);
            $moved++;
        } catch (Throwable $exception) {
            fleetra_log('Simulated location insert failed: ' . $exception->getMessage(), 'WARNING');
            $skipped++;
        }
    }

    return ['moved' => $moved, 'skipped' => $skipped];
}

/**
 * Record a real position for a bus and trip.
 *
 * @return int|false New location id, or false when the input is not usable.
 */
function record_device_position(
    int $busId,
    ?int $tripId,
    float $latitude,
    float $longitude,
    float $speed = 0.0,
    int $heading = 0
): int|false {
    if ($busId <= 0 || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return false;
    }

    if (!db_exists('buses', 'id', $busId)) {
        return false;
    }

    try {
        return db_insert('bus_locations', [
            'bus_id'      => $busId,
            'trip_id'     => $tripId,
            'latitude'    => round($latitude, 7),
            'longitude'   => round($longitude, 7),
            'speed'       => max(0.0, min(200.0, $speed)),
            'heading'     => max(0, min(359, $heading)),
            'source'      => 'device',
            'recorded_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $exception) {
        fleetra_log('Device location insert failed: ' . $exception->getMessage(), 'WARNING');

        return false;
    }
}

/**
 * The bus a driver is currently working with, if any.
 *
 * @return array<string, mixed>|null
 */
function driver_current_trip(int $driverId): ?array
{
    if ($driverId <= 0) {
        return null;
    }

    return db_one(
        'SELECT t.id AS trip_id, t.bus_id, t.trip_status, t.route_id,
                s.schedule_date, s.departure_time, s.arrival_time,
                r.route_code, r.route_name, b.bus_number, b.registration_number
           FROM trips t
           JOIN schedules s ON s.id = t.schedule_id
           JOIN routes r    ON r.id = t.route_id
           JOIN buses b     ON b.id = t.bus_id
          WHERE t.driver_id = ? AND s.schedule_date = CURDATE()
            AND t.trip_status IN ("boarding","running","delayed")
          ORDER BY s.departure_time
          LIMIT 1',
        [$driverId]
    );
}
