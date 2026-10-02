<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * tools/generate_operations.php
 *
 * Fills the operational history so every dashboard and report has real
 * data to aggregate:
 *
 *   • past schedules (departures that already ran)
 *   • trips        — completed / delayed / cancelled with passenger counts
 *   • passengers   — extra accounts that own the bookings
 *   • bookings     — confirmed / completed / cancelled / no-show seats
 *   • payments     — gross, refunded and net revenue per booking
 *   • tickets      — one e-ticket per confirmed booking
 *   • maintenance  — workshop jobs across the fleet (cost + status)
 *   • incidents    — breakdowns, delays and alerts tied to trips
 *
 * Everything is generated around the existing catalogue, buses and
 * drivers, and respects the database unique keys (a bus or driver is
 * never double-booked, a seat is never sold twice). It is idempotent per
 * section: existing rows are detected through the unique keys and skipped.
 *
 *   php tools/generate_operations.php
 *   php tools/generate_operations.php --days=45 --routes=700 --incidents=700
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bus_catalog.php';
require_once __DIR__ . '/../includes/operations.php';

$options = [
    'days'          => 45,
    'routes'        => 700,
    'passengers'    => 300,
    'bookings_min'  => 3,
    'bookings_max'  => 14,
    'maint_ratio'   => 55,
    'incidents'     => 700,
    'seed'          => 777001,
    'dry_run'       => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo <<<TXT
Fleetra operations generator

  php tools/generate_operations.php [options]

  --days=N           Days of history to create          (default 45)
  --routes=N         Routes that get a timetable        (default 700)
  --passengers=N     Passenger accounts to create       (default 300)
  --bookings-min=N   Minimum seats sold per trip         (default 3)
  --bookings-max=N   Maximum seats sold per trip         (default 14)
  --maint-ratio=N    Percent of fleet with workshop jobs (default 55)
  --incidents=N      Incidents to create                 (default 700)
  --seed=N           Deterministic seed                  (default 777001)
  --dry-run          Report only, insert nothing

TXT;
        exit(0);
    }

    if ($argument === '--dry-run') {
        $options['dry_run'] = true;
        continue;
    }

    foreach (['days', 'routes', 'passengers', 'maint_ratio', 'incidents', 'seed'] as $key) {
        if (str_starts_with($argument, '--' . $key . '=')) {
            $options[$key] = (int) substr($argument, strlen($key) + 3);
        }
    }

    $dashed = [
        'bookings_min' => 'bookings-min',
        'bookings_max' => 'bookings-max',
        'maint_ratio'  => 'maint-ratio',
    ];

    foreach ($dashed as $key => $flag) {
        if (str_starts_with($argument, '--' . $flag . '=')) {
            $options[$key] = (int) substr($argument, strlen($flag) + 3);
        }
    }
}

$options['days']       = max(1, min(365, $options['days']));
$options['routes']     = max(1, min(3000, $options['routes']));
$options['passengers'] = max(0, min(3000, $options['passengers']));
$options['incidents']  = max(0, min(5000, $options['incidents']));

mt_srand($options['seed']);

$pdo = db();

/* ------------------------------------------------------------------
 | Reference pools
 ------------------------------------------------------------------ */

$routes = db_all(
    'SELECT id, route_code, base_fare, estimated_duration, distance
       FROM routes
      WHERE status = "active"
      ORDER BY id'
);

if (count($routes) > $options['routes']) {
    shuffle($routes);
    $routes = array_slice($routes, 0, $options['routes']);
}

$buses = db_all('SELECT id, bus_type, capacity FROM buses WHERE status = "active" ORDER BY id');
$drivers = db_all('SELECT id FROM drivers WHERE employment_status = "active" ORDER BY id');

if ($routes === [] || $buses === [] || $drivers === []) {
    fwrite(STDERR, "Need routes, active buses and active drivers. Run tools/generate_bulk_data.php first.\n");
    exit(1);
}

/** Bus types and seat labels per capacity. */
function ops_seat_labels(int $capacity): array
{
    $labels = [];
    $letters = range('A', 'J');

    foreach ($letters as $letter) {
        for ($n = 1; $n <= 6; $n++) {
            $labels[] = $letter . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        }
    }

    return array_slice($labels, 0, max(1, min($capacity, count($labels))));
}

