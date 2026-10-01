<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/permissions.php
 *
 * Role based access control. Every restricted page and every AJAX
 * endpoint calls require_permission() so authorisation is always
 * verified on the server — never trusted from the browser.
 *
 * Adding a new role later means: add it to role_labels(), give it a
 * capability list here, and add its dashboard to dashboard_path().
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/* ------------------------------------------------------------------
 | Roles
 ------------------------------------------------------------------ */

/** Role key => display label. Keys are stored in users.role. */
function role_labels(): array
{
    return [
        'admin'      => 'Administrator',
        'manager'    => 'Transport Manager',
        'dispatcher' => 'Dispatcher',
        'driver'     => 'Driver',
        'passenger'  => 'Passenger',
    ];
}

/** Human readable label for a role key. */
function role_label(?string $role): string
{
    $labels = role_labels();

    return $labels[(string) $role] ?? labelize((string) $role);
}

/** Short badge colour for a role chip. */
function role_variant(string $role): string
{
    return match ($role) {
        'admin'      => 'primary',
        'manager'    => 'info',
        'dispatcher' => 'warning',
        'driver'     => 'success',
        default      => 'muted',
    };
}

/** Render a role chip. */
function role_badge(?string $role): string
{
    $role = (string) $role;

    return '<span class="badge-status badge-' . e(role_variant($role)) . '">'
        . e(role_label($role)) . '</span>';
}

/* ------------------------------------------------------------------
 | Capabilities
 ------------------------------------------------------------------ */

/**
 * Capability list per role. The wildcard "*" grants every capability and
 * the suffix ".*" grants a whole group, e.g. "fleet.*".
 *
 * @return array<string, array<int, string>>
 */
function role_permissions(): array
{
    return [
        'admin' => ['*'],

        'manager' => [
            'dashboard.view',
            'fleet.*',
            'drivers.*',
            'routes.*',
            'schedules.*',
            'trips.view',
            'trips.manage',
            'bookings.view',
            'passengers.view',
            'maintenance.*',
            'incidents.view',
            'incidents.manage',
            'notifications.view',
            'notifications.send',
            'reports.view',
            'profile.manage',
        ],

        'dispatcher' => [
            'dashboard.view',
            'fleet.view',
            'drivers.view',
            'routes.view',
            'schedules.view',
            'trips.view',
            'trips.manage',
            'bookings.view',
            'bookings.manage',
            'incidents.view',
            'incidents.report',
            'notifications.view',
            'notifications.send',
            'profile.manage',
        ],

        'driver' => [
            'dashboard.view',
            'trips.own',
            'trips.update_status',
            'incidents.report',
            'maintenance.report',
            'notifications.view',
            'profile.manage',
        ],

        'passenger' => [
            'dashboard.view',
            'trips.search',
            'bookings.create',
            'bookings.own',
            'bookings.cancel',
            'tickets.own',
            'notifications.view',
            'profile.manage',
        ],
    ];
}

/** Every capability the application knows about, for the settings screen. */
function all_capabilities(): array
{
    $capabilities = [];

    foreach (role_permissions() as $list) {
        foreach ($list as $capability) {
            $capabilities[] = $capability;
        }
    }

    $capabilities = array_unique($capabilities);
    sort($capabilities);

    return $capabilities;
}

/**
 * Check whether a role holds a capability.
 * Matching rules: "*" matches everything, "fleet.*" matches "fleet.view".
 */
function role_has_capability(string $role, string $capability): bool
{
    $granted = role_permissions()[$role] ?? [];

    foreach ($granted as $entry) {
        if ($entry === '*') {
            return true;
        }
        if ($entry === $capability) {
            return true;
        }
        if (str_ends_with($entry, '.*') && str_starts_with($capability, substr($entry, 0, -1))) {
            return true;
        }
    }

    return false;
}

/** Check the signed-in user's capabilities. */
function has_permission(string $capability, ?string $role = null): bool
{
    $role = $role ?? current_role();

    if ($role === '') {
        return false;
    }

    return role_has_capability($role, $capability);
}

/** Convenience alias used by templates: <?php if (can('fleet.manage')): ?> */
function can(string $capability): bool
{
    return has_permission($capability);
}

/** True when the signed-in user's role is one of the given roles. */
function has_role(string ...$roles): bool
{
    return in_array(current_role(), $roles, true);
}

/* ------------------------------------------------------------------
 | Guards
 ------------------------------------------------------------------ */

/**
 * Require a signed-in user holding a capability. Responds with 403 for
 * normal requests and a JSON error for AJAX callers.
 */
function require_permission(string $capability): void
{
    require_login();

    if (has_permission($capability)) {
        return;
    }

    fleetra_log(
        'Permission denied: user #' . user_id() . ' (' . current_role() . ') needed "' . $capability
        . '" at ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'),
        'WARNING'
    );

    if (is_ajax()) {
        json_response([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }

    fleetra_fatal(
        'Your account (' . role_label(current_role()) . ') does not have access to this page. '
        . 'Contact a Fleetra administrator if you believe this is a mistake.',
        403
    );
}

/** Require one of the given roles (in addition to being signed in). */
function require_role(string ...$roles): void
{
    require_login();

    if (has_role(...$roles)) {
        return;
    }

    if (is_ajax()) {
        json_response(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
    }

    fleetra_fatal('This page is not available for your account type.', 403);
}

/** Guard a page with an optional capability requirement. */
function require_page_access(string $capability = 'dashboard.view'): void
{
    require_permission($capability);
}
