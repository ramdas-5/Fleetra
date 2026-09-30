<?php
/**
 * Fleetra — Bus form (shared by create and edit)
 * ------------------------------------------------------------------
 * Expected variables set by the including page:
 *   $formAction  string  Form POST target.
 *   $values      array   Current field values.
 *   $errors      array   Field => message.
 *   $isEdit      bool    True on the edit screen.
 *   $busImage    ?string Existing image filename.
 */

declare(strict_types=1);

$fieldClass = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
$currentYear = (int) date('Y');
?>

<form method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text">
                The bus could not be saved. Please review the highlighted fields.
            </span>
        </div>
    <?php endif; ?>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Basic information</h2>
                <p class="card-fl__subtitle">How this vehicle is identified in the fleet</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div>
                    <label class="form-label" for="bus_number">Bus number <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('bus_number') ?>"
                           id="bus_number" name="bus_number" value="<?= e($values['bus_number']) ?>"
                           required maxlength="20" placeholder="e.g. FLT-106">
                    <?php if (isset($errors['bus_number'])): ?>
                        <p class="form-error"><?= e($errors['bus_number']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Fleetra's internal identifier for this vehicle.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="registration_number">Registration number <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('registration_number') ?>"
                           id="registration_number" name="registration_number"
                           value="<?= e($values['registration_number']) ?>" required maxlength="30"
                           placeholder="e.g. KA-01-MN-2468">
                    <?php if (isset($errors['registration_number'])): ?>
                        <p class="form-error"><?= e($errors['registration_number']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="bus_type">Bus type <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('bus_type') ?>" id="bus_type" name="bus_type" required>
                        <?= option_tags(bus_type_options(), $values['bus_type']) ?>
                    </select>
                    <?php if (isset($errors['bus_type'])): ?>
                        <p class="form-error"><?= e($errors['bus_type']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="status">Operational status <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('status') ?>" id="status" name="status" required>
                        <?= option_tags(bus_status_options(), $values['status']) ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <p class="form-error"><?= e($errors['status']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Only active buses can be assigned to schedules.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Vehicle specifications</h2>
                <p class="card-fl__subtitle">Manufacturer, capacity and running information</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row form-row--3">
                <div>
                    <label class="form-label" for="manufacturer">Manufacturer</label>
                    <input type="text" class="form-control<?= $fieldClass('manufacturer') ?>"
                           id="manufacturer" name="manufacturer" value="<?= e($values['manufacturer']) ?>"
                           maxlength="80" placeholder="e.g. Volvo">
                    <?php if (isset($errors['manufacturer'])): ?>
                        <p class="form-error"><?= e($errors['manufacturer']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="model">Model</label>
                    <input type="text" class="form-control<?= $fieldClass('model') ?>"
                           id="model" name="model" value="<?= e($values['model']) ?>"
                           maxlength="80" placeholder="e.g. 9400 Intercity">
                    <?php if (isset($errors['model'])): ?>
                        <p class="form-error"><?= e($errors['model']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="manufacturing_year">Manufacturing year</label>
                    <input type="number" class="form-control<?= $fieldClass('manufacturing_year') ?>"
                           id="manufacturing_year" name="manufacturing_year"
                           value="<?= e($values['manufacturing_year']) ?>"
                           min="1980" max="<?= $currentYear + 1 ?>" step="1" placeholder="<?= $currentYear ?>">
                    <?php if (isset($errors['manufacturing_year'])): ?>
                        <p class="form-error"><?= e($errors['manufacturing_year']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="capacity">Seating capacity <span class="req">*</span></label>
                    <input type="number" class="form-control<?= $fieldClass('capacity') ?>"
                           id="capacity" name="capacity" value="<?= e($values['capacity']) ?>"
                           required min="1" max="80" step="1" placeholder="e.g. 45">
                    <?php if (isset($errors['capacity'])): ?>
                        <p class="form-error"><?= e($errors['capacity']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="fuel_type">Fuel type <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('fuel_type') ?>" id="fuel_type" name="fuel_type" required>
                        <?= option_tags(fuel_type_options(), $values['fuel_type']) ?>
                    </select>
                    <?php if (isset($errors['fuel_type'])): ?>
                        <p class="form-error"><?= e($errors['fuel_type']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="current_mileage">Current odometer (km)</label>
                    <input type="number" class="form-control<?= $fieldClass('current_mileage') ?>"
                           id="current_mileage" name="current_mileage"
                           value="<?= e($values['current_mileage']) ?>"
                           min="0" max="9999999" step="0.01" placeholder="e.g. 184320.50">
                    <?php if (isset($errors['current_mileage'])): ?>
                        <p class="form-error"><?= e($errors['current_mileage']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Used to plan servicing intervals.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Vehicle photo</h2>
                <p class="card-fl__subtitle">JPG, PNG or WEBP up to 2 MB</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="avatar-editor">
                <?php if ($busImage !== null): ?>
                    <div class="avatar-editor__preview">
                        <img class="avatar avatar--xl" src="<?= e(upload_url($busImage, 'buses')) ?>"
                             alt="<?= e($values['bus_number']) ?>">
                    </div>
                <?php endif; ?>

                <div class="avatar-editor__fields">
                    <label class="form-label" for="image">
                        <?= $busImage !== null ? 'Replace the current photo' : 'Upload a photo' ?>
                    </label>
                    <input type="file" class="form-control<?= $fieldClass('image') ?>"
                           id="image" name="image" accept="image/jpeg,image/png,image/webp">
                    <?php if (isset($errors['image'])): ?>
                        <p class="form-error"><?= e($errors['image']) ?></p>
                    <?php endif; ?>

                    <?php if ($busImage !== null): ?>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="remove_image" name="remove_image">
                            <label class="form-check-label" for="remove_image" style="font-size:13.5px;">
                                Remove the current photo
                            </label>
                        </div>
                    <?php endif; ?>

                    <p class="avatar-editor__hint">
                        A clear photo helps drivers and dispatchers confirm the right vehicle at a glance.
                    </p>
                </div>
            </div>
        </div>

        <div class="card-fl__footer">
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/buses/index.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                <i class="bi bi-check2" aria-hidden="true"></i>
                <?= $isEdit ? 'Save changes' : 'Add bus to fleet' ?>
            </button>
        </div>
    </div>
</form>