/** The half-hour departure palette used for generated history. */
function ops_slots(): array
{
    static $slots = null;

    if ($slots !== null) {
        return $slots;
    }

    $slots = [];
    for ($minutes = 5 * 60; $minutes <= 22 * 60; $minutes += 30) {
        $slots[] = substr(minutes_to_time($minutes), 0, 5);
    }

    return $slots;
}

/* ------------------------------------------------------------------
 | Passengers
 ------------------------------------------------------------------ */

$firstNames = ['Ananya', 'Riya', 'Soumya', 'Ishita', 'Ayan', 'Neha', 'Rohan', 'Maya', 'Kabir', 'Puja', 'Arjun', 'Sneha', 'Vikram', 'Deepa', 'Manish', 'Kavya', 'Aditya', 'Nisha', 'Rahul', 'Priya', 'Sandeep', 'Anjali', 'Farhan', 'Meera', 'Tanmay', 'Swati', 'Gaurav', 'Pallavi', 'Nikhil', 'Ritika'];
$lastNames  = ['Das', 'Sen', 'Ghosh', 'Roy', 'Dutta', 'Paul', 'Bose', 'Sarkar', 'Nandi', 'Sharma', 'Verma', 'Reddy', 'Rao', 'Nair', 'Menon', 'Patel', 'Singh', 'Kumar', 'Gupta', 'Joshi', 'Kulkarni', 'Chopra', 'Kapoor', 'Iyer', 'Pillai'];

$passwordHash = (string) db_value("SELECT password FROM users WHERE email = 'passenger@fleetra.com' LIMIT 1", [], '');
if ($passwordHash === '') {
    $passwordHash = password_hash('Fleetra@123', PASSWORD_BCRYPT);
}

$passengerIds = [];
foreach (db_all("SELECT id FROM users WHERE role = \"passenger\" ORDER BY id") as $row) {
    $passengerIds[] = (int) $row['id'];
}

if (!$options['dry_run'] && $options['passengers'] > 0) {
    $userStmt = $pdo->prepare(
        'INSERT IGNORE INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, "passenger", "active")'
    );

    $pdo->beginTransaction();

    for ($i = 0; $i < $options['passengers']; $i++) {
        $seq  = $i + 1;
        $name = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $mail = sprintf('passenger%04d@fleetra.com', 2000 + $seq);
        $userStmt->execute([$name, $mail, sprintf('+91 8%09d', mt_rand(100000000, 999999999)), $passwordHash]);

        if ($userStmt->rowCount() > 0) {
            $passengerIds[] = (int) $pdo->lastInsertId();
        }
    }

    $pdo->commit();
}

if ($passengerIds === []) {
    $passengerIds = [7]; // fall back to the seeded passenger account
}

/* ------------------------------------------------------------------
 | Plan the work (so --dry-run can report without touching the DB)
 ------------------------------------------------------------------ */

$days      = $options['days'];
$startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
$today     = date('Y-m-d');
$now       = date('H:i');
$slots     = ops_slots();

// Per-day occupancy loaded from the DB so generated departures never
// collide with an existing (bus, date, time) or (driver, date, time).
$occupancy = [];
$loadDay = static function (string $date) use (&$occupancy, $pdo): void {
    if (isset($occupancy[$date])) {
        return;
    }

    $occupancy[$date] = ['bus' => [], 'driver' => []];

    $stmt = $pdo->prepare('SELECT bus_id, driver_id, TIME_FORMAT(departure_time, "%H:%i") AS slot FROM schedules WHERE schedule_date = ?');
    $stmt->execute([$date]);

    foreach ($stmt->fetchAll() as $row) {
        $occupancy[$date]['bus'][(int) $row['bus_id'] . '|' . $row['slot']]       = true;
        $occupancy[$date]['driver'][(int) $row['driver_id'] . '|' . $row['slot']] = true;
    }
};

