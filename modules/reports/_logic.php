<?php
/**
 * Fleetra — Reporting logic
 * ------------------------------------------------------------------
 * modules/reports/_logic.php
 *
 * Eight operational reports, all built from real database data with the
 * same filter set (date range, route, bus, driver). Each report returns a
 * summary, a table and an optional chart, so the report page and the CSV
 * export always describe exactly the same numbers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

/* ------------------------------------------------------------------
 | Filters
 ------------------------------------------------------------------ */

/**
 * Default filter values: the last 30 days.
 *
 * @return array{date_from:string, date_to:string, route_id:string, bus_id:string, driver_id:string}
 */
function report_default_filters(): array
{
    return [
        'date_from' => date('Y-m-d', strtotime('-29 days')),
        'date_to'   => date('Y-m-d'),
        'route_id'  => '',
        'bus_id'    => '',
        'driver_id' => '',
    ];
}

/**
 * Read and validate the filters from the query string.
 *
 * @param array<string, mixed> $source
 * @return array{date_from:string, date_to:string, route_id:string, bus_id:string, driver_id:string}
 */
function report_filters(array $source = []): array
{
    $source = $source === [] ? $_GET : $source;
    $get    = static fn (string $key): string => trim((string) ($source[$key] ?? ''));

    $filters = report_default_filters();

    if (is_valid_date($get('date_from'))) {
        $filters['date_from'] = $get('date_from');
    }

    if (is_valid_date($get('date_to'))) {
        $filters['date_to'] = $get('date_to');
    }

    // The range is inclusive; swapping reversed dates is friendlier than an error.
    if ($filters['date_from'] > $filters['date_to']) {
        [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
    }

    if (is_valid_option(report_route_options(), $get('route_id'))) {
        $filters['route_id'] = $get('route_id');
    }

    if (is_valid_option(report_bus_options(), $get('bus_id'))) {
        $filters['bus_id'] = $get('bus_id');
    }

    if (is_valid_option(report_driver_options(), $get('driver_id'))) {
        $filters['driver_id'] = $get('driver_id');
    }

    return $filters;
}

/** @return array<int, string> */
function report_route_options(): array
{
    $options = [];

    foreach (db_all('SELECT id, route_code, route_name FROM routes ORDER BY route_code') as $route) {
        $options[(int) $route['id']] = $route['route_code'] . ' · ' . $route['route_name'];
    }

    return $options;
}

/** @return array<int, string> */
function report_bus_options(): array
{
    $options = [];

    foreach (db_all('SELECT id, bus_number, registration_number FROM buses ORDER BY bus_number') as $bus) {
        $options[(int) $bus['id']] = $bus['bus_number'] . ' · ' . $bus['registration_number'];
    }

    return $options;
}

/** @return array<int, string> */
function report_driver_options(): array
{
    $options = [];

    foreach (db_all(
        'SELECT d.id, d.employee_id, u.name FROM drivers d JOIN users u ON u.id = d.user_id ORDER BY u.name'
    ) as $driver) {
        $options[(int) $driver['id']] = $driver['name'] . ' (' . $driver['employee_id'] . ')';
    }

    return $options;
}

/**
 * Trip-level filter fragment (excluding the date range, which the callers
 * apply against schedules.schedule_date).
 *
 * @param array<string, string> $filters
 * @return array{0:string, 1:array<int, mixed>}
 */
function report_trip_scope(array $filters): array
{
    $sql    = '';
    $params = [];

    if ($filters['route_id'] !== '') {
        $sql     .= ' AND t.route_id = ?';
        $params[] = (int) $filters['route_id'];
    }

    if ($filters['bus_id'] !== '') {
        $sql     .= ' AND t.bus_id = ?';
        $params[] = (int) $filters['bus_id'];
    }

    if ($filters['driver_id'] !== '') {
        $sql     .= ' AND t.driver_id = ?';
        $params[] = (int) $filters['driver_id'];
    }

    return [$sql, $params];
}

/**
 * The same filters, but expressed against a schedules row (used by reports
 * that are built from bookings or payments rather than trips).
 *
 * @param array<string, string> $filters
 * @return array{0:string, 1:array<int, mixed>}
 */
function report_schedule_scope(array $filters, string $alias = 's'): array
{
    $sql    = '';
    $params = [];

    foreach (['route_id' => 'route_id', 'bus_id' => 'bus_id', 'driver_id' => 'driver_id'] as $filter => $column) {
        if ($filters[$filter] !== '') {
            $sql     .= ' AND ' . $alias . '.' . $column . ' = ?';
            $params[] = (int) $filters[$filter];
        }
    }

    return [$sql, $params];
}

/* ------------------------------------------------------------------
 | Report catalogue
 ------------------------------------------------------------------ */

/**
 * @return array<string, array{label:string, description:string, icon:string}>
 */
function report_definitions(): array
{
    return [
        'fleet_utilization' => [
            'label'       => 'Fleet utilisation',
            'description' => 'How hard each vehicle has worked: trips, kilometres, passengers and load factor.',
            'icon'        => 'bi-bus-front',
        ],
        'trip_completion' => [
            'label'       => 'Trip completion',
            'description' => 'Completed, cancelled and delayed services day by day.',
            'icon'        => 'bi-check2-all',
        ],
        'route_performance' => [
            'label'       => 'Route performance',
            'description' => 'Passengers, revenue and punctuality for every route.',
            'icon'        => 'bi-map',
        ],
        'passenger_bookings' => [
            'label'       => 'Passenger bookings',
            'description' => 'The passengers who book most, what they spend and how often they cancel.',
            'icon'        => 'bi-people',
        ],
        'revenue' => [
            'label'       => 'Revenue',
            'description' => 'Money collected, refunded and net takings, day by day and by method.',
            'icon'        => 'bi-cash-stack',
        ],
        'maintenance_cost' => [
            'label'       => 'Maintenance cost',
            'description' => 'Workshop spend per bus, with the last and next service dates.',
            'icon'        => 'bi-tools',
        ],
        'driver_trips' => [
            'label'       => 'Driver activity',
            'description' => 'Trips operated per driver, passengers carried and punctuality.',
            'icon'        => 'bi-person-badge',
        ],
        'delay_analysis' => [
            'label'       => 'Delay analysis',
            'description' => 'Where delays come from and how long they run.',
            'icon'        => 'bi-clock-history',
        ],
    ];
}

/** True when a report key exists. */
function report_exists(string $key): bool
{
    return array_key_exists($key, report_definitions());
}

/* ------------------------------------------------------------------
 | Builders
 ------------------------------------------------------------------ */

/**
 * Build a report.
 *
 * @param array<string, string> $filters
 * @return array{
 *     title:string,
 *     subtitle:string,
 *     summary:array<int, array{label:string, value:string, icon:string, variant:string, meta:string}>,
 *     columns:array<string, string>,
 *     rows:array<int, array<int, mixed>>,
 *     chart:array{type:string, labels:array<int, string>, datasets:array<int, array<string, mixed>>}|null
 * }
 */
function build_report(string $key, array $filters): array
{
    $definition = report_definitions()[$key];
    $from       = $filters['date_from'];
    $to         = $filters['date_to'];

    [$scopeSql, $scopeParams] = report_trip_scope($filters);

    switch ($key) {
        /* ---------------------------------------------------------
         | 1. Fleet utilisation
         --------------------------------------------------------- */
        case 'fleet_utilization':
            $busFilter  = $filters['bus_id'] !== '' ? ' AND b.id = ?' : '';
            $busParams  = $filters['bus_id'] !== '' ? [(int) $filters['bus_id']] : [];

            $rows = db_all(
                "SELECT b.id, b.bus_number, b.registration_number, b.status, b.capacity,
                        COUNT(t.id) AS trips,
                        COALESCE(SUM(CASE WHEN t.trip_status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
                        COALESCE(SUM(r.distance), 0) AS route_km,
                        COALESCE(SUM(t.passenger_count), 0) AS passengers
                   FROM buses b
                   LEFT JOIN trips t ON t.bus_id = b.id
                   LEFT JOIN schedules s ON s.id = t.schedule_id
                   LEFT JOIN routes r ON r.id = t.route_id
                  WHERE 1 = 1$busFilter
                    AND (t.id IS NULL OR (s.schedule_date BETWEEN ? AND ?$scopeSql))
                  GROUP BY b.id, b.bus_number, b.registration_number, b.status, b.capacity
                  ORDER BY trips DESC, b.bus_number",
                array_merge($busParams, [$from, $to], $scopeParams)
            );

            $tableRows = [];
            $totalKm   = 0.0;
            $totalPax  = 0;
            $totalTrips = 0;

            $chartLabels = [];
            $chartData   = [];

            foreach ($rows as $row) {
                $trips     = (int) $row['trips'];
                $passengers = (int) $row['passengers'];
                $capacity  = (int) $row['capacity'];
                $seats     = $trips * $capacity;
                $load      = $seats > 0 ? round(($passengers / $seats) * 100, 1) : 0.0;

                $totalKm    += (float) $row['route_km'];
                $totalPax   += $passengers;
                $totalTrips += $trips;

                $tableRows[] = [
                    $row['bus_number'],
                    $row['registration_number'],
                    labelize((string) $row['status']),
                    number_format($trips),
                    number_format($capacity),
                    number_format((float) $row['route_km'], 1) . ' km',
                    number_format($passengers),
                    $load . '%',
                ];

                if ($trips > 0) {
                    $chartLabels[] = (string) $row['bus_number'];
                    $chartData[]   = $load;
                }
            }

            return [
                'title'    => $definition['label'],
                'subtitle' => 'Vehicles that have not run in this period show zero trips, not a blank row.',
                'summary'  => [
                    ['label' => 'Vehicles in report', 'value' => number_format(count($rows)), 'icon' => 'bi-bus-front', 'variant' => 'primary', 'meta' => 'Matching the current filters'],
                    ['label' => 'Trips operated', 'value' => number_format($totalTrips), 'icon' => 'bi-signpost-split', 'variant' => 'info', 'meta' => 'Scheduled services in range'],
                    ['label' => 'Distance covered', 'value' => number_format($totalKm, 0) . ' km', 'icon' => 'bi-geo-alt', 'variant' => 'success', 'meta' => 'Sum of route distances'],
                    ['label' => 'Passengers carried', 'value' => number_format($totalPax), 'icon' => 'bi-people', 'variant' => 'muted', 'meta' => 'Recorded on-board counts'],
                ],
                'columns' => [
                    'Bus', 'Registration', 'Status', 'Trips', 'Capacity', 'Distance', 'Passengers', 'Load factor',
                ],
                'rows'  => $tableRows,
                'chart' => [
                    'type'     => 'bar',
                    'labels'   => $chartLabels,
                    'datasets' => [[
                        'label'           => 'Load factor %',
                        'data'            => $chartData,
                        'backgroundColor' => '#2563EB',
                        'borderRadius'    => 5,
                    ]],
                ],
            ];

        /* ---------------------------------------------------------
         | 2. Trip completion
         --------------------------------------------------------- */
        case 'trip_completion':
            $rows = db_all(
                "SELECT s.schedule_date AS d,
                        COUNT(t.id) AS total,
                        COALESCE(SUM(CASE WHEN t.trip_status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
                        COALESCE(SUM(CASE WHEN t.trip_status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled,
                        COALESCE(SUM(CASE WHEN t.trip_status = 'delayed' THEN 1 ELSE 0 END), 0) AS delayed_count
                   FROM trips t
                   JOIN schedules s ON s.id = t.schedule_id
                  WHERE s.schedule_date BETWEEN ? AND ?$scopeSql
                  GROUP BY s.schedule_date
                  ORDER BY s.schedule_date",
                array_merge([$from, $to], $scopeParams)
            );

            $tableRows = [];
            $labels     = [];
            $completed  = [];
            $cancelled  = [];
            $totalTrips = 0;
            $totalCompleted = 0;
            $totalCancelled = 0;
            $totalDelayed   = 0;

            foreach ($rows as $row) {
                $total   = (int) $row['total'];
                $done    = (int) $row['completed'];
                $cancels = (int) $row['cancelled'];
                $delays  = (int) $row['delayed_count'];
                $rate    = $total > 0 ? round(($done / $total) * 100, 1) : 0.0;

                $totalTrips     += $total;
                $totalCompleted += $done;
                $totalCancelled += $cancels;
                $totalDelayed   += $delays;

                $labels[]    = format_date((string) $row['d'], 'd M');
                $completed[] = $done;
                $cancelled[] = $cancels;

                $tableRows[] = [
                    format_date((string) $row['d'], 'D, d M Y'),
                    number_format($total),
                    number_format($done),
                    number_format($cancels),
                    number_format($delays),
                    $rate . '%',
                ];
            }

            $overallRate = $totalTrips > 0 ? round(($totalCompleted / $totalTrips) * 100, 1) : 0.0;

            return [
                'title'    => $definition['label'],
                'subtitle' => 'Completion rate is completed trips divided by all trips scheduled that day.',
                'summary'  => [
                    ['label' => 'Trips in range', 'value' => number_format($totalTrips), 'icon' => 'bi-signpost-split', 'variant' => 'primary', 'meta' => 'All statuses'],
                    ['label' => 'Completed', 'value' => number_format($totalCompleted), 'icon' => 'bi-check2-all', 'variant' => 'success', 'meta' => 'Reached the destination'],
                    ['label' => 'Cancelled', 'value' => number_format($totalCancelled), 'icon' => 'bi-x-octagon', 'variant' => $totalCancelled > 0 ? 'danger' : 'muted', 'meta' => 'Called off by the operator'],
                    ['label' => 'Completion rate', 'value' => $overallRate . '%', 'icon' => 'bi-percent', 'variant' => 'info', 'meta' => 'Delayed trips still count as completed'],
                ],
                'columns' => ['Date', 'Trips', 'Completed', 'Cancelled', 'Delayed', 'Completion'],
                'rows'    => $tableRows,
                'chart'   => [
                    'type'     => 'line',
                    'labels'   => $labels,
                    'datasets' => [
                        ['label' => 'Completed', 'data' => $completed, 'borderColor' => '#16A34A', 'backgroundColor' => 'rgba(22,163,74,.12)', 'fill' => true, 'tension' => .3],
                        ['label' => 'Cancelled', 'data' => $cancelled, 'borderColor' => '#DC2626', 'backgroundColor' => 'rgba(220,38,38,.12)', 'fill' => true, 'tension' => .3],
                    ],
                ],
            ];

        /* ---------------------------------------------------------
         | 3. Route performance
         --------------------------------------------------------- */
        case 'route_performance':
            $rows = db_all(
                "SELECT r.route_code, r.route_name, r.source, r.destination, r.distance,
                        COUNT(t.id) AS trips,
                        COALESCE(SUM(t.passenger_count), 0) AS passengers,
                        COALESCE(SUM(t.delay_minutes), 0) AS delay_minutes,
                        COALESCE((
                            SELECT SUM(bk.fare) FROM bookings bk
                             WHERE bk.schedule_id IN (SELECT s2.id FROM schedules s2 WHERE s2.route_id = r.id
                                 AND s2.schedule_date BETWEEN ? AND ?)
                               AND bk.booking_status IN ('confirmed','completed')
                        ), 0) AS revenue
                   FROM routes r
                   LEFT JOIN trips t ON t.route_id = r.id
                   LEFT JOIN schedules s ON s.id = t.schedule_id
                  WHERE t.id IS NULL OR (s.schedule_date BETWEEN ? AND ?$scopeSql)
                  GROUP BY r.id, r.route_code, r.route_name, r.source, r.destination, r.distance
                  ORDER BY passengers DESC",
                array_merge([$from, $to, $from, $to], $scopeParams)
            );

            $tableRows  = [];
            $labels     = [];
            $passengers = [];
            $revenue    = [];
            $totalPax   = 0;
            $totalRevenue = 0.0;
            $totalDelay   = 0;
            $totalTrips   = 0;

            foreach ($rows as $row) {
                $pax  = (int) $row['passengers'];
                $rev  = (float) $row['revenue'];
                $trip = (int) $row['trips'];

                $totalPax     += $pax;
                $totalRevenue += $rev;
                $totalDelay   += (int) $row['delay_minutes'];
                $totalTrips   += $trip;

                $avgDelay = $trip > 0 ? round((int) $row['delay_minutes'] / $trip, 1) : 0.0;

                $labels[]     = (string) $row['route_code'];
                $passengers[] = $pax;
                $revenue[]    = round($rev, 2);

                $tableRows[] = [
                    $row['route_code'],
                    $row['source'] . ' → ' . $row['destination'],
                    number_format($trip),
                    number_format($pax),
                    money($rev),
                    $avgDelay . ' min',
                ];
            }

            return [
                'title'    => $definition['label'],
                'subtitle' => 'Revenue counts confirmed and completed bookings on services within the date range.',
                'summary'  => [
                    ['label' => 'Routes', 'value' => number_format(count($rows)), 'icon' => 'bi-map', 'variant' => 'primary', 'meta' => 'In the current filters'],
                    ['label' => 'Passengers', 'value' => number_format($totalPax), 'icon' => 'bi-people', 'variant' => 'info', 'meta' => 'Carried in range'],
                    ['label' => 'Revenue', 'value' => money($totalRevenue), 'icon' => 'bi-cash-stack', 'variant' => 'success', 'meta' => 'Booked fare value'],
                    ['label' => 'Average delay', 'value' => ($totalTrips > 0 ? round($totalDelay / $totalTrips, 1) : 0) . ' min', 'icon' => 'bi-clock-history', 'variant' => 'warning', 'meta' => 'Across all trips'],
                ],
                'columns' => ['Route', 'Journey', 'Trips', 'Passengers', 'Revenue', 'Avg delay'],
                'rows'    => $tableRows,
                'chart'   => [
                    'type'     => 'bar',
                    'labels'   => $labels,
                    'datasets' => [
                        ['label' => 'Passengers', 'data' => $passengers, 'backgroundColor' => '#2563EB', 'borderRadius' => 5],
                        ['label' => 'Revenue', 'data' => $revenue, 'backgroundColor' => '#BFDBFE', 'borderRadius' => 5],
                    ],
                ],
            ];

        /* ---------------------------------------------------------
         | 4. Passenger bookings
         --------------------------------------------------------- */
        case 'passenger_bookings':
            [$scheduleSql, $scheduleParams] = report_schedule_scope($filters);

            $rows = db_all(
                "SELECT u.id, u.name, u.email, u.phone,
                        COUNT(b.id) AS bookings,
                        COALESCE(SUM(CASE WHEN b.booking_status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled,
                        COALESCE(SUM(CASE WHEN b.payment_status = 'paid' THEN b.fare ELSE 0 END), 0) AS spend,
                        MAX(b.created_at) AS last_booking
                   FROM users u
                   JOIN bookings b ON b.user_id = u.id
                   JOIN schedules s ON s.id = b.schedule_id
                  WHERE u.role = 'passenger'
                    AND s.schedule_date BETWEEN ? AND ?$scheduleSql
                  GROUP BY u.id, u.name, u.email, u.phone
                  ORDER BY bookings DESC, spend DESC
                  LIMIT 200",
                array_merge([$from, $to], $scheduleParams)
            );

            $tableRows = [];
            $labels     = [];
            $bookings   = [];
            $totalBookings = 0;
            $totalSpend    = 0.0;
            $totalCancelled = 0;

            foreach ($rows as $row) {
                $count = (int) $row['bookings'];
                $spend = (float) $row['spend'];

                $totalBookings  += $count;
                $totalSpend     += $spend;
                $totalCancelled += (int) $row['cancelled'];

                if (count($labels) < 8) {
                    $labels[]   = (string) $row['name'];
                    $bookings[] = $count;
                }

                $tableRows[] = [
                    $row['name'],
                    $row['email'],
                    $row['phone'] ?? '—',
                    number_format($count),
                    number_format((int) $row['cancelled']),
                    money($spend),
                    format_date((string) $row['last_booking'], 'd M Y'),
                ];
            }

            return [
                'title'    => $definition['label'],
                'subtitle' => 'Only passengers with at least one booking in the range are listed (top 200 by volume).',
                'summary'  => [
                    ['label' => 'Booking passengers', 'value' => number_format(count($rows)), 'icon' => 'bi-people', 'variant' => 'primary', 'meta' => 'With at least one booking'],
                    ['label' => 'Bookings', 'value' => number_format($totalBookings), 'icon' => 'bi-journal-check', 'variant' => 'info', 'meta' => 'Seats reserved in range'],
                    ['label' => 'Fare paid', 'value' => money($totalSpend), 'icon' => 'bi-cash-coin', 'variant' => 'success', 'meta' => 'Paid bookings only'],
                    ['label' => 'Cancelled', 'value' => number_format($totalCancelled), 'icon' => 'bi-x-circle', 'variant' => $totalCancelled > 0 ? 'warning' : 'muted', 'meta' => 'Released by the passenger or operator'],
                ],
                'columns' => ['Passenger', 'Email', 'Phone', 'Bookings', 'Cancelled', 'Fare paid', 'Last booking'],
                'rows'    => $tableRows,
                'chart'   => [
                    'type'     => 'bar',
                    'labels'   => $labels,
                    'datasets' => [[
                        'label'           => 'Bookings',
                        'data'            => $bookings,
                        'backgroundColor' => '#0284C7',
                        'borderRadius'    => 5,
                    ]],
                ],
            ];

        /* ---------------------------------------------------------
         | 5. Revenue
         --------------------------------------------------------- */
        case 'revenue':
            [$scheduleSql, $scheduleParams] = report_schedule_scope($filters, 'ss');

            $tripScope = $scheduleParams === [] ? '' : ' AND EXISTS (
                    SELECT 1 FROM bookings bb
                      JOIN schedules ss ON ss.id = bb.schedule_id
                     WHERE bb.id = p.booking_id' . $scheduleSql . ')';

            $rows = db_all(
                "SELECT DATE(p.created_at) AS d,
                        COUNT(p.id) AS transactions,
                        COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS gross,
                        COALESCE(SUM(CASE WHEN p.status = 'refunded' THEN p.amount ELSE 0 END), 0) AS refunded,
                        COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END), 0) AS pending
                   FROM payments p
                  WHERE DATE(p.created_at) BETWEEN ? AND ?$tripScope
                  GROUP BY DATE(p.created_at)
                  ORDER BY d",
                array_merge([$from, $to], $scheduleParams)
            );

            $rowsByMethod = db_all(
                "SELECT p.payment_method,
                        COUNT(p.id) AS transactions,
                        COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS gross
                   FROM payments p
                  WHERE DATE(p.created_at) BETWEEN ? AND ?$tripScope
                  GROUP BY p.payment_method
                  ORDER BY gross DESC",
                array_merge([$from, $to], $scheduleParams)
            );

            $tableRows = [];
            $labels     = [];
            $grossData  = [];
            $totalGross = 0.0;
            $totalRefunded = 0.0;
            $totalPending  = 0.0;
            $transactions  = 0;

            foreach ($rows as $row) {
                $gross   = (float) $row['gross'];
                $refund  = (float) $row['refunded'];
                $pending = (float) $row['pending'];

                $totalGross    += $gross;
                $totalRefunded += $refund;
                $totalPending  += $pending;
                $transactions  += (int) $row['transactions'];

                $labels[]    = format_date((string) $row['d'], 'd M');
                $grossData[] = round($gross, 2);

                $tableRows[] = [
                    format_date((string) $row['d'], 'D, d M Y'),
                    number_format((int) $row['transactions']),
                    money($gross),
                    money($refund),
                    money($gross - $refund),
                ];
            }

            foreach ($rowsByMethod as $method) {
                $tableRows[] = [
                    labelize((string) $method['payment_method']) . ' (method)',
                    number_format((int) $method['transactions']),
                    money($method['gross']),
                    '—',
                    '—',
                ];
            }

            return [
                'title'    => $definition['label'],
                'subtitle' => 'Payments are simulated locally: no real gateway is charged, but the records are real.',
                'summary'  => [
                    ['label' => 'Gross collected', 'value' => money($totalGross), 'icon' => 'bi-cash-stack', 'variant' => 'success', 'meta' => 'Paid transactions'],
                    ['label' => 'Refunded', 'value' => money($totalRefunded), 'icon' => 'bi-arrow-counterclockwise', 'variant' => $totalRefunded > 0 ? 'warning' : 'muted', 'meta' => 'Cancelled services and bookings'],
                    ['label' => 'Net revenue', 'value' => money($totalGross - $totalRefunded), 'icon' => 'bi-graph-up', 'variant' => 'primary', 'meta' => 'Gross less refunds'],
                    ['label' => 'Transactions', 'value' => number_format($transactions), 'icon' => 'bi-credit-card', 'variant' => 'info', 'meta' => money($totalPending) . ' still pending'],
                ],
                'columns' => ['Date / method', 'Transactions', 'Gross', 'Refunded', 'Net'],
                'rows'    => $tableRows,
                'chart'   => [
                    'type'     => 'line',
                    'labels'   => $labels,
                    'datasets' => [[
                        'label'           => 'Gross collected',
                        'data'            => $grossData,
                        'borderColor'     => '#16A34A',
                        'backgroundColor' => 'rgba(22,163,74,.12)',
                        'fill'            => true,
                        'tension'         => .3,
                    ]],
                ],
            ];

        /* ---------------------------------------------------------
         | 6. Maintenance cost
         --------------------------------------------------------- */
        case 'maintenance_cost':
            $busFilter = $filters['bus_id'] !== '' ? ' AND b.id = ?' : '';
            $busParams = $filters['bus_id'] !== '' ? [(int) $filters['bus_id']] : [];

            $rows = db_all(
                "SELECT b.id, b.bus_number, b.registration_number, b.status, b.current_mileage,
                        COUNT(m.id) AS jobs,
                        COALESCE(SUM(CASE WHEN m.status <> 'cancelled' THEN m.cost ELSE 0 END), 0) AS spend,
                        MAX(m.service_date) AS last_service,
                        MIN(CASE WHEN m.next_service_date >= CURDATE() THEN m.next_service_date END) AS next_service
                   FROM buses b
                   LEFT JOIN maintenance m ON m.bus_id = b.id AND m.service_date BETWEEN ? AND ?
                  WHERE 1 = 1$busFilter
                  GROUP BY b.id, b.bus_number, b.registration_number, b.status, b.current_mileage
                  ORDER BY spend DESC, b.bus_number",
                array_merge([$from, $to], $busParams)
            );

            $tableRows = [];
            $labels     = [];
            $spendData  = [];
            $totalSpend = 0.0;
            $totalJobs  = 0;
            $spendPerKm = 0.0;
            $totalKm    = 0.0;

            foreach ($rows as $row) {
                $spend = (float) $row['spend'];
                $jobs  = (int) $row['jobs'];

                $totalSpend += $spend;
                $totalJobs  += $jobs;
                $totalKm    += (float) $row['current_mileage'];

                if ($spend > 0 && count($labels) < 8) {
                    $labels[]    = (string) $row['bus_number'];
                    $spendData[] = round($spend, 2);
                }

                $tableRows[] = [
                    $row['bus_number'],
                    $row['registration_number'],
                    labelize((string) $row['status']),
                    number_format($jobs),
                    money($spend),
                    format_date((string) $row['last_service'], 'd M Y'),
                    format_date($row['next_service'] ?? null, 'd M Y'),
                ];
            }

            if ($totalKm > 0) {
                $spendPerKm = $totalSpend / $totalKm;
            }

            return [
                'title'    => $definition['label'],
                'subtitle' => 'Costs are counted on the service date, so a back-dated job lands in the right period.',
                'summary'  => [
                    ['label' => 'Workshop spend', 'value' => money($totalSpend), 'icon' => 'bi-tools', 'variant' => 'primary', 'meta' => 'Excluding cancelled jobs'],
                    ['label' => 'Jobs recorded', 'value' => number_format($totalJobs), 'icon' => 'bi-clipboard-check', 'variant' => 'info', 'meta' => 'Services, repairs and inspections'],
                    ['label' => 'Vehicles serviced', 'value' => number_format(count(array_filter($rows, static fn (array $r): bool => (int) $r['jobs'] > 0))), 'icon' => 'bi-bus-front', 'variant' => 'success', 'meta' => 'Buses with at least one job'],
                    ['label' => 'Cost per 1,000 km', 'value' => money($spendPerKm * 1000), 'icon' => 'bi-speedometer2', 'variant' => 'muted', 'meta' => 'Against lifetime odometer'],
                ],
                'columns' => ['Bus', 'Registration', 'Status', 'Jobs', 'Spend', 'Last service', 'Next service'],
                'rows'    => $tableRows,
                'chart'   => [
                    'type'     => 'bar',
                    'labels'   => $labels,
                    'datasets' => [[
                        'label'           => 'Maintenance spend',
                        'data'            => $spendData,
                        'backgroundColor' => '#D97706',
                        'borderRadius'    => 5,
                    ]],
                ],
            ];

        /* ---------------------------------------------------------
         | 7. Driver activity
         --------------------------------------------------------- */
        case 'driver_trips':
            $rows = db_all(
                "SELECT u.name, d.employee_id, d.employment_status, d.experience_years,
                        COUNT(t.id) AS trips,
                        COALESCE(SUM(CASE WHEN t.trip_status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
                        COALESCE(SUM(t.passenger_count), 0) AS passengers,
                        COALESCE(SUM(t.delay_minutes), 0) AS delay_minutes
                   FROM drivers d
                   JOIN users u ON u.id = d.user_id
                   LEFT JOIN trips t ON t.driver_id = d.id
                   LEFT JOIN schedules s ON s.id = t.schedule_id
                  WHERE (t.id IS NULL OR (s.schedule_date BETWEEN ? AND ?$scopeSql))
                  GROUP BY d.id, u.name, d.employee_id, d.employment_status, d.experience_years
                  ORDER BY trips DESC, u.name",
                array_merge([$from, $to], $scopeParams)
            );

            $tableRows = [];
            $labels     = [];
            $tripsData  = [];
            $totalTrips = 0;
            $totalPax   = 0;
            $totalDelay = 0;
            $totalCompleted = 0;

            foreach ($rows as $row) {
                $trips = (int) $row['trips'];

                $totalTrips     += $trips;
                $totalPax       += (int) $row['passengers'];
                $totalDelay     += (int) $row['delay_minutes'];
                $totalCompleted += (int) $row['completed'];

                $labels[]    = (string) $row['name'];
                $tripsData[] = $trips;

                $tableRows[] = [
                    $row['name'],
                    $row['employee_id'],
                    labelize((string) $row['employment_status']),
                    number_format($trips),
                    number_format((int) $row['completed']),
                    number_format((int) $row['passengers']),
                    ($trips > 0 ? round((int) $row['delay_minutes'] / $trips, 1) : 0) . ' min',
                ];
            }

            return [
                'title'    => $definition['label'],
                'subtitle' => 'A driver with no duty in the range appears with zero trips rather than being hidden.',
                'summary'  => [
                    ['label' => 'Drivers', 'value' => number_format(count($rows)), 'icon' => 'bi-person-badge', 'variant' => 'primary', 'meta' => 'On the roster'],
                    ['label' => 'Trips operated', 'value' => number_format($totalTrips), 'icon' => 'bi-signpost-split', 'variant' => 'info', 'meta' => 'Across all drivers'],
                    ['label' => 'Passengers carried', 'value' => number_format($totalPax), 'icon' => 'bi-people', 'variant' => 'success', 'meta' => 'On-board counts'],
                    ['label' => 'Average delay', 'value' => ($totalTrips > 0 ? round($totalDelay / $totalTrips, 1) : 0) . ' min', 'icon' => 'bi-clock-history', 'variant' => 'warning', 'meta' => 'Per trip operated'],
                ],
                'columns' => ['Driver', 'Employee ID', 'Status', 'Trips', 'Completed', 'Passengers', 'Avg delay'],
                'rows'    => $tableRows,
                'chart'   => [
                    'type'     => 'bar',
                    'labels'   => $labels,
                    'datasets' => [[
                        'label'           => 'Trips',
                        'data'            => $tripsData,
                        'backgroundColor' => '#2563EB',
                        'borderRadius'    => 5,
                    ]],
                ],
            ];

        /* ---------------------------------------------------------
         | 8. Delay analysis
         --------------------------------------------------------- */
        case 'delay_analysis':
            $rows = db_all(
                "SELECT r.route_code, r.route_name, r.source, r.destination,
                        COUNT(t.id) AS trips,
                        COALESCE(SUM(CASE WHEN t.delay_minutes > 0 THEN 1 ELSE 0 END), 0) AS delayed_trips,
                        COALESCE(SUM(t.delay_minutes), 0) AS total_delay,
                        COALESCE(MAX(t.delay_minutes), 0) AS worst_delay
                   FROM routes r
                   LEFT JOIN trips t ON t.route_id = r.id
                   LEFT JOIN schedules s ON s.id = t.schedule_id
                  WHERE (t.id IS NULL OR (s.schedule_date BETWEEN ? AND ?$scopeSql))
                  GROUP BY r.id, r.route_code, r.route_name, r.source, r.destination
                  ORDER BY total_delay DESC",
                array_merge([$from, $to], $scopeParams)
            );

            $delayReasons = db_all(
                "SELECT t.remarks, t.delay_minutes, s.schedule_date, r.route_code
                   FROM trips t
                   JOIN schedules s ON s.id = t.schedule_id
                   JOIN routes r ON r.id = t.route_id
                  WHERE t.delay_minutes > 0
                    AND s.schedule_date BETWEEN ? AND ?$scopeSql
                  ORDER BY t.delay_minutes DESC
                  LIMIT 8",
                array_merge([$from, $to], $scopeParams)
            );

            $tableRows = [];
            $labels     = [];
            $delayData  = [];
            $totalTrips = 0;
            $totalDelayed = 0;
            $totalDelay = 0;
            $worstDelay = 0;
            $punctualRoutes = 0;

            foreach ($rows as $row) {
                $trips   = (int) $row['trips'];
                $delayed = (int) $row['delayed_trips'];

                $totalTrips   += $trips;
                $totalDelayed += $delayed;
                $totalDelay   += (int) $row['total_delay'];
                $worstDelay    = max($worstDelay, (int) $row['worst_delay']);

                if ($delayed === 0 && $trips > 0) {
                    $punctualRoutes++;
                }

                $onTime = $trips > 0 ? round((($trips - $delayed) / $trips) * 100, 1) : 0.0;
                $avg    = $trips > 0 ? round((int) $row['total_delay'] / $trips, 1) : 0.0;

                $labels[]    = (string) $row['route_code'];
                $delayData[] = (int) $row['total_delay'];

                $tableRows[] = [
                    $row['route_code'],
                    $row['source'] . ' → ' . $row['destination'],
                    number_format($trips),
                    number_format($delayed),
                    number_format((int) $row['total_delay']) . ' min',
                    $avg . ' min',
                    $onTime . '%',
                ];
            }

            foreach ($delayReasons as $reason) {
                $tableRows[] = [
                    (string) $reason['route_code'] . ' (reason)',
                    format_date((string) $reason['schedule_date'], 'd M Y'),
                    '+ ' . (int) $reason['delay_minutes'] . ' min',
                    '',
                    '',
                    '',
                    truncate((string) ($reason['remarks'] ?? 'No reason recorded'), 90),
                ];
            }

            return [
                'title'    => $definition['label'],
                'subtitle' => 'On-time percentage ignores cancelled trips; a delayed trip still counts as operated.',
                'summary'  => [
                    ['label' => 'Trips analysed', 'value' => number_format($totalTrips), 'icon' => 'bi-signpost-split', 'variant' => 'primary', 'meta' => 'All statuses in range'],
                    ['label' => 'Delayed trips', 'value' => number_format($totalDelayed), 'icon' => 'bi-clock-history', 'variant' => $totalDelayed > 0 ? 'warning' : 'muted', 'meta' => 'Reported a delay'],
                    ['label' => 'Total delay', 'value' => number_format($totalDelay) . ' min', 'icon' => 'bi-hourglass-split', 'variant' => 'danger', 'meta' => 'Worst single delay ' . $worstDelay . ' min'],
                    ['label' => 'Fully punctual routes', 'value' => number_format($punctualRoutes), 'icon' => 'bi-check2-circle', 'variant' => 'success', 'meta' => 'No delay recorded at all'],
                ],
                'columns' => ['Route / reason', 'Journey or date', 'Trips / delay', 'Delayed', 'Total delay', 'Average', 'On time / detail'],
                'rows'    => $tableRows,
                'chart'   => [
                    'type'     => 'bar',
                    'labels'   => $labels,
                    'datasets' => [[
                        'label'           => 'Total delay (minutes)',
                        'data'            => $delayData,
                        'backgroundColor' => '#DC2626',
                        'borderRadius'    => 5,
                    ]],
                ],
            ];
    }

    // Unknown report — the caller validates first, so this is only a fallback.
    return [
        'title'    => 'Report',
        'subtitle' => '',
        'summary'  => [],
        'columns'  => [],
        'rows'     => [],
        'chart'    => null,
    ];
}

/* ------------------------------------------------------------------
 | CSV export
 ------------------------------------------------------------------ */

/**
 * Stream a report as a CSV download.
 *
 * @param array<string, string> $columns
 * @param array<int, array<int, mixed>> $rows
 */
function stream_report_csv(string $filename, array $columns, array $rows, array $meta = []): never
{
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'fleetra-report';

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '.csv"');
        header('Cache-Control: no-store');
    }

    // Excel opens UTF-8 CSV reliably when it sees a byte-order mark.
    echo "\xEF\xBB\xBF";

    $handle = fopen('php://output', 'wb');

    if ($handle === false) {
        exit;
    }

    foreach ($meta as $line) {
        fputcsv($handle, [$line]);
    }

    if ($meta !== []) {
        fputcsv($handle, []);
    }

    fputcsv($handle, array_values($columns));

    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn ($value): string => (string) $value, $row));
    }

    fclose($handle);
    exit;
}
