<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/locations.php
 *
 * Reference location data: major bus terminals, stands, stops and
 * landmarks across India (database/locations). This powers the passenger
 * search and the "Enter location" autocomplete field.
 *
 * Everything here is read-only reference data. The functions are written
 * so matching stays fast as the dataset grows (an indexed, generated
 * search_text column plus a bounded LIMIT).
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/* ------------------------------------------------------------------
 | Labels
 ------------------------------------------------------------------ */

/** @return array<string, string> */
function location_type_options(): array
{
    return [
        'terminal'  => 'Bus terminal',
        'bus_stand' => 'Bus stand',
        'bus_stop'  => 'Bus stop',
        'landmark'  => 'Landmark',
        'city'      => 'City',
    ];
}

/** Human label for a location_type. */
function location_type_label(?string $type): string
{
    $options = location_type_options();

    return $options[(string) $type] ?? 'Location';
}

/**
 * A single-line label for a location row, e.g.
 * "Kempegowda Bus Station Majestic — Bengaluru, Karnataka".
 *
 * @param array<string, mixed> $location
 */
function location_label(array $location): string
{
    return trim((string) $location['name']) . ' — ' . trim((string) $location['city'])
        . ', ' . trim((string) $location['state']);
}

/** Short label used inside the autocomplete dropdown. */
function location_short_label(array $location): string
{
    return trim((string) $location['city']) . ' · ' . trim((string) $location['name']);
}

/* ------------------------------------------------------------------
 | Normalisation
 ------------------------------------------------------------------ */

/**
 * Normalise a free-text query for matching: lowercase, collapse spaces,
 * strip anything that is not a letter, digit, space or dash. Keeps the
 * LIKE pattern safe (the value is always bound as a parameter, but a
 * clean string also makes matching predictable).
 */
function normalize_location_query(string $query): string
{
    $query = mb_strtolower(trim($query), 'UTF-8');
    $query = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $query) ?? '';
    $query = preg_replace('/\s+/', ' ', $query) ?? '';

    return trim($query);
}

/* ------------------------------------------------------------------
 | Search
 ------------------------------------------------------------------ */

/**
 * Search the location reference table.
 *
 * Ranking: an exact city match first, then a prefix match on the indexed
 * search_text, then any substring match. Results are always bounded by
 * $limit so the endpoint stays fast no matter how large the table grows.
 *
 * @return array<int, array<string, mixed>>
 */
function search_locations(string $query, int $limit = 8): array
{
    $limit = max(1, min(25, $limit));
    $needle = normalize_location_query($query);

    if (mb_strlen($needle) < 2) {
        return [];
    }

    $prefix = $needle . '%';
    $word   = '% ' . $needle . '%';
    $any    = '%' . $needle . '%';
    $cityEq = $needle;

    $rows = db_all(
        'SELECT id, name, city, district, state, state_code, location_type, latitude, longitude, aliases
           FROM locations
          WHERE search_text LIKE ?
             OR search_text LIKE ?
             OR city = ?
          ORDER BY
             CASE
                WHEN LOWER(city) = ? THEN 0
                WHEN LOWER(city) LIKE ? THEN 1
                WHEN LOWER(name) LIKE ? THEN 2
                WHEN aliases IS NOT NULL AND LOWER(aliases) LIKE ? THEN 3
                WHEN search_text LIKE ? THEN 4
                ELSE 5
             END,
             city ASC, name ASC
          LIMIT ' . $limit,
        [$prefix, $any, $cityEq, $cityEq, $prefix, $prefix, $prefix, $prefix]
    );

    return $rows;
}

/**
 * Fetch one location by id. Returns null when it does not exist.
 *
 * @return array<string, mixed>|null
 */
function find_location(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    return db_one(
        'SELECT id, name, city, district, state, state_code, location_type, latitude, longitude, aliases, pincode
           FROM locations WHERE id = ? LIMIT 1',
        [$id]
    );
}

/**
 * Nearest known terminal/stand to a coordinate (for the optional "use my
 * location" helper). Uses a bounding-box prefilter so the indexed
 * latitude/longitude comparison never scans the whole table, then picks
 * the closest by great-circle distance.
 *
 * @return array<string, mixed>|null
 */
function nearest_location(float $latitude, float $longitude, float $radiusKm = 60.0): ?array
{
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return null;
    }

    // ~111 km per degree of latitude; a generous longitude factor for India.
    $latDelta = $radiusKm / 111.0;
    $lngDelta = $radiusKm / max(1.0, 111.0 * cos(deg2rad($latitude)));

    $candidates = db_all(
        'SELECT id, name, city, district, state, state_code, location_type, latitude, longitude
           FROM locations
          WHERE latitude BETWEEN ? AND ?
            AND longitude BETWEEN ? AND ?
          LIMIT 200',
        [
            $latitude - $latDelta,
            $latitude + $latDelta,
            $longitude - $lngDelta,
            $longitude + $lngDelta,
        ]
    );

    $best     = null;
    $bestDist = null;

    foreach ($candidates as $candidate) {
        $lat = (float) $candidate['latitude'];
        $lng = (float) $candidate['longitude'];

        $dLat = deg2rad($lat - $latitude);
        $dLng = deg2rad($lng - $longitude);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($latitude)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
        $dist = 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));

        if ($bestDist === null || $dist < $bestDist) {
            $bestDist = $dist;
            $best     = $candidate + ['distance_km' => round($dist, 1)];
        }
    }

    return $best;
}

/* ------------------------------------------------------------------
 | Optional geolocation helper (never forced)
 ------------------------------------------------------------------ */

/**
 * Best-effort reverse geocode: name of the nearest known bus location to
 * a coordinate, otherwise null. Used only when the visitor chooses to
 * share their device location; manual entry never depends on it.
 */
function describe_coordinate(float $latitude, float $longitude): ?string
{
    $nearest = nearest_location($latitude, $longitude, 120.0);

    if ($nearest === null) {
        return null;
    }

    return trim((string) $nearest['city']);
}
