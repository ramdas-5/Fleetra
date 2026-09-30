<?php
/**
 * Fleetra — Routes / Manage stops
 * ------------------------------------------------------------------
 * modules/routes/stops.php
 *
 * Stops are ordered within a route. Adding, editing, deleting and moving
 * are all handled here; the order is always rewritten as a contiguous
 * 1..N sequence so the schedule offsets stay meaningful.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('routes.view');

$canManage = can('routes.manage');

$routeId = get_int('route_id');

if ($routeId <= 0) {
    abort_not_found('No route was specified.');
}

$route = find_or_404('routes', $routeId);

$errors       = [];
$editingStop  = null;

/* ------------------------------------------------------------------
 | Write actions
 ------------------------------------------------------------------ */

if (is_post()) {
    require_permission('routes.manage');
    require_csrf();

    $action = post('action');

    if ($action === 'add' || $action === 'update') {
        $result = validate_stop_request($_POST);
        $errors = $result['errors'];
        $values = $result['values'];

        if ($errors === []) {
            $payload = [
                'stop_name'      => $values['stop_name'],
                'latitude'       => $values['latitude'] === '' ? null : (float) $values['latitude'],
                'longitude'      => $values['longitude'] === '' ? null : (float) $values['longitude'],
                'arrival_offset' => $values['arrival_offset'] === '' ? 0 : (int) $values['arrival_offset'],
            ];

            if ($action === 'add') {
                // Append first, then reposition if the admin asked for a spot
                // in the middle of the route.
                $appendOrder = next_stop_order($routeId);

                $stopId = db_insert('stops', array_merge($payload, [
                    'route_id'   => $routeId,
                    'stop_order' => $appendOrder,
                ]));

                if ($values['stop_order'] !== '' && (int) $values['stop_order'] < $appendOrder) {
                    set_stop_order($routeId, $stopId, (int) $values['stop_order']);
                }

                log_activity('Added stop to ' . $route['route_code'], 'routes', $routeId, $values['stop_name'] . ' added');
                flash('success', 'Stop "' . $values['stop_name'] . '" was added.');
            } else {
                $stopId = post_int('stop_id');

                if ($stopId <= 0) {
                    abort_not_found('No stop was specified.');
                }

                $existing = db_one('SELECT * FROM stops WHERE id = ? AND route_id = ? LIMIT 1', [$stopId, $routeId]);

                if ($existing === null) {
                    abort_not_found('That stop does not belong to this route.');
                }

                db_update('stops', $payload, ['id' => $stopId]);

                if ($values['stop_order'] !== '' && (int) $values['stop_order'] !== (int) $existing['stop_order']) {
                    set_stop_order($routeId, $stopId, (int) $values['stop_order']);
                }

                log_activity('Updated stop on ' . $route['route_code'], 'routes', $routeId, $values['stop_name'] . ' updated');
                flash('success', 'Stop "' . $values['stop_name'] . '" was updated.');
            }

            redirect('modules/routes/stops.php?route_id=' . $routeId);
        }
    }

    if ($action === 'delete') {
        $stopId = post_int('stop_id');

        $stop = db_one('SELECT * FROM stops WHERE id = ? AND route_id = ? LIMIT 1', [$stopId, $routeId]);

        if ($stop === null) {
            abort_not_found('That stop does not belong to this route.');
        }

        $affectedBookings = (int) db_value(
            'SELECT COUNT(*) FROM bookings WHERE boarding_stop_id = ? OR destination_stop_id = ?',
            [$stopId, $stopId],
            0
        );

        db_execute('DELETE FROM stops WHERE id = ?', [$stopId]);

        resequence_stops($routeId);

        log_activity(
            'Deleted stop from ' . $route['route_code'],
            'routes',
            $routeId,
            $stop['stop_name'] . ' removed'
        );

        flash(
            'success',
            'Stop "' . $stop['stop_name'] . '" was removed and the remaining stops were renumbered.'
            . ($affectedBookings > 0
                ? ' ' . $affectedBookings . ' existing booking(s) referenced this stop and will now show no boarding point.'
                : '')
        );

        redirect('modules/routes/stops.php?route_id=' . $routeId);
    }

    if ($action === 'move') {
        $stopId    = post_int('stop_id');
        $direction = post('direction') === 'up' ? 'up' : 'down';

        if (move_stop($routeId, $stopId, $direction)) {
            log_activity('Reordered stops on ' . $route['route_code'], 'routes', $routeId, 'Stop moved ' . $direction);
            flash('success', 'Stop order updated.');
        }

        redirect('modules/routes/stops.php?route_id=' . $routeId);
    }
}

