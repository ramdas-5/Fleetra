<?php
/**
 * Fleetra — Fleet / Add bus
 * ------------------------------------------------------------------
 * modules/buses/create.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('fleet.manage');

$errors = [];
$values = bus_form_values();
$busImage = null;

if (is_post()) {
    require_csrf();

    $result   = validate_bus_request($_POST, null);
    $errors   = $result['errors'];
    $values   = $result['values'];
    $busImage = $result['newImage'];

    if ($errors === []) {
        $payload = bus_db_payload($values);

        if ($busImage !== null) {
            $payload['image'] = $busImage;
        }

        $busId = db_insert('buses', $payload);

        log_activity(
            'Created bus ' . $values['bus_number'],
            'buses',
            $busId,
            'Added ' . $values['registration_number'] . ' to the fleet'
        );
        fleetra_log('Bus #' . $busId . ' (' . $values['bus_number'] . ') created by user #' . user_id(), 'INFO');

        flash('success', 'Bus ' . $values['bus_number'] . ' was added to the fleet.');
        redirect('modules/buses/view.php?id=' . $busId);
    }

    // The upload succeeded but the row did not save — do not leave the file behind.
    if ($busImage !== null) {
        delete_upload($busImage, 'buses');
        $busImage = null;
    }
}

$formAction = url('modules/buses/create.php');
$isEdit     = false;

$page_title       = 'Add bus';
$active_nav       = 'buses';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/buses/index.php')],
    ['label' => 'Add bus'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Add a bus',
    'Register a new vehicle in the Fleetra fleet',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/buses/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to fleet
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
