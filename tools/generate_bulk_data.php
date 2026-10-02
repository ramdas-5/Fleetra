<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * tools/generate_bulk_data.php
 *
 * Generates a large, realistic-looking transport catalogue from the
 * reference data already in the database:
 *
 *   • routes  — city pairs within a state (and plausible interstate
 *               pairs), each attributed to that state's own operator,
 *               with road distance, journey time and pro-rated fare.
 *   • stops   — a boarding, arrival and a few intermediate stops per
 *               route so the seat picker can order a journey.
 *   • buses   — fleet vehicles with state-correct registration plates,
 *               manufacturer/model matches and realistic mileage.
 *   • drivers — driver accounts plus their profiles, for scheduling.
 *
 * Everything is derived from `locations` (cities + states + coordinates)
 * and `bus_operators` (state transport undertakings), so the output is
 * internally consistent: a West Bengal route is only ever operated by a
 * West Bengal corporation, and the bus carrying it wears a WB plate.
 *
 * The tool is idempotent — it generates stable codes and uses
 * INSERT IGNORE against the unique keys, so running it twice never
 * duplicates a row. It also writes a portable SQL dump so the catalogue
 * can be re-imported on another machine.
 *
 *   php tools/generate_bulk_data.php
 *   php tools/generate_bulk_data.php --routes=1500 --buses=1500 --drivers=500
 *   php tools/generate_bulk_data.php --dry-run
 *
 * After this, build the timetable with:
 *   php tools/generate_schedules.php --days=7
 */

declare(strict_types=1);

// Maintenance tool: never expose it over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bus_catalog.php';

/* ------------------------------------------------------------------
 | Options
 ------------------------------------------------------------------ */

$options = [
    'routes'  => 1500,
    'buses'   => 1500,
    'drivers' => 500,
    'sql'     => __DIR__ . '/../database/bulk_catalog_generated.sql',
    'seed'    => 20261002,
    'dry_run' => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo <<<TXT
Fleetra bulk catalogue generator

  php tools/generate_bulk_data.php [options]

  --routes=N     Number of routes to create      (default 1500)
  --buses=N      Number of buses to create       (default 1500)
  --drivers=N    Number of drivers to create     (default 500)
  --sql=PATH     Where to write the SQL dump     (default database/bulk_catalog_generated.sql)
  --seed=N       Deterministic seed              (default 20261002)
  --dry-run      Report what would be created, insert nothing

TXT;
        exit(0);
    }

    if ($argument === '--dry-run') {
        $options['dry_run'] = true;
        continue;
    }

    foreach (['routes', 'buses', 'drivers', 'seed'] as $key) {
        if (str_starts_with($argument, '--' . $key . '=')) {
            $options[$key] = (int) substr($argument, strlen($key) + 3);
        }
    }

    if (str_starts_with($argument, '--sql=')) {
        $options['sql'] = substr($argument, 6);
    }
}

$options['routes']  = max(0, min(20000, $options['routes']));
$options['buses']   = max(0, min(20000, $options['buses']));
$options['drivers'] = max(0, min(10000, $options['drivers']));

mt_srand($options['seed']);

/* ------------------------------------------------------------------
 | Reference data
 ------------------------------------------------------------------ */

$pdo = db();

// Some states are absent from the imported operator list. Add the known
// undertakings so no state ever has to borrow another state's operator.
$pdo->exec(
    "INSERT IGNORE INTO bus_operators
        (operator_code, operator_name, operator_type, state, headquarters, verification_status, notes)
     VALUES
        ('MPSRTC', 'Madhya Pradesh State Road Transport Corporation', 'State Transport', 'Madhya Pradesh', 'Bhopal', 'Needs Verification', 'State road transport undertaking added so Madhya Pradesh routes are attributed locally.'),
        ('LST', 'Ladakh State Transport', 'State Transport', 'Ladakh', 'Leh', 'Needs Verification', 'Regional transport undertaking added so Ladakh routes are attributed locally.'),
        ('DNHTC', 'Dadra and Nagar Haveli and Daman & Diu Transport', 'State Transport', 'Dadra and Nagar Haveli and Daman and Diu', 'Silvassa', 'Needs Verification', 'Union territory transport undertaking added so Daman/Diu routes are attributed locally.')"
);

