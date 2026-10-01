<?php
/**
 * Fleetra — Location directory / form
 * ------------------------------------------------------------------
 * modules/locations/_form.php
 *
 * @var array<string, mixed>  $values
 * @var array<int, string>    $errors
 * @var bool                  $isEdit
 */

declare(strict_types=1);

require_once __DIR__ . '/_logic.php';

$isEdit = $isEdit ?? false;
$errors = $errors ?? [];
$values = array_merge([
    'name'          => '',
    'city'          => '',
    'district'      => '',
    'state'         => '',
    'state_code'    => '',
    'location_type' => 'bus_stand',
    'latitude'      => '',
    'longitude'     => '',
    'pincode'       => '',
    'aliases'       => '',
], $values ?? []);

$action = $isEdit
    ? url('modules/locations/edit.php?id=' . (int) ($locationId ?? 0))
    : url('modules/locations/create.php');
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            <strong>Please fix the following:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="form-card card-fl" novalidate>
    <?= csrf_field() ?>

    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title"><?= $isEdit ? 'Edit location' : 'Add location' ?></h2>
            <p class="card-fl__subtitle">Reference data used by passenger search and autocomplete</p>
        </div>
    </div>

    <div class="card-fl__body">
        <div class="form-row">
            <div>
                <label class="form-label" for="name">Terminal / stop name <span class="req">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required maxlength="180"
                       value="<?= e($values['name']) ?>" placeholder="e.g. Esplanade Bus Terminus">
            </div>
            <div>
                <label class="form-label" for="city">City <span class="req">*</span></label>
                <input type="text" class="form-control" id="city" name="city" required maxlength="120"
                       value="<?= e($values['city']) ?>" placeholder="e.g. Kolkata">
            </div>
        </div>

        <div class="form-row">
            <div>
                <label class="form-label" for="district">District</label>
                <input type="text" class="form-control" id="district" name="district" maxlength="120"
                       value="<?= e($values['district']) ?>" placeholder="e.g. Kolkata">
            </div>
            <div>
                <label class="form-label" for="state">State / UT <span class="req">*</span></label>
                <input type="text" class="form-control" id="state" name="state" required maxlength="120"
                       value="<?= e($values['state']) ?>" placeholder="e.g. West Bengal">
            </div>
        </div>

        <div class="form-row form-row--3">
            <div>
                <label class="form-label" for="state_code">State code</label>
                <input type="text" class="form-control text-uppercase" id="state_code" name="state_code" maxlength="3"
                       value="<?= e($values['state_code']) ?>" placeholder="e.g. WB">
            </div>
            <div>
                <label class="form-label" for="location_type">Type</label>
                <select class="form-select" id="location_type" name="location_type">
                    <?= option_tags(location_type_options(), $values['location_type']) ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="pincode">PIN code</label>
                <input type="text" class="form-control" id="pincode" name="pincode" maxlength="12"
                       value="<?= e($values['pincode']) ?>" placeholder="Optional">
            </div>
        </div>

        <div class="form-row">
            <div>
                <label class="form-label" for="latitude">Latitude</label>
                <input type="number" class="form-control" id="latitude" name="latitude" step="0.0000001"
                       min="-90" max="90" value="<?= e($values['latitude']) ?>" placeholder="e.g. 22.5600">
            </div>
            <div>
                <label class="form-label" for="longitude">Longitude</label>
                <input type="number" class="form-control" id="longitude" name="longitude" step="0.0000001"
                       min="-180" max="180" value="<?= e($values['longitude']) ?>" placeholder="e.g. 88.3510">
            </div>
        </div>

        <div>
            <label class="form-label" for="aliases">Search aliases</label>
            <input type="text" class="form-control" id="aliases" name="aliases" maxlength="240"
                   value="<?= e($values['aliases']) ?>" placeholder="Alternate or former names, comma separated">
            <p class="form-text">A common name still finds this location while searching (e.g. Calcutta, Bangalore).</p>
        </div>
    </div>

    <div class="card-fl__footer form-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url('modules/locations/index.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg" aria-hidden="true"></i> <?= $isEdit ? 'Save changes' : 'Add location' ?>
        </button>
    </div>
</form>