/* ------------------------------------------------------------------
 | Read
 ------------------------------------------------------------------ */

$stops = route_stops($routeId);

$editId = get_int('edit');
if ($editId > 0) {
    $editingStop = db_one('SELECT * FROM stops WHERE id = ? AND route_id = ? LIMIT 1', [$editId, $routeId]);
}

$scheduleCount = (int) db_value('SELECT COUNT(*) FROM schedules WHERE route_id = ?', [$routeId], 0);

$formValues = [
    'stop_name'      => '',
    'stop_order'     => (string) next_stop_order($routeId),
    'latitude'       => '',
    'longitude'      => '',
    'arrival_offset' => '',
];

if (is_post() && $errors !== []) {
    // Re-render the submitted values instead of losing them.
    $formValues = array_merge($formValues, [
        'stop_name'      => post('stop_name'),
        'stop_order'     => post('stop_order'),
        'latitude'       => post('latitude'),
        'longitude'      => post('longitude'),
        'arrival_offset' => post('arrival_offset'),
    ]);
} elseif ($editingStop !== null) {
    $formValues = [
        'stop_name'      => (string) $editingStop['stop_name'],
        'stop_order'     => (string) $editingStop['stop_order'],
        'latitude'       => $editingStop['latitude'] !== null ? (string) $editingStop['latitude'] : '',
        'longitude'      => $editingStop['longitude'] !== null ? (string) $editingStop['longitude'] : '',
        'arrival_offset' => (string) $editingStop['arrival_offset'],
    ];
}

$page_title       = 'Stops · ' . $route['route_code'];
$active_nav       = 'routes';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Fleet', 'url' => url('modules/routes/index.php')],
    ['label' => 'Routes', 'url' => url('modules/routes/index.php')],
    ['label' => (string) $route['route_code'], 'url' => url('modules/routes/view.php?id=' . $routeId)],
    ['label' => 'Stops'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Stops · ' . $route['route_code'],
    $route['source'] . ' → ' . $route['destination'] . ' · ' . count($stops) . ' stop'
        . (count($stops) === 1 ? '' : 's'),
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/routes/view.php?id=' . $routeId)) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to route
     </a>'
) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">The stop could not be saved. Please review the highlighted fields.</span>
    </div>
<?php endif; ?>

