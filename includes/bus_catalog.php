<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/bus_catalog.php
 *
 * One source of truth for "what buses can I take?".
 *
 * Two kinds of service are merged here and nowhere else, so the passenger
 * search and the Available Buses board can never disagree:
 *
 *   REAL  — a dated departure from the schedules table (imported Excel
 *           routes once they are scheduled, plus the seeded timetable).
 *   DEMO  — a clearly flagged, deterministically generated service used to
 *           fill the gaps so a route or terminal is never an empty screen.
 *
 * Guarantees:
 *   • A demo service is skipped whenever a real service already covers the
 *     same (origin, destination, departure time) — no duplicates.
 *   • Terminal names are normalised (Kolkata / kolkata / KOLKATA, a
 *     trailing "Bus Stand"/"Depot", a bracketed area) before they are
 *     compared, so variations never create a second "terminal".
 *   • Everything is returned chronologically by departure time.
 *
 * The demo generator is deterministic: the same query always yields the
 * same timetable, so the application looks stable between page loads while
 * still being generated from live data rather than hardcoded results.
 *
 * Loading order: functions.php -> operations.php -> bus_catalog.php
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/operations.php';

/* ------------------------------------------------------------------
 | Name normalisation
 ------------------------------------------------------------------ */

/**
 * Normalise free text for comparison: lowercase, drop bracketed notes and
 * punctuation, collapse whitespace.
 */
function bus_catalog_normalize(?string $value): string
{
    $value = mb_strtolower(trim((string) $value), 'UTF-8');
    $value = preg_replace('/\([^)]*\)/u', ' ', $value) ?? $value;      // "(Howrah Depot)"
    $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value; // punctuation incl. dashes
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    // Drop generic transport words that never identify a place.
    $noise = [
        'bus stand', 'bus station', 'bus terminal', 'bus terminus', 'bus depot',
        'bus stop', 'central bus', 'state bank', 'isbt', 'depot', 'terminus',
        'terminal', 'stand', 'junction', 'bypass', 'area', 'per listing',
    ];

    $value = ' ' . $value . ' ';
    foreach ($noise as $word) {
        $value = str_replace(' ' . $word . ' ', ' ', $value);
    }

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

/**
 * The primary place name of a terminal string, e.g.
 * "Bengaluru — Majestic" -> "bengaluru", "Kolkata (Howrah Depot)" -> "kolkata".
 */
function bus_catalog_place_token(?string $value): string
{
    $raw = (string) $value;
    $raw = preg_replace('/\([^)]*\)/u', ' ', $raw) ?? $raw;

    // Keep the part before the first dash / comma / slash separator.
    $parts = preg_split('/[—–\-\/,|]+/u', $raw) ?: [$raw];

    return bus_catalog_normalize($parts[0] ?? $raw);
}

/** A stable 31-bit seed for a string (used to make output deterministic). */
function bus_catalog_seed(string $key): int
{
    return (int) (abs(crc32('fleetra|' . $key)) % 2147483647);
}

/**
 * True when a normalised needle matches any of the candidate strings.
 * Equality or a whole-word substring both count.
 */
function bus_catalog_matches(string $needle, array $candidates): bool
{
    $needle = trim($needle);

    if ($needle === '') {
        return true;
    }

    foreach ($candidates as $candidate) {
        $candidate = bus_catalog_normalize((string) $candidate);

        if ($candidate === '') {
            continue;
        }

        if ($candidate === $needle || str_contains($candidate, $needle)) {
            return true;
        }
    }

    return false;
}

/* ------------------------------------------------------------------
 | Demo reference data
 ------------------------------------------------------------------ */

/** True when a table exists in the current database (cached per request). */
function bus_catalog_table_exists(string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $cache[$table] = (int) db_value(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table],
        0
    ) > 0;

    return $cache[$table];
}

/**
 * True when the routes table carries the imported bus-catalog columns.
 *
 * The application works with or without them: an un-migrated database just
 * falls back to the base route columns, so the search page never dies with
 * an "Unknown column" error before the migration is run.
 */
