<?php
/**
 * Fleetra — Schedules / Edit departure
 * ------------------------------------------------------------------
 * modules/schedules/edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('schedules.manage');

$scheduleId = get_int('id');

if ($scheduleId <= 0) {
    abort_not_found('No schedule was specified.');
}

$schedule = find_schedule($scheduleId);

if ($schedule === null) {
    abort_not_found('That departure does not exist.');
}

$errors    = [];
$conflicts = ['bus' => [], 'driver' => []];
$values    = schedule_form_values($schedule);
$values['remarks'] = (string) ($schedule['trip_remarks'] ?? '');

if (is_post()) {
    require_csrf();

    $result    = validate_schedule_request($_POST, true, $scheduleId);
    $errors    = $result['errors'];
    $values    = $result['values'];
    $conflicts = $result['conflicts'];

    if ($errors === []) {
        $previousStatus = (string) $schedule['status'];
        $cancellation   = null;

        $pdo = db();

        try {
            $pdo->beginTransaction();

            db_update('schedules', schedule_db_payload($values), ['id' => $scheduleId]);

            // The trip mirrors the schedule, so keep vehicle, crew and route
            // in step and refresh the trip sheet notes.
            if ($schedule['trip_id'] !== null) {
                db_update('trips', [
                    'bus_id'    => (int) $values['bus_id'],
                    'driver_id' => (int) $values['driver_id'],
                    'route_id'  => (int) $values['route_id'],
                    'remarks'   => $values['remarks'] === '' ? null : $values['remarks'],
                ], ['id' => (int) $schedule['trip_id']]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $exception->getMessage();

            if (str_contains($message, 'uq_schedules_bus_slot')) {
                $errors['bus_id'] = 'That bus already has a departure at exactly this time on '
                    . format_date($values['schedule_date']) . '.';
            } elseif (str_contains($message, 'uq_schedules_driver_slot')) {
                $errors['driver_id'] = 'That driver is already assigned to a departure at exactly this time on '
                    . format_date($values['schedule_date']) . '.';
            } else {
                fleetra_log('Schedule update failed: ' . $message);
                fleetra_fatal('Something went wrong while saving this departure. Please try again.');
            }
        }

        if ($errors === []) {
            // A status change carries side effects: cancelling releases every
            // seat and refunds paid fares; re-opening revives the trip.
            $cancellation = apply_schedule_status(
                $scheduleId,
                $previousStatus,
                $values['status'],
                $values['remarks'] !== '' ? $values['remarks'] : 'Service cancelled by the operator.'
            );

            log_activity(
                'Updated schedule #' . $scheduleId,
                'schedules',
                $scheduleId,
                format_time($values['departure_time']) . ' on ' . format_date($values['schedule_date'])
                    . ' · status ' . $values['status']
            );

            if ($cancellation !== null) {
                $flash = 'The service was cancelled and its trip closed. '
                    . $cancellation['bookings'] . ' booking(s) cancelled'
                    . ($cancellation['refunded'] > 0 ? ', ' . money($cancellation['refunded']) . ' refunded' : '')
                    . ($cancellation['notified'] > 0 ? ', ' . $cancellation['notified'] . ' passenger(s) notified' : '')
                    . '.';
            } elseif ($previousStatus === 'cancelled') {
                $flash = 'The service was re-opened. Bookings that were cancelled at the time stay cancelled.';
            } else {
                $flash = 'The departure on ' . format_date($values['schedule_date']) . ' was updated.';
            }

            flash($cancellation !== null ? 'warning' : 'success', $flash);
            redirect('modules/schedules/view.php?id=' . $scheduleId);
        }
    }
}

$formAction = url('modules/schedules/edit.php?id=' . $scheduleId);
$isEdit     = true;

$page_title       = 'Edit schedule #' . $scheduleId;
$active_nav       = 'schedules';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Schedules', 'url' => url('modules/schedules/index.php')],
    ['label' => $schedule['route_code'] . ' ' . format_time($schedule['departure_time']),
        'url' => url('modules/schedules/view.php?id=' . $scheduleId)],
    ['label' => 'Edit'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Edit departure',
    $schedule['route_code'] . ' · ' . format_date($schedule['schedule_date']) . ' · '
        . time_range_label((string) $schedule['departure_time'], (string) $schedule['arrival_time']),
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/schedules/view.php?id=' . $scheduleId)) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to departure
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