<div class="grid-main-side">
    <div class="card-fl">
        <div class="card-fl__header">
            <div>
                <h2 class="card-fl__title">Stops in travel order</h2>
                <p class="card-fl__subtitle">Use the arrows to reorder; offsets are minutes after departure</p>
            </div>
            <span class="chip"><?= $scheduleCount ?> schedules use this route</span>
        </div>

        <?php if ($stops === []): ?>
            <?= empty_state(
                'No stops defined yet',
                'Add the boarding points for this route. Passengers cannot book a seat until at least one stop exists.',
                'bi-geo-alt'
            ) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-fl">
                    <thead>
                        <tr>
                            <th class="cell-tight">#</th>
                            <th>Stop</th>
                            <th>Coordinates</th>
                            <th>Offset</th>
                            <?php if ($canManage): ?>
                                <th class="cell-actions">Order</th>
                                <th class="cell-actions">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stops as $index => $stop): ?>
                            <tr>
                                <td class="cell-tight">
                                    <span class="chip"><?= (int) $stop['stop_order'] ?></span>
                                </td>
                                <td>
                                    <span class="cell-strong"><?= e($stop['stop_name']) ?></span>
                                    <?php if ($index === 0): ?>
                                        <br><span class="cell-muted">Departure point</span>
                                    <?php elseif ($index === count($stops) - 1): ?>
                                        <br><span class="cell-muted">Final stop</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($stop['latitude'] !== null && $stop['longitude'] !== null): ?>
                                        <span class="cell-muted">
                                            <?= e(number_format((float) $stop['latitude'], 5)) ?>,
                                            <?= e(number_format((float) $stop['longitude'], 5)) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="cell-muted">Not mapped</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-num">
                                    <?= (int) $stop['arrival_offset'] === 0
                                        ? 'Departure'
                                        : '+' . e(format_duration((int) $stop['arrival_offset'])) ?>
                                </td>

                                <?php if ($canManage): ?>
                                    <td class="cell-actions">
                                        <form method="post" action="<?= e(url('modules/routes/stops.php?route_id=' . $routeId)) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="stop_id" value="<?= (int) $stop['id'] ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="row-action" title="Move up"
                                                    aria-label="Move <?= e($stop['stop_name']) ?> up"
                                                    <?= $index === 0 ? 'disabled' : '' ?>>
                                                <i class="bi bi-arrow-up" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        <form method="post" action="<?= e(url('modules/routes/stops.php?route_id=' . $routeId)) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="stop_id" value="<?= (int) $stop['id'] ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="row-action" title="Move down"
                                                    aria-label="Move <?= e($stop['stop_name']) ?> down"
                                                    <?= $index === count($stops) - 1 ? 'disabled' : '' ?>>
                                                <i class="bi bi-arrow-down" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="cell-actions">
                                        <a class="row-action"
                                           href="<?= e(url('modules/routes/stops.php?route_id=' . $routeId . '&edit=' . (int) $stop['id'])) ?>"
                                           title="Edit stop" aria-label="Edit <?= e($stop['stop_name']) ?>">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </a>

                                        <form method="post" action="<?= e(url('modules/routes/stops.php?route_id=' . $routeId)) ?>" class="d-inline"
                                              data-confirm="Removing a stop renumbers the remaining stops. Bookings that used it will lose their boarding point."
                                              data-confirm-title="Delete stop?"
                                              data-confirm-button="Delete stop">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="stop_id" value="<?= (int) $stop['id'] ?>">
                                            <button type="submit" class="row-action row-action--danger"
                                                    title="Delete stop" aria-label="Delete <?= e($stop['stop_name']) ?>">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($canManage): ?>
            <form method="post" action="<?= e(url('modules/routes/stops.php?route_id=' . $routeId)) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editingStop !== null ? 'update' : 'add' ?>">
                <?php if ($editingStop !== null): ?>
                    <input type="hidden" name="stop_id" value="<?= (int) $editingStop['id'] ?>">
                <?php endif; ?>

                <div class="card-fl">
                    <div class="card-fl__header">
                        <div>
                            <h2 class="card-fl__title"><?= $editingStop !== null ? 'Edit stop' : 'Add a stop' ?></h2>
                            <p class="card-fl__subtitle">
                                <?= $editingStop !== null
                                    ? 'Update this boarding point'
                                    : 'Add a boarding point to ' . $route['route_code'] ?>
                            </p>
                        </div>
                    </div>

                    <div class="card-fl__body">
                        <div class="mb-3">
                            <label class="form-label" for="stop_name">Stop name <span class="req">*</span></label>
                            <input type="text" class="form-control<?= isset($errors['stop_name']) ? ' is-invalid' : '' ?>"
                                   id="stop_name" name="stop_name" value="<?= e($formValues['stop_name']) ?>"
                                   required maxlength="140" placeholder="e.g. Ramanagara Bus Stand">
                            <?php if (isset($errors['stop_name'])): ?>
                                <p class="form-error"><?= e($errors['stop_name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="stop_order">Position in route</label>
                            <input type="number" class="form-control<?= isset($errors['stop_order']) ? ' is-invalid' : '' ?>"
                                   id="stop_order" name="stop_order" value="<?= e($formValues['stop_order']) ?>"
                                   min="1" max="99" step="1">
                            <?php if (isset($errors['stop_order'])): ?>
                                <p class="form-error"><?= e($errors['stop_order']) ?></p>
                            <?php else: ?>
                                <p class="form-text">Stops are always saved as a contiguous 1..N sequence.</p>
                            <?php endif; ?>
                        </div>

                        <div class="form-row">
                            <div>
                                <label class="form-label" for="latitude">Latitude</label>
                                <input type="number" class="form-control<?= isset($errors['latitude']) ? ' is-invalid' : '' ?>"
                                       id="latitude" name="latitude" value="<?= e($formValues['latitude']) ?>"
                                       min="-90" max="90" step="0.0000001" placeholder="12.7217000">
                                <?php if (isset($errors['latitude'])): ?>
                                    <p class="form-error"><?= e($errors['latitude']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label class="form-label" for="longitude">Longitude</label>
                                <input type="number" class="form-control<?= isset($errors['longitude']) ? ' is-invalid' : '' ?>"
                                       id="longitude" name="longitude" value="<?= e($formValues['longitude']) ?>"
                                       min="-180" max="180" step="0.0000001" placeholder="77.2800000">
                                <?php if (isset($errors['longitude'])): ?>
                                    <p class="form-error"><?= e($errors['longitude']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label" for="arrival_offset">Minutes after departure</label>
                            <input type="number" class="form-control<?= isset($errors['arrival_offset']) ? ' is-invalid' : '' ?>"
                                   id="arrival_offset" name="arrival_offset" value="<?= e($formValues['arrival_offset']) ?>"
                                   min="0" max="2880" step="1" placeholder="e.g. 105">
                            <?php if (isset($errors['arrival_offset'])): ?>
                                <p class="form-error"><?= e($errors['arrival_offset']) ?></p>
                            <?php else: ?>
                                <p class="form-text">Leave blank or 0 for the starting point.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-fl__footer">
                        <?php if ($editingStop !== null): ?>
                            <a class="btn btn-outline-secondary"
                               href="<?= e(url('modules/routes/stops.php?route_id=' . $routeId)) ?>">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                            <i class="bi bi-<?= $editingStop !== null ? 'check2' : 'plus-lg' ?>" aria-hidden="true"></i>
                            <?= $editingStop !== null ? 'Save stop' : 'Add stop' ?>
                        </button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="card-fl">
                <div class="card-fl__body">
                    <p class="form-text mb-0">
                        Stops are managed by administrators and transport managers. You have read-only access to
                        this route.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Route summary</h2>
                    <p class="card-fl__subtitle">Service parameters</p>
                </div>
            </div>
            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Route code</span>
                        <span class="fact-list__value"><?= e($route['route_code']) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Distance</span>
                        <span class="fact-list__value"><?= e(number_format((float) $route['distance'], 1)) ?> km</span>
                    </li>
                    <li>
                        <span class="fact-list__label">Scheduled duration</span>
                        <span class="fact-list__value"><?= e(format_duration((int) $route['estimated_duration'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Final stop offset</span>
                        <span class="fact-list__value">
                            <?= $stops !== []
                                ? '+' . e(format_duration((int) end($stops)['arrival_offset']))
                                : '—' ?>
                        </span>
                    </li>
                    <li>
                        <span class="fact-list__label">Base fare</span>
                        <span class="fact-list__value"><?= e(money($route['base_fare'])) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Status</span>
                        <span class="fact-list__value"><?= status_badge($route['status']) ?></span>
                    </li>
                </ul>

                <?php if ($stops !== [] && (int) end($stops)['arrival_offset'] > 0
                    && (int) end($stops)['arrival_offset'] !== (int) $route['estimated_duration']): ?>
                    <p class="form-text mt-3 mb-0">
                        The final stop offset differs from the route duration. That is allowed — offsets describe
                        boarding times while the duration is the total journey time.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
