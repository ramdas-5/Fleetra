<?php
/**
 * Fleetra — Schedules / Add departure
 * ------------------------------------------------------------------
 * modules/schedules/create.php
 *
 * Creating a schedule also creates the trip that operates it, in one
 * transaction. A schedule without a trip would mean a departure nobody
 * can run, update or book against.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('schedules.manage');

$errors    = [];
$conflicts = ['bus' => [], 'driver' => []];
$values    = schedule_form_values(null, [
    'schedule_date' => is_valid_date(get('date')) ? get('date') : date('Y-m-d'),
    'route_id'      => (string) get_int('route_id'),
    'bus_id'        => (string) get_int('bus_id'),
    'driver_id'     => (string) get_int('driver_id'),
]);

if (is_post()) {
    require_csrf();

    $result    = validate_schedule_request($_POST, false);
    $errors    = $result['errors'];
    $values    = $result['values'];
    $conflicts = $result['conflicts'];

    if ($errors === []) {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            $scheduleId = db_insert('schedules', schedule_db_payload($values));

            db_insert('trips', [
                'schedule_id' => $scheduleId,
                'bus_id'      => (int) $values['bus_id'],
                'driver_id'   => (int) $values['driver_id'],
                'route_id'    => (int) $values['route_id'],
                'trip_status' => match ($values['status']) {
                    'running'   => 'running',
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    default     => 'scheduled',
                },
                'remarks'     => $values['remarks'] === '' ? null : $values['remarks'],
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $exception->getMessage();

            if (str_contains($message, 'uq_schedules_bus_slot')) {
                $errors['bus_id'] = 'That bus already has a departure at exactly this time on '
                    . format_date($values['schedule_date']) . '. Pick a different time or vehicle.';
            } elseif (str_contains($message, 'uq_schedules_driver_slot')) {
                $errors['driver_id'] = 'That driver is already assigned to a departure at exactly this time on '
                    . format_date($values['schedule_date']) . '.';
            } else {
                fleetra_log('Schedule creation failed: ' . $message);
                fleetra_fatal('Something went wrong while saving this departure. Please try again.');
            }
        }

        if ($errors === []) {
            log_activity(
                'Created schedule #' . $scheduleId,
                'schedules',
                $scheduleId,
                $values['route_id'] . ' departure at ' . format_time($values['departure_time'])
                    . ' on ' . format_date($values['schedule_date']) . ' with the linked trip'
            );
            fleetra_log('Schedule #' . $scheduleId . ' created by user #' . user_id(), 'INFO');

            flash('success', 'The ' . format_time($values['departure_time']) . ' departure on '
                . format_date($values['schedule_date']) . ' was scheduled and its trip created.');

            redirect('modules/schedules/view.php?id=' . $scheduleId);
        }
    }
}

$formAction = url('modules/schedules/create.php');
$isEdit     = false;
$scheduleId = null;

$page_title       = 'Add departure';
$active_nav       = 'schedules';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Operations'],
    ['label' => 'Schedules', 'url' => url('modules/schedules/index.php')],
    ['label' => 'Add departure'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Schedule a departure',
    'Assign a route, a bus and a driver to a date and time',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/schedules/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to schedules
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
