<?php
/**
 * Fleetra — Passenger services / Search buses
 * ------------------------------------------------------------------
 * modules/search/index.php
 *
 * The passenger journey, in two clearly separated sections:
 *
 *   1. Search buses   — From / To / date / passengers.
 *   2. Available buses — "Your Current Bus Terminal": pick the terminal you
 *      are standing in and see every departure still to come, in
 *      chronological order, based on the current time.
 *
 * Both sections read the same catalogue (includes/bus_catalog.php), which
 * merges real scheduled departures with clearly flagged demo services so a
 * route or terminal is never an empty screen. Demo services can never
 * duplicate a real one.
 *
 * Every future service is bookable, demo or not: a demo service is
 * materialised into a real route, bus, driver and schedule the moment the
 * passenger selects it (see modules/search/book_demo.php), then booked
 * through the ordinary seat picker.
 *
 * Availability is about the timetable, not vehicle telemetry:
 *   scheduled    departure still ahead and seats available
 *   departed     already left — only shown under "Earlier buses"
 *   unavailable  cancelled, in the workshop or fully booked
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/operations.php';
require_once __DIR__ . '/../../includes/bus_catalog.php';
require_once __DIR__ . '/../../includes/schedule_seeder.php';

require_permission('trips.search');

/* ------------------------------------------------------------------
 | Input
 ------------------------------------------------------------------ */

$from       = get('from');
$to         = get('to');
$dateInput  = get('date');
$date       = is_valid_date($dateInput) ? $dateInput : '';
$passengers = max(1, min(6, get_int('passengers', 1, 1)));

$terminal = get('terminal');
$earlier  = get('earlier') === '1';

$tab = get('tab');

if (!in_array($tab, ['search', 'available'], true)) {
    $tab = $terminal !== '' ? 'available' : 'search';
}

/* ------------------------------------------------------------------
 | Imported catalogue routes carry no timetable of their own until they
 | are scheduled. Make sure the routes being looked at are real and
 | bookable before they are listed, so a passenger never lands on a
 | demo-only screen with no seat to select.
 ------------------------------------------------------------------ */

if ($from !== '' || $to !== '' || $terminal !== '') {
    fleetra_ensure_route_schedules(
        $from !== '' ? $from : null,
        $to !== '' ? $to : null,
        $terminal !== '' ? $terminal : null,
        $date !== '' ? $date : null
    );
}

/* ------------------------------------------------------------------
 | Data
 ------------------------------------------------------------------ */

$search = bus_catalog_search([
    'from'       => $from,
    'to'         => $to,
    'date'       => $date,
    'passengers' => $passengers,
]);

$available = $terminal !== ''
    ? bus_catalog_available($terminal, $date !== '' ? $date : null, $earlier)
    : null;

/** Popular terminals offered as one-click starting points. */
$popularTerminals = ['Kolkata', 'Siliguri', 'Delhi', 'Mumbai', 'Bengaluru', 'Patna', 'Guwahati', 'Chennai'];

/* ------------------------------------------------------------------
 | Renderers
 ------------------------------------------------------------------ */

/**
 * Full bus card used by the Search buses results.
 *
 * @param array<string, mixed> $service
 * @param array<string, array<string, string>> $labels
 */
