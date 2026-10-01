<?php
/**
 * Fleetra — Incidents / Report an incident
 * ------------------------------------------------------------------
 * modules/incidents/create.php
 *
 * Dispatch, managers and administrators can file an incident on behalf of
 * the operation. A driver reporting from the road gets a shorter form: the
 * driver record is pinned to their own account and the trip list is
 * limited to their own duties.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../trips/_logic.php';
require_once __DIR__ . '/_logic.php';

require_permission('incidents.report');

$canView  = can('incidents.view');
$myDriver = current_driver_id();

// A driver (no dispatch-level visibility) can only report against their
// own record, so the driver field is fixed rather than offered as a choice.
$driverMode     = !$canView && $myDriver > 0;
$forcedDriverId = $driverMode ? $myDriver : 0;

$errors = [];
$values = incident_form_values();

if ($driverMode) {
    $values['driver_id'] = (string) $myDriver;
}

if (is_post()) {
    require_csrf();

    $result = validate_incident_request($_POST, $forcedDriverId);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        $payload                = incident_db_payload($values);
        $payload['reported_by'] = user_id();
        $payload['reported_at'] = date('Y-m-d H:i:s');

        $incidentId = db_insert('incidents', $payload);
        $incident   = find_incident($incidentId);

        log_activity(
            'Reported incident INC-' . str_pad((string) $incidentId, 4, '0', STR_PAD_LEFT),
            'incidents',
            $incidentId,
            labelize($values['incident_type']) . ' · ' . labelize($values['severity']) . ' severity'
        );

        if ($incident !== null) {
            $headline = 'INC-' . str_pad((string) $incidentId, 4, '0', STR_PAD_LEFT)
                . ' reported by ' . current_user_name()
                . ($incident['bus_number'] !== null ? ' for bus ' . $incident['bus_number'] : '') . '.';

            notify_incident_staff($incident, $headline);
        }

        if (incident_needs_alert($values)) {
            flash('warning', 'The incident was reported and the operations team has been alerted immediately.');
        } else {
            flash('success', 'The incident was reported and the operations team can now review it.');
        }

        if ($canView) {
            redirect('modules/incidents/view.php?id=' . $incidentId);
        }

        redirect(dashboard_path(current_role()));
    }
}

$tripOptions   = incident_trip_options($driverMode ? $myDriver : null);
$busOptions    = incident_bus_options();
$driverOptions = incident_driver_options();

$formAction = url('modules/incidents/create.php');

$actions = $canView
    ? '<a class="btn btn-outline-secondary" href="' . e(url('modules/incidents/index.php')) . '">
           <i class="bi bi-arrow-left" aria-hidden="true"></i> Incidents
       </a>'
    : '<a class="btn btn-outline-secondary" href="' . e(url(dashboard_path(current_role()))) . '">
           <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to dashboard
       </a>';

$page_title       = 'Report incident';
$active_nav       = 'incidents';
$page_breadcrumbs = $canView
    ? [
        ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
        ['label' => 'Incidents', 'url' => url('modules/incidents/index.php')],
        ['label' => 'Report incident'],
    ]
    : [
        ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
        ['label' => 'Report incident'],
    ];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Report an incident',
    $driverMode
        ? 'Tell the operations team about a problem on your duty'
        : 'Log a breakdown, delay, accident or other operational incident',
    $actions
) ?>

<div class="form-card">
    <form method="post" action="<?= e($formAction) ?>" novalidate>
        <?= csrf_field() ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger app-alert" role="alert">
                <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
                <span class="app-alert__text">
                    The incident could not be reported. Please review the highlighted fields.
                </span>
            </div>
        <?php endif; ?>

        <?php if ($driverMode): ?>
            <div class="alert alert-info app-alert" role="alert">
                <i class="bi bi-info-circle app-alert__icon" aria-hidden="true"></i>
                <span class="app-alert__text">
                    This report is filed against your driver record. High and critical incidents alert the
                    operations team straight away.
                </span>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">What happened</h2>
                    <p class="card-fl__subtitle">Type and severity determine how quickly this is escalated</p>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="form-row">
                    <div>
                        <label class="form-label" for="incident_type">Incident type <span class="req">*</span></label>
                        <select class="form-select<?= isset($errors['incident_type']) ? ' is-invalid' : '' ?>"
                                id="incident_type" name="incident_type" required>
                            <?= option_tags(incident_type_options(), $values['incident_type']) ?>
                        </select>
                        <?php if (isset($errors['incident_type'])): ?>
                            <p class="form-error"><?= e($errors['incident_type']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="form-label" for="severity">Severity <span class="req">*</span></label>
                        <select class="form-select<?= isset($errors['severity']) ? ' is-invalid' : '' ?>"
                                id="severity" name="severity" required>
                            <?= option_tags(incident_severity_options(), $values['severity']) ?>
                        </select>
                        <?php if (isset($errors['severity'])): ?>
                            <p class="form-error"><?= e($errors['severity']) ?></p>
                        <?php else: ?>
                            <p class="form-text">High and critical immediately notify dispatch and management.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($canView): ?>
                        <div>
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>"
                                    id="status" name="status">
                                <?= option_tags(incident_status_options(), $values['status']) ?>
                            </select>
                            <?php if (isset($errors['status'])): ?>
                                <p class="form-error"><?= e($errors['status']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="span-2">
                        <label class="form-label" for="description">Description <span class="req">*</span></label>
                        <textarea class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>"
                                  id="description" name="description" rows="4" required maxlength="2000"
                                  placeholder="Describe what happened, where, and what action you have taken or need."><?= e($values['description']) ?></textarea>
                        <?php if (isset($errors['description'])): ?>
                            <p class="form-error"><?= e($errors['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Where it happened</h2>
                    <p class="card-fl__subtitle">Link the incident to a trip, bus and driver, and record the location</p>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="form-row">
                    <div class="span-2">
                        <label class="form-label" for="trip_id">Trip</label>
                        <select class="form-select<?= isset($errors['trip_id']) ? ' is-invalid' : '' ?>"
                                id="trip_id" name="trip_id">
                            <?= option_tags($tripOptions, $values['trip_id'], 'Not linked to a specific trip') ?>
                        </select>
                        <?php if (isset($errors['trip_id'])): ?>
                            <p class="form-error"><?= e($errors['trip_id']) ?></p>
                        <?php else: ?>
                            <p class="form-text">Choosing a trip fills in the bus and driver for you.</p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="form-label" for="bus_id">Bus</label>
                        <select class="form-select<?= isset($errors['bus_id']) ? ' is-invalid' : '' ?>"
                                id="bus_id" name="bus_id">
                            <?= option_tags($busOptions, $values['bus_id'], 'No specific bus') ?>
                        </select>
                        <?php if (isset($errors['bus_id'])): ?>
                            <p class="form-error"><?= e($errors['bus_id']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="form-label" for="driver_id">Driver</label>
                        <?php if ($driverMode): ?>
                            <input type="text" class="form-control" id="driver_id_display"
                                   value="<?= e(current_user_name()) ?>" disabled>
                            <input type="hidden" id="driver_id" name="driver_id" value="<?= (int) $myDriver ?>">
                            <p class="form-text">Reports from a driver account are always filed against your own record.</p>
                        <?php else: ?>
                            <select class="form-select<?= isset($errors['driver_id']) ? ' is-invalid' : '' ?>"
                                    id="driver_id" name="driver_id">
                                <?= option_tags($driverOptions, $values['driver_id'], 'Not linked to a driver') ?>
                            </select>
                            <?php if (isset($errors['driver_id'])): ?>
                                <p class="form-error"><?= e($errors['driver_id']) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="form-label" for="latitude">Latitude</label>
                        <input type="number" class="form-control<?= isset($errors['latitude']) ? ' is-invalid' : '' ?>"
                               id="latitude" name="latitude" value="<?= e($values['latitude']) ?>"
                               min="-90" max="90" step="0.0000001" placeholder="e.g. 12.9437000">
                        <?php if (isset($errors['latitude'])): ?>
                            <p class="form-error"><?= e($errors['latitude']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="form-label" for="longitude">Longitude</label>
                        <input type="number" class="form-control<?= isset($errors['longitude']) ? ' is-invalid' : '' ?>"
                               id="longitude" name="longitude" value="<?= e($values['longitude']) ?>"
                               min="-180" max="180" step="0.0000001" placeholder="e.g. 77.5202000">
                        <?php if (isset($errors['longitude'])): ?>
                            <p class="form-error"><?= e($errors['longitude']) ?></p>
                        <?php endif; ?>
                        <p class="form-text">Optional. Used to place the incident on the map.</p>
                    </div>
                </div>
            </div>

            <div class="card-fl__footer">
                <a class="btn btn-outline-secondary" href="<?= e($canView ? url('modules/incidents/index.php') : url(dashboard_path(current_role()))) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary" data-loading-text="Reporting…">
                    <i class="bi bi-send" aria-hidden="true"></i> Submit report
                </button>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