/**
 * Cities that can be used as route endpoints, grouped by state.
 *
 * Coordinates come from the terminals already in `locations`, averaged to
 * a city centre, so generated distances are genuine road-ish estimates.
 *
 * @return array<string, array{code:string, cities:array<string, array{lat:float,lng:float,ref:?string}>}>
 */
function bulk_states(): array
{
    static $states = null;

    if ($states !== null) {
        return $states;
    }

    $states = [];

    // Every city with at least one located terminal.
    $rows = db_all(
        'SELECT city, state, state_code,
                AVG(latitude)  AS lat,
                AVG(longitude) AS lng,
                MIN(id)        AS ref
           FROM locations
          WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND city <> ""
          GROUP BY city, state, state_code
          ORDER BY state, city'
    );

    foreach ($rows as $row) {
        $state = trim((string) $row['state']);
        $city  = trim((string) $row['city']);

        if ($state === '' || $city === '') {
            continue;
        }

        $states[$state] ??= ['code' => '', 'cities' => []];

        $code = trim((string) ($row['state_code'] ?? ''));
        if ($code !== '' && $states[$state]['code'] === '') {
            $states[$state]['code'] = strtoupper($code);
        }

        $states[$state]['cities'][$city] = [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'ref' => $row['ref'] !== null ? (int) $row['ref'] : null,
        ];
    }

    // Fallback code for a state whose terminals carried no state_code.
    foreach ($states as $state => $meta) {
        if ($meta['code'] === '') {
            $states[$state]['code'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $state) ?? 'IN', 0, 2));
        }
    }

    // Island territories have no road link to the mainland, so they can
    // never be an endpoint of an intercity bus route.
    unset($states['Lakshadweep'], $states['Andaman and Nicobar Islands']);

    return $states;
}

/**
 * Operators available per state (state transport undertakings first).
 *
 * @return array<string, array<int, string>>
 */
function bulk_operators(): array
{
    static $operators = null;

    if ($operators !== null) {
        return $operators;
    }

    $operators = [];

    $rows = db_all('SELECT operator_name, state FROM bus_operators ORDER BY operator_name');

    foreach ($rows as $row) {
        $state = trim((string) $row['state']);
        $name  = trim((string) $row['operator_name']);

        if ($state === '' || $name === '') {
            continue;
        }

        $operators[$state][] = $name;
    }

    return $operators;
}

/** Straight-line distance in km scaled to a road distance. */
function bulk_distance(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 6371.0;
    $dLat  = deg2rad($lat2 - $lat1);
    $dLng  = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    $km = $earth * 2 * asin(min(1.0, sqrt($a)));

    // Roads are never straight, so apply a conservative detour factor.
    return max(18.0, $km * 1.22);
}

/** Pick a random element, honouring an optional weight callback. */
function bulk_pick(array $items, ?callable $weight = null)
{
    if ($items === []) {
        return null;
    }

    if ($weight === null) {
        return $items[array_rand($items)];
    }

    $weighted = [];
    foreach ($items as $item) {
        $weighted[] = ['item' => $item, 'weight' => max(0.0, (float) $weight($item))];
    }

    $total = array_sum(array_column($weighted, 'weight'));

    if ($total <= 0) {
        return $items[array_rand($items)];
    }

    $target = (mt_rand() / mt_getrandmax()) * $total;

    foreach ($weighted as $entry) {
        $target -= $entry['weight'];

        if ($target <= 0) {
            return $entry['item'];
        }
    }

    return $weighted[array_key_last($weighted)]['item'];
}

/**
 * Deterministic state-weighted selection so states with more cities get
 * proportionally more routes, exactly like a real network.
 */
function bulk_weighted_state(array $states)
{
    return bulk_pick(array_keys($states), static fn ($state) => count($states[$state]['cities']));
}

/** Mean coordinate of every located city in a state (used for proximity). */
function bulk_state_centroid(array $stateMeta): array
{
    $lat = 0.0;
    $lng = 0.0;
    $n   = 0;

    foreach ($stateMeta['cities'] as $city) {
        $lat += $city['lat'];
        $lng += $city['lng'];
        $n++;
    }

    return $n > 0 ? [$lat / $n, $lng / $n] : [0.0, 0.0];
}

