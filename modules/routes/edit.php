<?php
/**
 * Fleetra — Routes / Edit route
 * ------------------------------------------------------------------
 * modules/routes/edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('routes.manage');

$routeId = get_int('id');

if ($routeId <= 0) {
    abort_not_found('No route was specified.');
}

$route = find_or_404('routes', $routeId);

$errors = [];
$values = route_form_values($route);

if (is_post()) {
    require_csrf();

    $result = validate_route_request($_POST, $routeId);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        db_update('routes', route_db_payload($values), ['id' => $routeId]);

        log_activity('Updated route ' . $values['route_code'], 'routes', $routeId, 'Route details updated');

        flash('success', 'Route ' . $values['route_code'] . ' was updated.');
        redirect('modules/routes/view.php?id=' . $routeId);
    }
}

$stopCount = (int) db_value('SELECT COUNT(*) FROM stops WHERE route_id = ?', [$routeId], 0);

$formAction = url('modules/routes/edit.php?id=' . $routeId);
$isEdit     = true;

$page_title       = 'Edit ' . $route['route_code'];
$active_nav       = 'routes';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/routes/index.php')],
    ['label' => 'Routes', 'url' => url('modules/routes/index.php')],
    ['label' => (string) $route['route_code'], 'url' => url('modules/routes/view.php?id=' . $routeId)],
    ['label' => 'Edit'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Edit ' . $route['route_code'],
    $route['route_name'] . ' · ' . $stopCount . ' stop' . ($stopCount === 1 ? '' : 's') . ' defined',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/routes/stops.php?route_id=' . $routeId)) . '">
        <i class="bi bi-geo-alt" aria-hidden="true"></i> Manage stops
     </a>
     <a class="btn btn-outline-secondary" href="' . e(url('modules/routes/view.php?id=' . $routeId)) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to route
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