function bus_catalog_routes_have_catalog_columns(): bool
{
    static $has = null;

    if ($has !== null) {
        return $has;
    }

    $has = (int) db_value(
        'SELECT COUNT(*) FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'routes\' AND COLUMN_NAME = \'origin_city\'',
        [],
        0
    ) > 0;

    return $has;
}

/** Operating agencies used for generated services (real ones come from the DB). */
function bus_catalog_operator_pool(): array
{
    static $pool = null;

    if ($pool !== null) {
        return $pool;
    }

    $pool = [];

    if (bus_catalog_table_exists('bus_operators')) {
        foreach (db_all('SELECT operator_name FROM bus_operators ORDER BY operator_name LIMIT 60') as $row) {
            $name = trim((string) ($row['operator_name'] ?? ''));
            if ($name !== '') {
                $pool[] = $name;
            }
        }
    }

    if ($pool === []) {
        $pool = [
            'State Road Transport Corporation',
            'Fleetra Intercity Express',
            'National Travels',
            'CityLink Roadways',
        ];
    }

    return $pool;
}

/** A major-city fallback list so every terminal has somewhere to go. */
function bus_catalog_major_cities(): array
{
    return [
        'Kolkata', 'Siliguri', 'Darjeeling', 'Digha', 'Asansol', 'Durgapur', 'Howrah',
        'Delhi', 'New Delhi', 'Mumbai', 'Pune', 'Nagpur', 'Bengaluru', 'Mysuru', 'Mangaluru',
        'Chennai', 'Coimbatore', 'Madurai', 'Hyderabad', 'Vijayawada', 'Visakhapatnam',
        'Kochi', 'Thiruvananthapuram', 'Ahmedabad', 'Surat', 'Vadodara', 'Rajkot',
        'Jaipur', 'Jodhpur', 'Udaipur', 'Lucknow', 'Varanasi', 'Kanpur', 'Agra',
        'Patna', 'Gaya', 'Ranchi', 'Bhubaneswar', 'Cuttack', 'Puri', 'Guwahati',
        'Shillong', 'Raipur', 'Bhopal', 'Indore', 'Chandigarh', 'Dehradun', 'Shimla',
    ];
}

/** Cached route catalogue from the database (imported Excel routes included). */
function bus_catalog_known_routes(): array
{
    static $routes = null;

    if ($routes !== null) {
        return $routes;
    }

    $routes = [];
    $catalog = bus_catalog_routes_have_catalog_columns();

    $originCity = $catalog ? 'COALESCE(origin_city, source)' : 'source';
    $destCity   = $catalog ? 'COALESCE(destination_city, destination)' : 'destination';
    $operator   = $catalog ? 'operator_name' : 'NULL';
    $routeType  = $catalog ? 'route_type' : 'NULL';
    $dataSource = $catalog ? 'data_source' : "'manual'";

    try {
        $routes = db_all(
            'SELECT id, route_code, route_name, source, destination, distance, base_fare,
                    ' . $operator . ' AS operator_name,
                    ' . $routeType . ' AS route_type,
                    ' . $originCity . ' AS origin_city,
                    ' . $destCity . ' AS destination_city,
                    ' . $dataSource . ' AS data_source
               FROM routes
              WHERE status = "active"
              ORDER BY route_code
              LIMIT 500'
        );
    } catch (Throwable $exception) {
        $routes = [];
    }

    return $routes;
}

/**
 * Best matching known route for an origin/destination pair, if any.
 *
 * @return array<string, mixed>|null
 */
function bus_catalog_route_hint(string $origin, string $destination): ?array
{
    $originToken = bus_catalog_place_token($origin);
    $destToken   = bus_catalog_place_token($destination);

    foreach (bus_catalog_known_routes() as $route) {
        $from = bus_catalog_place_token((string) ($route['origin_city'] ?? $route['source']));
        $to   = bus_catalog_place_token((string) ($route['destination_city'] ?? $route['destination']));

        if ($from === $originToken && $to === $destToken) {
            return $route;
        }
    }

    return null;
}

/**
 * Destinations that make sense to demo from a terminal: real route
 * endpoints first, then notable major cities. Deduplicated and ordered
 * deterministically so the list is stable between requests.
 *
 * @return array<int, string>
 */
