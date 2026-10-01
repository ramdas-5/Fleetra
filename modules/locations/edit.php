<?php
/**
 * Fleetra — Location directory / edit
 * ------------------------------------------------------------------
 * modules/locations/edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/_logic.php';

require_permission('locations.manage');

$locationId = get_int('id');

if ($locationId <= 0) {
    abort_not_found('No location was specified.');
}

$location = find_location($locationId);

if ($location === null) {
    abort_not_found('That location does not exist.');
}

$values = location_form_values([
    'name'          => $location['name'],
    'city'          => $location['city'],
    'district'      => $location['district'] ?? '',
    'state'         => $location['state'],
    'state_code'    => $location['state_code'] ?? '',
    'location_type' => $location['location_type'],
    'latitude'      => $location['latitude'] ?? '',
    'longitude'     => $location['longitude'] ?? '',
    'pincode'       => $location['pincode'] ?? '',
    'aliases'       => $location['aliases'] ?? '',
]);
$errors = [];

if (is_post()) {
    require_csrf();

    $values = location_form_values($_POST);
    $result = validate_location_form($values, $locationId);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        db_update('locations', $result['data'], ['id' => $locationId]);

        log_activity('Updated location ' . $values['name'], 'locations', $locationId, $values['city'] . ', ' . $values['state']);
        flash('success', $values['name'] . ' was updated.');
        redirect('modules/locations/index.php');
    }
}

$isEdit           = true;
$page_title       = 'Edit location';
$active_nav       = 'locations';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Locations & Terminals', 'url' => url('modules/locations/index.php')],
    ['label' => 'Edit location'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header('Edit location', 'Update ' . $location['name']) ?>

<?php require __DIR__ . '/_form.php'; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
