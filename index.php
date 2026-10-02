<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * index.php — public landing page
 *
 * Signed-in users are sent straight to their role dashboard. Everyone else
 * gets the Fleetra landing page: a product introduction, a live passenger
 * search that leads into the booking flow, and the demo accounts so the
 * system can be reviewed quickly.
 *
 * The hero search is a real search: it opens the passenger search page, and
 * a guest is asked to sign in first, then returned to their results.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

if (is_logged_in()) {
    check_session_validity();
    redirect(dashboard_path(current_role()));
}

/* ------------------------------------------------------------------
 | Live figures for the landing page.
 |
 | The landing page must still render before the database has been
 | imported, so every figure is only read when the database answers.
 ------------------------------------------------------------------ */

$hasDatabase = db_available();

$fleetCount     = null;
$routeCount     = null;
$stopsCount     = null;
$passengerCount = null;
$tripsOperated  = null;
$popularRoutes  = [];

if ($hasDatabase) {
    $fleetCount     = (int) db_value("SELECT COUNT(*) FROM buses WHERE status <> 'inactive'", [], 0);
    $routeCount     = (int) db_value("SELECT COUNT(*) FROM routes WHERE status = 'active'", [], 0);
    $stopsCount     = (int) db_value('SELECT COUNT(*) FROM stops', [], 0);
    $passengerCount = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'passenger'", [], 0);
    $tripsOperated  = (int) db_value("SELECT COUNT(*) FROM trips WHERE trip_status = 'completed'", [], 0);

    $popularRoutes = db_all(
        "SELECT r.route_code, r.route_name, r.source, r.destination, r.distance,
                r.estimated_duration, r.base_fare,
                (SELECT COUNT(*) FROM stops st WHERE st.route_id = r.id) AS stop_count,
                (SELECT COUNT(*) FROM bookings b
                   JOIN schedules s ON s.id = b.schedule_id
                  WHERE s.route_id = r.id AND b.booking_status IN ('confirmed','completed')) AS bookings
           FROM routes r
          WHERE r.status = 'active'
          GROUP BY r.id, r.route_code, r.route_name, r.source, r.destination, r.distance, r.estimated_duration, r.base_fare
          ORDER BY bookings DESC, r.route_code
          LIMIT 3"
    );
}

/** Statistic strip, only for figures the database could actually supply. */
$heroStats = [];

if ($fleetCount !== null) {
    $heroStats = [
        ['value' => number_format($fleetCount),     'label' => 'Buses in the fleet'],
        ['value' => number_format($routeCount),     'label' => 'Active routes'],
        ['value' => number_format($stopsCount),     'label' => 'Mapped stops'],
        ['value' => number_format($tripsOperated),  'label' => 'Trips operated'],
    ];
}

$features = [
    [
        'icon'  => 'bi-bus-front',
        'title' => 'Fleet and driver records',
        'text'  => 'Vehicle specifications, odometer readings, licences and employment records kept in one register.',
        'bullets' => ['Bus status: active, workshop, retired', 'Licence expiry tracking', 'Bus-to-driver assignment'],
    ],
    [
        'icon'  => 'bi-calendar3',
        'title' => 'Conflict-free scheduling',
        'text'  => 'A departure is validated against real overlap rules before it is ever saved.',
        'bullets' => ['No double-booked bus or driver', 'Only serviceable vehicles', 'Trips created automatically'],
    ],
    [
        'icon'  => 'bi-person-badge',
        'title' => 'Seat booking and tickets',
        'text'  => 'Passengers search a route, pick seats on a live seat map and receive a printable ticket.',
        'bullets' => ['Pro-rated fares by journey leg', 'Seat locking at the database level', 'QR verification at the gate'],
    ],
    [
        'icon'  => 'bi-clipboard-check',
        'title' => 'Daily operations',
        'text'  => 'Drivers start, delay and complete services while dispatch works from a single board.',
        'bullets' => ['Trip status workflow', 'Delay and incident reporting', 'Boarding and passenger counts'],
    ],
    [
        'icon'  => 'bi-tools',
        'title'  => 'Maintenance and incidents',
        'text'  => 'Workshop jobs hold a bus out of service automatically until the work is completed.',
        'bullets' => ['Service diary and due dates', 'Cost tracking per bus', 'Severity-based incident alerts'],
    ],
    [
        'icon'  => 'bi-bar-chart-line',
        'title' => 'Reporting you can export',
        'text'  => 'Eight operational reports share one filter bar and export to CSV exactly as displayed.',
        'bullets' => ['Utilisation and load factor', 'Revenue and refunds', 'Delay analysis by route'],
    ],
];

