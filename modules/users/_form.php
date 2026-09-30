<?php
/**
 * Fleetra — User form (shared by create and edit)
 * ------------------------------------------------------------------
 * Expected variables: $formAction, $values, $errors, $isEdit, $userId
 */

declare(strict_types=1);

$fieldClass  = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
$roleOptions = assignable_role_options($isEdit ? ($values['role'] ?? null) : null);
$isDriverAccount = ($values['role'] ?? '') === 'driver';
?>

<form method="post" action="<?= e($formAction) ?>" novalidate>
    <?= csrf_field() ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text">The account could not be saved. Please review the highlighted fields.</span>
        </div>
    <?php endif; ?>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Account holder</h2>
                <p class="card-fl__subtitle">Identity and sign-in details</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div>
                    <label class="form-label" for="name">Full name <span class="req">*</span></label>
                    <input type="text" class="form-control<?= $fieldClass('name') ?>" id="name" name="name"
                           value="<?= e($values['name']) ?>" required maxlength="120" placeholder="e.g. Priya Nair">
                    <?php if (isset($errors['name'])): ?><p class="form-error"><?= e($errors['name']) ?></p><?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="email">Email address <span class="req">*</span></label>
                    <input type="email" class="form-control<?= $fieldClass('email') ?>" id="email" name="email"
                           value="<?= e($values['email']) ?>" required placeholder="name@fleetra.com">
                    <?php if (isset($errors['email'])): ?>
                        <p class="form-error"><?= e($errors['email']) ?></p>
                    <?php else: ?>
                        <p class="form-text">This is the sign-in address.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="phone">Mobile number</label>
                    <input type="tel" class="form-control<?= $fieldClass('phone') ?>" id="phone" name="phone"
                           value="<?= e($values['phone']) ?>" placeholder="+91 98860 41120">
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
                                ? 'Setting a password here also cancels that user\'s pending reset links.'
                                : 'Share this with the account holder; they can change it from their profile.' ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Role &amp; access</h2>
                <p class="card-fl__subtitle">What this account is allowed to do</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div>
                    <label class="form-label" for="role">Role <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('role') ?>" id="role" name="role" required>
                        <?= option_tags($roleOptions, $values['role']) ?>
                    </select>
                    <?php if (isset($errors['role'])): ?>
                        <p class="form-error"><?= e($errors['role']) ?></p>
                    <?php else: ?>
                        <p class="form-text">
                            <?= $isDriverAccount
                                ? 'This is a driver account. Licence and vehicle assignment are managed in the Drivers module.'
                                : 'Driver accounts are created from the Drivers module so the licence and employment records stay in sync.' ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="status">Account status <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('status') ?>" id="status" name="status" required>
                        <?= option_tags(account_status_options(), $values['status']) ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <p class="form-error"><?= e($errors['status']) ?></p>
                    <?php else: ?>
                        <p class="form-text">
                            Only active accounts can sign in. Suspending an account keeps all of its history.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isEdit && $userId === user_id()): ?>
                <div class="alert alert-info app-alert mt-3 mb-0" role="alert">
                    <i class="bi bi-info-circle app-alert__icon" aria-hidden="true"></i>
                    <span class="app-alert__text">
                        You are editing your own account. Your role and status are protected so you cannot lock
                        yourself out of Fleetra.
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl__footer">
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/users/index.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                <i class="bi bi-check2" aria-hidden="true"></i>
                <?= $isEdit ? 'Save changes' : 'Create account' ?>
            </button>
        </div>
    </div>
</form>
