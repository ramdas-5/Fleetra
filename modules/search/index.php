<?php
/**
 * Fleetra — Passenger search / Find a bus
 * ------------------------------------------------------------------
 * modules/search/index.php
 *
 * The passenger booking journey. A user enters a boarding point and a
 * destination (typed manually or chosen from location suggestions), picks
 * a date and sees bookable services as clean bus cards.
 *
 * Availability here is about the timetable, not vehicle telemetry:
 *   scheduled    departure still ahead and seats available → bookable
 *   departed     already left today → shown, but not bookable
 *   unavailable  cancelled, in the workshop or fully booked
 *
 * Browser/device location permission is never required: suggestions come
 * from the location dataset and manual entry always works.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/operations.php';
require_once __DIR__ . '/../bookings/_logic.php';

require_permission('trips.search');

$from        = get('from');
$to          = get('to');
$dateInput   = get('date');
$date        = is_valid_date($dateInput) ? $dateInput : '';
$passengers  = max(1, min(6, get_int('passengers', 1, 1)));
$hasSearched = $from !== '' || $to !== '' || $date !== '';

/* Date window: an explicit date searches just that day, otherwise a week. */
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
            b.id AS bus_id, b.bus_number, b.bus_type, b.capacity, b.registration_number,
            b.manufacturer, b.model, b.status AS bus_status,
            u.name AS driver_name,
            t.trip_status,
            (SELECT COUNT(*) FROM bookings bk
              WHERE bk.schedule_id = s.id AND bk.booking_status IN ('pending','confirmed','completed')) AS seats_taken,
            (SELECT COUNT(*) FROM stops st2 WHERE st2.route_id = r.id) AS stop_count
       FROM schedules s
       JOIN routes r  ON r.id = s.route_id
       JOIN buses b   ON b.id = s.bus_id
       JOIN drivers d ON d.id = s.driver_id
       JOIN users u   ON u.id = d.user_id
       LEFT JOIN trips t ON t.schedule_id = s.id
       $whereSql
      ORDER BY s.schedule_date ASC, s.departure_time ASC, r.route_code
      LIMIT 80",
    $params
);

/* ------------------------------------------------------------------
 | Classify by the timetable
 ------------------------------------------------------------------ */

$now   = time();
$cards = [];

foreach ($rows as $row) {
    $departure = strtotime((string) $row['schedule_date'] . ' ' . (string) $row['departure_time']);
    $arrival   = strtotime((string) $row['schedule_date'] . ' ' . (string) $row['arrival_time']);

    if ($arrival !== false && $departure !== false && $arrival <= $departure) {
        $arrival += 86400;
    }

    $seatsLeft   = max(0, (int) $row['capacity'] - (int) $row['seats_taken']);
    $isCancelled = (string) $row['schedule_status'] === 'cancelled';
    $busOff      = (string) $row['bus_status'] !== 'active';
    $isFuture    = $departure !== false && $departure > $now;
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

    $row['departure_ts'] = $departure;
    $row['arrival_ts']   = $arrival;
    $row['seats_left']   = $seatsLeft;
    $row['availability'] = $availability;
    $row['too_full']     = $tooFull;
    $row['bookable']     = $availability === 'scheduled';

    $cards[$availability][] = $row;
}

$labels = [
    'scheduled'   => ['label' => 'Available',   'variant' => 'success', 'icon' => 'bi-check-circle'],
    'departed'    => ['label' => 'Departed',    'variant' => 'muted',   'icon' => 'bi-flag'],
    'unavailable' => ['label' => 'Unavailable', 'variant' => 'danger',  'icon' => 'bi-slash-circle'],
];

$groups = [
    'scheduled'   => ['title' => 'Available buses', 'subtitle' => 'Departures you can book'],
    'departed'    => ['title' => 'Departed',        'subtitle' => 'Already left on the selected day'],
    'unavailable' => ['title' => 'Unavailable',     'subtitle' => 'Cancelled, in the workshop or fully booked'],
];

$scheduled   = $cards['scheduled'] ?? [];
$unavailable = $cards['unavailable'] ?? [];
$departed    = $cards['departed'] ?? [];
$totalShown  = count($scheduled) + count($unavailable) + count($departed);

$totalSeats = 0;
foreach ($scheduled as $card) {
    $totalSeats += $card['seats_left'];
}

$cheapest = 0.0;
foreach ($rows as $row) {
    $fare = (float) $row['base_fare'];
    if ($cheapest === 0.0 || $fare < $cheapest) {
        $cheapest = $fare;
    }
}

/**
 * Render one bus card.
 *
 * @param array<string, mixed> $service
 * @param array<string, array<string, string>> $labels
 */