/** Great-circle distance between two state centres, in km. */
function bulk_state_distance(array $states, string $a, string $b): float
{
    if (!isset($states[$a], $states[$b])) {
        return PHP_FLOAT_MAX;
    }

    [$lat1, $lng1] = bulk_state_centroid($states[$a]);
    [$lat2, $lng2] = bulk_state_centroid($states[$b]);

    return bulk_distance($lat1, $lng1, $lat2, $lng2);
}

/**
 * States whose centre lies within $maxKm of the origin state's centre, so
 * interstate routes only ever join regions that actually border or nearly
 * border one another.
 *
 * @return array<int, string>
 */
function bulk_nearby_states(array $states, string $originState, float $maxKm): array
{
    $nearby = [];

    foreach (array_keys($states) as $state) {
        if ($state === $originState) {
            continue;
        }

        if (bulk_state_distance($states, $originState, $state) <= $maxKm) {
            $nearby[] = $state;
        }
    }

    return $nearby;
}

/** The main state transport undertaking for a state, when one is listed. */
function bulk_preferred_operator(array $operators, string $state): ?string
{
    $list = $operators[$state] ?? [];

    if ($list === []) {
        return null;
    }

    $mains = [];

    foreach ($list as $name) {
        $lower = strtolower($name);

        if (str_contains($lower, 'metropolitan') || str_contains($lower, 'city transport')) {
            continue;
        }

        if (str_contains($lower, 'transport corporation')
            || str_contains($lower, 'state transport')
            || str_contains($lower, 'state roadways')
            || str_contains($lower, 'roadways')
        ) {
            $mains[] = $name;
        }
    }

    // Rotate between every main corporation in the state (KSRTC, NWKRTC,
    // KKRTC; WBTC, SBSTC, NBSTC) instead of always using the first one.
    if ($mains !== []) {
        return $mains[array_rand($mains)];
    }

    return $list[array_rand($list)];
}

/** Pick a believable service class for a route's shape. */
function bulk_service_for(bool $interstate, float $distance, bool $isCity): string
{
    if ($isCity) {
        return bulk_pick(['City Ordinary', 'City Express']);
    }

    if ($interstate) {
        $classes = ['Express', 'Super Express', 'Semi-Sleeper Express'];

        if ($distance > 200) {
            $classes = array_merge($classes, ['AC Volvo', 'AC Sleeper', 'Non-stop']);
        }

        return bulk_pick($classes);
    }

    // The longer the run, the more comfortable the class — an ordinary
    // stopping service makes no sense on a 400 km route.
    if ($distance < 60) {
        $classes = ['Ordinary', 'State Transport', 'City Express'];
    } elseif ($distance < 150) {
        $classes = ['Ordinary', 'State Transport', 'Express'];
    } elseif ($distance < 300) {
        $classes = ['Express', 'Super Express', 'Semi-Sleeper Express', 'State Transport'];
    } else {
        $classes = ['Super Express', 'Semi-Sleeper Express', 'AC Volvo', 'AC Sleeper', 'Non-stop'];
    }

    return bulk_pick($classes);
}

/* ------------------------------------------------------------------
 | Service profile
 ------------------------------------------------------------------ */

/** Service types with their fare rate (₹/km) and average speed (km/h). */
function bulk_service_types(): array
{
    return [
        'Ordinary'            => ['rate' => 0.85, 'speed' => 38],
        'State Transport'     => ['rate' => 0.95, 'speed' => 42],
        'Express'             => ['rate' => 1.05, 'speed' => 48],
        'Super Express'       => ['rate' => 1.20, 'speed' => 52],
        'Non-stop'            => ['rate' => 1.25, 'speed' => 55],
        'Semi-Sleeper Express' => ['rate' => 1.15, 'speed' => 50],
        'AC Volvo'            => ['rate' => 1.55, 'speed' => 55],
        'AC Sleeper'          => ['rate' => 1.70, 'speed' => 55],
        'City Ordinary'       => ['rate' => 0.70, 'speed' => 26],
        'City Express'        => ['rate' => 0.80, 'speed' => 30],
    ];
}

/* ------------------------------------------------------------------
 | Buses
 ------------------------------------------------------------------ */

