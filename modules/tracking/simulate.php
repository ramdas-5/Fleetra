<?php
/**
 * Fleetra — Live tracking / Advance the simulation
 * ------------------------------------------------------------------
 * modules/tracking/simulate.php
 *
 * Moves every bus on today's active services one step further along its
 * route and stores the result as a simulated position. This exists only
 * because a local XAMPP install has no GPS hardware — it is labelled as
 * simulated everywhere it is shown and never mixed with device fixes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

/* Simulation is an operations tool. It is restricted to operations roles
   on the server — a driver holding only tracking.update must NOT be able
   to advance the fleet simulation. */
require_role('admin', 'manager', 'dispatcher');

require_csrf();

if (!is_post()) {
    redirect('modules/tracking/index.php');
}

$result = simulate_live_positions();

if ($result['moved'] === 0 && $result['skipped'] === 1) {
    flash('info', 'The simulation was already advanced a moment ago. Try again in a second.');
} elseif ($result['moved'] === 0) {
    flash('warning', 'No bus could be simulated. Active services need a route with mapped stops.');
} else {
    log_activity(
        'Advanced live tracking simulation',
        'tracking',
        null,
        $result['moved'] . ' bus position(s) generated' . ($result['skipped'] > 0 ? ', ' . $result['skipped'] . ' skipped' : '')
    );

    flash('success', $result['moved'] . ' position' . ($result['moved'] === 1 ? '' : 's') . ' generated for the live map.');
}

redirect('modules/tracking/index.php');
