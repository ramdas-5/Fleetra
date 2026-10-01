<?php
/**
 * Fleetra — Passenger services / Find a bus
 * ------------------------------------------------------------------
 * modules/search/index.php
 *
 * A realistic availability view rather than a booking-only list. Each
 * service on the chosen date is classified on the server as one of:
 *
 *   live         the bus is on the road with a fresh position fix
 *   stale        the bus is running but has stopped reporting (no live fix)
 *   scheduled    a departure still ahead (bookable)
 *   departed     already left, with its last known position / actual time
 *   unavailable  cancelled, in maintenance or fully booked
 *
 * A bus is never shown as live unless the freshness of its position
 * proves it. Scheduled and departed services are still listed — with
 * route, departure, arrival, estimated arrival and last update — so a
 * passenger can plan around services that are not transmitting right now.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/operations.php';
require_once __DIR__ . '/../../includes/locations.php';
require_once __DIR__ . '/../bookings/_logic.php';

require_permission('trips.search');

$from        = get('from');
$to          = get('to');
$dateInput   = get('date');
$date        = is_valid_date($dateInput) ? $dateInput : '';
$passengers  = max(1, min(6, get_int('passengers', 1, 1)));
$hasSearched = $from !== '' || $to !== '' || $date !== '';

/* Date window: an explicit date searches just that day; otherwise the next
   seven days (so a service that appears later today is still found). */
if ($date !== '') {
    $windowFrom = $date;
    $windowTo   = $date;
} else {
    $windowFrom = date('Y-m-d');
    $windowTo   = date('Y-m-d', strtotime('+7 days'));
}

$where = [
    'r.status = "active"',
    's.status <> "cancelled"',
    's.schedule_date BETWEEN ? AND ?',
];
$params = [$windowFrom, $windowTo];

if ($from !== '') {
    $like    = '%' . $from . '%';
    $where[] = '(r.source LIKE ? OR r.route_name LIKE ? OR EXISTS (SELECT 1 FROM stops st WHERE st.route_id = r.id AND st.stop_name LIKE ?))';
    array_push($params, $like, $like, $like);
}

