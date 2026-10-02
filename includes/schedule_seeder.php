<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/schedule_seeder.php
 *
 * The Excel catalogue imports terminals, cities and routes, but a route
 * on its own is not a departure — nothing can be booked until a dated
 * schedule exists. That is why the passenger search used to fall back to
 * demo services for every imported route and no seat could ever be sold.
 *
 * This seeder closes that gap. For the imported routes a passenger is
 * actually looking at, it:
 *
 *   1. Backfills the metrics the workbook left at zero — distance,
 *      journey time and base fare — using the same deterministic
 *      estimator the demo generator uses, so real and demo prices agree.
 *   2. Backfills stop arrival offsets when a route's stops are all flat,
 *      otherwise the booking form cannot tell one stop from the next.
 *   3. Writes real, dated departures for the next few days, assigning a
 *      bus and a driver to a free (bus, date, time) / (driver, date,
 *      time) slot so the database unique keys are never violated.
 *
 * Everything is idempotent: a route that already has an upcoming
 * departure is left completely alone, and running the seeder again is a
 * no-op. If it cannot run (no buses, no drivers, un-migrated schema) it
 * fails quietly and the page still renders demo services as before.
 *
 * Loading order: functions.php -> operations.php -> bus_catalog.php -> here
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/bus_catalog.php';

/** How many imported routes one seeding run will schedule at most. */
const FLEETRA_SCHEDULE_SEED_MAX_ROUTES = 40;

/** A safety ceiling on new departures created in a single request. */
const FLEETRA_SCHEDULE_SEED_MAX_DEPARTURES = 300;

/**
 * Make sure the imported routes in view have real, bookable departures.
 *
 * Only routes that match the supplied search are considered, so a page
 * never tries to schedule the whole catalogue at once.
 *
 * @param string|null $from     Boarding city/terminal typed by the passenger.
 * @param string|null $to       Destination city/terminal typed by the passenger.
 * @param string|null $terminal Current-terminal board filter.
 * @param string|null $date     First day to schedule (defaults to today).
 * @param int         $days     How many consecutive days to seed.
 * @return int Number of departures created.
 */
function fleetra_ensure_route_schedules(
    ?string $from = null,
    ?string $to = null,
    ?string $terminal = null,
    ?string $date = null,
    int $days = 5
): int {
    $from     = trim((string) $from);
    $to       = trim((string) $to);
    $terminal = trim((string) $terminal);

    // Nothing to match on — do not touch the database.
    if ($from === '' && $to === '' && $terminal === '') {
        return 0;
    }

    if (!bus_catalog_table_exists('schedules') || !bus_catalog_table_exists('stops')) {
        return 0;
    }

    if (!bus_catalog_routes_have_catalog_columns()) {
        // The imported catalogue is not present; there is nothing to seed.
        return 0;
    }

    // Never seed departures in the past; a requested past date still gets a
    // bookable window starting today.
    $startDate = is_valid_date($date) ? (string) $date : date('Y-m-d');
    $startDate = max($startDate, date('Y-m-d'));
    $days      = max(1, min(7, $days));

    try {
        $routes = fleetra_schedule_candidate_routes($from, $to, $terminal, $startDate);
    } catch (Throwable $exception) {
        fleetra_log('Auto-schedule lookup failed: ' . $exception->getMessage(), 'WARNING');

        return 0;
    }

    return fleetra_seed_candidate_routes($routes, $startDate, $days, true);
}

/**
 * Schedule every imported route that still has no upcoming departure.
 *
 * Used by the `tools/generate_schedules.php` CLI to pre-build a full
 * bookable timetable instead of letting the first search request do it.
 *
 * @return int Number of departures created.
 */
function fleetra_seed_all_route_schedules(?string $date = null, int $days = 7): int
{
    if (!bus_catalog_table_exists('schedules') || !bus_catalog_table_exists('stops')) {
        return 0;
    }

    if (!bus_catalog_routes_have_catalog_columns()) {
        return 0;
    }

    $startDate = is_valid_date($date) ? (string) $date : date('Y-m-d');
    $days      = max(1, min(14, $days));

    try {
        $routes = fleetra_schedule_candidate_routes('', '', '', $startDate, PHP_INT_MAX);
    } catch (Throwable $exception) {
        fleetra_log('Schedule catalogue lookup failed: ' . $exception->getMessage(), 'WARNING');

        return 0;
    }

    return fleetra_seed_candidate_routes($routes, $startDate, $days, false);
}