/** Manufacturer and model choices per vehicle type. */
function bulk_vehicle_models(): array
{
    return [
        'seater'       => [['Tata Motors', 'Starbus Ultra 4/12'], ['Ashok Leyland', 'Viking 222'], ['Eicher', 'Skyline Pro 3011'], ['BharatBenz', '1017R']],
        'semi_sleeper' => [['Ashok Leyland', 'Lynx 412'], ['Tata Motors', 'LP 1618'], ['Eicher', 'Skyline Pro 5011']],
        'sleeper'      => [['Ashok Leyland', 'Falcon 222'], ['Tata Motors', 'LPO 1618'], ['Scania', 'Metrolink HD']],
        'ac_seater'    => [['Volvo', '9400 Intercity'], ['Scania', 'Interlink HD'], ['Tata Motors', 'Magnus EV'], ['Ashok Leyland', 'Cheetah 179']],
        'ac_sleeper'   => [['Volvo', '9600 Grand'], ['Scania', 'Metrolink HD'], ['Ashok Leyland', 'Falcon 222']],
        'mini'         => [['Force', 'Traveller 3350'], ['Mahindra', 'Cruzio'], ['Tata Motors', 'Starbus Urban 9/9']],
    ];
}

/** Fleet bus types and their typical seating capacities. */
function bulk_bus_types(): array
{
    return [
        'seater'       => [48, 52, 54],
        'semi_sleeper' => [40, 43, 45],
        'sleeper'      => [36, 38, 40],
        'ac_seater'    => [41, 45, 49],
        'ac_sleeper'   => [36, 38, 40],
        'mini'         => [20, 26, 32],
    ];
}

$states    = bulk_states();
$operators = bulk_operators();

if ($states === []) {
    fwrite(STDERR, "No located cities found. Import database/excel_bus_catalog.sql first.\n");
    exit(1);
}

/* ------------------------------------------------------------------
 | Generate routes
 ------------------------------------------------------------------ */

$serviceTypes = bulk_service_types();
$routeSql     = [];
$stopPlan     = [];   // route index => [ ['name'=>, 'order'=>, 'lat'=>, 'lng'=>, 'offset'=>], ... ]
$routes       = [];   // rows ready for insert

$stateCodes = [];
foreach ($states as $state => $meta) {
    $stateCodes[$state] = $meta['code'];
}

