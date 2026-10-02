<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/excel_importer.php
 *
 * Imports the India bus reference workbook (the .xlsx file in the project
 * root) into the Fleetra database.
 *
 * Design rules — these are what make the import safe to run repeatedly:
 *
 *   • The workbook is the primary source for terminals, cities, routes and
 *     operators. Nothing here invents reference data that is not in the
 *     sheet.
 *   • Every insert is idempotent. Terminals/cities are matched on the
 *     unique (city, name, state) key, operators on their code and routes on
 *     their external id, so re-running the import never duplicates a record
 *     and never overwrites a row an administrator has since edited.
 *   • Terminal names are used verbatim; normalisation (Kolkata / kolkata /
 *     KOLKATA) is handled by the case-insensitive collation of the unique
 *     key, so the same terminal can never exist twice with different case.
 *
 * Loading order: config.php -> functions.php -> xlsx_reader.php -> here.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/xlsx_reader.php';

/** The workbook this project ships with, relative to the project root. */
function excel_import_default_file(): string
{
    return BASE_PATH . '/India_Bus_Terminals_and_Routes_EXPANDED_2026-10-02.xlsx';
}

/** ISO 3166-2:IN subdivision code for an Indian state or union territory. */
function excel_state_code(string $state): ?string
{
    static $codes = [
        'andhra pradesh'                 => 'AP',
        'arunachal pradesh'              => 'AR',
        'assam'                          => 'AS',
        'bihar'                          => 'BR',
        'chhattisgarh'                   => 'CG',
        'goa'                            => 'GA',
        'gujarat'                        => 'GJ',
        'haryana'                        => 'HR',
        'himachal pradesh'               => 'HP',
        'jharkhand'                      => 'JH',
        'karnataka'                      => 'KA',
        'kerala'                         => 'KL',
        'madhya pradesh'                 => 'MP',
        'maharashtra'                    => 'MH',
        'manipur'                        => 'MN',
        'meghalaya'                      => 'ML',
        'mizoram'                        => 'MZ',
        'nagaland'                       => 'NL',
        'odisha'                         => 'OR',
        'orissa'                         => 'OR',
        'punjab'                         => 'PB',
        'rajasthan'                      => 'RJ',
        'sikkim'                         => 'SK',
        'tamil nadu'                     => 'TN',
        'telangana'                      => 'TG',
        'tripura'                        => 'TR',
        'uttar pradesh'                  => 'UP',
        'uttarakhand'                    => 'UT',
        'west bengal'                    => 'WB',
        'andaman and nicobar islands'    => 'AN',
        'chandigarh'                     => 'CH',
        'dadra and nagar haveli and daman and diu' => 'DH',
        'delhi'                          => 'DL',
        'jammu and kashmir'              => 'JK',
        'ladakh'                         => 'LA',
        'lakshadweep'                    => 'LD',
        'puducherry'                     => 'PY',
    ];

    return $codes[mb_strtolower(trim($state))] ?? null;
}

/** Map a workbook Terminal_Type to the locations.location_type enum. */
function excel_location_type(string $type): string
{
    return match (mb_strtolower(trim($type))) {
        'bus terminal', 'terminal'     => 'terminal',
        'bus stand', 'bus_stand'       => 'bus_stand',
        'landmark'                     => 'landmark',
        'city'                         => 'city',
        default                        => 'bus_stop',
    };
}

/** Trim a value to a column's maximum length so an import can never fail on size. */
function excel_clip(?string $value, int $max): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : mb_substr($value, 0, $max);
}

/** A numeric value (or null when blank / not a number). */
function excel_number(?string $value, int $decimals = 2): ?float
{
    $value = trim((string) $value);

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return round((float) $value, $decimals);
}

/** True when the row's verification status counts as verified. */
function excel_is_verified(string $status): bool
{
    return stripos($status, 'needs verification') === false
        && stripos($status, 'verified') !== false;
}

/**
 * Read the workbook and map every sheet to the rows Fleetra stores.
 *
 * @return array{
 *   source: string,
 *   locations: array<int, array<string, mixed>>,
 *   operators: array<int, array<string, mixed>>,
 *   routes: array<int, array<string, mixed>>,
 *   stops: array<int, array<string, mixed>>,
 *   counts: array<string, int>
 * }
 */
