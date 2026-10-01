<?php
/**
 * Fleetra — Location directory / create
 * ------------------------------------------------------------------
 * modules/locations/create.php
 */

declare(strict_types=1);

require_once __DIR__ . '/_logic.php';

require_permission('locations.manage');

$values = location_form_values(is_post() ? $_POST : []);
$errors = [];

if (is_post()) {
    require_csrf();

    $result = validate_location_form($values);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        $id = db_insert('locations', $result['data']);

        log_activity('Added location ' . $values['name'], 'locations', $id, $values['city'] . ', ' . $values['state']);
        flash('success', $values['name'] . ' was added to the location directory.');
        redirect('modules/locations/index.php');
    }
}

$isEdit = false;
$page_title       = 'Add location';
$active_nav       = 'locations';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Locations & Terminals', 'url' => url('modules/locations/index.php')],
    ['label' => 'Add location'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header('Add location', 'Add a bus terminal, stand, stop or landmark to the directory') ?>

<?php require __DIR__ . '/_form.php'; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