function bus_catalog_destinations_for(string $terminal, int $limit = 8): array
{
    $token = bus_catalog_place_token($terminal);

    if ($token === '') {
        return [];
    }

    // Destinations that are served by a real (imported) route from this
    // terminal always come first. Generic major cities only fill the gaps.
    $routeFound = [];

    foreach (bus_catalog_known_routes() as $route) {
        $from = bus_catalog_place_token((string) ($route['origin_city'] ?? $route['source']));
        $to   = bus_catalog_place_token((string) ($route['destination_city'] ?? $route['destination']));

        if ($from !== '' && ($from === $token || str_contains($from, $token) || str_contains($token, $from))) {
            $routeFound[$to] = (string) $route['destination_city'];
        } elseif ($to !== '' && ($to === $token || str_contains($to, $token) || str_contains($token, $to))) {
            $routeFound[$from] = (string) $route['origin_city'];
        }
    }

    unset($routeFound[$token], $routeFound['']);

    $fallback = [];
    foreach (bus_catalog_major_cities() as $city) {
        $cityToken = bus_catalog_place_token($city);

        if ($cityToken !== $token
            && !isset($routeFound[$cityToken])
            && !isset($fallback[$cityToken])
            && !bus_catalog_matches($token, [$city])
        ) {
            $fallback[$cityToken] = $city;
        }
    }

    // Deterministic order within each group (stable between requests).
    $sortBySeed = static function (string $a, string $b) use ($token): int {
        return bus_catalog_seed($token . '|' . $a) <=> bus_catalog_seed($token . '|' . $b);
    };

    $routeCities = array_values($routeFound);
    usort($routeCities, $sortBySeed);

    $fallbackCities = array_values($fallback);
    usort($fallbackCities, $sortBySeed);

    return array_slice(array_merge($routeCities, $fallbackCities), 0, max(1, $limit));
}

/* ------------------------------------------------------------------
 | Demo generation
 ------------------------------------------------------------------ */

/** The fixed departure palette demo timetables are drawn from. */
function bus_catalog_demo_palette(): array
{
    return [
        '05:30', '06:10', '06:50', '07:30', '08:00', '08:45', '09:20', '10:10',
        '10:45', '11:30', '12:15', '13:00', '14:30', '15:15', '16:00', '16:45',
        '17:30', '18:15', '19:00', '20:15', '21:00', '22:30',
    ];
}

/**
 * Deterministic departure times for a pair, spread across the day.
 *
 * @return array<int, string> Sorted HH:MM values.
 */
function bus_catalog_demo_times(string $key, int $count = 3): array
{
    $palette = bus_catalog_demo_palette();
    $size    = count($palette);
    $seed    = bus_catalog_seed($key);
    $count   = max(2, min(6, $count));

    $step   = intdiv($size, $count);
    $picked = [];

    for ($i = 0; $i < $count; $i++) {
        $index = ($seed + ($i * $step) + ($i * 3)) % $size;
        $picked[$palette[$index]] = true;
    }

    $times = array_keys($picked);
    sort($times);

    return $times;
}

/** A deterministic, realistic bus type for a generated service. */
function bus_catalog_demo_bus_type(string $key): string
{
    $types = ['seater', 'semi_sleeper', 'sleeper', 'ac_seater', 'ac_sleeper', 'mini'];

    return $types[bus_catalog_seed('type|' . $key) % count($types)];
}

/** Estimated road distance (km) for a generated pair, using a real route when known. */
function bus_catalog_estimate_distance(string $origin, string $destination, ?array $hint): float
{
    if ($hint !== null && (float) $hint['distance'] > 0) {
        return (float) $hint['distance'];
    }

    $seed = bus_catalog_seed('dist|' . bus_catalog_place_token($origin) . '|' . bus_catalog_place_token($destination));

    return (float) (80 + ($seed % 720));
}

/**
 * Estimated journey time in minutes for a road distance, at roughly
 * 45 km/h. Used for demo services and for imported routes whose workbook
 * row carried no duration.
 */
function bus_catalog_estimate_duration(float $distanceKm): int
{
    return max(45, (int) round($distanceKm / 45 * 60));
}

/**
 * Indicative base fare for a road distance. Used for demo services and for
 * imported routes whose workbook row carried no fare, so a ticket is never
 * issued for a zero rupee journey.
 */