if ($to !== '') {
    $like    = '%' . $to . '%';
    $where[] = '(r.destination LIKE ? OR r.route_name LIKE ? OR EXISTS (SELECT 1 FROM stops st WHERE st.route_id = r.id AND st.stop_name LIKE ?))';
    array_push($params, $like, $like, $like);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = db_all(
    "SELECT s.id, s.schedule_date, s.departure_time, s.arrival_time, s.status AS schedule_status,
            r.id AS route_id, r.route_code, r.route_name, r.source, r.destination,
            r.distance, r.estimated_duration, r.base_fare,
            b.id AS bus_id, b.bus_number, b.bus_type, b.capacity, b.registration_number, b.status AS bus_status,
            u.name AS driver_name,
            t.id AS trip_id, t.trip_status, t.delay_minutes,
            bl.latitude, bl.longitude, bl.speed, bl.recorded_at, bl.source AS location_source,
            (SELECT COUNT(*) FROM bookings bk
              WHERE bk.schedule_id = s.id AND bk.booking_status IN ('pending','confirmed','completed')) AS seats_taken,
            (SELECT COUNT(*) FROM stops st2 WHERE st2.route_id = r.id) AS stop_count
       FROM schedules s
       JOIN routes r  ON r.id = s.route_id
       JOIN buses b   ON b.id = s.bus_id
       JOIN drivers d ON d.id = s.driver_id
       JOIN users u   ON u.id = d.user_id
       LEFT JOIN trips t ON t.schedule_id = s.id
       LEFT JOIN bus_locations bl ON bl.id = (
            SELECT x.id FROM bus_locations x
             WHERE x.bus_id = b.id
             ORDER BY x.recorded_at DESC, x.id DESC
             LIMIT 1
       )
       $whereSql
      ORDER BY s.schedule_date ASC, s.departure_time ASC, r.route_code
      LIMIT 80",
    $params
);

/* ------------------------------------------------------------------
 | Classification
 ------------------------------------------------------------------ */

$now      = time();
$sections = ['live' => [], 'scheduled' => [], 'stale' => [], 'departed' => [], 'unavailable' => []];

foreach ($rows as $row) {
    $departure = strtotime((string) $row['schedule_date'] . ' ' . (string) $row['departure_time']);
    $arrival   = strtotime((string) $row['schedule_date'] . ' ' . (string) $row['arrival_time']);

    if ($arrival !== false && $departure !== false && $arrival <= $departure) {
        $arrival += 86400; // crosses midnight
    }

    $seatsLeft   = max(0, (int) $row['capacity'] - (int) $row['seats_taken']);
    $freshness   = tracking_freshness($row['recorded_at'] !== null ? (string) $row['recorded_at'] : null);
    $isCancelled = (string) $row['schedule_status'] === 'cancelled';
    $busOff      = (string) $row['bus_status'] !== 'active';
    $tripStatus  = (string) ($row['trip_status'] ?? '');
    $isRunning   = in_array($tripStatus, ['boarding', 'running', 'delayed'], true);
    $isFuture    = $departure !== false && $departure > $now;
    $tooFull     = $seatsLeft < $passengers;

    if ($isCancelled || $busOff) {
        $availability = 'unavailable';
    } elseif ($isRunning && in_array($freshness['status'], ['live', 'recent'], true)) {
        $availability = 'live';
    } elseif ($isRunning) {
        // On the road but it has stopped reporting — never shown as live.
        $availability = 'stale';
    } elseif ($isFuture) {
        $availability = $tooFull ? 'unavailable' : 'scheduled';
    } else {
        $availability = 'departed';
    }

    if ($tooFull && $availability !== 'unavailable') {
        $availability = 'unavailable';
    }

    $row['departure_ts']  = $departure;
    $row['arrival_ts']    = $arrival;
    $row['seats_left']    = $seatsLeft;
    $row['freshness']     = $freshness;
    $row['availability']  = $availability;
    $row['is_running']    = $isRunning;
    $row['is_future']     = $isFuture;
    $row['too_full']      = $tooFull;
    $row['bookable']      = !$isCancelled && !$busOff && $isFuture && !$tooFull;
    $row['has_fix']       = $row['recorded_at'] !== null;

    if ($availability === 'live') {
        $sections['live'][] = $row;
    } elseif ($availability === 'scheduled') {
        $sections['scheduled'][] = $row;
    } elseif ($availability === 'stale') {
        $sections['stale'][] = $row;
    } elseif ($availability === 'departed') {
        $sections['departed'][] = $row;
    } else {
        $sections['unavailable'][] = $row;
    }
}

/* Order departed/“last known” with the most recent activity first. */
usort($sections['departed'], static fn (array $a, array $b): int => ($b['departure_ts'] ?? 0) <=> ($a['departure_ts'] ?? 0));

$availabilityLabels = [
    'live'        => ['label' => 'Live now',               'variant' => 'success', 'icon' => 'bi-broadcast'],
    'scheduled'   => ['label' => 'Scheduled',              'variant' => 'info',    'icon' => 'bi-clock'],
    'stale'       => ['label' => 'Running · no live data', 'variant' => 'warning', 'icon' => 'bi-exclamation-triangle'],
    'departed'    => ['label' => 'Departed · last known',  'variant' => 'muted',   'icon' => 'bi-flag'],
    'unavailable' => ['label' => 'Unavailable',            'variant' => 'danger',  'icon' => 'bi-slash-circle'],
];

$liveCount        = count($sections['live']);
$scheduledCount   = count($sections['scheduled']);
$staleCount       = count($sections['stale']);
$departedCount    = count($sections['departed']);
$unavailableCount = count($sections['unavailable']);
$totalShown       = $liveCount + $scheduledCount + $staleCount + $departedCount + $unavailableCount;

$cheapest = 0.0;
foreach ($rows as $row) {
    $fare = (float) $row['base_fare'];
    if ($cheapest === 0.0 || $fare < $cheapest) {
        $cheapest = $fare;
    }
}

$page_title       = 'Find a bus';
$active_nav       = 'search';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Passenger services'],
    ['label' => 'Find a bus'],
];

