<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/nav.php
 *
 * Single source of truth for the sidebar navigation. Items declare the
 * capability required to see them; an item is only rendered as a link
 * when its target file actually exists, so the sidebar never contains
 * dead links while modules are being built phase by phase.
 */

declare(strict_types=1);

require_once __DIR__ . '/permissions.php';

/**
 * Grouped navigation definition.
 *
 * @return array<int, array{section:string, items:array<int, array<string, mixed>>}>
 */
function fleetra_navigation(): array
{
    return [
        [
            'section' => 'Overview',
            'items'   => [
                [
                    'key'      => 'dashboard',
                    'label'    => 'Dashboard',
                    'icon'     => 'bi-grid-1x2',
                    'href'     => '@dashboard',
                    'capabilities' => ['dashboard.view'],
                ],
            ],
        ],
        [
            'section' => 'Operations',
            'items'   => [
                [
                    'key'      => 'trips',
                    'label'    => 'Trips',
                    'icon'     => 'bi-signpost-split',
                    'href'     => 'modules/trips/index.php',
                    'capabilities' => ['trips.view', 'trips.manage', 'trips.own'],
                ],
                [
                    'key'      => 'schedules',
                    'label'    => 'Schedules',
                    'icon'     => 'bi-calendar3',
                    'href'     => 'modules/schedules/index.php',
                    'capabilities' => ['schedules.view', 'schedules.manage'],
                ],
                [
                    'key'      => 'incidents',
                    'label'    => 'Incidents',
                    'icon'     => 'bi-exclamation-triangle',
                    'href'     => 'modules/incidents/index.php',
                    'capabilities' => ['incidents.view', 'incidents.report', 'incidents.manage'],
                ],
            ],
        ],
        [
            'section' => 'Fleet',
            'items'   => [
                [
                    'key'      => 'buses',
                    'label'    => 'Buses',
                    'icon'     => 'bi-bus-front',
                    'href'     => 'modules/buses/index.php',
                    'capabilities' => ['fleet.view', 'fleet.manage'],
                ],
                [
                    'key'      => 'drivers',
                    'label'    => 'Drivers',
                    'icon'     => 'bi-person-badge',
                    'href'     => 'modules/drivers/index.php',
                    'capabilities' => ['drivers.view', 'drivers.manage'],
                ],
                [
                    'key'      => 'routes',
                    'label'    => 'Routes & Stops',
                    'icon'     => 'bi-map',
                    'href'     => 'modules/routes/index.php',
                    'capabilities' => ['routes.view', 'routes.manage'],
                ],
                [
                    'key'      => 'locations',
                    'label'    => 'Locations & Terminals',
                    'icon'     => 'bi-geo-alt',
                    'href'     => 'modules/locations/index.php',
                    'capabilities' => ['locations.manage'],
                ],
                [
                    'key'      => 'maintenance',
                    'label'    => 'Maintenance',
                    'icon'     => 'bi-tools',
                    'href'     => 'modules/maintenance/index.php',
                    'capabilities' => ['maintenance.view', 'maintenance.manage'],
                ],
            ],
        ],
        [
            'section' => 'Passenger Services',
            'items'   => [
                [
                    'key'      => 'search',
                    'label'    => 'Search Buses',
                    'icon'     => 'bi-search',
                    'href'     => 'modules/search/index.php',
                    // Passenger booking journey — never shown to staff, whose
                    // booking work happens in Manage Bookings instead.
                    'roles'        => ['passenger'],
                    'capabilities' => ['trips.search'],
                ],
                [
                    'key'      => 'passengers',
                    'label'    => 'Passengers',
                    'icon'     => 'bi-people',
                    'href'     => 'modules/passengers/index.php',
                    'capabilities' => ['passengers.view'],
                ],
                [
                    'key'      => 'bookings',
                    'label'    => 'Bookings',
                    'icon'     => 'bi-journal-check',
                    'href'     => 'modules/bookings/index.php',
                    'capabilities' => ['bookings.view', 'bookings.manage', 'bookings.own'],
                ],
                [
                    'key'      => 'tickets',
                    'label'    => 'Tickets',
                    'icon'     => 'bi-ticket-perforated',
                    'href'     => 'modules/tickets/index.php',
                    'capabilities' => ['tickets.own', 'tickets.manage'],
                ],
            ],
        ],
        [
            'section' => 'Insights',
            'items'   => [
                [
                    'key'      => 'reports',
                    'label'    => 'Reports',
                    'icon'     => 'bi-bar-chart-line',
                    'href'     => 'modules/reports/index.php',
                    'capabilities' => ['reports.view'],
                ],
                [
                    'key'      => 'notifications',
                    'label'    => 'Notifications',
                    'icon'     => 'bi-bell',
                    'href'     => 'modules/notifications/index.php',
                    'badge'    => 'unread_notifications',
                    'capabilities' => ['notifications.view'],
                ],
            ],
        ],
        [
            'section' => 'Administration',
            'items'   => [
                [
                    'key'      => 'users',
                    'label'    => 'Users',
                    'icon'     => 'bi-shield-lock',
                    'href'     => 'modules/users/index.php',
                    'capabilities' => ['users.manage'],
                ],
                [
                    'key'      => 'logs',
                    'label'    => 'Activity Logs',
                    'icon'     => 'bi-clock-history',
                    'href'     => 'modules/logs/index.php',
                    'capabilities' => ['logs.view'],
                ],
                [
                    'key'      => 'settings',
                    'label'    => 'Settings',
                    'icon'     => 'bi-sliders',
                    'href'     => 'modules/settings/index.php',
                    'capabilities' => ['settings.manage'],
                ],
            ],
        ],
    ];
}

/**
 * Resolve an item's href. "@dashboard" points at the dashboard for the
 * signed-in role. Returns null when the target does not exist yet.
 */
function nav_resolve_href(array $item): ?string
{
    $href = (string) ($item['href'] ?? '');

    if ($href === '@dashboard') {
        $path = dashboard_path(current_role());

        return url($path);
    }

    if ($href === '') {
        return null;
    }

    // Only link to modules that are actually implemented on disk.
    if (!file_exists(BASE_PATH . '/' . ltrim($href, '/'))) {
        return null;
    }

    return url($href);
}

/**
 * True when the signed-in user may see a navigation item.
 *
 * An item may be restricted to an explicit list of roles as well as to a
 * capability. The UI is built around each role's actual workflow: a broad
 * admin permission does not mean every role-specific screen is shown, so
 * the passenger booking journey stays hidden from administrators.
 */
function nav_item_allowed(array $item): bool
{
    $roles = $item['roles'] ?? [];

    if ($roles !== [] && !has_role(...$roles)) {
        return false;
    }

    $capabilities = $item['capabilities'] ?? [];

    if ($capabilities === []) {
        return true;
    }

    foreach ($capabilities as $capability) {
        if (has_permission((string) $capability)) {
            return true;
        }
    }

    return false;
}
