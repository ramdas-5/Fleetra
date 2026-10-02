<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * tools/import_excel.php
 *
 * Command-line importer for the India bus reference workbook. It turns
 * the .xlsx file in the project root into Fleetra catalogue data.
 *
 *   php tools/import_excel.php --stats
 *       Parse the workbook and print what it contains. No writes.
 *
 *   php tools/import_excel.php --sql
 *       Regenerate database/excel_bus_catalog.sql (idempotent INSERTs)
 *       that can be imported with phpMyAdmin.
 *
 *   php tools/import_excel.php --apply
 *       Insert the data straight into the connected database. Safe to run
 *       any number of times — existing records are skipped, never
 *       duplicated and never overwritten.
 *
 * Options:
 *   --file=PATH   Use a different workbook (default: the project root one)
 *   --sql=PATH    Write the SQL to a different location
 *   --dry-run     With --apply, validate everything but roll back
 */

declare(strict_types=1);

// This is a maintenance tool: never expose it over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/excel_importer.php';

/* ------------------------------------------------------------------
 | Argument parsing
 ------------------------------------------------------------------ */

$options = [
    'stats'   => false,
    'sql'     => false,
    'apply'   => false,
    'dryRun'  => false,
    'file'    => excel_import_default_file(),
    'sqlPath' => BASE_PATH . '/database/excel_bus_catalog.sql',
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--stats') {
        $options['stats'] = true;
    } elseif ($argument === '--sql') {
        $options['sql'] = true;
    } elseif ($argument === '--apply') {
        $options['apply'] = true;
    } elseif ($argument === '--dry-run') {
        $options['dryRun'] = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo <<<TXT
Fleetra Excel importer

  php tools/import_excel.php --stats          Show what the workbook contains
  php tools/import_excel.php --sql            Regenerate database/excel_bus_catalog.sql
  php tools/import_excel.php --apply          Import into the connected database
  php tools/import_excel.php --apply --dry-run  Validate then roll back

  --file=PATH   Workbook to read
  --sql=PATH    Where to write the SQL file

TXT;
        exit(0);
    } elseif (str_starts_with($argument, '--file=')) {
        $options['file'] = substr($argument, 7);
    } elseif (str_starts_with($argument, '--sql=')) {
        $options['sqlPath'] = substr($argument, 6);
    }
}

if (!$options['stats'] && !$options['sql'] && !$options['apply']) {
    $options['stats'] = true;
}

/* ------------------------------------------------------------------
 | Run
 ------------------------------------------------------------------ */

try {
    $data = excel_import_data($options['file']);

    printf("Workbook: %s\n", $options['file']);
    printf("  terminals : %d\n", $data['counts']['terminals']);
    printf("  cities    : %d\n", $data['counts']['cities']);
    printf("  operators : %d\n", $data['counts']['operators']);
    printf("  routes    : %d\n", $data['counts']['routes']);
    printf("  stops     : %d\n", $data['counts']['stops']);
    echo "\n";

    if ($options['sql']) {
        $sql      = excel_import_sql($data);
        $directory = dirname($options['sqlPath']);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (file_put_contents($options['sqlPath'], $sql) === false) {
            fwrite(STDERR, "Could not write {$options['sqlPath']}\n");
            exit(1);
        }

        printf("SQL written: %s (%d bytes)\n", $options['sqlPath'], strlen($sql));
    }

    if ($options['apply']) {
        $inserted = excel_import_apply($options['file'], !$options['dryRun'], $options['dryRun']);

        printf(
            "Applied: %d operators, %d locations, %d routes, %d stops inserted%s\n",
            $inserted['operators'],
            $inserted['locations'],
            $inserted['routes'],
            $inserted['stops'],
            $options['dryRun'] ? ' (dry run — rolled back)' : ''
        );
    }

    if (!$options['sql'] && !$options['apply']) {
        echo "Nothing written. Add --sql or --apply to import.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Import failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