require __DIR__ . '/../../includes/header.php';

/**
 * Render one service card.
 *
 * @param array<string, mixed> $service
 * @param array<string, array<string, string>> $labels
 */
function render_service_card(array $service, array $labels, string $from, string $to, int $passengers): string
{
    $availability = (string) $service['availability'];
    $meta         = $labels[$availability] ?? ['label' => 'Unknown', 'variant' => 'muted', 'icon' => 'bi-question'];
    $freshness    = $service['freshness'];
    $departure    = $service['departure_ts'];
    $arrival      = $service['arrival_ts'];
    $seatsLeft    = (int) $service['seats_left'];
    $minutesToGo  = $departure !== false ? (int) floor(($departure - time()) / 60) : null;

    $bookUrl = url('modules/search/seats.php?' . http_build_query([
        'schedule_id' => (int) $service['id'],
        'passengers'  => $passengers,
        'from'        => $from,
        'to'          => $to,
    ]));

    $eta = '—';
    if ($arrival !== false && $service['is_running']) {
        $eta = format_time(date('H:i:s', $arrival)) . ' (est.)';
    } elseif ($arrival !== false) {
        $eta = format_time(date('H:i:s', $arrival));
    }

    $html  = '<article class="service-card service-card--' . e($availability) . '">';
    $html .= '<div class="service-card__main">';

    $html .= '<div class="service-card__route">';
    $html .= '<span class="service-card__code">' . e((string) $service['route_code']) . '</span>';
    $html .= '<span class="service-card__name">' . e((string) $service['route_name']) . '</span>';
    $html .= '<span class="badge-status badge-' . e($meta['variant']) . ' status-dot status-dot--' . e($availability) . '">';
    $html .= '<i class="bi ' . e($meta['icon']) . '" aria-hidden="true"></i> ' . e($meta['label']) . '</span>';
    $html .= '</div>';

    $html .= '<div class="service-card__journey">';
    $html .= '<div class="service-card__point">';
    $html .= '<span class="service-card__time">' . e(format_time((string) $service['departure_time'])) . '</span>';
    $html .= '<span class="service-card__place">' . e((string) $service['source']) . '</span>';
    $html .= '</div>';
    $html .= '<div class="service-card__line" aria-hidden="true">';
    $html .= '<span class="service-card__dot"></span><span class="service-card__rail"></span><span class="service-card__dot"></span>';
    $html .= '<span class="service-card__duration">'
        . e(format_duration(time_range_duration((string) $service['departure_time'], (string) $service['arrival_time'])))
        . '</span>';
    $html .= '</div>';
    $html .= '<div class="service-card__point service-card__point--end">';
    $html .= '<span class="service-card__time">' . e(format_time((string) $service['arrival_time'])) . '</span>';
    $html .= '<span class="service-card__place">' . e((string) $service['destination']) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="service-card__meta">';
    $html .= '<span class="chip"><i class="bi bi-calendar3" aria-hidden="true"></i> '
        . e(format_date((string) $service['schedule_date'], 'D, d M Y')) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-bus-front" aria-hidden="true"></i> '
        . e((string) $service['bus_number']) . ' · ' . e(labelize((string) $service['bus_type'])) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-signpost-2" aria-hidden="true"></i> '
        . (int) $service['stop_count'] . ' stops · ' . e(number_format((float) $service['distance'], 1)) . ' km</span>';
    $html .= '<span class="chip"><i class="bi bi-person-badge" aria-hidden="true"></i> ' . e((string) $service['driver_name']) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-stopwatch" aria-hidden="true"></i> ETA ' . e($eta) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-geo-alt" aria-hidden="true"></i> Last update: '
        . e($service['has_fix'] ? time_ago((string) $service['recorded_at']) : 'not transmitting') . '</span>';
    $html .= '</div>';

    $html .= '<div class="service-card__live">';
    $html .= live_status_badge($service['has_fix'] ? (string) $service['recorded_at'] : null);
    if ($service['is_running']) {
        $html .= '<span class="cell-muted">' . e($service['location_source'] === 'device' ? 'Real device fix' : 'Simulated demo fix') . '</span>';
    }
    $html .= '</div>';

    $html .= '</div>'; // main

    $html .= '<div class="service-card__aside">';
    $html .= '<div class="service-card__fare">';
    $html .= '<span class="service-card__fare-label">Base fare</span>';
    $html .= '<span class="service-card__fare-value">' . e(money((float) $service['base_fare'])) . '</span>';
    $html .= '<span class="service-card__fare-note">Pro-rated for part journeys</span>';
    $html .= '</div>';

    $html .= '<div class="service-card__seats">';
    if ((int) $service['seats_left'] <= 5 && (int) $service['seats_left'] > 0) {
        $html .= '<span class="badge-status badge-warning">Only ' . (int) $service['seats_left'] . ' left</span>';
    } elseif ((int) $service['seats_left'] <= 0) {
        $html .= '<span class="badge-status badge-danger">Fully booked</span>';
    } else {
        $html .= '<span class="badge-status badge-success">' . (int) $service['seats_left'] . ' seats free</span>';
    }

    if ($minutesToGo !== null && $minutesToGo >= 0 && $minutesToGo <= 60) {
        $html .= '<span class="badge-status badge-warning">Leaves in ' . $minutesToGo . ' min</span>';
    }
    $html .= '</div>';

    if ($service['bookable']) {
        $html .= '<a class="btn btn-primary w-100" href="' . e($bookUrl) . '">'
            . '<i class="bi bi-ui-checks-grid" aria-hidden="true"></i> Select seats</a>';
    } else {
        $reason = $availability === 'live'
            ? 'On the road — booking closed'
            : ($availability === 'unavailable'
                ? ($service['too_full'] ? 'No seats for ' . $passengers . ' passenger(s)' : 'Not available')
                : 'Booking closed');
        $html .= '<span class="btn btn-outline-secondary w-100 disabled" aria-disabled="true">'
            . '<i class="bi bi-slash-circle" aria-hidden="true"></i> ' . e($reason) . '</span>';
    }

    $html .= '</div>'; // aside
    $html .= '</article>';

    return $html;
}
?>

