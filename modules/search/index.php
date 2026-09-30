<?php
/**
 * Fleetra — Passenger services / Search buses
 * ------------------------------------------------------------------
 * modules/search/index.php
 *
 * The passenger-facing search. It only ever shows services that can
 * actually be boarded: active routes, uncancelled schedules, departures
 * still in the future and with seats left to sell.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../bookings/_logic.php';

require_permission('trips.search');

$from       = get('from');
$to         = get('to');
$dateInput  = get('date');
$date       = is_valid_date($dateInput) ? $dateInput : '';
$passengers = max(1, min(6, get_int('passengers', 1, 1)));
$hasSearched = $from !== '' || $to !== '' || $date !== '';

$where = [
    'r.status = "active"',
    's.status <> "cancelled"',
    '(s.schedule_date > CURDATE() OR (s.schedule_date = CURDATE() AND s.departure_time > CURTIME()))',
];
$params = [];

if ($date !== '') {
    $where[]  = 's.schedule_date = ?';
    $params[] = $date;
} elseif (!$hasSearched) {
    // Landing on the page with no criteria shows today and the days ahead.
    $where[]  = 's.schedule_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
}

if ($from !== '') {
    $like    = '%' . $from . '%';
    $where[] = '(r.source LIKE ? OR EXISTS (SELECT 1 FROM stops st WHERE st.route_id = r.id AND st.stop_name LIKE ?))';
    array_push($params, $like, $like);
}

if ($to !== '') {
    $like    = '%' . $to . '%';
    $where[] = '(r.destination LIKE ? OR EXISTS (SELECT 1 FROM stops st WHERE st.route_id = r.id AND st.stop_name LIKE ?))';
    array_push($params, $like, $like);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$services = db_all(
    "SELECT s.id, s.schedule_date, s.departure_time, s.arrival_time, s.status,
            r.id AS route_id, r.route_code, r.route_name, r.source, r.destination,
            r.distance, r.estimated_duration, r.base_fare,
            b.bus_number, b.bus_type, b.capacity, b.registration_number,
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
      LIMIT 40",
    $params
);

/** Drop anything without enough seats for the party. */
$services = array_values(array_filter(
    $services,
    static fn (array $service): bool => ((int) $service['capacity'] - (int) $service['seats_taken']) >= $passengers
));

$totalSeats = 0;
foreach ($services as $service) {
    $totalSeats += max(0, (int) $service['capacity'] - (int) $service['seats_taken']);
}

$cheapest = $services === []
    ? 0.0
    : min(array_map(static fn (array $service): float => (float) $service['base_fare'], $services));

$page_title       = 'Search buses';
$active_nav       = 'search';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Passenger services'],
    ['label' => 'Search buses'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Find a bus',
    'Search by city or by any stop on the route, then pick your seats',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/bookings/index.php')) . '">
        <i class="bi bi-journal-check" aria-hidden="true"></i> My bookings
     </a>'
) ?>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/search/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                <label class="visually-hidden" for="from">Boarding from</label>
                <input type="search" class="form-control" id="from" name="from" value="<?= e($from) ?>"
                       placeholder="Leaving from... (city or stop)">
            </div>

            <div class="search-field">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                <label class="visually-hidden" for="to">Going to</label>
                <input type="search" class="form-control" id="to" name="to" value="<?= e($to) ?>"
                       placeholder="Going to... (city or stop)">
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
</div>

<?php if ($services !== []): ?>
    <div class="stat-grid">
        <?= stat_card('Services found', count($services), 'bi-bus-front', 'primary', $date !== '' ? 'On ' . format_date($date, 'd M Y') : 'Within the next 7 days') ?>
        <?= stat_card('Seats available', $totalSeats, 'bi-people', 'info', 'Across these services') ?>
        <?= stat_card('First departure', format_time((string) $services[0]['departure_time']), 'bi-clock', 'success', format_date((string) $services[0]['schedule_date'], 'd M Y')) ?>
        <?= stat_card('Fares from', money($cheapest), 'bi-cash-coin', 'warning', 'Full route base fare') ?>
    </div>
<?php endif; ?>

<?php if ($services === []): ?>
    <div class="card-fl">
        <?= empty_state(
            $hasSearched ? 'No buses match this search' : 'No services are on sale right now',
            $hasSearched
                ? 'Try a different date, or search by a wider place name. Leave the date empty to see the next seven days.'
                : 'There are no upcoming departures with seats left. Please try another day.',
            'bi-search',
            $hasSearched
                ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/search/index.php')) . '">Clear the search</a>'
                : ''
        ) ?>
    </div>
