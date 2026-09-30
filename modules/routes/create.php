<?php
/**
 * Fleetra — Routes / Add route
 * ------------------------------------------------------------------
 * modules/routes/create.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('routes.manage');

$errors = [];
$values = route_form_values();

if (is_post()) {
    require_csrf();

    $result = validate_route_request($_POST);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        $routeId = db_insert('routes', route_db_payload($values));

        log_activity(
            'Created route ' . $values['route_code'],
            'routes',
            $routeId,
            $values['source'] . ' to ' . $values['destination']
        );

        flash('success', 'Route ' . $values['route_code'] . ' was created. Add its stops next.');
        redirect('modules/routes/stops.php?route_id=' . $routeId);
    }
}

$formAction = url('modules/routes/create.php');
$isEdit     = false;

$page_title       = 'Add route';
$active_nav       = 'routes';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/routes/index.php')],
    ['label' => 'Routes', 'url' => url('modules/routes/index.php')],
    ['label' => 'Add route'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Add a route',
    'Define a service between a starting point and a destination',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/routes/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to routes
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