$roles = [
    ['bi-shield-lock',  'Administrator',     'Full control: users, roles, settings, audit trail and every module.'],
    ['bi-clipboard-data', 'Transport Manager', 'Runs the operation: fleet, drivers, routes, schedules and maintenance.'],
    ['bi-headset',      'Dispatcher',        'Owns the dispatch board: assigns vehicles, updates trips and reports incidents.'],
    ['bi-steering-wheel', 'Driver',          'A simple duty sheet: today\'s trips, route, passengers and status updates.'],
    ['bi-person',       'Passenger',         'Searches buses, books seats and prints or downloads tickets.'],
];

$demoAccounts = [
    ['Administrator',     'admin@fleetra.com'],
    ['Transport Manager', 'manager@fleetra.com'],
    ['Dispatcher',        'dispatcher@fleetra.com'],
    ['Driver',            'driver1@fleetra.com'],
    ['Passenger',         'passenger@fleetra.com'],
];

$pageTitle = FLEETRA_NAME . ' · ' . FLEETRA_TAGLINE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Fleetra is a smart bus and transport management system: fleet, drivers, scheduling, bookings, tickets, maintenance and reporting in one console.">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?></title>

    <?php /* Vendor assets are served locally so the landing page works offline. */ ?>
    <link href="<?= e(asset('vendor/fonts/inter.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/responsive.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/landing.css')) ?>" rel="stylesheet">
</head>
<body class="lp">

<header class="lp-nav" id="landingNav">
    <div class="lp-container lp-nav__inner">
        <a class="lp-nav__brand" href="<?= e(url('index.php')) ?>"
           aria-label="<?= e(FLEETRA_NAME) ?> — <?= e(FLEETRA_TAGLINE) ?>">
            <img class="lp-nav__logo" src="<?= e(asset('images/logo.png')) ?>"
                 alt="<?= e(FLEETRA_NAME) ?>" width="101" height="32" decoding="async">
        </a>

        <ul class="lp-nav__links" id="landingLinks">
            <li><a class="lp-nav__link" href="#features">Platform</a></li>
            <li><a class="lp-nav__link" href="#how">How it works</a></li>
            <?php if ($popularRoutes !== []): ?>
                <li><a class="lp-nav__link" href="#routes">Routes</a></li>
            <?php endif; ?>
            <li><a class="lp-nav__link" href="#roles">Roles</a></li>
            <li><a class="lp-nav__link" href="#faq">FAQ</a></li>
        </ul>

        <div class="lp-nav__actions">
            <a class="lp-btn lp-btn--ghost" href="<?= e(url('login.php')) ?>">Sign in</a>
            <a class="lp-btn lp-btn--primary" href="<?= e(url('register.php')) ?>">Create account</a>
            <button type="button" class="lp-nav__toggle" id="landingToggle"
                    aria-label="Toggle navigation" aria-expanded="false" aria-controls="landingLinks">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</header>