<?php else: ?>
    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Available services</h2>
                <p class="card-fl__subtitle">
                    <?= count($services) ?> departure<?= count($services) === 1 ? '' : 's' ?>
                    with room for <?= $passengers ?> passenger<?= $passengers === 1 ? '' : 's' ?>
                </p>
            </div>
        </div>

        <div class="card-fl__body stack-sm">
            <?php foreach ($services as $service): ?>
                <?php
                $seatsLeft = max(0, (int) $service['capacity'] - (int) $service['seats_taken']);
                $departure = strtotime((string) $service['schedule_date'] . ' ' . (string) $service['departure_time']);
                $minutesToLeave = $departure !== false ? (int) floor(($departure - time()) / 60) : 0;
                $bookUrl = url('modules/search/seats.php?' . http_build_query([
                    'schedule_id' => (int) $service['id'],
                    'passengers'  => $passengers,
                    'from'        => $from,
                    'to'          => $to,
                ]));
                ?>
                <article class="service-card<?= $minutesToLeave >= 0 && $minutesToLeave <= 60 ? ' service-card--soon' : '' ?>">
                    <div class="service-card__main">
                        <div class="service-card__route">
                            <span class="service-card__code"><?= e($service['route_code']) ?></span>
                            <span class="service-card__name"><?= e($service['route_name']) ?></span>
                            <?= status_badge($service['trip_status'] ?? 'scheduled') ?>
                        </div>

                        <div class="service-card__journey">
                            <div class="service-card__point">
                                <span class="service-card__time"><?= e(format_time((string) $service['departure_time'])) ?></span>
                                <span class="service-card__place"><?= e($service['source']) ?></span>
                            </div>
                            <div class="service-card__line" aria-hidden="true">
                                <span class="service-card__dot"></span>
                                <span class="service-card__rail"></span>
                                <span class="service-card__dot"></span>
                                <span class="service-card__duration">
                                    <?= e(format_duration(time_range_duration((string) $service['departure_time'], (string) $service['arrival_time']))) ?>
                                </span>
                            </div>
                            <div class="service-card__point service-card__point--end">
                                <span class="service-card__time"><?= e(format_time((string) $service['arrival_time'])) ?></span>
                                <span class="service-card__place"><?= e($service['destination']) ?></span>
                            </div>
                        </div>

                        <div class="service-card__meta">
                            <span class="chip"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e(format_date((string) $service['schedule_date'], 'D, d M Y')) ?></span>
                            <span class="chip"><i class="bi bi-bus-front" aria-hidden="true"></i> <?= e($service['bus_number']) ?> · <?= e(labelize((string) $service['bus_type'])) ?></span>
                            <span class="chip"><i class="bi bi-signpost-2" aria-hidden="true"></i> <?= (int) $service['stop_count'] ?> stops · <?= e(number_format((float) $service['distance'], 1)) ?> km</span>
                            <span class="chip"><i class="bi bi-person-badge" aria-hidden="true"></i> <?= e($service['driver_name']) ?></span>
                        </div>
                    </div>

                    <div class="service-card__aside">
                        <div class="service-card__fare">
                            <span class="service-card__fare-label">Base fare</span>
                            <span class="service-card__fare-value"><?= e(money($service['base_fare'])) ?></span>
                            <span class="service-card__fare-note">Pro-rated for part journeys</span>
                        </div>

                        <div class="service-card__seats">
                            <?php if ($seatsLeft <= 5): ?>
                                <span class="badge-status badge-warning">Only <?= $seatsLeft ?> left</span>
                            <?php else: ?>
                                <span class="badge-status badge-success"><?= $seatsLeft ?> seats free</span>
                            <?php endif; ?>
                            <?php if ($minutesToLeave >= 0 && $minutesToLeave <= 60): ?>
                                <span class="badge-status badge-warning">
                                    Leaves in <?= $minutesToLeave ?> min
                                </span>
                            <?php endif; ?>
                        </div>

                        <a class="btn btn-primary w-100" href="<?= e($bookUrl) ?>">
                            <i class="bi bi-ui-checks-grid" aria-hidden="true"></i> Select seats
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