for ($i = 0; $i < $options['routes']; $i++) {
    $seq          = $i + 1;
    $originState  = bulk_weighted_state($states);
    $originCities = array_keys($states[$originState]['cities']);
    $originCity   = $originCities[array_rand($originCities)];
    $origin       = $states[$originState]['cities'][$originCity];

    $destState = $originState;
    $destCity  = null;
    $distance  = null;

    // ~26% of routes cross a state border, but only into a neighbouring
    // region and never absurdly far — no Kavaratti to Itanagar services.
    if (mt_rand(1, 100) <= 26 && count($states) > 1) {
        $nearby = bulk_nearby_states($states, $originState, 950.0);

        for ($try = 0; $try < 12 && $nearby !== []; $try++) {
            $candidate = bulk_pick($nearby, static fn ($state) => 1.0 / (1.0 + bulk_state_distance($states, $originState, $state)));
            $cities    = array_keys($states[$candidate]['cities']);
            $city      = $cities[array_rand($cities)];
            $coords    = $states[$candidate]['cities'][$city];
            $km        = bulk_distance($origin['lat'], $origin['lng'], $coords['lat'], $coords['lng']);

            if ($km <= 1200) {
                $destState = $candidate;
                $destCity  = $city;
                $distance  = $km;
                break;
            }
        }
    }

    if ($destCity === null) {
        $sameCities = array_values(array_diff($originCities, [$originCity]));
        shuffle($sameCities);

        foreach ($sameCities as $city) {
            $coords = $states[$originState]['cities'][$city];
            $km     = bulk_distance($origin['lat'], $origin['lng'], $coords['lat'], $coords['lng']);

            // Prefer a plausible intrastate hop; the last candidate is accepted.
            if ($km <= 650 || $city === end($sameCities)) {
                $destCity = $city;
                $distance = $km;
                break;
            }
        }

        if ($destCity === null) {
            // A one-city state — cross into a neighbour instead.
            $nearby = bulk_nearby_states($states, $originState, 1200.0);

            if ($nearby === []) {
                continue;
            }

            $destState  = $nearby[array_rand($nearby)];
            $destCities = array_keys($states[$destState]['cities']);
            $destCity   = $destCities[array_rand($destCities)];
            $coords     = $states[$destState]['cities'][$destCity];
            $distance   = bulk_distance($origin['lat'], $origin['lng'], $coords['lat'], $coords['lng']);
        }
    }

    $interstate = $destState !== $originState;
    $distance   = round((float) $distance, 1);
    $isCity     = !$interstate && $distance <= 35;

    $service    = bulk_service_for($interstate, $distance, $isCity);
    $profile    = $serviceTypes[$service];
    $duration   = max(20, (int) round($distance / $profile['speed'] * 60));
    $fare       = max(30.0, round($distance * $profile['rate'] / 5) * 5);

    $routeType  = $isCity ? 'City' : ($interstate ? 'Interstate' : 'Intrastate');
    $originCode = $stateCodes[$originState];

    $routeCode  = sprintf('GEN-%s-%04d', $originCode, $seq);

    // Attribute the route to the origin state's main corporation; only an
    // interstate route may fall back to the destination state's operator.
    $operator = bulk_preferred_operator($operators, $originState)
        ?? ($interstate ? bulk_preferred_operator($operators, $destState) : null)
        ?? 'Fleetra Intercity Express';

    $routes[] = [
        'route_code'              => $routeCode,
        'route_name'              => $originCity . ' - ' . $destCity,
        'source'                  => $originCity,
        'destination'             => $destCity,
        'distance'                => $distance,
        'estimated_duration'      => $duration,
        'base_fare'               => $fare,
        'status'                  => 'active',
        'operator_name'           => $operator,
        'route_type'              => $routeType,
        'service_type'            => $service,
        'origin_terminal_ref'     => $a['ref'] !== null ? 'LOC-' . $a['ref'] : null,
        'destination_terminal_ref' => $b['ref'] !== null ? 'LOC-' . $b['ref'] : null,
        'origin_city'             => $originCity,
        'destination_city'        => $destCity,
        'external_ref'            => $routeCode,
        'data_source'             => 'excel',
    ];

    // Stops: origin, a couple of intermediate cities, then destination.
    $stopCities = [$originCity];
    if (!$isCity) {
        $pool = array_values(array_diff(array_keys($states[$originState]['cities']), [$originCity, $destCity]));
        shuffle($pool);
        $extra = min(count($pool), $distance > 180 ? 3 : 1);
        for ($s = 0; $s < $extra; $s++) {
            $stopCities[] = $pool[$s];
        }
    }
    $stopCities[] = $destCity;

    $stopPlan[$i] = [];
    $count        = count($stopCities);
    foreach ($stopCities as $index => $city) {
        $coords = $states[$originState]['cities'][$city] ?? null;
        if ($coords === null) {
            foreach ($states as $meta) {
                if (isset($meta['cities'][$city])) {
                    $coords = $meta['cities'][$city];
                    break;
                }
            }
        }

        $stopPlan[$i][] = [
            'name'   => $city . ($index === 0 ? ' Central Bus Stand' : ($index === $count - 1 ? ' Bus Stand' : ' Bypass')),
            'order'  => $index + 1,
            'lat'    => $coords['lat'] ?? null,
            'lng'    => $coords['lng'] ?? null,
            'offset' => $index === 0 ? 0 : (int) round($duration * $index / max(1, $count - 1)),
        ];
    }
}

/* ------------------------------------------------------------------
 | Generate buses
 ------------------------------------------------------------------ */

$busTypes  = bulk_bus_types();
$models    = bulk_vehicle_models();
$buses     = [];
$usedRegs  = [];
$rtoLetter = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M', 'N', 'P', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

