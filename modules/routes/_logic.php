<?php
/**
 * Fleetra — Route & stop module logic
 * ------------------------------------------------------------------
 * modules/routes/_logic.php
 *
 * A route has an ordered list of stops. stop_order carries a UNIQUE
 * (route_id, stop_order) constraint, so every reorder is written in two
 * passes through a temporary range to avoid transient collisions.
 */

declare(strict_types=1);

/** Temporary offset used while reshuffling stop_order values. */
const STOP_ORDER_TEMP_BASE = 60000;

/* ------------------------------------------------------------------
 | Route form
 ------------------------------------------------------------------ */

/**
 * @param array<string, mixed>|null $route
 * @return array<string, string>
 */
function route_form_values(?array $route = null): array
{
    $defaults = [
        'route_code'         => '',
        'route_name'         => '',
        'source'             => '',
        'destination'        => '',
        'distance'           => '',
        'estimated_duration' => '',
        'base_fare'          => '',
        'status'             => 'active',
    ];

    if ($route === null) {
        return $defaults;
    }

    foreach ($defaults as $field => $default) {
        if (array_key_exists($field, $route) && $route[$field] !== null) {
            $defaults[$field] = (string) $route[$field];
        }
    }

    return $defaults;
}

/**
 * @param array<string, mixed> $post
 * @return array{errors:array<string,string>, values:array<string,string>}
 */