<?= render_page_header(
    'Find a bus',
    'Live, scheduled and last-known services · search by city, terminal or any stop on the route',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/bookings/index.php')) . '">
        <i class="bi bi-journal-check" aria-hidden="true"></i> My bookings
     </a>'
) ?>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/search/index.php')) ?>" id="findBusForm">
        <div class="toolbar__filters">
            <div class="search-field search-field--location" data-location-autocomplete
                 data-endpoint="<?= e(url('api/locations.php')) ?>">
                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                <label class="visually-hidden" for="from">Boarding from</label>
                <input type="search" class="form-control" id="from" name="from" value="<?= e($from) ?>"
                       placeholder="Enter location — city, area, terminal or stop" autocomplete="off">
                <ul class="loc-suggest" role="listbox" aria-label="Boarding location suggestions" hidden></ul>
                <button type="button" class="search-field__geo" data-use-location data-target="from"
                        data-endpoint="<?= e(url('api/locations.php')) ?>" title="Use my current location (optional)">
                    <i class="bi bi-crosshair" aria-hidden="true"></i>
                    <span class="visually-hidden">Use my current location</span>
                </button>
            </div>

            <div class="search-field search-field--location" data-location-autocomplete
                 data-endpoint="<?= e(url('api/locations.php')) ?>">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                <label class="visually-hidden" for="to">Going to</label>
                <input type="search" class="form-control" id="to" name="to" value="<?= e($to) ?>"
                       placeholder="Going to — city, area, terminal or stop" autocomplete="off">
                <ul class="loc-suggest" role="listbox" aria-label="Destination suggestions" hidden></ul>
                <button type="button" class="search-field__geo" data-use-location data-target="to"
                        data-endpoint="<?= e(url('api/locations.php')) ?>" title="Use my current location (optional)">
                    <i class="bi bi-crosshair" aria-hidden="true"></i>
                    <span class="visually-hidden">Use my current location</span>
                </button>
            </div>

            <label class="visually-hidden" for="date">Travel date</label>
            <input type="date" class="form-control" id="date" name="date" value="<?= e($date) ?>"
                   min="<?= e(date('Y-m-d')) ?>" style="width:auto;">

            <label class="visually-hidden" for="passengers">Passengers</label>
            <select class="form-select" id="passengers" name="passengers" style="width:auto;">
                <?php for ($count = 1; $count <= 6; $count++): ?>
                    <option value="<?= $count ?>" <?= $count === $passengers ? 'selected' : '' ?>>
                        <?= $count ?> passenger<?= $count === 1 ? '' : 's' ?>
                    </option>
                <?php endfor; ?>
            </select>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search" aria-hidden="true"></i> Search
            </button>

            <?php if ($hasSearched): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/search/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="form-text mt-2 mb-0">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Location permission is never required — type any city, area, terminal, bus stop or landmark. Suggestions appear as you type.
    </p>
