<?php
/**
 * Fleetra — Location search API
 * ------------------------------------------------------------------
 * api/locations.php
 *
 * GET ?q=<text>&limit=<n>    → matching India-wide bus locations (JSON)
 * GET ?lat=<f>&lng=<f>       → nearest known bus location (JSON)
 *
 * This endpoint serves public reference data (terminal/stand names and
 * their cities), so it works for a signed-out visitor on the landing page
 * as well as for a signed-in passenger. It never exposes user data and it
 * is rate limited per session to prevent scraping or abuse.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/locations.php';

/** Allow only GET. */
if (is_post()) {
    json_response(['success' => false, 'message' => 'This endpoint only accepts GET requests.'], 405);
}

/* ------------------------------------------------------------------
 | Simple per-session rate limit (no extra table required).
 ------------------------------------------------------------------ */

/**
 * @return bool True when the caller is under the limit.
 */
function locations_rate_ok(int $maxPerMinute = 60): bool
{
    $now      = time();
    $window   = 60;
    $requests = $_SESSION['location_api_calls'] ?? [];

    // Drop anything outside the window.
    $requests = array_values(array_filter(
        is_array($requests) ? $requests : [],
        static fn ($timestamp): bool => is_int($timestamp) && ($now - $timestamp) < $window
    ));

    if (count($requests) >= $maxPerMinute) {
        $_SESSION['location_api_calls'] = $requests;

        return false;
    }

    $requests[] = $now;
    $_SESSION['location_api_calls'] = $requests;

    return true;
}

if (!locations_rate_ok()) {
    json_response(['success' => false, 'message' => 'Too many requests. Please slow down.'], 429);
}

/* ------------------------------------------------------------------
 | Reverse lookup by coordinate
 ------------------------------------------------------------------ */

if (get('lat') !== '' || get('lng') !== '') {
    $lat = filter_var(get('lat'), FILTER_VALIDATE_FLOAT);
    $lng = filter_var(get('lng'), FILTER_VALIDATE_FLOAT);

    if ($lat === false || $lng === false) {
        json_response(['success' => false, 'message' => 'Valid coordinates are required.'], 422);
    }

    $nearest = nearest_location((float) $lat, (float) $lng, 120.0);

    if ($nearest === null) {
        json_response(['success' => true, 'location' => null, 'message' => 'No known terminal near you.']);
    }

    json_response([
        'success'  => true,
        'location' => [
            'id'            => (int) $nearest['id'],
            'name'          => (string) $nearest['name'],
            'city'          => (string) $nearest['city'],
            'state'         => (string) $nearest['state'],
            'type'          => (string) $nearest['location_type'],
            'type_label'    => location_type_label((string) $nearest['location_type']),
            'label'         => location_label($nearest),
            'distance_km'   => (float) ($nearest['distance_km'] ?? 0),
        ],
    ]);
}

/* ------------------------------------------------------------------
 | Text search
 ------------------------------------------------------------------ */

$query = get('q');
$limit = min(12, max(1, get_int('limit', 8, 1)));

if (mb_strlen(normalize_location_query($query)) < 2) {
    json_response(['success' => true, 'locations' => [], 'query' => $query]);
}

$results = [];

foreach (search_locations($query, $limit) as $row) {
    $results[] = [
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'city'       => (string) $row['city'],
        'state'      => (string) $row['state'],
        'type'       => (string) $row['location_type'],
        'type_label' => location_type_label((string) $row['location_type']),
        'label'      => location_label($row),
        'short'      => location_short_label($row),
        'lat'        => $row['latitude'] !== null ? (float) $row['latitude'] : null,
        'lng'        => $row['longitude'] !== null ? (float) $row['longitude'] : null,
    ];
}

json_response([
    'success'   => true,
    'query'     => $query,
    'count'     => count($results),
    'locations' => $results,
]);