function render_bus_card(array $service, array $labels, string $from, string $to, int $passengers): string
{
    $availability = (string) $service['availability'];
    $meta         = $labels[$availability] ?? ['label' => 'Unknown', 'variant' => 'muted', 'icon' => 'bi-question'];
    $seatsLeft    = (int) $service['seats_left'];
    $departure    = $service['departure_ts'];
    $minutesToGo  = $departure !== false ? (int) floor(($departure - time()) / 60) : null;

    $operator = trim((string) ($service['manufacturer'] ?? ''));
    if ($operator === '') {
        $operator = 'Fleetra';
    }

    $bookUrl = url('modules/search/seats.php?' . http_build_query([
        'schedule_id' => (int) $service['id'],
        'passengers'  => $passengers,
        'from'        => $from,
        'to'          => $to,
    ]));

    $html  = '<article class="bus-card bus-card--' . e($availability) . '">';
    $html .= '<div class="bus-card__head">';

    $html .= '<div class="bus-card__operator">';
    $html .= '<span class="bus-card__avatar" aria-hidden="true"><i class="bi bi-bus-front"></i></span>';
    $html .= '<div>';
    $html .= '<span class="bus-card__name">' . e((string) $service['bus_number']) . '</span>';
    $html .= '<span class="bus-card__sub">' . e($operator) . ' · ' . e(labelize((string) $service['bus_type'])) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<span class="badge-status badge-' . e($meta['variant']) . '">'
        . '<i class="bi ' . e($meta['icon']) . '" aria-hidden="true"></i> ' . e($meta['label']) . '</span>';
    $html .= '</div>';

    $html .= '<div class="bus-card__body">';
    $html .= '<div class="bus-card__journey">';
    $html .= '<div class="bus-card__leg">';
    $html .= '<span class="bus-card__time">' . e(format_time((string) $service['departure_time'])) . '</span>';
    $html .= '<span class="bus-card__place">' . e((string) $service['source']) . '</span>';
    $html .= '</div>';

    $html .= '<div class="bus-card__between" aria-hidden="true">';
    $html .= '<span class="bus-card__duration">'
        . e(format_duration(time_range_duration((string) $service['departure_time'], (string) $service['arrival_time']))) . '</span>';
    $html .= '<span class="bus-card__rail"></span>';
    $html .= '<span class="bus-card__stops">' . (int) $service['stop_count'] . ' stops · '
        . e(number_format((float) $service['distance'], 0)) . ' km</span>';
    $html .= '</div>';

    $html .= '<div class="bus-card__leg bus-card__leg--end">';
    $html .= '<span class="bus-card__time">' . e(format_time((string) $service['arrival_time'])) . '</span>';
    $html .= '<span class="bus-card__place">' . e((string) $service['destination']) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="bus-card__meta">';
    $html .= '<span class="chip"><i class="bi bi-calendar3" aria-hidden="true"></i> '
        . e(format_date((string) $service['schedule_date'], 'D, d M Y')) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-geo-alt" aria-hidden="true"></i> Route ' . e((string) $service['route_code']) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-person-badge" aria-hidden="true"></i> ' . e((string) $service['driver_name']) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="bus-card__aside">';
    $html .= '<div class="bus-card__fare">';
    $html .= '<span class="bus-card__fare-value">' . e(money((float) $service['base_fare'])) . '</span>';
    $html .= '<span class="bus-card__fare-note">Base fare · pro-rated per leg</span>';
    $html .= '</div>';

    $html .= '<div class="bus-card__seats">';
    if ($availability === 'unavailable' && $service['too_full']) {
        $html .= '<span class="badge-status badge-danger">Fully booked</span>';
    } elseif ($availability === 'unavailable') {
        $html .= '<span class="badge-status badge-danger">Not available</span>';
    } elseif ($seatsLeft <= 5) {
        $html .= '<span class="badge-status badge-warning">Only ' . $seatsLeft . ' left</span>';
    } else {
        $html .= '<span class="badge-status badge-success">' . $seatsLeft . ' seats free</span>';
    }

    if ($availability === 'scheduled' && $minutesToGo !== null && $minutesToGo >= 0 && $minutesToGo <= 60) {
        $html .= '<span class="badge-status badge-warning">Leaves in ' . $minutesToGo . ' min</span>';
    }
    $html .= '</div>';

    if ($service['bookable']) {
        $html .= '<a class="btn btn-primary w-100" href="' . e($bookUrl) . '">'
            . '<i class="bi bi-ui-checks-grid" aria-hidden="true"></i> Select seats</a>';
    } else {
        $html .= '<span class="btn btn-outline-secondary w-100 disabled" aria-disabled="true">'
            . '<i class="bi bi-slash-circle" aria-hidden="true"></i> '
            . ($availability === 'departed' ? 'Departed' : 'Not bookable') . '</span>';
    }

    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

$page_title       = 'Find a bus';
$active_nav       = 'search';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Passenger services'],
    ['label' => 'Find a bus'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Find a bus',
    'Search by city, terminal or any stop on the route and choose your seats',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/tickets/index.php')) . '">
        <i class="bi bi-ticket-perforated" aria-hidden="true"></i> My tickets
     </a>'
) ?>

<div class="card-fl search-panel">
    <form method="get" action="<?= e(url('modules/search/index.php')) ?>" id="findBusForm">
        <h2 class="search-panel__heading">Where are you going?</h2>

        <div class="search-panel__row">
            <div class="search-panel__field">
                <label class="search-panel__label" for="from">From</label>
                <div class="search-field search-field--location" data-location-autocomplete
                     data-endpoint="<?= e(url('api/locations.php')) ?>">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    <input type="text" class="form-control" id="from" name="from" value="<?= e($from) ?>"
                           placeholder="Enter your current location" autocomplete="off">
                    <ul class="loc-suggest" role="listbox" aria-label="Boarding location suggestions" hidden></ul>
                    <button type="button" class="search-field__geo" data-use-location data-target="from"
                            data-endpoint="<?= e(url('api/locations.php')) ?>" title="Use my current location (optional)">
                        <i class="bi bi-crosshair" aria-hidden="true"></i>
                        <span class="visually-hidden">Use my current location</span>
                    </button>
                </div>
            </div>

            <button type="button" class="search-panel__swap" id="swapLocations"
                    data-swap-from="from" data-swap-to="to" title="Swap From and To" aria-label="Swap From and To">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
            </button>

            <div class="search-panel__field">
                <label class="search-panel__label" for="to">To</label>
                <div class="search-field search-field--location" data-location-autocomplete
                     data-endpoint="<?= e(url('api/locations.php')) ?>">
                    <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                    <input type="text" class="form-control" id="to" name="to" value="<?= e($to) ?>"
                           placeholder="Enter destination" autocomplete="off">
                    <ul class="loc-suggest" role="listbox" aria-label="Destination suggestions" hidden></ul>
                    <button type="button" class="search-field__geo" data-use-location data-target="to"
                            data-endpoint="<?= e(url('api/locations.php')) ?>" title="Use my current location (optional)">
                        <i class="bi bi-crosshair" aria-hidden="true"></i>
                        <span class="visually-hidden">Use my current location</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="search-panel__row search-panel__row--options">
            <div class="search-panel__field">
                <label class="search-panel__label" for="date">Travel date</label>
                <input type="date" class="form-control" id="date" name="date" value="<?= e($date) ?>"
                       min="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="search-panel__field">
                <label class="search-panel__label" for="passengers">Passengers</label>
                <select class="form-select" id="passengers" name="passengers">
                    <?php for ($count = 1; $count <= 6; $count++): ?>
                        <option value="<?= $count ?>" <?= $count === $passengers ? 'selected' : '' ?>>
                            <?= $count ?> passenger<?= $count === 1 ? '' : 's' ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary search-panel__submit">
                <i class="bi bi-search" aria-hidden="true"></i> Search bus
            </button>

            <?php if ($hasSearched): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/search/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>

        <p class="form-text mb-0">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Type a city, area, terminal or bus stop — suggestions appear as you type. No location permission is required.
        </p>
    </form>
</div>

<?php if ($totalShown > 0): ?>
    <div class="stat-grid">
        <?= stat_card('Buses found', count($scheduled), 'bi-bus-front', 'primary', $date !== '' ? 'On ' . format_date($date, 'd M Y') : 'Within the next 7 days') ?>
        <?= stat_card('Seats available', $totalSeats, 'bi-people', 'info', 'Across bookable services') ?>
        <?= stat_card('First departure', $scheduled !== [] ? format_time((string) $scheduled[0]['departure_time']) : '—', 'bi-clock', 'success', $scheduled !== [] ? format_date((string) $scheduled[0]['schedule_date'], 'd M Y') : 'No services') ?>
        <?= stat_card('Fares from', $cheapest > 0 ? money($cheapest) : '—', 'bi-cash-coin', 'warning', 'Full route base fare') ?>
    </div>
<?php endif; ?>

<?php if ($totalShown === 0): ?>
    <div class="card-fl">
        <?= empty_state(
            $hasSearched ? 'No buses match this search' : 'No services are on sale right now',
            $hasSearched
                ? 'Try a different date, or choose a nearby city or terminal. Leave the date empty to see the next seven days.'
                : 'There are no departures in the next seven days. Please try another day.',
            'bi-search',
            $hasSearched
                ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/search/index.php')) . '">Clear the search</a>'
                : ''
        ) ?>
    </div>
<?php else: ?>
    <?php foreach ($groups as $key => $def): ?>
        <?php if (($cards[$key] ?? []) === []) { continue; } ?>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title"><?= e($def['title']) ?> <span class="chip"><?= count($cards[$key]) ?></span></h2>
                    <p class="card-fl__subtitle"><?= e($def['subtitle']) ?></p>
                </div>
            </div>
            <div class="card-fl__body stack-sm">
                <?php foreach ($cards[$key] as $service): ?>
                    <?= render_bus_card($service, $labels, $from, $to, $passengers) ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
