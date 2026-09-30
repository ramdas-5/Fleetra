<?php
/**
 * Fleetra — Maintenance form (shared by create and edit)
 * ------------------------------------------------------------------
 * Expected variables set by the including page:
 *   $formAction  string  Form POST target.
 *   $values      array   Current field values.
 *   $errors      array   Field => message.
 *   $isEdit      bool    True on the edit screen.
 */

declare(strict_types=1);

$fieldClass = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
$busOptions = maintenance_bus_options(true);
?>

<form method="post" action="<?= e($formAction) ?>" novalidate>
    <?= csrf_field() ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text">
                The maintenance record could not be saved. Please review the highlighted fields.
            </span>
        </div>
    <?php endif; ?>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Job details</h2>
                <p class="card-fl__subtitle">Which vehicle, what kind of work and where it is being done</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div>
                    <label class="form-label" for="bus_id">Bus <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('bus_id') ?>" id="bus_id" name="bus_id" required>
                        <?= option_tags($busOptions, $values['bus_id'], 'Select a bus…') ?>
                    </select>
                    <?php if (isset($errors['bus_id'])): ?>
                        <p class="form-error"><?= e($errors['bus_id']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="maintenance_type">Maintenance type <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('maintenance_type') ?>" id="maintenance_type" name="maintenance_type" required>
                        <?= option_tags(maintenance_type_options(), $values['maintenance_type']) ?>
                    </select>
                    <?php if (isset($errors['maintenance_type'])): ?>
                        <p class="form-error"><?= e($errors['maintenance_type']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="status">Status <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('status') ?>" id="status" name="status" required>
                        <?= option_tags(maintenance_status_options(), $values['status']) ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <p class="form-error"><?= e($errors['status']) ?></p>
                    <?php else: ?>
                        <p class="form-text">While a job is in progress the bus is marked as in maintenance.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="service_provider">Service provider</label>
                    <input type="text" class="form-control<?= $fieldClass('service_provider') ?>"
                           id="service_provider" name="service_provider"
                           value="<?= e($values['service_provider']) ?>" maxlength="140"
                           placeholder="e.g. Volvo Service Centre, Bengaluru">
                    <?php if (isset($errors['service_provider'])): ?>
                        <p class="form-error"><?= e($errors['service_provider']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Work and schedule</h2>
                <p class="card-fl__subtitle">What is being done, when, and when it is next due</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div class="span-2">
                    <label class="form-label" for="description">Description <span class="req">*</span></label>
                    <textarea class="form-control<?= $fieldClass('description') ?>" id="description" name="description"
                              rows="3" required maxlength="1000"
                              placeholder="e.g. Full periodic service: engine oil, filters, coolant top-up and 40-point inspection."><?= e($values['description']) ?></textarea>
                    <?php if (isset($errors['description'])): ?>
                        <p class="form-error"><?= e($errors['description']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Describe the fault found or the work carried out.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="service_date">Service date <span class="req">*</span></label>
                    <input type="date" class="form-control<?= $fieldClass('service_date') ?>"
                           id="service_date" name="service_date" value="<?= e($values['service_date']) ?>" required>
                    <?php if (isset($errors['service_date'])): ?>
                        <p class="form-error"><?= e($errors['service_date']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="next_service_date">Next service due</label>
                    <input type="date" class="form-control<?= $fieldClass('next_service_date') ?>"
                           id="next_service_date" name="next_service_date" value="<?= e($values['next_service_date']) ?>">
                    <?php if (isset($errors['next_service_date'])): ?>
                        <p class="form-error"><?= e($errors['next_service_date']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Used to build the maintenance diary and due reminders.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="odometer_reading">Odometer at service (km)</label>
                    <input type="number" class="form-control<?= $fieldClass('odometer_reading') ?>"
                           id="odometer_reading" name="odometer_reading"
                           value="<?= e($values['odometer_reading']) ?>"
                           min="0" max="9999999" step="0.01" placeholder="e.g. 184320.50">
                    <?php if (isset($errors['odometer_reading'])): ?>
                        <p class="form-error"><?= e($errors['odometer_reading']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Raising this updates the bus odometer automatically.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="cost">Cost (<?= e(APP_CURRENCY) ?>) <span class="req">*</span></label>
                    <input type="number" class="form-control<?= $fieldClass('cost') ?>"
                           id="cost" name="cost" value="<?= e($values['cost']) ?>"
                           min="0" max="9999999" step="0.01" required>
                    <?php if (isset($errors['cost'])): ?>
                        <p class="form-error"><?= e($errors['cost']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="span-2">
                    <label class="form-label" for="remarks">Remarks</label>
                    <input type="text" class="form-control<?= $fieldClass('remarks') ?>"
                           id="remarks" name="remarks" value="<?= e($values['remarks']) ?>" maxlength="255"
                           placeholder="Optional internal note">
                    <?php if (isset($errors['remarks'])): ?>
                        <p class="form-error"><?= e($errors['remarks']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-fl__footer">
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/maintenance/index.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                <i class="bi bi-check2" aria-hidden="true"></i>
                <?= $isEdit ? 'Save changes' : 'Log maintenance' ?>
            </button>
        </div>
    </div>
</form>