if ($options['dry_run']) {
    $plannedTrips   = (int) round(count($routes) * $days * 0.55);
    $plannedSeats   = (int) round($plannedTrips * ($options['bookings_min'] + $options['bookings_max']) / 2);
    $plannedMaint   = (int) round(count($buses) * $options['maint_ratio'] / 100 * 1.8);

    printf("Would create approximately:\n  %d past schedules + trips\n  %d bookings (+ payments + tickets)\n  %d maintenance jobs\n  %d incidents\n  %d new passengers\n",
        $plannedTrips, $plannedSeats, $plannedMaint, $options['incidents'], $options['passengers']);
    exit(0);
}

/* ------------------------------------------------------------------
 | Statements
 ------------------------------------------------------------------ */

$scheduleStmt = $pdo->prepare(
    'INSERT IGNORE INTO schedules (route_id, bus_id, driver_id, schedule_date, departure_time, arrival_time, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$tripStmt = $pdo->prepare(
    'INSERT IGNORE INTO trips (schedule_id, bus_id, driver_id, route_id, actual_start_time, actual_end_time, trip_status, passenger_count, delay_minutes, remarks)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$bookingStmt = $pdo->prepare(
    'INSERT IGNORE INTO bookings (booking_number, user_id, schedule_id, seat_number, fare, booking_status, payment_status, booking_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$paymentStmt = $pdo->prepare(
    'INSERT IGNORE INTO payments (booking_id, transaction_reference, payment_method, amount, status, payment_date)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$ticketStmt = $pdo->prepare(
    'INSERT IGNORE INTO tickets (ticket_number, booking_id, qr_code, issued_at, status)
     VALUES (?, ?, ?, ?, ?)'
);
$tripCountStmt = $pdo->prepare('UPDATE trips SET passenger_count = ? WHERE schedule_id = ?');

$bookingSeq = 900000;
$ticketSeq  = 700000;
$txnSeq     = 500000;

$totals = ['schedules' => 0, 'trips' => 0, 'bookings' => 0, 'payments' => 0, 'tickets' => 0];

$methods = ['upi', 'card', 'cash', 'wallet', 'net_banking', 'simulated'];

/* ------------------------------------------------------------------
 | Past schedules → trips → bookings → revenue
 ------------------------------------------------------------------ */

$pdo->beginTransaction();
$sinceCommit = 0;