/**
 * Write departures for a list of routes inside one transaction.
 *
 * @param array<int, array<string, mixed>> $routes
 * @return int Number of departures created.
 */
function fleetra_seed_candidate_routes(array $routes, string $startDate, int $days, bool $capTotal): int
{
    if ($routes === []) {
        return 0;
    }

    $buses   = fleetra_schedule_bus_pool();
    $drivers = fleetra_schedule_driver_pool();

    if ($buses === [] || $drivers === []) {
        return 0;
    }

    $ceiling = $capTotal ? FLEETRA_SCHEDULE_SEED_MAX_DEPARTURES : PHP_INT_MAX;
    $created = 0;
    $pdo     = db();

    try {
        $pdo->beginTransaction();

        foreach ($routes as $route) {
            $created += fleetra_seed_route_departures($route, $buses, $drivers, $startDate, $days);

            if ($created >= $ceiling) {
                break;
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fleetra_log('Auto-schedule failed: ' . $exception->getMessage(), 'WARNING');

        return 0;
    }

    if ($created > 0) {
        fleetra_log('Auto-scheduled ' . $created . ' bookable departure(s) for imported routes.', 'INFO');
    }

    return $created;
}

/* ------------------------------------------------------------------
 | Candidate routes
 ------------------------------------------------------------------ */

/**
 * Imported routes that match the search and have no upcoming departure.
 *
 * @return array<int, array<string, mixed>>
 */
function fleetra_schedule_candidate_routes(
    string $from,
    string $to,
    string $terminal,
    string $startDate,
    int $maxRoutes = FLEETRA_SCHEDULE_SEED_MAX_ROUTES
): array {
    $fromToken     = $from !== '' ? bus_catalog_place_token($from) : '';
    $toToken       = $to !== '' ? bus_catalog_place_token($to) : '';
    $terminalToken = $terminal !== '' ? bus_catalog_place_token($terminal) : '';

    // Routes that already have a live departure from the start date on.
    $scheduled = [];
    foreach (db_all(
        'SELECT DISTINCT route_id FROM schedules WHERE schedule_date >= ? AND status <> "cancelled"',
        [$startDate]
    ) as $row) {
        $scheduled[(int) $row['route_id']] = true;
    }

    // Routes without at least two stops cannot be booked end to end.
    $stopCounts = [];
    foreach (db_all('SELECT route_id, COUNT(*) AS stop_count FROM stops GROUP BY route_id') as $row) {
        $stopCounts[(int) $row['route_id']] = (int) $row['stop_count'];
    }

    $candidates = [];

    foreach (bus_catalog_known_routes() as $route) {
        if ((string) ($route['data_source'] ?? 'manual') !== 'excel') {
            continue;
        }

        $routeId = (int) $route['id'];

        if (isset($scheduled[$routeId]) || ($stopCounts[$routeId] ?? 0) < 2) {
            continue;
        }

        $origin = (string) ($route['origin_city'] ?? $route['source']);
        $dest   = (string) ($route['destination_city'] ?? $route['destination']);
        $name   = (string) ($route['route_name'] ?? '');
        $source = (string) ($route['source'] ?? '');
        $target = (string) ($route['destination'] ?? '');

        if ($terminalToken !== '' && !bus_catalog_matches($terminalToken, [$origin, $source, $name])) {
            continue;
        }

        if ($fromToken !== '' && !bus_catalog_matches($fromToken, [$origin, $source, $name])) {
            continue;
        }

        if ($toToken !== '' && !bus_catalog_matches($toToken, [$dest, $target, $name])) {
            continue;
        }

        $route['origin_label']      = $origin;
        $route['destination_label'] = $dest;

        $candidates[] = $route;

        if (count($candidates) >= $maxRoutes) {
            break;
        }
    }

    return $candidates;
}

/* ------------------------------------------------------------------
 | Resources
 ------------------------------------------------------------------ */

/**
 * Buses that may be put into service.
 *
 * @return array<int, int>
 */
function fleetra_schedule_bus_pool(): array
{
    $ids = [];

    foreach (db_all('SELECT id FROM buses WHERE status = "active" ORDER BY id') as $row) {
        $ids[] = (int) $row['id'];
    }

    return $ids;
}

/**
 * Drivers who may be assigned to a departure.
 *
 * @return array<int, int>
 */
function fleetra_schedule_driver_pool(): array
{
    $ids = [];

    foreach (db_all('SELECT id FROM drivers WHERE employment_status = "active" ORDER BY id') as $row) {
        $ids[] = (int) $row['id'];
    }

    return $ids;
}

/* ------------------------------------------------------------------
 | Seeding one route
 ------------------------------------------------------------------ */

/**
 * Create the next few days of departures for one route.
 *
 * @param array<string, mixed> $route
 * @param array<int, int>      $buses
 * @param array<int, int>      $drivers
 * @return int Departures created.
 */
function fleetra_seed_route_departures(array $route, array $buses, array $drivers, string $startDate, int $days): int
{
    $routeId = (int) $route['id'];

    $metrics  = fleetra_backfill_route_metrics($route);
    fleetra_backfill_stop_offsets($routeId, $metrics['duration']);

    $slotCount = count(fleetra_departure_slots());
    $startSlot = bus_catalog_seed('route-slot|' . (string) $route['route_code']) % $slotCount;

    $created = 0;

    for ($offset = 0; $offset < $days; $offset++) {
        $date = date('Y-m-d', strtotime($startDate . ' +' . $offset . ' day'));

        // Load what is already taken on this day (cached per request).
        fleetra_schedule_occupancy($date);

        $slot = fleetra_find_free_slot($date, $startSlot, $buses, $drivers);

        if ($slot === null) {
            break; // Every slot for the day is used; try again next day.
        }

        $departure = $slot['time'] . ':00';
        $arrival   = minutes_to_time(time_to_minutes($departure) + $metrics['duration']);

        db_insert('schedules', [
            'route_id'       => $routeId,
            'bus_id'         => $slot['bus'],
            'driver_id'      => $slot['driver'],
            'schedule_date'  => $date,
            'departure_time' => $departure,
            'arrival_time'   => $arrival,
            'status'         => 'scheduled',
        ]);

        fleetra_mark_slot($date, $slot['bus'], $slot['driver'], $slot['time']);

        $created++;
    }

    return $created;
}

/**
 * Give an imported route realistic distance, duration and fare when the
 * workbook supplied none, and persist the change.
 *
 * @param array<string, mixed> $route
 * @return array{distance:float, duration:int, fare:float}
 */
function fleetra_backfill_route_metrics(array $route): array
{
    $origin = (string) ($route['origin_label'] ?? $route['origin_city'] ?? $route['source'] ?? '');
    $dest   = (string) ($route['destination_label'] ?? $route['destination_city'] ?? $route['destination'] ?? '');

    $distance = (float) ($route['distance'] ?? 0);
    $duration = (int) ($route['estimated_duration'] ?? 0);
    $fare     = (float) ($route['base_fare'] ?? 0);

    if ($distance <= 0) {
        $distance = bus_catalog_estimate_distance($origin, $dest, null);
    }

    if ($duration <= 0) {
        $duration = bus_catalog_estimate_duration($distance);
    }

    if ($fare <= 0) {
        $fare = bus_catalog_estimate_fare($distance);
    }

    if ((float) ($route['distance'] ?? 0) <= 0
        || (int) ($route['estimated_duration'] ?? 0) <= 0
        || (float) ($route['base_fare'] ?? 0) <= 0
    ) {
        db_update('routes', [
            'distance'           => $distance,
            'estimated_duration' => $duration,
            'base_fare'          => $fare,
        ], ['id' => (int) $route['id']]);
    }

    return ['distance' => $distance, 'duration' => $duration, 'fare' => $fare];
}

/**
 * Spread a route's stop offsets across its journey time when they are all
 * zero. Without this, every booking would look like a zero-minute hop and
 * the seat picker could not order the stops.
 */
function fleetra_backfill_stop_offsets(int $routeId, int $durationMinutes): void
{
    $stops = db_all(
        'SELECT id, stop_order, arrival_offset FROM stops WHERE route_id = ? ORDER BY stop_order, id',
        [$routeId]
    );

    if (count($stops) < 2) {
        return;
    }

    $highest = 0;
    foreach ($stops as $stop) {
        $highest = max($highest, (int) $stop['arrival_offset']);
    }

    if ($highest > 0) {
        return;
    }

    $lastIndex = count($stops) - 1;

    foreach ($stops as $index => $stop) {
        if ($index === 0) {
            continue; // The origin keeps its zero offset.
        }

        $offset = (int) round($durationMinutes * $index / $lastIndex);

        db_update('stops', ['arrival_offset' => $offset], ['id' => (int) $stop['id']]);
    }
}

/* ------------------------------------------------------------------
 | Departure slots
 ------------------------------------------------------------------ */

/**
 * The half-hour departure slots an auto-scheduled service may use.
 *
 * @return array<int, string> Sorted HH:MM values.
 */
function fleetra_departure_slots(): array
{
    static $slots = null;

    if ($slots !== null) {
        return $slots;
    }

    $slots = [];

    for ($minutes = 5 * 60; $minutes <= 22 * 60 + 30; $minutes += 30) {
        $slots[] = substr(minutes_to_time($minutes), 0, 5);
    }

    return $slots;
}

/**
 * A free (bus, driver) slot on a given day, or null when the day is full.
 *
 * @param array<int, int> $buses
 * @param array<int, int> $drivers
 * @return array{slot:int, time:string, bus:int, driver:int}|null
 */
function fleetra_find_free_slot(string $date, int $startSlot, array $buses, array $drivers): ?array
{
    $slots     = fleetra_departure_slots();
    $slotCount = count($slots);
    $busCount  = count($buses);
    $driverCount = count($drivers);

    $occupancy = &fleetra_schedule_occupancy_ref();
    $day       = $occupancy[$date] ?? ['bus' => [], 'driver' => []];

    for ($attempt = 0; $attempt < $slotCount; $attempt++) {
        $slotIndex = ($startSlot + $attempt) % $slotCount;
        $time      = $slots[$slotIndex];

        for ($b = 0; $b < $busCount; $b++) {
            $busId = $buses[$b];

            if (isset($day['bus'][$busId][$time])) {
                continue;
            }

            for ($d = 0; $d < $driverCount; $d++) {
                $driverId = $drivers[$d];

                if (isset($day['driver'][$driverId][$time])) {
                    continue;
                }

                return ['slot' => $slotIndex, 'time' => $time, 'bus' => $busId, 'driver' => $driverId];
            }
        }
    }

    return null;
}

/**
 * The per-day map of taken (bus, time) and (driver, time) slots, loaded
 * lazily and shared for the whole request.
 *
 * @return array<string, array{bus:array<int, array<string, bool>>, driver:array<int, array<string, bool>>}>
 */
function &fleetra_schedule_occupancy_ref(): array
{
    static $occupancy = [];

    return $occupancy;
}

/**
 * Load and cache one day's taken slots.
 *
 * @return array{bus:array<int, array<string, bool>>, driver:array<int, array<string, bool>>}
 */
function fleetra_schedule_occupancy(string $date): array
{
    $occupancy = &fleetra_schedule_occupancy_ref();

    if (isset($occupancy[$date])) {
        return $occupancy[$date];
    }

    $occupancy[$date] = ['bus' => [], 'driver' => []];

    $rows = db_all(
        'SELECT bus_id, driver_id, TIME_FORMAT(departure_time, "%H:%i") AS slot
           FROM schedules
          WHERE schedule_date = ?',
        [$date]
    );

    foreach ($rows as $row) {
        $slot = (string) $row['slot'];

        $occupancy[$date]['bus'][(int) $row['bus_id']][$slot]       = true;
        $occupancy[$date]['driver'][(int) $row['driver_id']][$slot] = true;
    }

    return $occupancy[$date];
}

/** Record a slot we just filled so later routes in the same request see it. */
function fleetra_mark_slot(string $date, int $busId, int $driverId, string $time): void
{
    $occupancy = &fleetra_schedule_occupancy_ref();

    $occupancy[$date]['bus'][$busId][$time]       = true;
    $occupancy[$date]['driver'][$driverId][$time] = true;
}
