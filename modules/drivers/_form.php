<?php
/**
 * Fleetra — Driver form (shared by create and edit)
 * ------------------------------------------------------------------
 * Expected variables:
 *   $formAction string, $values array, $errors array, $isEdit bool,
 *   $buses array (assignable buses)
 */

declare(strict_types=1);

$fieldClass = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
?>

<form method="post" action="<?= e($formAction) ?>" novalidate>
    <?= csrf_field() ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text">The driver record could not be saved. Please review the highlighted fields.</span>
        </div>
    <?php endif; ?>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Driver account</h2>
                <p class="card-fl__subtitle">The login this driver uses to access Fleetra</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div>
                    <label class="form-label" for="name">Full name <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('name') ?>" id="name" name="name"
                           value="<?= e($values['name']) ?>" required maxlength="120" placeholder="e.g. Suresh Kumar">
                    <?php if (isset($errors['name'])): ?><p class="form-error"><?= e($errors['name']) ?></p><?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="email">Email address <span class="req">*</span></label>
                    <input type="email" class="form-control<?= $fieldClass('email') ?>" id="email" name="email"
                           value="<?= e($values['email']) ?>" required placeholder="driver@fleetra.com">
                    <?php if (isset($errors['email'])): ?>
                        <p class="form-error"><?= e($errors['email']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Used to sign in to the driver dashboard.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="phone">Mobile number</label>
                    <input type="tel" class="form-control<?= $fieldClass('phone') ?>" id="phone" name="phone"
                           value="<?= e($values['phone']) ?>" placeholder="+91 90080 12345">
                    <?php if (isset($errors['phone'])): ?><p class="form-error"><?= e($errors['phone']) ?></p><?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="password">
                        <?= $isEdit ? 'Reset password' : 'Initial password' ?>
                        <?php if (!$isEdit): ?><span class="req">*</span><?php endif; ?>
                    </label>
                    <div class="input-group">
                        <input type="password" class="form-control<?= $fieldClass('password') ?>"
                               id="password" name="password" <?= $isEdit ? '' : 'required' ?>
                               autocomplete="new-password"
                               placeholder="<?= $isEdit ? 'Leave blank to keep the current password' : 'At least 8 characters' ?>">
                        <button class="btn btn-outline-secondary" type="button"
                                data-toggle-password="password" aria-label="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <p class="form-error"><?= e($errors['password']) ?></p>
                    <?php else: ?>
                        <p class="form-text">
                            <?= $isEdit
                                ? 'Only fill this in to set a new password for the driver.'
                                : 'Share this with the driver; they can change it from their profile.' ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Licence &amp; employment</h2>
                <p class="card-fl__subtitle">Compliance details checked before every assignment</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row form-row--3">
                <div>
                    <label class="form-label" for="employee_id">Employee ID <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('employee_id') ?>"
                           id="employee_id" name="employee_id" value="<?= e($values['employee_id']) ?>"
                           required maxlength="20" placeholder="e.g. DRV-1004">
                    <?php if (isset($errors['employee_id'])): ?>
                        <p class="form-error"><?= e($errors['employee_id']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="license_number">Licence number <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('license_number') ?>"
                           id="license_number" name="license_number" value="<?= e($values['license_number']) ?>"
                           required maxlength="40" placeholder="e.g. KA0120160004521">
                    <?php if (isset($errors['license_number'])): ?>
                        <p class="form-error"><?= e($errors['license_number']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="license_expiry">Licence expiry <span class="req">*</span></label>
                    <input type="date" class="form-control<?= $fieldClass('license_expiry') ?>"
                           id="license_expiry" name="license_expiry" value="<?= e($values['license_expiry']) ?>" required>
                    <?php if (isset($errors['license_expiry'])): ?>
                        <p class="form-error"><?= e($errors['license_expiry']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="employment_status">Employment status <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('employment_status') ?>"
                            id="employment_status" name="employment_status" required>
                        <?= option_tags(employment_status_options(), $values['employment_status']) ?>
                    </select>
                    <?php if (isset($errors['employment_status'])): ?>
                        <p class="form-error"><?= e($errors['employment_status']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="experience_years">Experience (years)</label>
                    <input type="number" class="form-control<?= $fieldClass('experience_years') ?>"
                           id="experience_years" name="experience_years" value="<?= e($values['experience_years']) ?>"
                           min="0" max="60" step="1" placeholder="e.g. 8">
                    <?php if (isset($errors['experience_years'])): ?>
                        <p class="form-error"><?= e($errors['experience_years']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="joining_date">Joining date</label>
                    <input type="date" class="form-control<?= $fieldClass('joining_date') ?>"
                           id="joining_date" name="joining_date" value="<?= e($values['joining_date']) ?>">
                    <?php if (isset($errors['joining_date'])): ?>
                        <p class="form-error"><?= e($errors['joining_date']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="date_of_birth">Date of birth</label>
                    <input type="date" class="form-control<?= $fieldClass('date_of_birth') ?>"
                           id="date_of_birth" name="date_of_birth" value="<?= e($values['date_of_birth']) ?>">
                    <?php if (isset($errors['date_of_birth'])): ?>
                        <p class="form-error"><?= e($errors['date_of_birth']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Must be at least 18 years ago.</p>
                    <?php endif; ?>
                </div>

                <div class="span-2">
                    <label class="form-label" for="address">Address</label>
                    <input type="text" class="form-control<?= $fieldClass('address') ?>" id="address" name="address"
                           value="<?= e($values['address']) ?>" maxlength="255"
                           placeholder="e.g. 14, 3rd Cross, Vijayanagar, Bengaluru 560040">
                    <?php if (isset($errors['address'])): ?><p class="form-error"><?= e($errors['address']) ?></p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Bus assignment</h2>
                <p class="card-fl__subtitle">Only active buses with no other driver can be assigned</p>
            </div>
        </div>

        <div class="card-fl__body">
            <label class="form-label" for="assigned_bus_id">Assigned bus</label>
            <select class="form-select<?= $fieldClass('assigned_bus_id') ?>" id="assigned_bus_id" name="assigned_bus_id">
                <option value="">No bus assigned</option>
                <?php foreach ($buses as $busOption): ?>
                    <?php
                    $label = $busOption['bus_number'] . ' · ' . $busOption['registration_number']
                        . ' · ' . (int) $busOption['capacity'] . ' seats';

                    if ($busOption['status'] !== 'active') {
                        $label .= ' (' . labelize((string) $busOption['status']) . ')';
                    }
                    ?>
                    <option value="<?= (int) $busOption['id'] ?>"
                        <?= ((string) $busOption['id'] === (string) $values['assigned_bus_id']) ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['assigned_bus_id'])): ?>
                <p class="form-error"><?= e($errors['assigned_bus_id']) ?></p>
            <?php else: ?>
                <p class="form-text">
                    A driver with an expired licence cannot be assigned. Release the current driver before
                    assigning their bus to someone else.
                </p>
            <?php endif; ?>
        </div>

        <div class="card-fl__footer">
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/drivers/index.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                <i class="bi bi-check2" aria-hidden="true"></i>
                <?= $isEdit ? 'Save changes' : 'Add driver' ?>
            </button>
        </div>
    </div>
</form>