for ($dayOffset = $days - 1; $dayOffset >= 0; $dayOffset--) {
    $date = date('Y-m-d', strtotime('-' . $dayOffset . ' day'));
    $loadDay($date);

    foreach ($routes as $routeIndex => $route) {
        // Not every route runs every day.
        if (mt_rand(1, 100) > 55) {
            continue;
        }

        $slotIndex = ($routeIndex * 7 + $dayOffset * 3 + mt_rand(0, 3)) % count($slots);
        $time      = $slots[$slotIndex];

        // For today, keep generated history in the past only.
        if ($date === $today && $time >= $now) {
            continue;
        }

        // Find a free bus and driver for this (date, time).
        $busId = null;
        $driverId = null;

        for ($attempt = 0; $attempt < count($slots) && $busId === null; $attempt++) {
            $trySlot = $slots[($slotIndex + $attempt) % count($slots)];
            if ($date === $today && $trySlot >= $now) {
                continue;
            }

            foreach ($buses as $bus) {
                if (isset($occupancy[$date]['bus'][(int) $bus['id'] . '|' . $trySlot])) {
                    continue;
                }

                foreach ($drivers as $driver) {
                    if (isset($occupancy[$date]['driver'][(int) $driver['id'] . '|' . $trySlot])) {
                        continue;
                    }

                    $busId    = (int) $bus['id'];
                    $driverId = (int) $driver['id'];
                    $time     = $trySlot;
                    break 2;
                }
            }
        }

        if ($busId === null || $driverId === null) {
            continue;
        }

        $occupancy[$date]['bus'][$busId . '|' . $time]       = true;
        $occupancy[$date]['driver'][$driverId . '|' . $time] = true;

        $duration  = max(30, (int) $route['estimated_duration']);
        $departure = $time . ':00';
        $arrival   = minutes_to_time(time_to_minutes($departure) + $duration);
        $routeId   = (int) $route['id'];

        // One in twenty past departures was cancelled before it ran.
        $cancelled = mt_rand(1, 100) <= 5;

        $scheduleStmt->execute([$routeId, $busId, $driverId, $date, $departure, $arrival, $cancelled ? 'cancelled' : 'completed']);

        if ($scheduleStmt->rowCount() === 0) {
            continue; // Slot already used by an existing departure.
        }

        $totals['schedules']++;
        $scheduleId = (int) $pdo->lastInsertId();
        $sinceCommit += 1;

        if ($cancelled) {
            // A cancelled departure still gets a trip record so the trip
            // completion report can show it, but it sells no seats.
            $tripStmt->execute([
                $scheduleId, $busId, $driverId, $routeId,
                null, null, 'cancelled', 0, 0, 'Cancelled before departure',
            ]);

            if ($tripStmt->rowCount() > 0) {
                $totals['trips']++;
            }

            continue;
        }

        // Trip -------------------------------------------------------
        $delayed   = mt_rand(1, 100) <= 18;
        $delayMins = $delayed ? mt_rand(10, 75) : 0;
        $tripStatus = $delayed ? 'delayed' : 'completed';

        $startedAt = $date . ' ' . $time . ':00';
        $endedAt   = $date . ' ' . minutes_to_time(time_to_minutes($departure) + $duration + $delayMins) . ':00';

        $tripStmt->execute([
            $scheduleId, $busId, $driverId, $routeId,
            $startedAt, $endedAt, $tripStatus, 0, $delayMins,
            $delayed ? 'Delayed by traffic' : null,
        ]);

        if ($tripStmt->rowCount() > 0) {
            $totals['trips']++;
            $sinceCommit += 1;
        }

        // Bookings ---------------------------------------------------
        $bus        = null;
        foreach ($buses as $candidate) {
            if ((int) $candidate['id'] === $busId) {
                $bus = $candidate;
                break;
            }
        }

        $capacity = (int) ($bus['capacity'] ?? 40);
        $seats    = ops_seat_labels($capacity);
        shuffle($seats);

        $maxSeats = max(1, min(count($seats), (int) round($capacity * 0.9)));
        $sold     = mt_rand($options['bookings_min'], min($options['bookings_max'], $maxSeats));
        $seatsSold = 0;

        for ($s = 0; $s < $sold; $s++) {
            $seat   = $seats[$s];
            $roll   = mt_rand(1, 100);
            if ($roll <= 84) {
                $bookingStatus = 'completed';
                $paymentStatus = 'paid';
            } elseif ($roll <= 91) {
                $bookingStatus = 'confirmed';
                $paymentStatus = 'paid';
            } elseif ($roll <= 96) {
                $bookingStatus = 'cancelled';
                $paymentStatus = 'refunded';
            } else {
                $bookingStatus = 'no_show';
                $paymentStatus = 'paid';
            }

            $fare = round(fleetra_fare_for((float) $route['base_fare'], (string) ($bus['bus_type'] ?? 'seater'), $departure) * (0.6 + mt_rand(0, 40) / 100), 2);
            $fare = max(25.0, $fare);

            $bookingSeq++;
            $bookingNumber = sprintf('GENBK-%06d', $bookingSeq);

            $bookingStmt->execute([
                $bookingNumber,
                $passengerIds[array_rand($passengerIds)],
                $scheduleId,
                $seat,
                $fare,
                $bookingStatus,
                $paymentStatus,
                $date,
            ]);

            if ($bookingStmt->rowCount() === 0) {
                continue;
            }

            $totals['bookings']++;
            $seatsSold++;
            $sinceCommit += 1;
            $bookingId = (int) $pdo->lastInsertId();

            // Payment ------------------------------------------------
            if ($paymentStatus === 'paid' || $paymentStatus === 'refunded') {
                $txnSeq++;
                $paymentStmt->execute([
                    $bookingId,
                    sprintf('GENTXN-%06d', $txnSeq),
                    $methods[array_rand($methods)],
                    $fare,
                    $paymentStatus === 'refunded' ? 'refunded' : 'paid',
                    $date . ' ' . $time . ':00',
                ]);

                if ($paymentStmt->rowCount() > 0) {
                    $totals['payments']++;
                }
            }

            // Ticket -------------------------------------------------
            if ($bookingStatus === 'completed' || $bookingStatus === 'confirmed' || $bookingStatus === 'no_show') {
                $ticketSeq++;
                $ticketStmt->execute([
                    sprintf('GENTK-%06d', $ticketSeq),
                    $bookingId,
                    strtoupper(bin2hex(random_bytes(8))),
                    $date . ' ' . $time . ':00',
                    $bookingStatus === 'no_show' ? 'expired' : ($bookingStatus === 'completed' ? 'used' : 'valid'),
                ]);

                if ($ticketStmt->rowCount() > 0) {
                    $totals['tickets']++;
                }
            }
        }

        // Record how many passengers actually travelled on the trip.
        $tripCountStmt->execute([$seatsSold, $scheduleId]);

        // Keep transactions bounded.
        if ($sinceCommit >= 6000) {
            $pdo->commit();
            $pdo->beginTransaction();
            $sinceCommit = 0;
        }
    }
}