function bus_catalog_estimate_fare(float $distanceKm): float
{
    return max(60.0, (float) (round($distanceKm * 1.15 / 10) * 10));
}

/**
 * Build one demo service in exactly the same shape as a real one.
 *
 * @param array<string, mixed>|null $hint Matching catalog route, when one exists.
 * @return array<string, mixed>
 */
function bus_catalog_demo_service(string $origin, string $destination, string $date, string $time, ?array $hint = null): array
{
    $originToken = bus_catalog_place_token($origin);
    $destToken   = bus_catalog_place_token($destination);
    $key         = $originToken . '|' . $destToken . '|' . $time;
    $seed        = bus_catalog_seed($key);

    $distance  = bus_catalog_estimate_distance($origin, $destination, $hint);
    $duration  = bus_catalog_estimate_duration($distance);
    $capacity  = 32 + ($seed % 23);           // 32–54 seats
    $seatsLeft = max(4, $capacity - ($seed % 17));
    $busType   = bus_catalog_demo_bus_type($key);

    // The workbook/route base fare stays on the route; the price shown and
    // sold for this specific bus is derived from it so two services on the
    // same route are never exactly the same money.
    $baseFare  = $hint !== null && (float) $hint['base_fare'] > 0
        ? (float) $hint['base_fare']
        : bus_catalog_estimate_fare($distance);
    $fare      = fleetra_fare_for($baseFare, $busType, $time);

    $operators = bus_catalog_operator_pool();
    $operator  = (string) ($hint['operator_name'] ?? '') ?: $operators[$seed % count($operators)];

    $departureTs = strtotime($date . ' ' . $time) ?: null;
    $arrivalTs   = $departureTs !== null ? $departureTs + ($duration * 60) : null;

    return [
        'key'                => 'demo:' . $key,
        'origin'             => $origin,
        'destination'        => $destination,
        'origin_token'       => $originToken,
        'destination_token'  => $destToken,
        'date'               => $date,
        'departure_time'     => substr($time, 0, 5) . ':00',
        'arrival_time'       => $arrivalTs !== null ? date('H:i:s', $arrivalTs) : '',
        'departure_ts'       => $departureTs,
        'arrival_ts'         => $arrivalTs,
        'bus_number'         => sprintf('DM-%04d', 1000 + ($seed % 8999)),
        'bus_type'           => $busType,
        'operator'           => $operator,
        'capacity'           => $capacity,
        'seats_left'         => $seatsLeft,
        'distance'           => round($distance, 1),
        'duration_minutes'   => $duration,
        'base_fare'          => round($baseFare, 2),
        'fare'               => $fare,
        'route_code'         => (string) ($hint['route_code'] ?? ('DEMO-' . strtoupper(substr($originToken, 0, 3) . '-' . substr($destToken, 0, 3)))),
        'route_name'         => (string) ($hint['route_name'] ?? ($origin . ' to ' . $destination)),
        'is_demo'            => true,
        'schedule_id'        => null,
        'route_id'           => $hint !== null ? (int) $hint['id'] : null,
        'driver_name'        => 'To be assigned',
        'stop_count'         => 0,
        'data_source'        => 'demo',
    ];
}

/**
 * Demo services for an explicit origin/destination pair on one date.
 *
 * @return array<int, array<string, mixed>>
 */
function bus_catalog_demo_for_pair(string $origin, string $destination, string $date): array
{
    $hint  = bus_catalog_route_hint($origin, $destination);
    $key   = bus_catalog_place_token($origin) . '|' . bus_catalog_place_token($destination);
    $count = 3 + (bus_catalog_seed('n|' . $key) % 3); // 3–5 departures

    $services = [];
    foreach (bus_catalog_demo_times($key, $count) as $time) {
        $services[] = bus_catalog_demo_service($origin, $destination, $date, $time, $hint);
    }

    return $services;
}

/**
 * Demo services departing a terminal for one date, across several
 * destinations — the backbone of the "Available Buses" board.
 *
 * @return array<int, array<string, mixed>>
 */
