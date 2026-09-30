<?php
/**
 * Fleetra — Fleet / Edit bus
 * ------------------------------------------------------------------
 * modules/buses/edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('fleet.manage');

$busId = get_int('id');

if ($busId <= 0) {
    abort_not_found('No bus was specified.');
}

$bus = find_or_404('buses', $busId);

$errors   = [];
$values   = bus_form_values($bus);
$busImage = $bus['image'] !== null ? (string) $bus['image'] : null;

if (is_post()) {
    require_csrf();

    $result      = validate_bus_request($_POST, $busId);
    $errors      = $result['errors'];
    $values      = $result['values'];
    $newImage    = $result['newImage'];
    $removeImage = $result['removeImage'];

    if ($errors === []) {
        $payload = bus_db_payload($values);

        if ($newImage !== null) {
            $payload['image'] = $newImage;
        } elseif ($removeImage) {
            $payload['image'] = null;
        }

        db_update('buses', $payload, ['id' => $busId]);

        // Remove the superseded file so uploads/ does not grow forever.
        if ($busImage !== null && ($newImage !== null || $removeImage)) {
            delete_upload($busImage, 'buses');
        }

        log_activity(
            'Updated bus ' . $values['bus_number'],
            'buses',
            $busId,
            'Fleet record updated'
        );

        flash('success', 'Bus ' . $values['bus_number'] . ' was updated.');
        redirect('modules/buses/view.php?id=' . $busId);
    }

    // Discard a newly uploaded file when validation failed elsewhere.
    if ($newImage !== null) {
        delete_upload($newImage, 'buses');
    }

    $busImage = $removeImage ? null : $busImage;
}

$formAction = url('modules/buses/edit.php?id=' . $busId);
$isEdit     = true;

$page_title       = 'Edit ' . $bus['bus_number'];
$active_nav       = 'buses';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/buses/index.php')],
    ['label' => $bus['bus_number'], 'url' => url('modules/buses/view.php?id=' . $busId)],
    ['label' => 'Edit'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Edit ' . $bus['bus_number'],
    'Update the vehicle record for ' . $bus['registration_number'],
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/buses/view.php?id=' . $busId)) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to bus
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