function excel_import_data(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('Workbook not found: ' . $file);
    }

    $archive   = xlsx_open_archive($file);
    $terminals = xlsx_read_sheet($file, 'Bus_Terminals', $archive);
    $cities    = xlsx_read_sheet($file, 'Cities', $archive);
    $routes    = xlsx_read_sheet($file, 'Bus_Routes', $archive);
    $interstate = xlsx_read_sheet($file, 'Interstate_Routes', $archive);
    $operators = xlsx_read_sheet($file, 'Bus_Operators', $archive);

    $locations = [];
    $seen      = [];

    // ---- Terminals -------------------------------------------------
    foreach ($terminals as $row) {
        $name  = excel_clip($row['Terminal_Name'] ?? '', 180);
        $city  = excel_clip($row['City'] ?? '', 120);
        $state = excel_clip($row['State'] ?? '', 120)
            ?? excel_clip($row['Union_Territory'] ?? '', 120)
            ?? 'India';

        if ($name === null || $city === null) {
            continue;
        }

        $key = mb_strtolower($city . '|' . $name . '|' . $state);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $locations[] = [
            'name'          => $name,
            'city'          => $city,
            'district'      => excel_clip($row['District'] ?? '', 120),
            'state'         => $state,
            'state_code'    => excel_state_code($state),
            'location_type' => excel_location_type($row['Terminal_Type'] ?? ''),
            'latitude'      => excel_number($row['Latitude'] ?? '', 7),
            'longitude'     => excel_number($row['Longitude'] ?? '', 7),
            'pincode'       => excel_clip($row['PIN_Code'] ?? '', 12),
            'aliases'       => excel_clip($row['Alternate_Names'] ?? '', 240),
            'is_verified'   => excel_is_verified($row['Verification_Status'] ?? '') ? 1 : 0,
            '_origin'       => 'terminal',
        ];
    }

    // ---- Cities (as searchable city-level locations) ---------------
    foreach ($cities as $row) {
        $name  = excel_clip($row['City_Name'] ?? '', 180);
        $city  = excel_clip($row['City_Name'] ?? '', 120);
        $state = excel_clip($row['State'] ?? '', 120)
            ?? excel_clip($row['Union_Territory'] ?? '', 120)
            ?? 'India';

        if ($name === null || $city === null) {
            continue;
        }

        $key = mb_strtolower($city . '|' . $name . '|' . $state);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $locations[] = [
            'name'          => $name,
            'city'          => $city,
            'district'      => excel_clip($row['District'] ?? '', 120),
            'state'         => $state,
            'state_code'    => excel_state_code($state),
            'location_type' => 'city',
            'latitude'      => excel_number($row['Latitude'] ?? '', 7),
            'longitude'     => excel_number($row['Longitude'] ?? '', 7),
            'pincode'       => null,
            'aliases'       => excel_clip($row['Alternate_Names'] ?? '', 240),
            'is_verified'   => 1,
            '_origin'       => 'city',
        ];
    }

    // ---- Operators -------------------------------------------------
    $operatorRows = [];
    foreach ($operators as $row) {
        $code = excel_clip($row['Operator_ID'] ?? '', 24);
        $name = excel_clip($row['Operator_Name'] ?? '', 160);

        if ($code === null || $name === null) {
            continue;
        }

        $operatorRows[] = [
            'operator_code'       => $code,
            'operator_name'       => $name,
            'operator_type'       => excel_clip($row['Operator_Type'] ?? '', 60),
            'state'               => excel_clip($row['State'] ?? '', 120),
            'headquarters'        => excel_clip($row['Headquarters'] ?? '', 120),
            'website'             => excel_clip($row['Website'] ?? '', 200),
            'source_url'          => excel_clip($row['Source_URL'] ?? '', 255),
            'verification_status' => excel_clip($row['Verification_Status'] ?? '', 40) ?? 'Needs Verification',
            'notes'               => excel_clip($row['Notes'] ?? '', 500),
        ];
    }

    // ---- Routes (intrastate + interstate) --------------------------
    $routeRows = [];
    $stopRows  = [];
    $seenRoutes = [];

    foreach ([['rows' => $routes, 'kind' => 'Bus_Routes'], ['rows' => $interstate, 'kind' => 'Interstate_Routes']] as $bag) {
        $isInterstate = $bag['kind'] === 'Interstate_Routes';

        foreach ($bag['rows'] as $row) {
            $ref   = excel_clip($row[$isInterstate ? 'Interstate_Route_ID' : 'Route_ID'] ?? '', 48);
            $name  = excel_clip($row['Route_Name'] ?? '', 140);
            $from  = excel_clip($row['Origin_City'] ?? '', 120);
            $to    = excel_clip($row['Destination_City'] ?? '', 120);

            if ($ref === null || $from === null || $to === null) {
                continue;
            }
            if (isset($seenRoutes[$ref])) {
                continue;
            }
            $seenRoutes[$ref] = true;

            $distance = excel_number($row['Approx_Distance_KM'] ?? '', 2) ?? 0.0;
            $duration = excel_number($row['Approx_Duration'] ?? '', 0) ?? 0.0;

            // Only derive a fare when the workbook actually states a distance.
            $fare = $distance > 0 ? max(60.0, round($distance * 1.15 / 10) * 10) : 0.0;

            $routeRows[] = [
                'route_code'               => $ref,
                'route_name'               => $name ?? ($from . ' - ' . $to),
                'source'                   => $from,
                'destination'              => $to,
                'distance'                 => $distance,
                'estimated_duration'       => (int) $duration,
                'base_fare'                => $fare,
                'status'                   => 'active',
                'operator_name'            => excel_clip($row['Operator'] ?? '', 160),
                'route_type'               => excel_clip($row['Route_Type'] ?? '', 40) ?? ($isInterstate ? 'Interstate' : null),
                'service_type'             => excel_clip($row['Service_Type'] ?? '', 60),
                'origin_terminal_ref'      => excel_clip($row['Origin_Terminal_ID'] ?? '', 48),
                'destination_terminal_ref' => excel_clip($row['Destination_Terminal_ID'] ?? '', 48),
                'origin_city'              => $from,
                'destination_city'         => $to,
                'external_ref'             => $ref,
                'data_source'              => 'excel',
                '_stops'                   => excel_clip($row['Intermediate_Cities'] ?? '', 500),
            ];
        }
    }

    // Expand intermediate cities into ordered stop rows.
    foreach ($routeRows as $route) {
        $names = [];
        $names[] = (string) $route['source'];

        if (!empty($route['_stops'])) {
            foreach (preg_split('/[,;\/]+/', (string) $route['_stops']) ?: [] as $stopName) {
                $stopName = excel_clip($stopName, 140);
                if ($stopName !== null) {
                    $names[] = $stopName;
                }
            }
        }

        $names[] = (string) $route['destination'];

        $order = 1;
        foreach (array_values(array_unique($names)) as $stopName) {
            $stopRows[] = [
                'route_code' => (string) $route['route_code'],
                'stop_name'  => $stopName,
                'stop_order' => $order++,
            ];
        }
    }

    return [
        'source'    => $file,
        'locations' => $locations,
        'operators' => $operatorRows,
        'routes'    => $routeRows,
        'stops'     => $stopRows,
        'counts'    => [
            'terminals' => count(array_filter($locations, static fn ($l) => $l['_origin'] === 'terminal')),
            'cities'    => count(array_filter($locations, static fn ($l) => $l['_origin'] === 'city')),
            'operators' => count($operatorRows),
            'routes'    => count($routeRows),
            'stops'     => count($stopRows),
        ],
    ];
}