function render_bus_card(array $service, array $labels, string $from, string $to, int $passengers): string
{
    $availability = (string) $service['availability'];
    $meta         = $labels[$availability] ?? ['label' => 'Unknown', 'variant' => 'muted', 'icon' => 'bi-question'];
    $seatsLeft    = (int) $service['seats_left'];
    $isDemo       = (bool) $service['is_demo'];
    $minutesToGo  = $service['minutes_to_go'];

    $bookUrl = url('modules/search/seats.php?' . http_build_query([
        'schedule_id' => (int) ($service['schedule_id'] ?? 0),
        'passengers'  => $passengers,
        'from'        => $from,
        'to'          => $to,
    ]));

    $html  = '<article class="bus-card bus-card--' . e($availability) . ($isDemo ? ' bus-card--demo' : '') . '">';
    $html .= '<div class="bus-card__head">';
    $html .= '<div class="bus-card__operator">';
    $html .= '<span class="bus-card__avatar" aria-hidden="true"><i class="bi bi-bus-front"></i></span>';
    $html .= '<div>';
    $html .= '<span class="bus-card__name">' . e((string) $service['bus_number']) . '</span>';
    $html .= '<span class="bus-card__sub">' . e((string) $service['operator']) . ' · ' . e(labelize((string) $service['bus_type'])) . '</span>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="bus-card__tags">';
    $html .= '<span class="badge-status badge-' . e($meta['variant']) . '">'
        . '<i class="bi ' . e($meta['icon']) . '" aria-hidden="true"></i> ' . e($meta['label']) . '</span>';
    $html .= '<span class="badge-status badge-' . ($isDemo ? 'info' : 'muted') . '">'
        . e(bus_catalog_source_label($service)) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="bus-card__body">';
    $html .= '<div class="bus-card__journey">';
    $html .= '<div class="bus-card__leg">';
    $html .= '<span class="bus-card__time">' . e(format_time((string) $service['departure_time'])) . '</span>';
    $html .= '<span class="bus-card__place">' . e((string) $service['origin']) . '</span>';
    $html .= '</div>';

    $html .= '<div class="bus-card__between" aria-hidden="true">';
    $html .= '<span class="bus-card__duration">' . e(format_duration((int) $service['duration_minutes'])) . '</span>';
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
        . e(format_date((string) $service['date'], 'D, d M Y')) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-geo-alt" aria-hidden="true"></i> Route ' . e((string) $service['route_code']) . '</span>';
    if (!$isDemo) {
        $html .= '<span class="chip"><i class="bi bi-person-badge" aria-hidden="true"></i> ' . e((string) $service['driver_name']) . '</span>';
    }
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="bus-card__aside">';
    $html .= '<div class="bus-card__fare">';
    $html .= '<span class="bus-card__fare-value">' . e(money((float) $service['fare'])) . '</span>';
    $html .= '<span class="bus-card__fare-note">' . ($isDemo ? 'Demo fare · bookable' : 'Base fare · pro-rated per leg') . '</span>';
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
    } elseif ($isDemo && $availability === 'scheduled') {
        // A demo service is bookable too: picking it turns it into a real
        // departure and drops the passenger into the normal seat picker.
        $html .= render_demo_booking_form(
            $service,
            $from,
            $to,
            $passengers,
            'btn btn-primary w-100',
            'Select seats',
            'bi-ui-checks-grid'
        );
    } else {
        $html .= '<span class="btn btn-outline-secondary w-100 disabled" aria-disabled="true">'
            . '<i class="bi bi-slash-circle" aria-hidden="true"></i> '
            . ($availability === 'departed' ? 'Departed' : 'Not bookable') . '</span>';
    }

    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

/**
 * Booking form for a demo service.
 *
 * Demo services are not stored, so selecting one posts its identity to
 * book_demo.php, which materialises it and then opens the seat picker.
 *
 * @param array<string, mixed> $service
 */
function render_demo_booking_form(
    array $service,
    string $from,
    string $to,
    int $passengers,
    string $buttonClass,
    string $label,
    string $icon
): string {
    $html  = '<form method="post" action="' . e(url('modules/search/book_demo.php')) . '" class="m-0">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="origin" value="' . e((string) $service['origin']) . '">';
    $html .= '<input type="hidden" name="destination" value="' . e((string) $service['destination']) . '">';
    $html .= '<input type="hidden" name="date" value="' . e((string) $service['date']) . '">';
    $html .= '<input type="hidden" name="time" value="' . e((string) $service['departure_time']) . '">';
    $html .= '<input type="hidden" name="passengers" value="' . $passengers . '">';
    $html .= '<input type="hidden" name="from" value="' . e($from) . '">';
    $html .= '<input type="hidden" name="to" value="' . e($to) . '">';
    $html .= '<button type="submit" class="' . e($buttonClass) . '">'
        . '<i class="bi ' . e($icon) . '" aria-hidden="true"></i> ' . e($label) . '</button>';
    $html .= '</form>';

    return $html;
}

/**
 * Compact, scannable row for the Available buses board.
 *
 * @param array<string, mixed> $service
 * @param string $terminal Selected terminal (for the booking deep link).
 */