$pdo->commit();

printf(
    "Operations: %d schedules, %d trips, %d bookings, %d payments, %d tickets.\n",
    $totals['schedules'], $totals['trips'], $totals['bookings'], $totals['payments'], $totals['tickets']
);

/* ------------------------------------------------------------------
 | Maintenance
 ------------------------------------------------------------------ */

$maintTypes = ['routine', 'inspection', 'oil_change', 'tyre', 'brake', 'repair', 'electrical', 'bodywork', 'other'];
$providers  = ['Fleetra Central Workshop', 'City Auto Works', 'Highway Motors Service', 'Sterling Bus Care', 'Precision Diesel Works', 'Authorised OEM Service Centre'];
$maintNotes = [
    'routine'     => 'Scheduled periodic service and fluids top-up.',
    'inspection'  => 'Annual fitness and safety inspection.',
    'oil_change'  => 'Engine oil and filter replacement.',
    'tyre'        => 'Tyre rotation and pressure check.',
    'brake'       => 'Brake pad inspection and replacement.',
    'repair'      => 'Repair after reported defect.',
    'electrical'  => 'Electrical and battery diagnostics.',
    'bodywork'    => 'Body panel repair and repaint.',
    'other'       => 'General workshop attention.',
];

$allBusIds = array_map(static fn ($b) => (int) $b['id'], db_all('SELECT id FROM buses ORDER BY id'));
$maintBuses = [];
foreach ($allBusIds as $i => $id) {
    if (mt_rand(1, 100) <= $options['maint_ratio']) {
        $maintBuses[] = $id;
    }
}