for ($i = 0; $i < $options['buses']; $i++) {
    $seq      = $i + 1;
    $state    = bulk_weighted_state($states);
    $code     = $stateCodes[$state];
    $busType  = bulk_pick(array_keys($busTypes));
    $capacity = bulk_pick($busTypes[$busType]);
    $vehicle  = bulk_pick($models[$busType]);

    $fuel = 'diesel';
    if (in_array($vehicle[0], ['Olectra', 'JBM'], true) || ($busType === 'ac_seater' && mt_rand(1, 100) <= 20)) {
        $fuel = 'electric';
    } elseif ($busType === 'mini' && mt_rand(1, 100) <= 35) {
        $fuel = 'cng';
    } elseif (mt_rand(1, 100) <= 7) {
        $fuel = 'hybrid';
    }

    $year = mt_rand(2016, 2024);
    $age  = max(0, (int) date('Y') - $year);

    do {
        $registration = sprintf(
            '%s-%02d-%s%s-%04d',
            $code,
            mt_rand(1, 49),
            $rtoLetter[array_rand($rtoLetter)],
            $rtoLetter[array_rand($rtoLetter)],
            mt_rand(1000, 9999)
        );
    } while (isset($usedRegs[$registration]));

    $usedRegs[$registration] = true;

    $statusRoll = mt_rand(1, 100);
    $status     = $statusRoll <= 86 ? 'active' : ($statusRoll <= 96 ? 'maintenance' : 'inactive');

    $buses[] = [
        'bus_number'         => sprintf('FLT-%d', 2000 + $seq),
        'registration_number' => $registration,
        'manufacturer'       => $vehicle[0],
        'model'              => $vehicle[1],
        'manufacturing_year' => $year,
        'bus_type'           => $busType,
        'capacity'           => $capacity,
        'fuel_type'          => $fuel,
        'current_mileage'    => round($age * mt_rand(55000, 95000) + mt_rand(5000, 40000), 2),
        'status'             => $status,
    ];
}

/* ------------------------------------------------------------------
 | Generate drivers (users + profiles)
 ------------------------------------------------------------------ */

$firstNames = ['Ramesh', 'Suresh', 'Mahesh', 'Rajesh', 'Vijay', 'Ajay', 'Sanjay', 'Manoj', 'Anil', 'Sunil', 'Rakesh', 'Deepak', 'Prakash', 'Vinod', 'Ashok', 'Mohan', 'Gopal', 'Ravi', 'Kiran', 'Arun', 'Karthik', 'Senthil', 'Murugan', 'Balaji', 'Prasad', 'Naveen', 'Harish', 'Girish', 'Yogesh', 'Imran', 'Salim', 'Farhan', 'Rahul', 'Amit', 'Vikas', 'Santosh', 'Mukesh', 'Dinesh', 'Naresh', 'Umesh', 'Subrata', 'Tapan', 'Bikash', 'Sourav', 'Pankaj', 'Ranjit', 'Jagdish', 'Kailash', 'Om', 'Shyam'];
$lastNames  = ['Kumar', 'Singh', 'Sharma', 'Yadav', 'Verma', 'Gupta', 'Patel', 'Reddy', 'Rao', 'Naidu', 'Nair', 'Menon', 'Pillai', 'Das', 'Roy', 'Bose', 'Ghosh', 'Mishra', 'Tiwari', 'Pandey', 'Joshi', 'Desai', 'Patil', 'Jadhav', 'More', 'Shinde', 'Kaur', 'Gill', 'Khan', 'Sheikh', 'Ansari', 'Mehta', 'Shah', 'Chauhan', 'Rathore', 'Sahu', 'Behera', 'Mahato', 'Barman', 'Deb'];

$drivers = [];
$driverUsers = [];

for ($i = 0; $i < $options['drivers']; $i++) {
    $seq  = $i + 1;
    $name = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];

    $driverUsers[] = [
        'name'  => $name,
        'email' => sprintf('driver%04d@fleetra.com', 2000 + $seq),
        'phone' => sprintf('+91 9%09d', mt_rand(100000000, 999999999)),
        'role'  => 'driver',
    ];

    $drivers[] = [
        'employee_id'       => sprintf('DRV-%04d', 2000 + $seq),
        'license_number'    => sprintf('DL-%02d%04d%06d', mt_rand(1, 99), mt_rand(1, 9999), mt_rand(1, 999999)),
        'license_expiry'    => date('Y-m-d', strtotime('+' . mt_rand(120, 1500) . ' days')),
        'date_of_birth'     => date('Y-m-d', strtotime('-' . mt_rand(28, 55) . ' years')),
        'experience_years'  => mt_rand(2, 25),
        'employment_status' => 'active',
        'joining_date'      => date('Y-m-d', strtotime('-' . mt_rand(60, 3000) . ' days')),
    ];
}