function render_departure_row(array $service, string $terminal, int $passengers = 1): string
{
    $isDemo      = (bool) $service['is_demo'];
    $availability = (string) $service['availability'];
    $seatsLeft   = (int) $service['seats_left'];
    $minutesToGo = $service['minutes_to_go'];

    $bookUrl = url('modules/search/seats.php?' . http_build_query([
        'schedule_id' => (int) ($service['schedule_id'] ?? 0),
        'passengers'  => $passengers,
        'from'        => $terminal,
        'to'          => (string) $service['destination'],
    ]));

    $html  = '<article class="departure' . ($isDemo ? ' departure--demo' : '') . ' departure--' . e($availability) . '">';

    $html .= '<div class="departure__time">';
    $html .= '<span class="departure__clock">' . e(format_time((string) $service['departure_time'])) . '</span>';
    $html .= '<span class="departure__date">' . e(format_date((string) $service['date'], 'D, d M')) . '</span>';
    $html .= '</div>';

    $html .= '<div class="departure__main">';
    $html .= '<div class="departure__route">';
    $html .= '<span class="departure__from">' . e((string) $service['origin']) . '</span>';
    $html .= '<i class="bi bi-arrow-right" aria-hidden="true"></i>';
    $html .= '<span class="departure__to">' . e((string) $service['destination']) . '</span>';
    $html .= '</div>';
    $html .= '<div class="departure__meta">';
    $html .= '<span class="chip"><i class="bi bi-bus-front" aria-hidden="true"></i> ' . e((string) $service['bus_number']) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-diagram-3" aria-hidden="true"></i> ' . e(labelize((string) $service['bus_type'])) . '</span>';
    $html .= '<span class="chip"><i class="bi bi-clock-history" aria-hidden="true"></i> Arr ' . e(format_time((string) $service['arrival_time'])) . '</span>';
    $html .= '<span class="badge-status badge-' . ($isDemo ? 'info' : 'muted') . '">' . e(bus_catalog_source_label($service)) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="departure__aside">';
    if ($seatsLeft <= 5) {
        $html .= '<span class="badge-status badge-warning">' . $seatsLeft . ' seats left</span>';
    } else {
        $html .= '<span class="badge-status badge-success">' . $seatsLeft . ' seats</span>';
    }

    if ($availability === 'scheduled' && $minutesToGo !== null && $minutesToGo >= 0) {
        $html .= '<span class="departure__eta">' . ($minutesToGo <= 60
            ? 'in ' . $minutesToGo . ' min'
            : 'in ' . format_duration($minutesToGo)) . '</span>';
    }

    if ($service['bookable']) {
        $html .= '<a class="btn btn-sm btn-primary" href="' . e($bookUrl) . '">Book</a>';
    } elseif ($isDemo && $availability === 'scheduled') {
        $html .= render_demo_booking_form(
            $service,
            $terminal,
            (string) $service['destination'],
            $passengers,
            'btn btn-sm btn-primary',
            'Book',
            'bi-ticket-perforated'
        );
    } else {
        $html .= '<span class="badge-status badge-muted">Demo</span>';
    }
    $html .= '</div>';

    $html .= '</article>';

    return $html;
}

/* ------------------------------------------------------------------
 | Page
 ------------------------------------------------------------------ */

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

$stats       = $search['stats'];
$searchTabs  = ['search', 'available'];
$searchUrl   = url('modules/search/index.php');

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
    'Search buses',
    'Find a bus by route, or see what is leaving your present terminal next',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/tickets/index.php')) . '">
        <i class="bi bi-ticket-perforated" aria-hidden="true"></i> My tickets
     </a>'
) ?>

<div class="search-tabs" role="tablist">
    <a class="search-tabs__tab<?= $tab === 'search' ? ' is-active' : '' ?>"
       href="<?= e($searchUrl . '?' . http_build_query(array_filter(['tab' => 'search', 'from' => $from, 'to' => $to, 'date' => $date, 'passengers' => $passengers > 1 ? $passengers : null]))) ?>"
       role="tab" aria-selected="<?= $tab === 'search' ? 'true' : 'false' ?>">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span>Search buses</span>
    </a>
    <a class="search-tabs__tab<?= $tab === 'available' ? ' is-active' : '' ?>"
       href="<?= e($searchUrl . '?' . http_build_query(array_filter(['tab' => 'available', 'terminal' => $terminal, 'earlier' => $earlier ? '1' : null]))) ?>"
       role="tab" aria-selected="<?= $tab === 'available' ? 'true' : 'false' ?>">
        <i class="bi bi-broadcast" aria-hidden="true"></i>
        <span>Available buses</span>
    </a>
