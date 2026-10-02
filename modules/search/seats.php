<?php
/**
 * Fleetra — Passenger services / Select seats
 * ------------------------------------------------------------------
 * modules/search/seats.php
 *
 * The seat picker for one departure. Seats already booked are shown as
 * taken and cannot be submitted: the same status list the database uses
 * for its seat_lock column decides what is free, so a seat offered here is
 * a seat that can actually be sold.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../bookings/_logic.php';

require_permission('bookings.create');

$scheduleId    = get_int('schedule_id');
$passengers    = max(1, min(6, get_int('passengers', 1, 1)));
$fromTerm      = get('from');
$toTerm        = get('to');
$boardingId    = get_int('boarding_stop_id');
$destinationId = get_int('destination_stop_id');

// Seats ticked before the stops were changed, so a reload keeps them.
$preSelected = array_values(array_filter(array_map(
    'strtoupper',
    array_map('trim', explode(',', get('seats')))
)));

if ($scheduleId <= 0) {
    abort_not_found('No departure was selected.');
}

$service = db_one(
    'SELECT s.id, s.schedule_date, s.departure_time, s.arrival_time, s.status,
            r.id AS route_id, r.route_code, r.route_name, r.source, r.destination,
            r.distance, r.estimated_duration, r.base_fare,
            b.bus_number, b.bus_type, b.capacity, b.registration_number,
            u.name AS driver_name, d.id AS driver_id
       FROM schedules s
       JOIN routes r  ON r.id = s.route_id
       JOIN buses b   ON b.id = s.bus_id
       JOIN drivers d ON d.id = s.driver_id
       JOIN users u   ON u.id = d.user_id
      WHERE s.id = ?
      LIMIT 1',
    [$scheduleId]
);

if ($service === null) {
    abort_not_found('That departure does not exist.');
}

$departureTs = strtotime((string) $service['schedule_date'] . ' ' . (string) $service['departure_time']);

if ((string) $service['status'] === 'cancelled' || $departureTs === false || $departureTs <= time()) {
    flash('warning', 'That departure is no longer available for booking.');
    redirect('modules/search/index.php');
}

$capacity = (int) $service['capacity'];
$stops    = db_all(
    'SELECT id, stop_name, stop_order, arrival_offset FROM stops WHERE route_id = ? ORDER BY stop_order',
    [(int) $service['route_id']]
);

/* ------------------------------------------------------------------
 | Boarding and destination stops
 ------------------------------------------------------------------ */

$boardingStop    = $stops[0] ?? null;
$destinationStop = $stops === [] ? null : $stops[count($stops) - 1];

if ($boardingId > 0) {
    foreach ($stops as $stop) {
        if ((int) $stop['id'] === $boardingId) {
            $boardingStop = $stop;
            break;
        }
    }
} else {
    foreach ($stops as $stop) {
        if ($fromTerm !== '' && mb_stripos((string) $stop['stop_name'], $fromTerm) !== false) {
            $boardingStop = $stop;
            break;
        }
    }
}

if ($destinationId > 0) {
    foreach ($stops as $stop) {
        if ((int) $stop['id'] === $destinationId) {
            $destinationStop = $stop;
            break;
        }
    }
} else {
    foreach ($stops as $stop) {
        if ($toTerm !== '' && mb_stripos((string) $stop['stop_name'], $toTerm) !== false) {
            $destinationStop = $stop;
        }
    }
}

// A journey must move forward along the route.
if ($boardingStop !== null && $destinationStop !== null
    && (int) $destinationStop['arrival_offset'] <= (int) $boardingStop['arrival_offset']
) {
    $destinationStop = $stops[count($stops) - 1];
}

// The price of this departure, not the route: it varies by seat type and
// time of day, exactly as the search card advertised.
$scheduleFare = fleetra_fare_for(
    (float) $service['base_fare'],
    (string) $service['bus_type'],
    (string) $service['departure_time']
);

$journey = resolve_journey(
    (int) $service['route_id'],
    $scheduleFare,
    $boardingStop !== null ? (int) $boardingStop['id'] : null,
    $destinationStop !== null ? (int) $destinationStop['id'] : null,
    (int) $service['estimated_duration']
);