/* ------------------------------------------------------------------
 | SQL generation — used for the committed import file and --sql output.
 ------------------------------------------------------------------ */

/** Quote a PHP value for a MySQL statement. */
function excel_sql_literal(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $value = (string) $value;
    $value = str_replace(
        ['\\', "'", "\0", "\n", "\r", "\x1a"],
        ['\\\\', "''", '\\0', '\\n', '\\r', '\\Z'],
        $value
    );

    return "'" . $value . "'";
}

/**
 * Render the whole import as one idempotent SQL script.
 *
 * @param array<string, mixed> $data From excel_import_data().
 */
function excel_import_sql(array $data): string
{
    $now   = date('Y-m-d H:i:s');
    $lines = [];

    $lines[] = '-- =====================================================================';
    $lines[] = '--  Fleetra — imported bus catalog';
    $lines[] = '--  Source: ' . basename((string) $data['source']) . '  (generated ' . $now . ')';
    $lines[] = '--';
    $lines[] = '--  Every statement is idempotent: terminals/cities are matched on the';
    $lines[] = '--  unique (city, name, state) key, operators on their code and routes on';
    $lines[] = '--  their external id, so re-running this file never creates a duplicate.';
    $lines[] = '--  Import it AFTER database/fleetra_db.sql (see README section 2).';
    $lines[] = '-- =====================================================================';
    $lines[] = '';
    $lines[] = 'SET NAMES utf8mb4;';
    $lines[] = 'SET time_zone = \'+05:30\';';
    $lines[] = '';
    $lines[] = 'START TRANSACTION;';
    $lines[] = '';

    $chunk = static function (array $rows, int $size = 200): array {
        return array_chunk($rows, $size);
    };

    // ---- Operators -------------------------------------------------
    if ($data['operators'] !== []) {
        $lines[] = '-- Operators -------------------------------------------------------';
        foreach ($chunk($data['operators']) as $group) {
            $values = [];
            foreach ($group as $row) {
                $values[] = '(' . implode(', ', array_map('excel_sql_literal', [
                    $row['operator_code'], $row['operator_name'], $row['operator_type'],
                    $row['state'], $row['headquarters'], $row['website'], $row['source_url'],
                    $row['verification_status'], $row['notes'],
                ])) . ')';
            }
            $lines[] = 'INSERT IGNORE INTO bus_operators'
                . ' (operator_code, operator_name, operator_type, state, headquarters, website, source_url, verification_status, notes)'
                . ' VALUES' . "\n  " . implode(",\n  ", $values) . ';';
        }
        $lines[] = '';
    }

    // ---- Locations (terminals + cities) ----------------------------
    if ($data['locations'] !== []) {
        $lines[] = '-- Terminals and cities --------------------------------------------';
        foreach ($chunk($data['locations']) as $group) {
            $values = [];
            foreach ($group as $row) {
                $values[] = '(' . implode(', ', array_map('excel_sql_literal', [
                    $row['name'], $row['city'], $row['district'], $row['state'], $row['state_code'],
                    $row['location_type'], $row['latitude'], $row['longitude'], $row['pincode'],
                    $row['aliases'], $row['is_verified'],
                ])) . ')';
            }
            $lines[] = 'INSERT IGNORE INTO locations'
                . ' (name, city, district, state, state_code, location_type, latitude, longitude, pincode, aliases, is_verified)'
                . ' VALUES' . "\n  " . implode(",\n  ", $values) . ';';
        }
        $lines[] = '';
    }

    // ---- Routes ----------------------------------------------------
    if ($data['routes'] !== []) {
        $lines[] = '-- Routes -----------------------------------------------------------';
        foreach ($chunk($data['routes'], 100) as $group) {
            $values = [];
            foreach ($group as $row) {
                $values[] = '(' . implode(', ', array_map('excel_sql_literal', [
                    $row['route_code'], $row['route_name'], $row['source'], $row['destination'],
                    $row['distance'], $row['estimated_duration'], $row['base_fare'], $row['status'],
                    $row['operator_name'], $row['route_type'], $row['service_type'],
                    $row['origin_terminal_ref'], $row['destination_terminal_ref'],
                    $row['origin_city'], $row['destination_city'], $row['external_ref'], $row['data_source'],
                ])) . ')';
            }
            $lines[] = 'INSERT IGNORE INTO routes'
                . ' (route_code, route_name, source, destination, distance, estimated_duration, base_fare, status,'
                . ' operator_name, route_type, service_type, origin_terminal_ref, destination_terminal_ref,'
                . ' origin_city, destination_city, external_ref, data_source)'
                . ' VALUES' . "\n  " . implode(",\n  ", $values) . ';';
        }
        $lines[] = '';
    }

    // ---- Stops -----------------------------------------------------
    if ($data['stops'] !== []) {
        $lines[] = '-- Route stops -----------------------------------------------------';

        $byRoute = [];
        foreach ($data['stops'] as $row) {
            $byRoute[(string) $row['route_code']][] = $row;
        }

        foreach ($byRoute as $routeCode => $stops) {
            $selects = [];
            foreach ($stops as $stop) {
                $selects[] = 'SELECT ' . excel_sql_literal($stop['stop_name']) . ' AS stop_name, '
                    . (int) $stop['stop_order'] . ' AS stop_order';
            }

            $lines[] = 'INSERT IGNORE INTO stops (route_id, stop_name, stop_order)'
                . "\nSELECT r.id, s.stop_name, s.stop_order FROM routes r CROSS JOIN (\n  "
                . implode("\n  UNION ALL ", $selects)
                . "\n) s WHERE r.route_code = " . excel_sql_literal($routeCode) . ';';
        }
        $lines[] = '';
    }

    // ---- Import log ------------------------------------------------
    $lines[] = '-- Import run log --------------------------------------------------';
    $lines[] = 'INSERT INTO data_import_runs'
        . ' (source_file, source_label, terminals_found, cities_found, operators_found, routes_found, stops_found, summary, imported_at)'
        . ' VALUES ('
        . implode(', ', [
            excel_sql_literal(basename((string) $data['source'])),
            excel_sql_literal('committed sql import'),
            excel_sql_literal((int) $data['counts']['terminals']),
            excel_sql_literal((int) $data['counts']['cities']),
            excel_sql_literal((int) $data['counts']['operators']),
            excel_sql_literal((int) $data['counts']['routes']),
            excel_sql_literal((int) $data['counts']['stops']),
            excel_sql_literal('Generated from the Excel workbook by tools/import_excel.php'),
            excel_sql_literal($now),
        ]) . ');';
    $lines[] = '';
    $lines[] = 'COMMIT;';
    $lines[] = '';

    return implode("\n", $lines);
}

