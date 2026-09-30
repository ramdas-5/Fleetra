<?php
/**
 * Fleetra — Reports / CSV export
 * ------------------------------------------------------------------
 * modules/reports/export.php
 *
 * Streams the selected report as a CSV download using exactly the same
 * builder and filters as the on-screen report, so what is exported is what
 * was reviewed. Permission is re-checked here: the URL can be requested
 * directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('reports.view');

$reportKey = get('report', 'fleet_utilization');

if (!report_exists($reportKey)) {
    abort_not_found('That report does not exist.');
}

$filters = report_filters();
$report  = build_report($reportKey, $filters);

/* Summarise the filters at the top of the file so a saved export is
   self-explanatory when it is opened months later. */
$filterSummary = ['From: ' . $filters['date_from'], 'To: ' . $filters['date_to']];

if ($filters['route_id'] !== '') {
    $filterSummary[] = 'Route: ' . (report_route_options()[$filters['route_id']] ?? $filters['route_id']);
}
if ($filters['bus_id'] !== '') {
    $filterSummary[] = 'Bus: ' . (report_bus_options()[$filters['bus_id']] ?? $filters['bus_id']);
}
if ($filters['driver_id'] !== '') {
    $filterSummary[] = 'Driver: ' . (report_driver_options()[$filters['driver_id']] ?? $filters['driver_id']);
}

$meta = array_merge(
    [
        FLEETRA_NAME . ' — ' . $report['title'],
        'Generated: ' . date('d M Y, h:i A') . ' by ' . current_user_name(),
    ],
    $filterSummary
);

foreach ($report['summary'] as $card) {
    $meta[] = $card['label'] . ': ' . $card['value'];
}

$filename = 'fleetra-' . str_replace('_', '-', $reportKey) . '-' . $filters['date_from'] . '-to-' . $filters['date_to'];

log_activity(
    'Exported report ' . $report['title'],
    'reports',
    null,
    $filters['date_from'] . ' to ' . $filters['date_to'] . ' (' . count($report['rows']) . ' rows)'
);

stream_report_csv($filename, $report['columns'], $report['rows'], $meta);