<main>
    <!-- ============================ Hero ============================ -->
    <section class="lp-hero">
        <div class="lp-container lp-hero__inner">
            <div>
                <span class="lp-eyebrow">
                    <i class="bi bi-broadcast-pin" aria-hidden="true"></i>
                    Built for bus and intercity transport operators
                </span>

                <h1 class="lp-hero__title">
                    Run every bus, route and booking from <em>one console</em>.
                </h1>

                <p class="lp-hero__text">
                    Fleetra connects your fleet, drivers, schedules, passengers and workshop into a single
                    operations platform — so nothing is planned on a whiteboard or tracked in a spreadsheet.
                </p>

                <form class="lp-search" method="get" action="<?= e(url('modules/search/index.php')) ?>">
                    <div class="lp-search__fields">
                        <div class="lp-search__field" data-location-autocomplete
                             data-endpoint="<?= e(url('api/locations.php')) ?>">
                            <label class="lp-search__label" for="hero_from">From</label>
                            <input class="lp-search__input" type="text" id="hero_from" name="from"
                                   placeholder="City, terminal or stop" autocomplete="off">
                            <ul class="loc-suggest" role="listbox" aria-label="Boarding location suggestions" hidden></ul>
                        </div>

                        <div class="lp-search__field" data-location-autocomplete
                             data-endpoint="<?= e(url('api/locations.php')) ?>">
                            <label class="lp-search__label" for="hero_to">To</label>
                            <input class="lp-search__input" type="text" id="hero_to" name="to"
                                   placeholder="City, terminal or stop" autocomplete="off">
                            <ul class="loc-suggest" role="listbox" aria-label="Destination suggestions" hidden></ul>
                        </div>

                        <div class="lp-search__field">
                            <label class="lp-search__label" for="hero_date">Travel date</label>
                            <input class="lp-search__input" type="date" id="hero_date" name="date"
                                   value="<?= e(date('Y-m-d')) ?>" min="<?= e(date('Y-m-d')) ?>">
                        </div>

                        <button type="submit" class="lp-search__submit">
                            <i class="bi bi-search" aria-hidden="true"></i> Search buses and seats
                        </button>
                    </div>
                </form>

                <p class="lp-hero__note">
                    Searching opens the live passenger booking flow — sign in with a passenger account to reserve a seat.
                </p>

                <ul class="lp-hero__trust">
                    <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Five role dashboards</li>
                    <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Seat-locking that cannot double-book</li>
                    <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Works offline, no external services</li>
                </ul>
            </div>

            <!-- Decorative interface preview (no data, purely illustrative). -->
            <div class="lp-preview" aria-hidden="true">
                <div class="lp-mock">
                    <div class="lp-mock__bar">
                        <span class="lp-mock__dot"></span>
                        <span class="lp-mock__dot"></span>
                        <span class="lp-mock__dot"></span>
                        <span class="lp-mock__title">Fleetra · Operations overview</span>
                    </div>

                    <div class="lp-mock__body">
                        <div class="lp-mock__tiles">
                            <div class="lp-mock__tile">
                                <span class="lp-mock__tile-label">Fleet</span>
                                <p class="lp-mock__tile-value">24 <small>active</small></p>
                            </div>
                            <div class="lp-mock__tile">
                                <span class="lp-mock__tile-label">Trips today</span>
                                <p class="lp-mock__tile-value">31</p>
                            </div>
                            <div class="lp-mock__tile">
                                <span class="lp-mock__tile-label">Load factor</span>
                                <p class="lp-mock__tile-value">78%</p>
                            </div>
                        </div>

                        <div class="lp-mock__panel">
                            <div class="lp-mock__panel-head">
                                <span class="lp-mock__panel-title">Trips operated</span>
                                <span class="lp-mock__panel-tag">Last 7 days</span>
                            </div>

                            <div class="lp-mock__bars">
                                <?php foreach ([42, 58, 46, 70, 64, 82, 74] as $index => $height): ?>
                                    <div class="lp-mock__bar-col">
                                        <div class="lp-mock__bar-track">
                                            <div class="lp-mock__bar-fill" style="height: <?= (int) $height ?>%;"></div>
                                        </div>
                                        <span class="lp-mock__bar-label"><?= e(date('D', strtotime('-' . (6 - $index) . ' day'))) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="lp-mock__panel">
                            <div class="lp-mock__panel-head">
                                <span class="lp-mock__panel-title">Today's services</span>
                                <span class="lp-mock__panel-tag">Live</span>
                            </div>

                            <div class="lp-mock__rows">
                                <div class="lp-mock__row">
                                    <span>
                                        <span class="lp-mock__row-name">R-01 · City Express</span><br>
                                        <span class="lp-mock__row-meta">FLT-101 · 06:30 departure</span>
                                    </span>
                                    <span class="lp-mock__pill lp-mock__pill--running">Running</span>
                                </div>
                                <div class="lp-mock__row">
                                    <span>
                                        <span class="lp-mock__row-name">R-02 · Coastal Link</span><br>
                                        <span class="lp-mock__row-meta">FLT-103 · 08:00 departure</span>
                                    </span>
                                    <span class="lp-mock__pill lp-mock__pill--done">Completed</span>
                                </div>
                                <div class="lp-mock__row">
                                    <span>
                                        <span class="lp-mock__row-name">R-03 · Airport Shuttle</span><br>
                                        <span class="lp-mock__row-meta">FLT-102 · 11:15 departure</span>
                                    </span>
                                    <span class="lp-mock__pill lp-mock__pill--late">+12 min</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="lp-mock__caption">Illustrative preview of the Fleetra operations console.</p>
            </div>
        </div>
    </section>

    <!-- ========================== Metrics ========================== -->
    <?php if ($heroStats !== []): ?>
        <section class="lp-stats">
            <div class="lp-container lp-stats__grid">
                <?php foreach ($heroStats as $stat): ?>
                    <div class="lp-stat">
                        <p class="lp-stat__value"><?= e($stat['value']) ?></p>
                        <p class="lp-stat__label"><?= e($stat['label']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- ========================== Features ========================== -->
    <section class="lp-section" id="features">
        <div class="lp-container">
            <div class="lp-section__head">
                <p class="lp-eyebrow-dark">The platform</p>
                <h2 class="lp-title">Everything a transport operation actually needs</h2>
                <p class="lp-text">
                    Not a CRUD demo — Fleetra enforces the rules that keep a real fleet safe: no double-booked
                    buses, no expired licences on the road, no seat sold twice.
                </p>
            </div>

            <div class="lp-grid-3">
                <?php foreach ($features as $feature): ?>
                    <article class="lp-card lp-reveal">
                        <span class="lp-card__icon"><i class="bi <?= e($feature['icon']) ?>" aria-hidden="true"></i></span>
                        <h3 class="lp-card__title"><?= e($feature['title']) ?></h3>
                        <p class="lp-card__text"><?= e($feature['text']) ?></p>
                        <ul class="lp-list">
                            <?php foreach ($feature['bullets'] as $bullet): ?>
                                <li>
                                    <i class="bi bi-check2" aria-hidden="true"></i>
                                    <span><?= e($bullet) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- =========================== Routes =========================== -->
    <?php if ($popularRoutes !== []): ?>
        <section class="lp-section lp-section--soft" id="routes">
            <div class="lp-container">
                <div class="lp-section__head">
                    <p class="lp-eyebrow-dark">Scheduled network</p>
                    <h2 class="lp-title">Routes passengers can book today</h2>
                    <p class="lp-text">
                        Fares are pro-rated to the leg travelled, so a part journey never costs the full route price.
                    </p>
                </div>

                <div class="lp-routes">
                    <?php foreach ($popularRoutes as $route): ?>
                        <article class="lp-route lp-reveal">
                            <div class="lp-route__visual">
                                <span class="lp-route__code"><?= e($route['route_code']) ?></span>
                                <i class="bi bi-bus-front" aria-hidden="true"></i>
                            </div>

                            <div class="lp-route__body">
                                <h3 class="lp-route__name"><?= e($route['route_name']) ?></h3>
                                <p class="lp-route__path">
                                    <?= e($route['source']) ?> &rarr; <?= e($route['destination']) ?>
                                </p>

                                <div class="lp-route__facts">
                                    <span class="lp-route__fact">
                                        <strong><?= e(number_format((float) $route['distance'], 1)) ?> km</strong>
                                        Distance
                                    </span>
                                    <span class="lp-route__fact">
                                        <strong><?= e(format_duration((int) $route['estimated_duration'])) ?></strong>
                                        Journey time
                                    </span>
                                    <span class="lp-route__fact">
                                        <strong><?= e(money($route['base_fare'])) ?></strong>
                                        From (full route)
                                    </span>
                                    <span class="lp-route__fact">
                                        <strong><?= (int) $route['stop_count'] ?> stops</strong>
                                        Boarding points
                                    </span>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- ========================= How it works ========================= -->
    <section class="lp-section" id="how">
        <div class="lp-container">
            <div class="lp-section__head">
                <p class="lp-eyebrow-dark">How booking works</p>
                <h2 class="lp-title">From search to boarding in four steps</h2>
                <p class="lp-text">
                    A passenger never has to phone the depot. Finding a service, choosing a seat, paying and
                    boarding all run through Fleetra — on any device.
                </p>
            </div>

            <div class="lp-steps">
                <article class="lp-step lp-reveal">
                    <span class="lp-step__num">1</span>
                    <h3 class="lp-step__title">Search a route</h3>
                    <p class="lp-step__text">
                        Enter a boarding point, destination and travel date. Fleetra lists every serviceable bus
                        with live seat availability.
                    </p>
                </article>

                <article class="lp-step lp-reveal">
                    <span class="lp-step__num">2</span>
                    <h3 class="lp-step__title">Pick a seat</h3>
                    <p class="lp-step__text">
                        Choose a seat on the live seat map. The seat is locked at the database level, so it can
                        never be sold to two passengers.
                    </p>
                </article>

                <article class="lp-step lp-reveal">
                    <span class="lp-step__num">3</span>
                    <h3 class="lp-step__title">Pay and get a ticket</h3>
                    <p class="lp-step__text">
                        Fares are pro-rated to the leg travelled. The confirmation issues a printable e-ticket
                        with a scannable QR code.
                    </p>
                </article>

                <article class="lp-step lp-reveal">
                    <span class="lp-step__num">4</span>
                    <h3 class="lp-step__title">Board with a scan</h3>
                    <p class="lp-step__text">
                        Staff validate the QR code at the gate. The ticket is marked used and the passenger count
                        updates on the dispatch board.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- ============================ Roles ============================ -->
    <section class="lp-section" id="roles">
        <div class="lp-container">
            <div class="lp-section__head">
                <p class="lp-eyebrow-dark">Five roles, one system</p>
                <h2 class="lp-title">Everyone sees exactly what their job needs</h2>
                <p class="lp-text">
                    Access is enforced on the server for every page and every action — a passenger can never reach
                    an administration screen by editing a URL.
                </p>
            </div>

            <div class="lp-roles">
                <?php foreach ($roles as [$icon, $name, $text]): ?>
                    <article class="lp-role lp-reveal">
                        <span class="lp-role__icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></span>
                        <h3 class="lp-role__name"><?= e($name) ?></h3>
                        <p class="lp-role__text"><?= e($text) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======================== Demo accounts ======================== -->
    <?php if (FLEETRA_SHOW_DEMO_CREDENTIALS && $hasDatabase): ?>
        <section class="lp-section lp-section--soft" id="demo">
            <div class="lp-container">
                <div class="lp-demo">
                    <p class="lp-eyebrow-dark">Local demonstration build</p>
                    <h2 class="lp-title">Try Fleetra with a demo account</h2>
                    <p class="lp-text">
                        Every account below uses the password <code>Fleetra@123</code>. This panel only appears while
                        the application is running in local development mode.
                    </p>

                    <div class="lp-demo__grid">
                        <?php foreach ($demoAccounts as [$role, $mail]): ?>
                            <div class="lp-demo__row">
                                <span>
                                    <span class="lp-demo__role"><?= e($role) ?></span><br>
                                    <span class="lp-demo__mail"><?= e($mail) ?></span>
                                </span>
                                <a class="lp-demo__fill" href="<?= e(url('login.php')) ?>">Sign in</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- ============================= FAQ ============================= -->
    <section class="lp-section" id="faq">
        <div class="lp-container">
            <div class="lp-section__head">
                <p class="lp-eyebrow-dark">Common questions</p>
                <h2 class="lp-title">Answers before you start</h2>
                <p class="lp-text">
                    Everything below reflects how Fleetra actually behaves — no marketing promises the code does
                    not keep.
                </p>
            </div>

            <div class="lp-faq">
                <details>
                    <summary>
                        <span>Does booking a part journey cost the full route fare?</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <p class="lp-faq__answer">
                        No. Fares are pro-rated to the leg travelled, so boarding and alighting part-way is charged
                        only for the distance covered, plus the configured base fare.
                    </p>
                </details>

                <details>
                    <summary>
                        <span>What stops two passengers taking the same seat?</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <p class="lp-faq__answer">
                        A seat is reserved with a unique constraint in the database, not just a hidden button. A
                        second attempt on the same seat is rejected server-side, so an oversold coach is impossible.
                    </p>
                </details>

                <details>
                    <summary>
                        <span>Can a bus or driver be assigned to two trips at once?</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <p class="lp-faq__answer">
                        Never. Every departure is validated against real overlap rules and the vehicle's serviceable
                        status before it is saved, so conflicting assignments cannot reach the schedule.
                    </p>
                </details>

                <details>
                    <summary>
                        <span>Who gets access to which screens?</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <p class="lp-faq__answer">
                        Fleetra has five roles — administrator, transport manager, dispatcher, driver and passenger.
                        Access is enforced on the server for every page and action, so editing a URL never unlocks a
                        screen a role should not see.
                    </p>
                </details>

                <details>
                    <summary>
                        <span>Does Fleetra need an internet connection or paid services?</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <p class="lp-faq__answer">
                        No. Vendor assets such as fonts and icons are served locally, so the whole console runs on a
                        normal PHP and MySQL host — including a laptop running XAMPP with no connection at all.
                    </p>
                </details>
            </div>
        </div>
    </section>

    <!-- ============================= CTA ============================= -->
    <section class="lp-section">
        <div class="lp-container">
            <div class="lp-cta">
                <h2 class="lp-cta__title">Ready to move your operation onto one console?</h2>
                <p class="lp-cta__text">
                    Create a passenger account to book a seat, or sign in as an operator to open the
                    dashboard, dispatch board and reporting suite.
                </p>

                <div class="lp-cta__actions">
                    <a class="lp-btn lp-btn--primary lp-btn--lg" href="<?= e(url('register.php')) ?>">
                        <i class="bi bi-person-plus" aria-hidden="true"></i> Create a passenger account
                    </a>
                    <a class="lp-btn lp-btn--light lp-btn--lg" href="<?= e(url('login.php')) ?>">
                        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign in to Fleetra
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="lp-footer">
    <div class="lp-container lp-footer__inner">
        <div class="lp-footer__brand">
            <img class="lp-footer__logo" src="<?= e(asset('images/logo.png')) ?>"
                 alt="<?= e(FLEETRA_NAME) ?>" width="95" height="30" decoding="async">
            <p>
                <?= e(FLEETRA_TAGLINE) ?> — fleet, drivers, schedules, bookings, maintenance and reporting
                brought together in one console.
            </p>
        </div>

        <nav class="lp-footer__nav" aria-label="Footer">
            <div class="lp-footer__col">
                <h4>Platform</h4>
                <ul>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#how">How it works</a></li>
                    <li><a href="#roles">Roles</a></li>
                    <?php if ($popularRoutes !== []): ?>
                        <li><a href="#routes">Routes</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="lp-footer__col">
                <h4>Support</h4>
                <ul>
                    <li><a href="#faq">FAQ</a></li>
                    <?php if (FLEETRA_SHOW_DEMO_CREDENTIALS): ?>
                        <li><a href="#demo">Demo accounts</a></li>
                    <?php endif; ?>
                    <li><a href="<?= e(url('login.php')) ?>">Sign in</a></li>
                    <li><a href="<?= e(url('register.php')) ?>">Create account</a></li>
                </ul>
            </div>
        </nav>
    </div>

    <div class="lp-container lp-footer__bottom">
        <span>&copy; <?= date('Y') ?> <?= e(FLEETRA_NAME) ?> &middot; <?= e(FLEETRA_TAGLINE) ?></span>
        <span>Version <?= e(FLEETRA_VERSION) ?> &middot; Local demonstration build</span>
    </div>
</footer>

<script>
/* Landing page behaviour: sticky navigation, mobile menu and smooth anchor
   scrolling. Nothing here is a security or business boundary. */
document.addEventListener('DOMContentLoaded', function () {
    var nav = document.getElementById('landingNav');
    var toggle = document.getElementById('landingToggle');
    var links = document.getElementById('landingLinks');

    function onScroll() {
        if (!nav) {
            return;
        }

        nav.classList.toggle('is-scrolled', window.scrollY > 24);
    }

    if (nav) {
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    if (toggle && links) {
        toggle.addEventListener('click', function () {
            var open = links.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        links.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                links.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (event) {
            var target = document.querySelector(anchor.getAttribute('href'));

            if (!target) {
                return;
            }

            event.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    /* Reveal cards as they scroll into view. The hidden state is only applied
       once this runs, so the page stays readable with scripts disabled. */
    var revealItems = document.querySelectorAll('.lp-reveal');

    if (revealItems.length && 'IntersectionObserver' in window) {
        document.documentElement.classList.add('lp-reveal-ready');

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.1 });

        revealItems.forEach(function (item) {
            observer.observe(item);
        });
    }
});
</script>

</body>
</html>