$taken      = booked_seat_map($scheduleId);
$layout     = seat_layout($capacity);
$freeSeats  = available_seats($scheduleId, $capacity);
$seatFare   = $journey['fare'];

if (count($freeSeats) === 0) {
    flash('warning', 'Every seat on that departure has been sold. Please choose another service.');
    redirect('modules/search/index.php');
}

$passengers = min($passengers, count($freeSeats));

$page_title       = 'Select seats';
$active_nav       = 'search';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Search buses', 'url' => url('modules/search/index.php')],
    ['label' => 'Select seats'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $service['route_code'] . ' · ' . $service['route_name'],
    format_date((string) $service['schedule_date'], 'D, d M Y') . ' · '
        . time_range_label((string) $service['departure_time'], (string) $service['arrival_time'])
        . ' · ' . $service['bus_number'],
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/search/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to search
     </a>'
) ?>

<form method="post" action="<?= e(url('modules/bookings/create.php')) ?>"
      data-seat-picker data-max-seats="<?= $passengers ?>" data-currency="<?= e(APP_CURRENCY) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
    <input type="hidden" name="route_minutes" value="<?= (int) $service['estimated_duration'] ?>">

    <div class="grid-main-side">
        <div>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Choose your seats</h2>
                        <p class="card-fl__subtitle">
                            Seat <?= $passengers === 1 ? '1 seat' : 'up to ' . $passengers . ' seats' ?>
                            · <?= count($freeSeats) ?> of <?= $capacity ?> still free
                        </p>
                    </div>
                    <?= status_badge('scheduled', 'On sale') ?>
                </div>

                <div class="card-fl__body">
                    <div class="seat-map">
                        <div class="seat-map__front">
                            <i class="bi bi-steering-wheel" aria-hidden="true"></i> Front of the bus
                        </div>

                        <?php foreach ($layout as $rowNumber => $row): ?>
                            <div class="seat-row">
                                <span class="seat-row__label"><?= $rowNumber + 1 ?></span>

                                <?php foreach ($row as $index => $seat): ?>
                                    <?php
                                    $isTaken = isset($taken[$seat]);
                                    $inputId = 'seat-' . strtolower($seat);
                                    ?>
                                    <label class="seat<?= $isTaken ? ' seat--taken' : '' ?>" for="<?= e($inputId) ?>">
                                        <input type="checkbox" id="<?= e($inputId) ?>"
                                               name="seat_numbers[]" value="<?= e($seat) ?>"
                                               data-fare="<?= e(number_format($seatFare, 2, '.', '')) ?>"
                                               data-seat="<?= e($seat) ?>"
                                               <?= $isTaken ? 'disabled data-taken="1"' : '' ?>
                                               <?= !$isTaken && in_array($seat, $preSelected, true) ? 'checked' : '' ?>>
                                        <?= e($seat) ?>
                                    </label>

                                    <?php if ($index === 1): ?>
                                        <span class="seat-row__aisle" aria-hidden="true"></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <span class="seat-row__label"><?= $rowNumber + 1 ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <ul class="seat-legend">
                        <li class="seat-legend__item">
                            <span class="seat-legend__swatch seat-legend__swatch--free"></span> Available
                        </li>
                        <li class="seat-legend__item">
                            <span class="seat-legend__swatch seat-legend__swatch--selected"></span> Selected
                        </li>
                        <li class="seat-legend__item">
                            <span class="seat-legend__swatch seat-legend__swatch--taken"></span> Already booked
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Where you board and get off</h2>
                        <p class="card-fl__subtitle">The fare is pro-rated to the part of the route you travel</p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <div class="form-row">
                        <div>
                            <label class="form-label" for="boarding_stop_id">Boarding stop <span class="req">*</span></label>
                            <select class="form-select" id="boarding_stop_id" name="boarding_stop_id" required>
                                <?php foreach ($stops as $stop): ?>
                                    <option value="<?= (int) $stop['id'] ?>"
                                        <?= $boardingStop !== null && (int) $stop['id'] === (int) $boardingStop['id'] ? 'selected' : '' ?>>
                                        <?= e($stop['stop_order'] . '. ' . $stop['stop_name']) ?>
                                        <?= (int) $stop['arrival_offset'] > 0 ? '(+' . (int) $stop['arrival_offset'] . ' min)' : '(departure)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="destination_stop_id">Destination <span class="req">*</span></label>
                            <select class="form-select" id="destination_stop_id" name="destination_stop_id" required>
                                <?php foreach ($stops as $stop): ?>
                                    <option value="<?= (int) $stop['id'] ?>"
                                        <?= $destinationStop !== null && (int) $stop['id'] === (int) $destinationStop['id'] ? 'selected' : '' ?>>
                                        <?= e($stop['stop_order'] . '. ' . $stop['stop_name']) ?>
                                        <?= (int) $stop['arrival_offset'] > 0 ? '(+' . (int) $stop['arrival_offset'] . ' min)' : '(departure)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="span-2">
                            <label class="form-label" for="payment_method">Payment</label>
                            <select class="form-select" id="payment_method" name="payment_method">
                                <option value="simulated">Pay now — simulated card/UPI</option>
                                <option value="cash">Pay the conductor at boarding (cash)</option>
                            </select>
                            <p class="form-text">
                                Fleetra runs without a live payment gateway, so online payment is simulated and
                                recorded against the booking. Cash bookings stay unpaid until collected.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Journey summary</h2>
                        <p class="card-fl__subtitle">Check before you confirm</p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <ul class="fact-list">
                        <li>
                            <span class="fact-list__label">Route</span>
                            <span class="fact-list__value"><?= e($service['route_code']) ?> · <?= e($service['route_name']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Date</span>
                            <span class="fact-list__value"><?= e(format_date((string) $service['schedule_date'], 'D, d M Y')) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Departs</span>
                            <span class="fact-list__value"><?= e(format_time((string) $service['departure_time'])) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Arrives</span>
                            <span class="fact-list__value"><?= e(format_time((string) $service['arrival_time'])) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Bus</span>
                            <span class="fact-list__value">
                                <?= e($service['bus_number']) ?> · <?= e(labelize((string) $service['bus_type'])) ?>
                            </span>
                        </li>
                        <li>
                            <span class="fact-list__label">Driver</span>
                            <span class="fact-list__value"><?= e($service['driver_name']) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Fare per seat</span>
                            <span class="fact-list__value"><?= e(money($seatFare)) ?></span>
                        </li>
                        <li>
                            <span class="fact-list__label">Seats selected</span>
                            <span class="fact-list__value" data-seat-count>0</span>
                        </li>
                    </ul>

                    <div class="divider"></div>

                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="fact-list__label">Total payable</span>
                        <span class="stat-card__value" data-seat-total><?= e(money(0)) ?></span>
                    </div>

                    <p class="form-text mt-2" data-seat-list>No seats selected yet.</p>

                    <button type="submit" class="btn btn-primary w-100 mt-2" data-loading-text="Booking…">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Confirm booking
                    </button>

                    <p class="form-text mt-2 mb-0">
                        One ticket is issued per seat. You can cancel any seat before the bus departs.
                    </p>
                </div>
            </div>

            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Stops on this route</h2>
                        <p class="card-fl__subtitle"><?= count($stops) ?> stops · <?= e(number_format((float) $service['distance'], 1)) ?> km</p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <ul class="timeline">
                        <?php foreach ($stops as $index => $stop): ?>
                            <?php
                            $dueAt = time_to_minutes((string) $service['departure_time']) + (int) $stop['arrival_offset'];
                            $isFirst = $index === 0;
                            $isLast = $index === count($stops) - 1;
                            ?>
                            <li class="timeline__item<?= $isLast ? ' timeline__item--done' : '' ?>">
                                <p class="timeline__title"><?= e($stop['stop_name']) ?></p>
                                <p class="timeline__meta">
                                    Due <?= e(format_time(minutes_to_time($dueAt))) ?>
                                    <?php if ($isFirst): ?> · Origin<?php endif; ?>
                                    <?php if ($isLast): ?> · Terminus<?php endif; ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
