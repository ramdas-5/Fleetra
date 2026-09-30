<?php
/**
 * Fleetra — Schedule form (shared by create and edit)
 * ------------------------------------------------------------------
 * Expected variables: $formAction, $values, $errors, $isEdit, $scheduleId, $conflicts
 */

declare(strict_types=1);

$fieldClass    = static fn (string $field): bool => isset($errors[$field]);
$routeOptions  = schedule_route_options(true);
$routeDurations = schedule_route_durations();
$busOptions    = schedulable_bus_options(true);
$driverOptions = schedulable_driver_options(true);
$conflicts     = $conflicts ?? ['bus' => [], 'driver' => []];
?>

<form method="post" action="<?= e($formAction) ?>" novalidate data-schedule-planner>
    <?= csrf_field() ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger app-alert" role="alert">
            <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
            <span class="app-alert__text">
                This departure could not be saved. Please review the highlighted fields.
            </span>
        </div>
    <?php endif; ?>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Route &amp; timing</h2>
                <p class="card-fl__subtitle">What runs, and when it leaves</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div class="span-2">
                    <label class="form-label" for="route_id">Route <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('route_id') ? ' is-invalid' : '' ?>" id="route_id" name="route_id" required
                            data-route-select>
                        <option value="">Select a route</option>
                        <?php foreach ($routeOptions as $routeId => $routeLabel): ?>
                            <option value="<?= e((string) $routeId) ?>"
                                    data-duration="<?= (int) ($routeDurations[$routeId] ?? 0) ?>"
                                <?= (string) $routeId === (string) $values['route_id'] ? ' selected' : '' ?>>
                                <?= e($routeLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['route_id'])): ?>
                        <p class="form-error"><?= e($errors['route_id']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Only active routes can be scheduled. Stops come from the route.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="departure_time">Departure time <span class="req">*</span></label>
                    <input type="time" class="form-control<?= $fieldClass('departure_time') ? ' is-invalid' : '' ?>"
                           id="departure_time" name="departure_time" required
                           value="<?= e($values['departure_time']) ?>" data-departure-input>
                    <?php if (isset($errors['departure_time'])): ?>
                        <p class="form-error"><?= e($errors['departure_time']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Local time at the source stop.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="arrival_time">Arrival time <span class="req">*</span></label>
                    <input type="time" class="form-control<?= $fieldClass('arrival_time') ? ' is-invalid' : '' ?>"
                           id="arrival_time" name="arrival_time" required
                           value="<?= e($values['arrival_time']) ?>" data-arrival-input>
                    <?php if (isset($errors['arrival_time'])): ?>
                        <p class="form-error"><?= e($errors['arrival_time']) ?></p>
                    <?php else: ?>
                        <p class="form-text">
                            Filled in from the route duration when you leave it untouched. Times after midnight are
                            treated as the next day.
                        </p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="schedule_date">Service date <span class="req">*</span></label>
                    <input type="date" class="form-control<?= $fieldClass('schedule_date') ? ' is-invalid' : '' ?>"
                           id="schedule_date" name="schedule_date" required
                           value="<?= e($values['schedule_date']) ?>">
                    <?php if (isset($errors['schedule_date'])): ?>
                        <p class="form-error"><?= e($errors['schedule_date']) ?></p>
                    <?php else: ?>
                        <p class="form-text">
                            <?= $isEdit
                                ? 'Historic dates can still be corrected.'
                                : 'New departures must be dated today or later.' ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="status">Schedule status <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('status') ? ' is-invalid' : '' ?>" id="status" name="status" required>
                        <?= option_tags(schedule_status_options(), $values['status']) ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <p class="form-error"><?= e($errors['status']) ?></p>
                    <?php else: ?>
                        <p class="form-text">
                            Setting this to <strong>Cancelled</strong> cancels the linked trip, releases every seat and
                            refunds paid fares.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Bus &amp; crew</h2>
                <p class="card-fl__subtitle">Who operates this departure</p>
            </div>
        </div>

        <div class="card-fl__body">
            <div class="form-row">
                <div>
                    <label class="form-label" for="bus_id">Bus <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('bus_id') ? ' is-invalid' : '' ?>" id="bus_id" name="bus_id" required>
                        <?= option_tags($busOptions, $values['bus_id'], 'Select a bus') ?>
                    </select>
                    <?php if (isset($errors['bus_id'])): ?>
                        <p class="form-error"><?= e($errors['bus_id']) ?></p>
                    <?php else: ?>
                        <p class="form-text">A bus cannot be in two places at once — overlaps are blocked.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="driver_id">Driver <span class="req">*</span></label>
                    <select class="form-select<?= $fieldClass('driver_id') ? ' is-invalid' : '' ?>" id="driver_id" name="driver_id" required>
                        <?= option_tags($driverOptions, $values['driver_id'], 'Select a driver') ?>
                    </select>
                    <?php if (isset($errors['driver_id'])): ?>
                        <p class="form-error"><?= e($errors['driver_id']) ?></p>
                    <?php else: ?>
                        <p class="form-text">Only drivers on active duty with a valid licence are listed.</p>
                    <?php endif; ?>
                </div>

                <div class="span-2">
                    <label class="form-label" for="remarks">Operational notes</label>
                    <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255"
                           value="<?= e($values['remarks']) ?>" placeholder="e.g. Extra stop requested at Bidadi">
                    <p class="form-text">Shown on the trip sheet for this departure.</p>
                </div>
            </div>

            <?php if ($conflicts['bus'] !== [] || $conflicts['driver'] !== []): ?>
                <div class="alert alert-warning app-alert mt-3 mb-0" role="alert">
                    <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
                    <span class="app-alert__text">
                        Clashes detected on <?= e(format_date($values['schedule_date'])) ?>:
                        <?php foreach ($conflicts['bus'] as $clash): ?>
                            <br>Bus <?= e($clash['bus_number']) ?> is already on <?= e($clash['route_code']) ?>
                            (<?= e(time_range_label((string) $clash['departure_time'], (string) $clash['arrival_time'])) ?>).
                        <?php endforeach; ?>
                        <?php foreach ($conflicts['driver'] as $clash): ?>
                            <br>Driver <?= e($clash['driver_name']) ?> is already on <?= e($clash['route_code']) ?>
                            (<?= e(time_range_label((string) $clash['departure_time'], (string) $clash['arrival_time'])) ?>).
                        <?php endforeach; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl__footer">
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/schedules/index.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                <i class="bi bi-check2" aria-hidden="true"></i>
                <?= $isEdit ? 'Save departure' : 'Create departure' ?>
            </button>
        </div>
    </div>
</form>