/* ------------------------------------------------------------------
 | Direct database import
 ------------------------------------------------------------------ */

/**
 * Insert the workbook's data straight into the connected database.
 *
 * @return array<string, int> Number of rows actually inserted per group.
 */
function excel_import_apply(string $file, bool $logRun = true, bool $dryRun = false): array
{
    $data = excel_import_data($file);
    $pdo  = db();

    $inserted = ['operators' => 0, 'locations' => 0, 'routes' => 0, 'stops' => 0];

    $pdo->beginTransaction();

    try {
        $operatorStmt = $pdo->prepare(
            'INSERT IGNORE INTO bus_operators'
            . ' (operator_code, operator_name, operator_type, state, headquarters, website, source_url, verification_status, notes)'
            . ' VALUES (:operator_code, :operator_name, :operator_type, :state, :headquarters, :website, :source_url, :verification_status, :notes)'
        );

        foreach ($data['operators'] as $row) {
            $operatorStmt->execute($row);
            $inserted['operators'] += $operatorStmt->rowCount();
        }

        $locationStmt = $pdo->prepare(
            'INSERT IGNORE INTO locations'
            . ' (name, city, district, state, state_code, location_type, latitude, longitude, pincode, aliases, is_verified)'
            . ' VALUES (:name, :city, :district, :state, :state_code, :location_type, :latitude, :longitude, :pincode, :aliases, :is_verified)'
        );

        foreach ($data['locations'] as $row) {
            unset($row['_origin']);
            $locationStmt->execute($row);
            $inserted['locations'] += $locationStmt->rowCount();
        }

        $routeStmt = $pdo->prepare(
            'INSERT IGNORE INTO routes'
            . ' (route_code, route_name, source, destination, distance, estimated_duration, base_fare, status,'
            . ' operator_name, route_type, service_type, origin_terminal_ref, destination_terminal_ref,'
            . ' origin_city, destination_city, external_ref, data_source)'
            . ' VALUES (:route_code, :route_name, :source, :destination, :distance, :estimated_duration, :base_fare, :status,'
            . ' :operator_name, :route_type, :service_type, :origin_terminal_ref, :destination_terminal_ref,'
            . ' :origin_city, :destination_city, :external_ref, :data_source)'
        );

        $routeIdByCode = [];

        foreach ($data['routes'] as $row) {
            unset($row['_stops']);
            $routeStmt->execute($row);
            $inserted['routes'] += $routeStmt->rowCount();
            $routeIdByCode[(string) $row['route_code']] = (int) db_value(
                'SELECT id FROM routes WHERE route_code = ? LIMIT 1',
                [$row['route_code']],
                0
            );
        }

        $stopStmt = $pdo->prepare(
            'INSERT IGNORE INTO stops (route_id, stop_name, stop_order) VALUES (:route_id, :stop_name, :stop_order)'
        );

        foreach ($data['stops'] as $row) {
            $routeId = $routeIdByCode[(string) $row['route_code']] ?? 0;

            if ($routeId <= 0) {
                continue;
            }

            $stopStmt->execute([
                'route_id'   => $routeId,
                'stop_name'  => $row['stop_name'],
                'stop_order' => $row['stop_order'],
            ]);
            $inserted['stops'] += $stopStmt->rowCount();
        }

        if ($logRun) {
            db_insert('data_import_runs', [
                'source_file'      => basename($file),
                'source_label'     => 'php importer',
                'terminals_found'  => (int) $data['counts']['terminals'],
                'cities_found'     => (int) $data['counts']['cities'],
                'operators_found'  => (int) $data['counts']['operators'],
                'routes_found'     => (int) $data['counts']['routes'],
                'stops_found'      => (int) $data['counts']['stops'],
                'summary'          => sprintf(
                    'Inserted %d operators, %d locations, %d routes, %d stops (duplicates skipped).',
                    $inserted['operators'],
                    $inserted['locations'],
                    $inserted['routes'],
                    $inserted['stops']
                ),
                'imported_at'      => date('Y-m-d H:i:s'),
            ]);
        }

        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return $inserted + ['parsed' => $data['counts']];
}