function validate_route_request(array $post, ?int $routeId = null): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'route_code'         => strtoupper($trim('route_code')),
        'route_name'         => $trim('route_name'),
        'source'             => $trim('source'),
        'destination'        => $trim('destination'),
        'distance'           => $trim('distance'),
        'estimated_duration' => $trim('estimated_duration'),
        'base_fare'          => $trim('base_fare'),
        'status'             => $trim('status'),
    ];

    if ($values['route_code'] === '') {
        $errors['route_code'] = 'Enter a route code.';
    } elseif (mb_strlen($values['route_code']) > 20) {
        $errors['route_code'] = 'The route code cannot be longer than 20 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9\-\/]+$/', $values['route_code'])) {
        $errors['route_code'] = 'Use letters, numbers, hyphens or slashes only.';
    } elseif (db_exists('routes', 'route_code', $values['route_code'], $routeId)) {
        $errors['route_code'] = 'Another route already uses this code.';
    }

    if ($values['route_name'] === '') {
        $errors['route_name'] = 'Enter a route name.';
    } elseif (mb_strlen($values['route_name']) > 140) {
        $errors['route_name'] = 'The route name cannot be longer than 140 characters.';
    }

    if ($values['source'] === '') {
        $errors['source'] = 'Enter the starting point.';
    } elseif (mb_strlen($values['source']) > 120) {
        $errors['source'] = 'The starting point cannot be longer than 120 characters.';
    }

    if ($values['destination'] === '') {
        $errors['destination'] = 'Enter the destination.';
    } elseif (mb_strlen($values['destination']) > 120) {
        $errors['destination'] = 'The destination cannot be longer than 120 characters.';
    } elseif (strcasecmp($values['destination'], $values['source']) === 0) {
        $errors['destination'] = 'The destination must be different from the starting point.';
    }

    if ($values['distance'] === '') {
        $errors['distance'] = 'Enter the route distance in kilometres.';
    } elseif (!is_numeric($values['distance']) || (float) $values['distance'] <= 0) {
        $errors['distance'] = 'Enter the distance as a number greater than zero.';
    } elseif ((float) $values['distance'] > 5000) {
        $errors['distance'] = 'That distance looks unrealistic for a route.';
    }

    if ($values['estimated_duration'] === '') {
        $errors['estimated_duration'] = 'Enter the estimated duration in minutes.';
    } elseif (!ctype_digit($values['estimated_duration'])
        || (int) $values['estimated_duration'] < 5
        || (int) $values['estimated_duration'] > 2880
    ) {
        $errors['estimated_duration'] = 'Enter a duration between 5 and 2880 minutes.';
    }

    if ($values['base_fare'] === '') {
        $errors['base_fare'] = 'Enter the base fare.';
    } elseif (!is_numeric($values['base_fare']) || (float) $values['base_fare'] < 0) {
        $errors['base_fare'] = 'Enter a fare of zero or more.';
    } elseif ((float) $values['base_fare'] > 100000) {
        $errors['base_fare'] = 'That fare looks unrealistic.';
    }

    if (!is_valid_option(route_status_options(), $values['status'])) {
        $errors['status'] = 'Choose a valid status.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * @param array<string, string> $values
 * @return array<string, mixed>
 */
function route_db_payload(array $values): array
{
    return [
        'route_code'         => $values['route_code'],
        'route_name'         => $values['route_name'],
        'source'             => $values['source'],
        'destination'        => $values['destination'],
        'distance'           => (float) $values['distance'],
        'estimated_duration' => (int) $values['estimated_duration'],
        'base_fare'          => (float) $values['base_fare'],
        'status'             => $values['status'],
    ];
}

/* ------------------------------------------------------------------
 | Stops
 ------------------------------------------------------------------ */

/**
 * @param array<string, mixed> $post
 * @return array{errors:array<string,string>, values:array<string,string>}
 */
function validate_stop_request(array $post): array
{
    $errors = [];
    $trim   = static fn (string $key): string => trim((string) ($post[$key] ?? ''));

    $values = [
        'stop_name'      => $trim('stop_name'),
        'stop_order'     => $trim('stop_order'),
        'latitude'       => $trim('latitude'),
        'longitude'      => $trim('longitude'),
        'arrival_offset' => $trim('arrival_offset'),
    ];

    if ($values['stop_name'] === '') {
        $errors['stop_name'] = 'Enter the stop name.';
    } elseif (mb_strlen($values['stop_name']) > 140) {
        $errors['stop_name'] = 'The stop name cannot be longer than 140 characters.';
    }

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

    if ($values['arrival_offset'] !== '') {
        if (!ctype_digit($values['arrival_offset']) || (int) $values['arrival_offset'] > 2880) {
            $errors['arrival_offset'] = 'Enter the minutes from departure as a whole number up to 2880.';
        }
    }

    if ($values['stop_order'] !== '' && (!ctype_digit($values['stop_order']) || (int) $values['stop_order'] < 1)) {
        $errors['stop_order'] = 'Enter the stop position as a whole number of 1 or more.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/** Stops belonging to a route, in travel order. */
function route_stops(int $routeId): array
{
    return db_all(
        'SELECT * FROM stops WHERE route_id = ? ORDER BY stop_order, id',
        [$routeId]
    );
}

/** Next free position at the end of a route. */
function next_stop_order(int $routeId): int
{
    return (int) db_value('SELECT COALESCE(MAX(stop_order), 0) + 1 FROM stops WHERE route_id = ?', [$routeId], 1);
}

/**
 * Write an ordered list of stop ids back as 1..N.
 * Two passes (temp range, then final) keep the unique index satisfied.
 *
 * @param array<int, int> $orderedStopIds
 */
function write_stop_order(array $orderedStopIds): void
{
    if ($orderedStopIds === []) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach ($orderedStopIds as $index => $stopId) {
            db_execute('UPDATE stops SET stop_order = ? WHERE id = ?', [STOP_ORDER_TEMP_BASE + $index, $stopId]);
        }

        foreach ($orderedStopIds as $index => $stopId) {
            db_execute('UPDATE stops SET stop_order = ? WHERE id = ?', [$index + 1, $stopId]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fleetra_log('Stop reorder failed: ' . $exception->getMessage());
        fleetra_fatal('The stop order could not be saved. Please try again.');
    }
}

/** Close gaps in the stop order after a deletion. */
function resequence_stops(int $routeId): void
{
    write_stop_order(array_map('intval', array_column(route_stops($routeId), 'id')));
}

/** Move a stop to a specific position (1-based) within its route. */
function set_stop_order(int $routeId, int $stopId, int $position): void
{
    $ids = array_map('intval', array_column(route_stops($routeId), 'id'));

    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $stopId));

    $position = max(1, min($position, count($ids) + 1));

    array_splice($ids, $position - 1, 0, [$stopId]);

    write_stop_order($ids);
}

/** Move a stop one place up or down. */
function move_stop(int $routeId, int $stopId, string $direction): bool
{
    $stops = route_stops($routeId);
    $ids   = array_map('intval', array_column($stops, 'id'));
    $index = array_search($stopId, $ids, true);

    if ($index === false) {
        return false;
    }

    $target = $direction === 'up' ? $index - 1 : $index + 1;

    if ($target < 0 || $target >= count($ids)) {
        return false;
    }

    [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

    write_stop_order($ids);

    return true;
}