function bus_catalog_demo_from_terminal(string $terminal, string $date, int $destinationLimit = 6): array
{
    $services = [];

    foreach (bus_catalog_destinations_for($terminal, $destinationLimit) as $destination) {
        $hint  = bus_catalog_route_hint($terminal, $destination);
        $key   = bus_catalog_place_token($terminal) . '|' . bus_catalog_place_token($destination);
        $count = 2 + (bus_catalog_seed('n|' . $key) % 3); // 2–4 departures

        foreach (bus_catalog_demo_times($key, $count) as $time) {
            $services[] = bus_catalog_demo_service($terminal, $destination, $date, $time, $hint);
        }
    }

    return $services;
}

/** Demo arrivals into a destination from a few origins (used when only "To" is given). */
function bus_catalog_demo_to_destination(string $destination, string $date, int $originLimit = 6): array
{
    $services = [];

    foreach (bus_catalog_destinations_for($destination, $originLimit) as $origin) {
        foreach (bus_catalog_demo_for_pair($origin, $destination, $date) as $service) {
            $services[] = $service;
        }
    }

    return $services;
}

/* ------------------------------------------------------------------
 | Real services
 ------------------------------------------------------------------ */

/**
 * Fetch and normalise real (scheduled) services.
 *
 * Filtering by terminal is done in PHP against a normalised place token,
 * so "Kolkata", "Kolkata (Esplanade)" and "kolkata" all match the same
 * depots, and a partial city name still finds its services.
 *
 * @param array{from?:string, to?:string, terminal?:string, date_from:string, date_to:string, limit?:int} $options
 * @return array<int, array<string, mixed>>
 */
