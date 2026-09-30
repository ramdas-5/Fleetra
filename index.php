<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * index.php — entry point
 *
 * Sends signed-in users to the dashboard for their role and guests to
 * the login screen. This is the URL users open in the browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

if (is_logged_in()) {
    check_session_validity();
    redirect(dashboard_path(current_role()));
}

redirect('login.php');
