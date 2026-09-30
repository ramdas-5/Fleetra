<?php
/**
 * Fleetra — Reports
 * ------------------------------------------------------------------
 * modules/reports/index.php
 *
 * One page, eight reports. The report picker, filter bar and export button
 * are shared, so every report behaves the same way and the CSV always
 * matches exactly what is on screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('reports.view');

$definitions = report_definitions();
$reportKey   = get('report', 'fleet_utilization');

if (!report_exists($reportKey)) {
    $reportKey = 'fleet_utilization';
}

$filters = report_filters();
$report  = build_report($reportKey, $filters);

/** Keep the current filters when switching report. */
$linkFor = static function (string $key) use ($filters): string {
    return url('modules/reports/index.php?' . http_build_query(array_merge(['report' => $key], $filters)));
};

$exportUrl = url('modules/reports/export.php?' . http_build_query(array_merge(['report' => $reportKey], $filters)));

$activeFilterChips = [];
if ($filters['route_id'] !== '') {
    $activeFilterChips[] = 'Route: ' . report_route_options()[$filters['route_id']];
}
if ($filters['bus_id'] !== '') {
    $activeFilterChips[] = 'Bus: ' . report_bus_options()[$filters['bus_id']];
}
if ($filters['driver_id'] !== '') {
    $activeFilterChips[] = 'Driver: ' . report_driver_options()[$filters['driver_id']];
}

$page_title       = 'Reports';
$active_nav       = 'reports';
$extra_js         = $report['chart'] !== null ? [asset('vendor/chartjs/chart.umd.min.js')] : [];
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Insights'],
    ['label' => 'Reports'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Reports',
    'Operational and financial reporting for ' . format_date($filters['date_from'], 'd M Y')
        . ' to ' . format_date($filters['date_to'], 'd M Y'),
    '<a class="btn btn-primary" href="' . e($exportUrl) . '">
        <i class="bi bi-filetype-csv" aria-hidden="true"></i> Export CSV
     </a>'
) ?>

<div class="card-fl">
    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title">Choose a report</h2>
            <p class="card-fl__subtitle">All reports share the same filters and export</p>
        </div>
    </div>

    <div class="card-fl__body">
        <div class="toolbar__filters">
            <?php foreach ($definitions as $key => $definition): ?>
                <a class="btn <?= $key === $reportKey ? 'btn-primary' : 'btn-outline-secondary' ?>"
                   href="<?= e($linkFor($key)) ?>"
                   title="<?= e($definition['description']) ?>"
                   <?= $key === $reportKey ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= e($definition['icon']) ?>" aria-hidden="true"></i>
                    <?= e($definition['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/reports/index.php')) ?>">
        <input type="hidden" name="report" value="<?= e($reportKey) ?>">

        <div class="toolbar__filters">
            <label class="visually-hidden" for="date_from">From date</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($filters['date_from']) ?>" style="width:auto;">

            <label class="visually-hidden" for="date_to">To date</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($filters['date_to']) ?>" style="width:auto;">

            <label class="visually-hidden" for="route_id">Route</label>
            <select class="form-select" id="route_id" name="route_id" style="width:auto;">
                <?= option_tags(report_route_options(), $filters['route_id'], 'All routes') ?>
            </select>

            <label class="visually-hidden" for="bus_id">Bus</label>
            <select class="form-select" id="bus_id" name="bus_id" style="width:auto;">
                <?= option_tags(report_bus_options(), $filters['bus_id'], 'All buses') ?>
            </select>

            <label class="visually-hidden" for="driver_id">Driver</label>
            <select class="form-select" id="driver_id" name="driver_id" style="width:auto;">
                <?= option_tags(report_driver_options(), $filters['driver_id'], 'All drivers') ?>
            </select>

            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Apply filters
            </button>

            <a class="btn btn-ghost" href="<?= e(url('modules/reports/index.php?report=' . urlencode($reportKey))) ?>">Reset</a>
        </div>
    </form>
</div>

<?php if ($activeFilterChips !== []): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($activeFilterChips as $chip): ?>
            <span class="chip"><?= e($chip) ?></span>
        <?php endforeach; ?>
        <span class="chip">Date range <strong><?= e(format_date($filters['date_from'], 'd M')) ?> – <?= e(format_date($filters['date_to'], 'd M Y')) ?></strong></span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?php foreach ($report['summary'] as $card): ?>
        <?= stat_card($card['label'], $card['value'], $card['icon'], $card['variant'], $card['meta']) ?>
    <?php endforeach; ?>
</div>

<div class="card-fl">
    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title"><?= e($report['title']) ?></h2>
            <p class="card-fl__subtitle"><?= e($report['subtitle']) ?></p>
        </div>
        <?php if ($report['chart'] !== null && $report['chart']['labels'] !== []): ?>
            <span class="chip">Chart shows the top <?= count($report['chart']['labels']) ?> rows</span>
        <?php endif; ?>
    </div>

    <?php if ($report['chart'] !== null && $report['chart']['labels'] !== []): ?>
        <div class="card-fl__body">
            <div class="chart-box chart-box--md">
                <canvas id="reportChart" aria-label="<?= e($report['title']) ?> chart" role="img"></canvas>
            </div>
        </div>
        <div class="divider m-0"></div>
    <?php endif; ?>

    <?php if ($report['rows'] === []): ?>
        <?= empty_state(
            'Nothing to report in this period',
            'No records match the selected dates and filters. Try widening the date range or clearing the route, bus and driver filters.',
            'bi-bar-chart-line'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <?php foreach ($report['columns'] as $column): ?>
                            <th><?= e($column) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['rows'] as $row): ?>
                        <tr>
                            <?php $columnIndex = 0; ?>
                            <?php foreach ($row as $cell): ?>
                                <?php
                                // First column is the identifier, numeric-looking cells align right.
                                $isNumeric = $columnIndex > 0 && is_string($cell)
                                    && preg_match('/^[₹$\d][\d,\.]*\s?(km|min|%)?$/', trim($cell));
                                ?>
                                <td class="<?= $columnIndex === 0 ? 'cell-strong' : ($isNumeric ? 'cell-num' : '') ?>">
                                    <?= e((string) $cell) ?>
                                </td>
                                <?php $columnIndex++; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <span class="table-foot__info">
                <?= count($report['rows']) ?> row<?= count($report['rows']) === 1 ? '' : 's' ?>
                · <a href="<?= e($exportUrl) ?>">Download as CSV</a>
            </span>
        </div>
    <?php endif; ?>
</div>

<?php if ($report['chart'] !== null && $report['chart']['labels'] !== []): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('reportChart');

    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    var config = <?= e_js($report['chart']) ?>;

    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.color = '#64748B';
    Chart.defaults.font.size = 12;

    new Chart(canvas, {
        type: config.type,
        data: {
            labels: config.labels,
            datasets: config.datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } },
                tooltip: { backgroundColor: '#0F172A', padding: 10 }
            },
            scales: config.type === 'line' || config.type === 'bar'
                ? {
                    x: { grid: { display: false }, border: { color: '#E2E8F0' } },
                    y: { beginAtZero: true, grid: { color: '#F1F5F9' }, border: { display: false } }
                }
                : {}
        }
    });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
