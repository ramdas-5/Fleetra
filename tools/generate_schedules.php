<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * tools/generate_schedules.php
 *
 * Builds real, bookable departures for every imported catalogue route
 * that does not already have an upcoming schedule.
 *
 * The passenger search does this automatically for the routes it shows,
 * so this tool is optional — but it is the fastest way to pre-build a
 * full timetable after importing the Excel catalogue, instead of making
 * the first passenger request pay for it.
 *
 *   php tools/generate_schedules.php                 Next 7 days
 *   php tools/generate_schedules.php --days=14       Next 14 days
 *   php tools/generate_schedules.php --date=2026-11-01
 *
 * It is idempotent: routes that already have departures are skipped, so
 * running it again never duplicates a schedule.
 */

declare(strict_types=1);

// Maintenance tool: never expose it over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/schedule_seeder.php';

$days = 7;
$date = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo <<<TXT
Fleetra schedule generator

  php tools/generate_schedules.php [--days=7] [--date=YYYY-MM-DD]

  --days=N          Days to schedule from the start date (1-14).
  --date=YYYY-MM-DD First day to schedule (default: today).

Creates real departures for imported catalogue routes so passengers get
scheduled services first, with demo services covering only the gaps.

TXT;
        exit(0);
    }

    if (str_starts_with($argument, '--days=')) {
        $days = (int) substr($argument, 7);
    } elseif (str_starts_with($argument, '--date=')) {
        $date = substr($argument, 7);
    }
}

try {
    $created = fleetra_seed_all_route_schedules($date, $days);

    printf(
        "Created %d bookable departure(s) for imported routes%s.\n",
        $created,
        $date !== null ? " from {$date}" : ''
    );

    if ($created === 0) {
        echo "Every imported route already had upcoming departures.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Schedule generation failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