/* ------------------------------------------------------------------
 | Report / dry run
 ------------------------------------------------------------------ */

$stopTotal = array_sum(array_map('count', $stopPlan));

printf(
    "Generating: %d routes, %d stops, %d buses, %d drivers (seed %d)%s\n",
    count($routes),
    $stopTotal,
    count($buses),
    count($drivers),
    $options['seed'],
    $options['dry_run'] ? ' [dry run]' : ''
);

if ($options['dry_run']) {
    foreach (array_slice($routes, 0, 8) as $route) {
        printf(
            "  %-16s %-34s %-40s %7.1f km  %4d min  %s%s\n",
            $route['route_code'],
            $route['route_name'],
            $route['operator_name'],
            $route['distance'],
            $route['estimated_duration'],
            $route['route_type'],
            $route['service_type'] !== null ? ' · ' . $route['service_type'] : ''
        );
    }

    exit(0);
}

/* ------------------------------------------------------------------
 | Persist
 ------------------------------------------------------------------ */

/** Quote a value for an inline SQL dump. */
function bulk_sql(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

$dump = [];
$dump[] = '-- Fleetra generated bulk catalogue';
$dump[] = '-- Created ' . date('Y-m-d H:i:s') . ' by tools/generate_bulk_data.php';
$dump[] = '-- Idempotent: re-importing only adds rows that are still missing.';
$dump[] = 'START TRANSACTION;';
$dump[] = '';

$insertedRoutes = 0;
$insertedStops  = 0;
$insertedBuses  = 0;
$insertedUsers  = 0;
$insertedDrivers = 0;

$pdo->beginTransaction();

try {
    /* Routes + stops -------------------------------------------------- */
    $routeStmt = $pdo->prepare(
        'INSERT IGNORE INTO routes
            (route_code, route_name, source, destination, distance, estimated_duration, base_fare,
             status, operator_name, route_type, service_type, origin_terminal_ref,
             destination_terminal_ref, origin_city, destination_city, external_ref, data_source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stopStmt = $pdo->prepare(
        'INSERT IGNORE INTO stops (route_id, stop_name, stop_order, latitude, longitude, arrival_offset)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($routes as $index => $route) {
        $routeStmt->execute([
            $route['route_code'], $route['route_name'], $route['source'], $route['destination'],
            $route['distance'], $route['estimated_duration'], $route['base_fare'], $route['status'],
            $route['operator_name'], $route['route_type'], $route['service_type'],
            $route['origin_terminal_ref'], $route['destination_terminal_ref'],
            $route['origin_city'], $route['destination_city'], $route['external_ref'], $route['data_source'],
        ]);

        if ($routeStmt->rowCount() === 0) {
            continue; // Already present.
        }

        $insertedRoutes++;
        $routeId = (int) $pdo->lastInsertId();

        foreach ($stopPlan[$index] as $stop) {
            $stopStmt->execute([
                $routeId, $stop['name'], $stop['order'], $stop['lat'], $stop['lng'], $stop['offset'],
            ]);
            $insertedStops++;
        }

        $dump[] = 'INSERT IGNORE INTO routes (route_code, route_name, source, destination, distance, estimated_duration, base_fare, status, operator_name, route_type, service_type, origin_terminal_ref, destination_terminal_ref, origin_city, destination_city, external_ref, data_source) VALUES ('
            . implode(', ', array_map('bulk_sql', array_values($route))) . ');';

        foreach ($stopPlan[$index] as $stop) {
            $dump[] = 'INSERT IGNORE INTO stops (route_id, stop_name, stop_order, latitude, longitude, arrival_offset) SELECT id, '
                . bulk_sql($stop['name']) . ', ' . (int) $stop['order'] . ', '
                . bulk_sql($stop['lat']) . ', ' . bulk_sql($stop['lng']) . ', ' . (int) $stop['offset']
                . ' FROM routes WHERE route_code = ' . bulk_sql($route['route_code']) . ';';
        }
    }

    /* Buses ----------------------------------------------------------- */
    $busStmt = $pdo->prepare(
        'INSERT IGNORE INTO buses
            (bus_number, registration_number, manufacturer, model, manufacturing_year, bus_type,
             capacity, fuel_type, current_mileage, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($buses as $bus) {
        $busStmt->execute([
            $bus['bus_number'], $bus['registration_number'], $bus['manufacturer'], $bus['model'],
            $bus['manufacturing_year'], $bus['bus_type'], $bus['capacity'], $bus['fuel_type'],
            $bus['current_mileage'], $bus['status'],
        ]);

        if ($busStmt->rowCount() === 0) {
            continue;
        }

        $insertedBuses++;

        $dump[] = 'INSERT IGNORE INTO buses (bus_number, registration_number, manufacturer, model, manufacturing_year, bus_type, capacity, fuel_type, current_mileage, status) VALUES ('
            . implode(', ', array_map('bulk_sql', array_values($bus))) . ');';
    }

    /* Drivers: user account then profile ------------------------------ */
    $userStmt = $pdo->prepare(
        'INSERT IGNORE INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, ?, "active")'
    );
    $driverStmt = $pdo->prepare(
        'INSERT IGNORE INTO drivers
            (user_id, employee_id, license_number, license_expiry, date_of_birth, experience_years, employment_status, joining_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    // Every generated driver shares the demo password hash.
    $passwordHash = (string) db_value("SELECT password FROM users WHERE email = 'driver1@fleetra.com' LIMIT 1", [], '');
    if ($passwordHash === '') {
        $passwordHash = password_hash('Fleetra@123', PASSWORD_BCRYPT);
    }

    foreach ($driverUsers as $index => $user) {
        $userStmt->execute([$user['name'], $user['email'], $user['phone'], $passwordHash, $user['role']]);

        if ($userStmt->rowCount() === 0) {
            continue;
        }

        $insertedUsers++;
        $userId = (int) $pdo->lastInsertId();
        $driver = $drivers[$index];

        $driverStmt->execute([
            $userId, $driver['employee_id'], $driver['license_number'], $driver['license_expiry'],
            $driver['date_of_birth'], $driver['experience_years'], $driver['employment_status'],
            $driver['joining_date'],
        ]);

        if ($driverStmt->rowCount() > 0) {
            $insertedDrivers++;
        }

        $dump[] = 'INSERT IGNORE INTO users (name, email, phone, password, role, status) VALUES ('
            . bulk_sql($user['name']) . ', ' . bulk_sql($user['email']) . ', ' . bulk_sql($user['phone'])
            . ', ' . bulk_sql($passwordHash) . ', ' . bulk_sql($user['role']) . ', "active");';
        $dump[] = 'INSERT IGNORE INTO drivers (user_id, employee_id, license_number, license_expiry, date_of_birth, experience_years, employment_status, joining_date) SELECT id, '
            . implode(', ', array_map('bulk_sql', array_values($driver)))
            . ' FROM users WHERE email = ' . bulk_sql($user['email']) . ';';
    }

    db_insert('data_import_runs', [
        'source_file'     => 'generator:bulk_catalog',
        'source_label'    => 'bulk generator',
        'terminals_found' => 0,
        'cities_found'    => 0,
        'operators_found' => array_sum(array_map('count', $operators)),
        'routes_found'    => $insertedRoutes,
        'stops_found'     => $insertedStops,
        'summary'         => sprintf('%d routes, %d buses, %d drivers generated from state reference data', $insertedRoutes, $insertedBuses, $insertedDrivers),
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Generation failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$dump[] = '';
$dump[] = 'COMMIT;';
$dump[] = '';

if (@file_put_contents($options['sql'], implode("\n", $dump)) === false) {
    fwrite(STDERR, 'Warning: could not write SQL dump to ' . $options['sql'] . "\n");
}

printf(
    "Inserted: %d routes, %d stops, %d buses, %d driver accounts, %d driver profiles.\n",
    $insertedRoutes,
    $insertedStops,
    $insertedBuses,
    $insertedUsers,
    $insertedDrivers
);
printf("SQL dump written to %s\n", $options['sql']);
echo "Next: php tools/generate_schedules.php --days=7\n";
