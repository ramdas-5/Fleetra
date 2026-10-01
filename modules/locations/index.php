<?php
/**
 * Fleetra — Location directory / list
 * ------------------------------------------------------------------
 * modules/locations/index.php
 *
 * Curates the India-wide bus terminal / stop reference data used by the
 * passenger search and autocomplete. Search, filter, paginate, add, edit
 * and remove locations.
 */

declare(strict_types=1);

require_once __DIR__ . '/_logic.php';

require_permission('locations.manage');

$search     = get('q');
$stateFilter = get('state');
$typeFilter = get('type');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(search_text LIKE ? OR name LIKE ?)';
    $like     = '%' . normalize_location_query($search) . '%';
    $params[] = $like;
    $params[] = '%' . $search . '%';
}

if ($stateFilter !== '') {
    $where[]  = 'state = ?';
    $params[] = $stateFilter;
}

if (is_valid_option(location_type_options(), $typeFilter)) {
    $where[]  = 'location_type = ?';
    $params[] = $typeFilter;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$total = (int) db_value("SELECT COUNT(*) FROM locations $whereSql", $params, 0);
$page  = paginate($total, 20);

$locations = db_all(
    "SELECT id, name, city, district, state, state_code, location_type, latitude, longitude, pincode, aliases
       FROM locations
       $whereSql
      ORDER BY state ASC, city ASC, name ASC
      LIMIT {$page['per_page']} OFFSET {$page['offset']}",
    $params
);

$states = location_state_list();
$withCoords = (int) db_value('SELECT COUNT(*) FROM locations WHERE latitude IS NOT NULL', [], 0);

$page_title       = 'Locations & Terminals';
$active_nav       = 'locations';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet'],
    ['label' => 'Locations & Terminals'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Locations & Terminals',
    number_format($total) . ' matching location' . ($total === 1 ? '' : 's') . ' in the directory',
    '<a class="btn btn-primary" href="' . e(url('modules/locations/create.php')) . '">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add location
     </a>'
) ?>

<div class="stat-grid">
    <?= stat_card('Directory size', (int) db_value('SELECT COUNT(*) FROM locations', [], 0), 'bi-geo-alt', 'primary', 'Bus terminals, stands and stops') ?>
    <?= stat_card('States & UTs', count($states), 'bi-map', 'info', 'Coverage across India') ?>
    <?= stat_card('With coordinates', $withCoords, 'bi-pin-map', 'success', 'Usable for map centring') ?>
</div>

<div class="card-fl">
    <form class="toolbar" method="get" action="<?= e(url('modules/locations/index.php')) ?>">
        <div class="toolbar__filters">
            <div class="search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="q">Search locations</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Search by name, city, district or alias">
            </div>

            <label class="visually-hidden" for="state">State</label>
            <select class="form-select" id="state" name="state" style="width:auto;">
                <option value="">All states</option>
                <?php foreach ($states as $state): ?>
                    <option value="<?= e($state['state']) ?>" <?= $stateFilter === $state['state'] ? 'selected' : '' ?>>
                        <?= e($state['state']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="visually-hidden" for="type">Type</label>
            <select class="form-select" id="type" name="type" style="width:auto;">
                <option value="">All types</option>
                <?= option_tags(location_type_options(), $typeFilter) ?>
            </select>

            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>

            <?php if ($search !== '' || $stateFilter !== '' || $typeFilter !== ''): ?>
                <a class="btn btn-ghost" href="<?= e(url('modules/locations/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($locations === []): ?>
        <?= empty_state(
            'No locations found',
            'Try a different search or clear the filters.',
            'bi-geo-alt',
            '<a class="btn btn-outline-secondary" href="' . e(url('modules/locations/index.php')) . '">Clear filters</a>'
        ) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-fl">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>City</th>
                        <th>District</th>
                        <th>State</th>
                        <th>Type</th>
                        <th>Coordinates</th>
                        <th class="cell-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($locations as $location): ?>
                        <tr>
                            <td>
                                <span class="cell-strong"><?= e($location['name']) ?></span>
                                <?php if (!empty($location['aliases'])): ?>
                                    <br><span class="cell-muted">aka <?= e($location['aliases']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($location['city']) ?></td>
                            <td><?= e($location['district'] ?? '—') ?></td>
                            <td>
                                <?= e($location['state']) ?>
                                <?php if (!empty($location['state_code'])): ?>
                                    <br><span class="cell-muted"><?= e($location['state_code']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= status_badge($location['location_type'], location_type_label($location['location_type'])) ?></td>
                            <td class="cell-num">
                                <?= $location['latitude'] !== null && $location['longitude'] !== null
                                    ? e(number_format((float) $location['latitude'], 4) . ', ' . number_format((float) $location['longitude'], 4))
                                    : '<span class="cell-muted">—</span>' ?>
                            </td>
                            <td class="cell-actions">
                                <a class="row-action" href="<?= e(url('modules/locations/edit.php?id=' . (int) $location['id'])) ?>"
                                   title="Edit" aria-label="Edit <?= e($location['name']) ?>">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </a>
                                <form method="post" action="<?= e(url('modules/locations/delete.php?id=' . (int) $location['id'])) ?>"
                                      class="d-inline"
                                      data-confirm="Remove <?= e($location['name']) ?> from the location directory?"
                                      data-confirm-title="Remove location?" data-confirm-button="Remove">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="row-action row-action--danger" title="Remove"
                                            aria-label="Remove <?= e($location['name']) ?>">
                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= render_pagination($page) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