</div>

<?php if ($totalShown > 0): ?>
    <div class="stat-grid">
        <?= stat_card('Live now', $liveCount, 'bi-broadcast', 'success', 'Buses transmitting a fresh position') ?>
        <?= stat_card('Scheduled', $scheduledCount, 'bi-clock', 'info', 'Departures still ahead') ?>
        <?= stat_card('Running · no live data', $staleCount, 'bi-exclamation-triangle', 'warning', 'On the road but not reporting') ?>
        <?= stat_card('Departed / last known', $departedCount, 'bi-flag', 'muted', 'Finished — last position shown') ?>
        <?= stat_card('Unavailable', $unavailableCount, 'bi-slash-circle', 'danger', 'Cancelled, in workshop or full') ?>
    </div>
<?php endif; ?>

<?php if ($totalShown === 0): ?>
    <div class="card-fl">
        <?= empty_state(
            $hasSearched ? 'No buses match this search' : 'No services are on sale right now',
            $hasSearched
                ? 'Try a different date, or search by a city or terminal name. Leave the date empty to see the next seven days.'
                : 'There are no departures in the next seven days. Please try another day.',
            'bi-search',
            $hasSearched
                ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/search/index.php')) . '">Clear the search</a>'
                : ''
        ) ?>
    </div>
<?php else: ?>
    <?php
    $groupDefs = [
        'live'        => ['title' => 'Live now',                 'subtitle' => 'Buses on the road with a fresh position fix'],
        'scheduled'   => ['title' => 'Scheduled departures',     'subtitle' => 'Upcoming services you can book'],
        'stale'       => ['title' => 'Running · no live data',   'subtitle' => 'On the road but not transmitting — shown with their last known position, not as live'],
        'departed'    => ['title' => 'Departed / last known',    'subtitle' => 'Finished services — shown with their last update, not as live'],
        'unavailable' => ['title' => 'Unavailable',              'subtitle' => 'Cancelled, in the workshop or fully booked'],
    ];
    ?>

    <?php foreach ($groupDefs as $key => $def): ?>
        <?php if ($sections[$key] === []) { continue; } ?>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title"><?= e($def['title']) ?>
                        <span class="chip"><?= count($sections[$key]) ?></span>
                    </h2>
                    <p class="card-fl__subtitle"><?= e($def['subtitle']) ?></p>
                </div>
            </div>

            <div class="card-fl__body stack-sm">
                <?php foreach ($sections[$key] as $service): ?>
                    <?= render_service_card($service, $availabilityLabels, $from, $to, $passengers) ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