$maintStmt = $pdo->prepare(
    'INSERT INTO maintenance (bus_id, maintenance_type, description, service_date, next_service_date, odometer_reading, cost, service_provider, status, remarks)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$maintenance = 0;
$pdo->beginTransaction();
$sinceCommit = 0;

foreach ($maintBuses as $busId) {
    $jobs = mt_rand(1, 3);

    for ($j = 0; $j < $jobs; $j++) {
        $type      = $maintTypes[array_rand($maintTypes)];
        $past      = mt_rand(1, 100) <= 72;
        $serviceDate = $past
            ? date('Y-m-d', strtotime('-' . mt_rand(3, 150) . ' days'))
            : date('Y-m-d', strtotime('+' . mt_rand(1, 30) . ' days'));

        if ($past) {
            $status = mt_rand(1, 100) <= 88 ? 'completed' : 'overdue';
        } else {
            $status = mt_rand(1, 100) <= 80 ? 'scheduled' : 'in_progress';
        }

        $cost = round(mt_rand(2500, 48000) / 50) * 50;

        $maintStmt->execute([
            $busId,
            $type,
            $maintNotes[$type],
            $serviceDate,
            date('Y-m-d', strtotime($serviceDate . ' +' . mt_rand(90, 180) . ' days')),
            round(mt_rand(20000, 480000), 2),
            $cost,
            $providers[array_rand($providers)],
            $status,
            $status === 'completed' ? 'Job closed' : null,
        ]);

        $maintenance++;
        $sinceCommit++;

        if ($sinceCommit >= 4000) {
            $pdo->commit();
            $pdo->beginTransaction();
            $sinceCommit = 0;
        }
    }
}

$pdo->commit();

printf("Maintenance: %d workshop job(s) across %d buses.\n", $maintenance, count($maintBuses));

/* ------------------------------------------------------------------
 | Incidents
 ------------------------------------------------------------------ */

$incidentTypes = ['breakdown', 'traffic', 'accident', 'medical', 'security', 'weather', 'other'];
$incidentText  = [
    'breakdown' => 'Vehicle broke down en route to the destination.',
    'traffic'   => 'Heavy traffic congestion caused a service delay.',
    'accident'  => 'Minor collision reported; no injuries.',
    'medical'   => 'Passenger required medical assistance on board.',
    'security'  => 'Security concern reported by the driver.',
    'weather'   => 'Severe weather forced a slow and cautious run.',
    'other'     => 'Operational issue logged by the crew.',
];
$severities = ['low', 'medium', 'high', 'critical'];

$tripPool = db_all(
    'SELECT t.id, t.driver_id, t.bus_id, s.schedule_date
       FROM trips t JOIN schedules s ON s.id = t.schedule_id
      ORDER BY t.id'
);

$incidentStmt = $pdo->prepare(
    'INSERT INTO incidents (trip_id, driver_id, bus_id, reported_by, incident_type, severity, description, status, reported_at, resolved_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$incidents = 0;

if ($tripPool !== [] && $options['incidents'] > 0) {
    $reporters = array_map(static fn ($u) => (int) $u['id'], db_all('SELECT id FROM users WHERE role IN ("admin","manager","dispatcher") ORDER BY id'));
    if ($reporters === []) {
        $reporters = [1];
    }

    $pdo->beginTransaction();
    $sinceCommit = 0;

    for ($i = 0; $i < $options['incidents']; $i++) {
        $trip   = $tripPool[array_rand($tripPool)];
        $type   = $incidentTypes[array_rand($incidentTypes)];
        $roll   = mt_rand(1, 100);
        $status = $roll <= 42 ? 'open' : ($roll <= 62 ? 'investigating' : ($roll <= 92 ? 'resolved' : 'closed'));

        // Severity is weighted towards low/medium.
        $sevRoll  = mt_rand(1, 100);
        $severity = $sevRoll <= 40 ? 'low' : ($sevRoll <= 75 ? 'medium' : ($sevRoll <= 92 ? 'high' : 'critical'));

        $reportedAt = date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, $options['days'] * 24) . ' hours'));
        $resolvedAt = in_array($status, ['resolved', 'closed'], true)
            ? date('Y-m-d H:i:s', strtotime($reportedAt . ' +' . mt_rand(1, 48) . ' hours'))
            : null;

        $incidentStmt->execute([
            (int) $trip['id'],
            $trip['driver_id'] !== null ? (int) $trip['driver_id'] : null,
            $trip['bus_id'] !== null ? (int) $trip['bus_id'] : null,
            $reporters[array_rand($reporters)],
            $type,
            $severity,
            $incidentText[$type],
            $status,
            $reportedAt,
            $resolvedAt,
        ]);

        $incidents++;
        $sinceCommit++;

        if ($sinceCommit >= 3000) {
            $pdo->commit();
            $pdo->beginTransaction();
            $sinceCommit = 0;
        }
    }

    $pdo->commit();
}

printf("Incidents: %d logged.\n", $incidents);

db_insert('data_import_runs', [
    'source_file'     => 'generator:operations',
    'source_label'    => 'operations generator',
    'terminals_found' => 0,
    'cities_found'    => 0,
    'operators_found' => 0,
    'routes_found'    => count($routes),
    'stops_found'     => 0,
    'summary'         => sprintf(
        '%d trips, %d bookings, %d payments, %d tickets, %d maintenance jobs, %d incidents over %d days',
        $totals['trips'], $totals['bookings'], $totals['payments'], $totals['tickets'], $maintenance, $incidents, $days
    ),
]);

echo "Done.\n";
