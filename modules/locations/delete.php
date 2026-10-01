<?php
/**
 * Fleetra — Location directory / delete
 * ------------------------------------------------------------------
 * modules/locations/delete.php
 *
 * POST only and CSRF protected. The location table has no foreign keys
 * from routes or stops, so removing a reference location is safe.
 */

declare(strict_types=1);

require_once __DIR__ . '/_logic.php';

require_permission('locations.manage');

if (!is_post()) {
    redirect('modules/locations/index.php');
}

require_csrf();

$locationId = get_int('id');

if ($locationId <= 0) {
    abort_not_found('No location was specified.');
}

$location = find_location($locationId);

if ($location === null) {
    abort_not_found('That location does not exist.');
}

db_execute('DELETE FROM locations WHERE id = ?', [$locationId]);

log_activity('Removed location ' . $location['name'], 'locations', $locationId, $location['city'] . ', ' . $location['state']);
flash('success', $location['name'] . ' was removed from the location directory.');
redirect('modules/locations/index.php');
