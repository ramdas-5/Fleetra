<?php
/**
 * Fleetra — Route form (shared by create and edit)
 * ------------------------------------------------------------------
 * Expected variables: $formAction, $values, $errors, $isEdit
 */

declare(strict_types=1);

$fieldClass = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
?>

<form method="post" action="<?= e($formAction) ?>" novalidate>
    <?= csrf_field() ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text">The route could not be saved. Please review the highlighted fields.</span>
        </div>
    <?php endif; ?>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Route identity</h2>
                <p class="card-fl__subtitle">How this service appears on schedules and tickets</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div>
                    <label class="form-label" for="route_code">Route code <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('route_code') ?>"
                           id="route_code" name="route_code" value="<?= e($values['route_code']) ?>"
                           required maxlength="20" placeholder="e.g. R-04">
                    <?php if (isset($errors['route_code'])): ?>
                        <p class="form-error"><?= e($errors['route_code']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Short internal reference, for example R-01.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="route_name">Route name <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('route_name') ?>"
                           id="route_name" name="route_name" value="<?= e($values['route_name']) ?>"
                           required maxlength="140" placeholder="e.g. City Express">
                    <?php if (isset($errors['route_name'])): ?>
                        <p class="form-error"><?= e($errors['route_name']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="source">Starting point <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('source') ?>"
                           id="source" name="source" value="<?= e($values['source']) ?>"
                           required maxlength="120" placeholder="e.g. Bengaluru — Majestic">
                    <?php if (isset($errors['source'])): ?>
                        <p class="form-error"><?= e($errors['source']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="destination">Destination <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('destination') ?>"
                           id="destination" name="destination" value="<?= e($values['destination']) ?>"
                           required maxlength="120" placeholder="e.g. Mysuru">
                    <?php if (isset($errors['destination'])): ?>
                        <p class="form-error"><?= e($errors['destination']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="status">Status <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('status') ?>" id="status" name="status" required>
                        <?= option_tags(route_status_options(), $values['status']) ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <p class="form-error"><?= e($errors['status']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Service details</h2>
                <p class="card-fl__subtitle">Used for fares, tickets and duration estimates</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row form-row--3">
                <div>
                    <label class="form-label" for="distance">Distance (km) <span class="req">*</span></label>
                    <input type="number" class="form-control<?= $fieldClass('distance') ?>"
                           id="distance" name="distance" value="<?= e($values['distance']) ?>"
                           required min="0.1" max="5000" step="0.1" placeholder="e.g. 146.2">
                    <?php if (isset($errors['distance'])): ?>
                        <p class="form-error"><?= e($errors['distance']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="estimated_duration">Duration (minutes) <span class="req">*</span></label>
                    <input type="number" class="form-control<?= $fieldClass('estimated_duration') ?>"
                           id="estimated_duration" name="estimated_duration"
                           value="<?= e($values['estimated_duration']) ?>"
                           required min="5" max="2880" step="5" placeholder="e.g. 195">
                    <?php if (isset($errors['estimated_duration'])): ?>
                        <p class="form-error"><?= e($errors['estimated_duration']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="base_fare">Base fare (<?= e(APP_CURRENCY) ?>) <span class="req">*</span></label>
                    <input type="number" class="form-control<?= $fieldClass('base_fare') ?>"
                           id="base_fare" name="base_fare" value="<?= e($values['base_fare']) ?>"
                           required min="0" max="100000" step="0.01" placeholder="e.g. 380.00">
                    <?php if (isset($errors['base_fare'])): ?>
                        <p class="form-error"><?= e($errors['base_fare']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Default full-route fare applied to bookings.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-fl__footer">
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/routes/index.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                <i class="bi bi-check2" aria-hidden="true"></i>
                <?= $isEdit ? 'Save changes' : 'Create route' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="alert alert-info app-alert" role="alert">
        <i class="bi bi-info-circle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            Stops for this route are managed on the
            <a href="<?= e(url('modules/routes/stops.php?route_id=' . get_int('id'))) ?>">manage stops</a> page,
            where they can be added, edited and reordered.
        </span>
    </div>
<?php endif; ?>