</div>

<?php if ($tab === 'search'): ?>

    <div class="card-fl search-panel">
        <form method="get" action="<?= e($searchUrl) ?>" id="findBusForm">
            <input type="hidden" name="tab" value="search">
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

                <?php if ($search['has_search']): ?>
                    <a class="btn btn-ghost" href="<?= e($searchUrl . '?tab=search') ?>">Clear</a>
                <?php endif; ?>
            </div>

            <p class="form-text mb-0">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Type a city, terminal or stop — suggestions appear as you type. Results include demo services when a
                route has no scheduled bus yet, so the board is never empty — and any demo service can be booked just
                like a scheduled one.
            </p>
        </form>
    </div>

    <?php if ($search['has_search']): ?>
        <div class="stat-grid">
            <?= stat_card('Buses found', $stats['scheduled'], 'bi-bus-front', 'primary', $date !== '' ? 'On ' . format_date($date, 'd M Y') : 'Within the next 7 days') ?>
            <?= stat_card('Seats available', $stats['seats'], 'bi-people', 'info', 'Across bookable services') ?>
            <?= stat_card('Real departures', $stats['real'], 'bi-patch-check', 'success', $stats['demo'] . ' demo service(s) filling gaps') ?>
            <?= stat_card('Fares from', $stats['cheapest'] > 0 ? money($stats['cheapest']) : '—', 'bi-cash-coin', 'warning', 'Full route base fare') ?>
        </div>

        <?php if ($stats['total'] === 0): ?>
            <div class="card-fl">
                <?= empty_state(
                    'No buses match this search',
                    'Try a different date, or choose a nearby city or terminal. Leave the date empty to see the next seven days.',
                    'bi-search',
                    '<a class="btn btn-outline-secondary" href="' . e($searchUrl . '?tab=search') . '">Clear the search</a>'
                ) ?>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $key => $def): ?>
                <?php if (($search['groups'][$key] ?? []) === []) { continue; } ?>
                <div class="card-fl">
                    <div class="card-fl__header">
                        <div>
                            <h2 class="card-fl__title"><?= e($def['title']) ?> <span class="chip"><?= count($search['groups'][$key]) ?></span></h2>
                            <p class="card-fl__subtitle"><?= e($def['subtitle']) ?></p>
                        </div>
                    </div>
                    <div class="card-fl__body stack-sm">
                        <?php foreach ($search['groups'][$key] as $service): ?>
                            <?= render_bus_card($service, $labels, $from, $to, $passengers) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="card-fl">
            <?= empty_state(
                'Search for a bus to get started',
                'Enter where you are and where you are going — for example Kolkata to Siliguri. The date is optional; leave it blank to see the next seven days.',
                'bi-signpost-split'
            ) ?>
            <div class="empty-state__quick">
                <span class="empty-state__quick-label">Popular journeys</span>
                <?php
                $quickRoutes = [
                    ['Kolkata', 'Siliguri'],
                    ['Kolkata', 'Digha'],
                    ['Delhi', 'Jaipur'],
                    ['Mumbai', 'Pune'],
                    ['Bengaluru', 'Mysuru'],
                    ['Patna', 'Ranchi'],
                ];
                foreach ($quickRoutes as [$qFrom, $qTo]): ?>
                    <a class="quick-chip" href="<?= e($searchUrl . '?' . http_build_query(['tab' => 'search', 'from' => $qFrom, 'to' => $qTo, 'date' => date('Y-m-d')])) ?>">
                        <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                        <?= e($qFrom) ?> → <?= e($qTo) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>

    <div class="card-fl terminal-panel">
        <form method="get" action="<?= e($searchUrl) ?>" id="terminalForm">
            <input type="hidden" name="tab" value="available">
            <h2 class="search-panel__heading">Your current bus terminal</h2>
            <p class="search-panel__hint">
                Choose the terminal you are standing in and we will list every bus still to leave from here,
                soonest first — based on the current time.
            </p>

            <div class="terminal-panel__row">
                <div class="terminal-panel__field">
                    <label class="search-panel__label" for="terminal">Your current bus terminal</label>
                    <div class="search-field search-field--location" data-location-autocomplete
                         data-endpoint="<?= e(url('api/locations.php')) ?>">
                        <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                        <input type="text" class="form-control" id="terminal" name="terminal" value="<?= e($terminal) ?>"
                               placeholder="Search terminal… e.g. Kol" autocomplete="off">
                        <ul class="loc-suggest" role="listbox" aria-label="Terminal suggestions" hidden></ul>
                    </div>
                </div>

                <div class="terminal-panel__field terminal-panel__field--date">
                    <label class="search-panel__label" for="available_date">Day</label>
                    <input type="date" class="form-control" id="available_date" name="date" value="<?= e($date) ?>">
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-broadcast" aria-hidden="true"></i> Show departures
                </button>
            </div>

            <div class="terminal-panel__quick">
                <span class="search-panel__label">Quick pick</span>
                <?php foreach ($popularTerminals as $city): ?>
                    <a class="quick-chip<?= bus_catalog_place_token($city) === bus_catalog_place_token($terminal) ? ' is-active' : '' ?>"
                       href="<?= e($searchUrl . '?' . http_build_query(['tab' => 'available', 'terminal' => $city])) ?>">
                        <?= e($city) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <?php if ($available === null): ?>
        <div class="card-fl">
            <?= empty_state(
                'Pick your current terminal',
                'Search for a terminal above or tap one of the quick picks. Typing “Kol” will suggest Kolkata, Kolkata Esplanade and more.',
                'bi-geo-alt'
            ) ?>
        </div>
    <?php else: ?>
        <div class="stat-grid">
            <?= stat_card('Upcoming departures', $available['stats']['total'], 'bi-clock', 'primary', 'From ' . $terminal) ?>
            <?= stat_card('Destinations', $available['stats']['destinations'], 'bi-signpost-2', 'info', 'Reachable from here') ?>
            <?= stat_card(
                'Next departure',
                $available['stats']['next'] !== null ? format_time((string) $available['stats']['next']['departure_time']) : '—',
                'bi-bus-front',
                'success',
                $available['stats']['next'] !== null ? 'To ' . $available['stats']['next']['destination'] : 'Nothing left today'
            ) ?>
            <?= stat_card('On the board', $available['stats']['demo'] . ' demo', 'bi-info-circle', 'warning', $available['stats']['real'] . ' scheduled service(s)') ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">
                        Departing from <?= e($terminal) ?>
                        <span class="chip"><?= count($available['services']) ?></span>
                    </h2>
                    <p class="card-fl__subtitle">
                        Current time <?= e(date('h:i A')) ?> · upcoming buses in chronological order
                        <?= $date !== '' ? ' (on ' . e(format_date($date, 'D, d M Y')) . ')' : '' ?>
                    </p>
                </div>
                <?php if ($available['stats']['earlier'] > 0 || $earlier): ?>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= e($searchUrl . '?' . http_build_query(['tab' => 'available', 'terminal' => $terminal, 'earlier' => $earlier ? null : '1'])) ?>">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                        <?= $earlier ? 'Hide earlier buses' : 'Earlier buses (' . $available['stats']['earlier'] . ')' ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="card-fl__body">
                <?php if ($available['services'] === []): ?>
                    <?= empty_state(
                        'No more buses leave later today',
                        'Every service from ' . $terminal . ' has departed. Turn on “Earlier buses” to review the day, or pick another terminal.',
                        'bi-moon-stars'
                    ) ?>
                <?php else: ?>
                    <div class="departure-board">
                        <?php foreach ($available['services'] as $service): ?>
                            <?= render_departure_row($service, $terminal, $passengers) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($earlier && $available['earlier'] !== []): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Earlier buses <span class="chip"><?= count($available['earlier']) ?></span></h2>
                        <p class="card-fl__subtitle">Already departed from <?= e($terminal) ?> today (most recent first)</p>
                    </div>
                </div>
                <div class="card-fl__body">
                    <div class="departure-board departure-board--muted">
                        <?php foreach ($available['earlier'] as $service): ?>
                            <?= render_departure_row($service, $terminal, $passengers) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