function bus_catalog_real_services(array $options): array
{
    $dateFrom = (string) ($options['date_from'] ?? date('Y-m-d'));
    $dateTo   = (string) ($options['date_to'] ?? $dateFrom);
    $limit    = max(1, min(500, (int) ($options['limit'] ?? 300)));

    // Work with or without the imported catalog columns.
    $catalog      = bus_catalog_routes_have_catalog_columns();
    $originCity   = $catalog ? 'COALESCE(r.origin_city, r.source)' : 'r.source';
    $destCity     = $catalog ? 'COALESCE(r.destination_city, r.destination)' : 'r.destination';
    $operatorName = $catalog ? 'r.operator_name' : 'NULL';
    $dataSource   = $catalog ? 'r.data_source' : "'manual'";

    try {
        $rows = db_all(
            'SELECT s.id, s.schedule_date, s.departure_time, s.arrival_time, s.status AS schedule_status,
                    r.id AS route_id, r.route_code, r.route_name, r.source, r.destination,
                    r.distance, r.estimated_duration, r.base_fare,
                    ' . $originCity . ' AS origin_city,
                    ' . $destCity . ' AS destination_city,
                    ' . $operatorName . ' AS operator_name,
                    ' . $dataSource . ' AS data_source,
                    b.id AS bus_id, b.bus_number, b.bus_type, b.capacity, b.manufacturer,
                    b.status AS bus_status,
                    u.name AS driver_name,
                    (SELECT COUNT(*) FROM bookings bk
                      WHERE bk.schedule_id = s.id AND bk.booking_status IN ("pending","confirmed","completed")) AS seats_taken,
                    (SELECT COUNT(*) FROM stops st2 WHERE st2.route_id = r.id) AS stop_count
               FROM schedules s
               JOIN routes r  ON r.id = s.route_id
               JOIN buses b   ON b.id = s.bus_id
               JOIN drivers d ON d.id = s.driver_id
               JOIN users u   ON u.id = d.user_id
              WHERE r.status = "active"
                AND s.status <> "cancelled"
                AND s.schedule_date BETWEEN ? AND ?
              ORDER BY s.schedule_date ASC, s.departure_time ASC
              LIMIT ' . $limit,
            [$dateFrom, $dateTo]
        );
    } catch (Throwable $exception) {
        fleetra_log('Bus catalog real query failed: ' . $exception->getMessage(), 'WARNING');

        return [];
    }

    $fromToken     = isset($options['from']) ? bus_catalog_place_token((string) $options['from']) : '';
    $toToken       = isset($options['to']) ? bus_catalog_place_token((string) $options['to']) : '';
    $terminalToken = isset($options['terminal']) ? bus_catalog_place_token((string) $options['terminal']) : '';

    $services = [];

    foreach ($rows as $row) {
        $origin      = (string) $row['source'];
        $destination = (string) $row['destination'];

        if ($terminalToken !== '') {
            $candidates = [
                (string) $row['origin_city'],
                $origin,
                (string) $row['route_name'],
            ];
            if (!bus_catalog_matches($terminalToken, $candidates)) {
                continue;
            }
        }

        if ($fromToken !== '' && !bus_catalog_matches($fromToken, [
            (string) $row['origin_city'], $origin, (string) $row['route_name'],
        ])) {
            continue;
        }

        if ($toToken !== '' && !bus_catalog_matches($toToken, [
            (string) $row['destination_city'], $destination, (string) $row['route_name'],
        ])) {
            continue;
        }

        $departureTs = strtotime((string) $row['schedule_date'] . ' ' . (string) $row['departure_time']) ?: null;
        $arrivalTs   = strtotime((string) $row['schedule_date'] . ' ' . (string) $row['arrival_time']) ?: null;

        if ($departureTs !== null && $arrivalTs !== null && $arrivalTs <= $departureTs) {
            $arrivalTs += 86400;
        }

        $capacity  = (int) $row['capacity'];
        $seatsLeft = max(0, $capacity - (int) $row['seats_taken']);

        $services[] = [
            'key'               => 'real:' . (int) $row['id'],
            'origin'            => $origin,
            'destination'       => $destination,
            'origin_token'      => bus_catalog_place_token((string) $row['origin_city']),
            'destination_token' => bus_catalog_place_token((string) $row['destination_city']),
            'date'              => (string) $row['schedule_date'],
            'departure_time'    => (string) $row['departure_time'],
            'arrival_time'      => (string) $row['arrival_time'],
            'departure_ts'      => $departureTs,
            'arrival_ts'        => $arrivalTs,
            'bus_number'        => (string) $row['bus_number'],
            'bus_type'          => (string) $row['bus_type'],
            'operator'          => trim((string) ($row['operator_name'] ?? '')) !== ''
                ? (string) $row['operator_name']
                : (trim((string) ($row['manufacturer'] ?? '')) !== '' ? (string) $row['manufacturer'] : 'Fleetra'),
            'capacity'          => $capacity,
            'seats_left'        => $seatsLeft,
            'distance'          => (float) $row['distance'],
            'duration_minutes'  => time_range_duration((string) $row['departure_time'], (string) $row['arrival_time']),
            // Priced per departure, not per route, so buses with different
            // seat types and departure times do not all cost the same.
            'fare'              => fleetra_fare_for(
                (float) $row['base_fare'],
                (string) $row['bus_type'],
                (string) $row['departure_time']
            ),
            'route_code'        => (string) $row['route_code'],
            'route_name'        => (string) $row['route_name'],
            'base_fare'         => (float) $row['base_fare'],
            'is_demo'           => false,
            'schedule_id'       => (int) $row['id'],
            'route_id'          => (int) $row['route_id'],
            'driver_name'       => (string) $row['driver_name'],
            'stop_count'        => (int) $row['stop_count'],
            'schedule_status'   => (string) $row['schedule_status'],
            'bus_status'        => (string) $row['bus_status'],
            'data_source'       => trim((string) ($row['data_source'] ?? '')) !== '' ? (string) $row['data_source'] : 'manual',
        ];
    }

    return $services;
}

/* ------------------------------------------------------------------
 | Merge, de-duplicate and classify
 ------------------------------------------------------------------ */

/** The key used to detect the same service appearing twice. */
function bus_catalog_dedupe_key(array $service): string
{
    return bus_catalog_place_token((string) $service['origin']) . '|'
        . bus_catalog_place_token((string) $service['destination']) . '|'
        . substr((string) $service['departure_time'], 0, 5)
        . '|' . (string) ($service['date'] ?? '');
}

/**
 * Merge real and demo services, dropping any demo that repeats a real
 * service (or another demo) and sorting by departure time.
 *
 * @return array<int, array<string, mixed>>
 */
