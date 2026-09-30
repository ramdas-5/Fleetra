<?php
/**
 * Fleetra — Live tracking API
 * ------------------------------------------------------------------
 * api/tracking.php
 *
 * GET  ?action=positions   latest position of every tracked bus (JSON)
 * POST  action=update      a real device fix from a driver's browser
 *
 * Authentication, capability checks and CSRF are all enforced here. A
 * driver can only ever post a position for the bus they are actually
 * working — the bus and trip are resolved on the server, never taken from
 * the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../modules/tracking/_logic.php';

$action = is_post() ? post('action', 'update') : get('action', 'positions');

/* Reading positions needs tracking.view; pushing a position needs
   tracking.update (drivers). Guard each action on its own capability so a
   driver can report without being able to browse the whole map. */
if ($action === 'positions') {
    require_permission('tracking.view');

    $markers = [];

    foreach (tracking_buses() as $bus) {
        $marker = tracking_marker_payload($bus);

        if ($marker !== null) {
            $markers[] = $marker;
        }
    }

    json_response([
        'success'    => true,
        'buses'      => $markers,
        'refreshed'  => date('c'),
        'tracked'    => count($markers),
        'refresh_in' => tracking_refresh_seconds(),
    ]);
}

if ($action === 'update') {
    require_permission('tracking.update');

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        fleetra_log('CSRF validation failed for api/tracking.php (user #' . user_id() . ')', 'WARNING');
        json_response([
            'success' => false,
            'message' => 'Your session expired. Reload the page and try again.',
        ], 419);
    }

    $latitude  = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($latitude === false || $longitude === false) {
        json_response(['success' => false, 'message' => 'A valid latitude and longitude are required.'], 422);
    }

    $speed   = (float) filter_var($_POST['speed'] ?? 0, FILTER_VALIDATE_FLOAT);
    $heading = (int) filter_var($_POST['heading'] ?? 0, FILTER_VALIDATE_INT);

    // The bus and trip are resolved from the signed-in driver's own duty, so
    // a caller can never report a position against another vehicle.
    $driverId = current_driver_id();

    if ($driverId <= 0) {
        json_response([
            'success' => false,
            'message' => 'Only a driver on an active duty can share a live position.',
        ], 403);
    }

    $trip = driver_current_trip($driverId);

    if ($trip === null) {
        json_response([
            'success' => false,
            'message' => 'You have no service in progress, so there is nothing to report a position for.',
        ], 409);
    }

    $busId      = (int) $trip['bus_id'];
    $tripId     = (int) $trip['trip_id'];
    $locationId = record_device_position($busId, $tripId, (float) $latitude, (float) $longitude, $speed, $heading);

    if ($locationId === false) {
        json_response(['success' => false, 'message' => 'That position could not be stored.'], 422);
    }

    log_activity(
        'Recorded device position',
        'tracking',
        $busId,
        sprintf('Bus #%d reported at %.5f, %.5f', $busId, (float) $latitude, (float) $longitude)
    );

    json_response([
        'success' => true,
        'message' => 'Your position was shared with dispatch.',
        'id'      => $locationId,
    ]);
}

json_response(['success' => false, 'message' => 'Unknown action.'], 400);
