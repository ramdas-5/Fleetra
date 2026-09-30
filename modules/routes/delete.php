<?php
/**
 * Fleetra — Routes / Delete or retire a route
 * ------------------------------------------------------------------
 * modules/routes/delete.php
 *
 * POST only, CSRF protected. Routes with schedules, trips or bookings
 * are retired (set to inactive) instead of being deleted, so historical
 * tickets and reports stay accurate. Stops cascade with a real delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('routes.manage');

if (!is_post()) {
    flash('warning', 'Deleting a route requires a confirmed form submission.');
    redirect('modules/routes/index.php');
}

require_csrf();

$routeId = get_int('id');

if ($routeId <= 0) {
    abort_not_found('No route was specified.');
}

$route = find_or_404('routes', $routeId);

$routeCode     = (string) $route['route_code'];
$scheduleCount = (int) db_value('SELECT COUNT(*) FROM schedules WHERE route_id = ?', [$routeId], 0);
$tripCount     = (int) db_value('SELECT COUNT(*) FROM trips WHERE route_id = ?', [$routeId], 0);
$bookingCount  = (int) db_value(
    'SELECT COUNT(*) FROM bookings b JOIN schedules s ON s.id = b.schedule_id WHERE s.route_id = ?',
    [$routeId],
    0
);

if ($scheduleCount + $tripCount + $bookingCount > 0) {
    db_update('routes', ['status' => 'inactive'], ['id' => $routeId]);

    log_activity(
        'Retired route ' . $routeCode,
        'routes',
        $routeId,
        sprintf('Set inactive: %d schedules, %d trips, %d bookings on record', $scheduleCount, $tripCount, $bookingCount)
    );

    flash(
        'warning',
        'Route ' . $routeCode . ' has ' . $scheduleCount . ' schedule(s) and ' . $bookingCount
        . ' booking(s) on record, so it was set to Inactive instead of being deleted.'
    );

    redirect('modules/routes/view.php?id=' . $routeId);
}

$stopCount = (int) db_value('SELECT COUNT(*) FROM stops WHERE route_id = ?', [$routeId], 0);

db_execute('DELETE FROM routes WHERE id = ?', [$routeId]);

log_activity('Deleted route ' . $routeCode, 'routes', $routeId, 'Route and its ' . $stopCount . ' stops were removed');
fleetra_log('Route #' . $routeId . ' (' . $routeCode . ') deleted by user #' . user_id(), 'INFO');

flash('success', 'Route ' . $routeCode . ' and its ' . $stopCount . ' stop(s) were deleted.');
redirect('modules/routes/index.php');