function bus_catalog_merge(array $real, array $demo): array
{
    $merged = [];
    $seen   = [];

    foreach ([$real, $demo] as $group) {
        foreach ($group as $service) {
            $key = bus_catalog_dedupe_key($service);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[]   = $service;
        }
    }

    usort($merged, static function (array $a, array $b): int {
        $left  = (int) ($a['departure_ts'] ?? 0);
        $right = (int) ($b['departure_ts'] ?? 0);

        return $left <=> $right ?: strcmp((string) $a['origin'], (string) $b['origin']);
    });

    return $merged;
}

/**
 * Add availability to a service based on the timetable and current seats.
 *
 * @return array<string, mixed>
 */
function bus_catalog_classify(array $service, int $now, int $passengers = 1): array
{
    $departure = $service['departure_ts'];
    $seatsLeft = (int) $service['seats_left'];
    $status    = (string) ($service['schedule_status'] ?? 'scheduled');
    $busStatus = (string) ($service['bus_status'] ?? 'active');

    $isDemo      = (bool) $service['is_demo'];
    $isCancelled = $status === 'cancelled';
    $busOff      = !$isDemo && $busStatus !== 'active';
    $isFuture    = $departure !== null && $departure > $now;
    $tooFull     = $seatsLeft < $passengers;

    if ($isCancelled || $busOff) {
        $availability = 'unavailable';
    } elseif (!$isFuture) {
        $availability = 'departed';
    } elseif ($tooFull) {
        $availability = 'unavailable';
    } else {
        $availability = 'scheduled';
    }

    $service['availability'] = $availability;
    $service['too_full']     = $tooFull;
    // A real, scheduled departure is bookable immediately. A future demo
    // service is bookable as well, but only through the materialisation
    // path (includes/demo_booking.php), so it is not flagged here; the
    // search UI renders it as a booking form pointing at book_demo.php.
    $service['bookable']     = $availability === 'scheduled' && !$isDemo;
    $service['minutes_to_go'] = $departure !== null ? (int) floor(($departure - $now) / 60) : null;

    return $service;
}

/* ------------------------------------------------------------------
 | Public API — Search Buses
 ------------------------------------------------------------------ */

/**
 * Search real + demo services for a journey.
 *
 * @param array{from?:string, to?:string, date?:string, passengers?:int} $query
 * @return array{
 *   services:array<int, array<string, mixed>>,
 *   groups:array<string, array<int, array<string, mixed>>>,
 *   stats:array<string, mixed>,
 *   has_search:bool
 * }
 */
function bus_catalog_search(array $query): array
{
    $from       = trim((string) ($query['from'] ?? ''));
    $to         = trim((string) ($query['to'] ?? ''));
    $date       = trim((string) ($query['date'] ?? ''));
    $passengers = max(1, min(6, (int) ($query['passengers'] ?? 1)));
    $hasSearch  = $from !== '' || $to !== '' || $date !== '';

    $today      = date('Y-m-d');
    $demoDate   = is_valid_date($date) ? $date : $today;
    $windowFrom = is_valid_date($date) ? $date : $today;
    $windowTo   = is_valid_date($date) ? $date : date('Y-m-d', strtotime('+7 days'));

    $real = bus_catalog_real_services([
        'from'      => $from !== '' ? $from : null,
        'to'        => $to !== '' ? $to : null,
        'date_from' => $windowFrom,
        'date_to'   => $windowTo,
    ]);

    $demo = [];

    if ($hasSearch) {
        if ($from !== '' && $to !== '') {
            $demo = bus_catalog_demo_for_pair($from, $to, $demoDate);
        } elseif ($from !== '') {
            $demo = bus_catalog_demo_from_terminal($from, $demoDate, 5);
        } elseif ($to !== '') {
            $demo = bus_catalog_demo_to_destination($to, $demoDate, 5);
        }
    }

    // Real services on the demo date win; a real service on another day is
    // still useful, so only collapse demos against the same date.
    $now       = time();
    $services  = [];
    $groups    = ['scheduled' => [], 'departed' => [], 'unavailable' => []];

    foreach (bus_catalog_merge($real, $demo) as $service) {
        $service = bus_catalog_classify($service, $now, $passengers);

        // When a specific date was requested, only show that date.
        if (is_valid_date($date) && (string) $service['date'] !== $date) {
            continue;
        }

        $services[] = $service;
        $groups[$service['availability']][] = $service;
    }

    $totalSeats = 0;
    $cheapest   = 0.0;

    foreach ($groups['scheduled'] as $service) {
        $totalSeats += (int) $service['seats_left'];
        $fare        = (float) $service['fare'];
        if ($fare > 0 && ($cheapest === 0.0 || $fare < $cheapest)) {
            $cheapest = $fare;
        }
    }

    return [
        'services'   => $services,
        'groups'     => $groups,
        'has_search' => $hasSearch,
        'stats'      => [
            'scheduled'   => count($groups['scheduled']),
            'departed'    => count($groups['departed']),
            'unavailable' => count($groups['unavailable']),
            'total'       => count($services),
            'seats'       => $totalSeats,
            'cheapest'    => $cheapest,
            'real'        => count(array_filter($services, static fn ($s) => !$s['is_demo'])),
            'demo'        => count(array_filter($services, static fn ($s) => $s['is_demo'])),
        ],
    ];
}

/* ------------------------------------------------------------------
 | Public API — Available Buses (from my current terminal)
 ------------------------------------------------------------------ */

/**
 * Upcoming departures from one terminal, in chronological order.
 *
 * @return array{
 *   services:array<int, array<string, mixed>>,
 *   earlier:array<int, array<string, mixed>>,
 *   stats:array<string, mixed>,
 *   terminal:string
 * }
 */
function bus_catalog_available(string $terminal, ?string $date = null, bool $includeEarlier = false, int $limit = 60): array
{
    $terminal = trim($terminal);
    $today    = date('Y-m-d');
    $date     = is_valid_date($date) ? $date : $today;
    $now      = time();

    $real = bus_catalog_real_services([
        'terminal'  => $terminal,
        'date_from' => $date,
        'date_to'   => $date,
    ]);

    $demo = bus_catalog_demo_from_terminal($terminal, $date, 6);

    $merged = bus_catalog_merge($real, $demo);

    // The board must not look empty late at night: add tomorrow's earliest
    // services when today has fewer than three still to come.
    $upcomingToday = array_filter($merged, static fn ($service) => ($service['departure_ts'] ?? 0) >= $now);

    if (count($upcomingToday) < 3 && $date === $today) {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $merged   = bus_catalog_merge(
            $merged,
            bus_catalog_merge(
                bus_catalog_real_services(['terminal' => $terminal, 'date_from' => $tomorrow, 'date_to' => $tomorrow]),
                bus_catalog_demo_from_terminal($terminal, $tomorrow, 4)
            )
        );
    }

    $upcoming = [];
    $earlier  = [];

    foreach ($merged as $service) {
        $service = bus_catalog_classify($service, $now, 1);

        if (!$includeEarlier && $service['availability'] === 'departed') {
            continue;
        }

        if ($service['availability'] === 'departed') {
            $earlier[] = $service;
        } else {
            $upcoming[] = $service;
        }
    }

    // Newest first for the "Earlier buses" list.
    usort($earlier, static fn ($a, $b) => (int) $b['departure_ts'] <=> (int) $a['departure_ts']);

    $upcoming    = array_slice($upcoming, 0, $limit);
    $nextService = $upcoming[0] ?? null;

    return [
        'services' => $upcoming,
        'earlier'  => array_slice($earlier, 0, 20),
        'terminal' => $terminal,
        'stats'    => [
            'total'     => count($upcoming),
            'earlier'   => count($earlier),
            'destinations' => count(array_unique(array_map(static fn ($s) => bus_catalog_place_token((string) $s['destination']), $upcoming))),
            'next'      => $nextService,
            'real'      => count(array_filter($upcoming, static fn ($s) => !$s['is_demo'])),
            'demo'      => count(array_filter($upcoming, static fn ($s) => $s['is_demo'])),
        ],
    ];
}

/** A short, honest label for where a service's data came from. */
function bus_catalog_source_label(array $service): string
{
    if (!empty($service['is_demo'])) {
        return 'Demo service';
    }

    return (string) ($service['data_source'] ?? 'manual') === 'excel' ? 'Imported' : 'Scheduled';
}
