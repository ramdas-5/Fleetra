<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * logout.php — end the session
 *
 * Accepts POST (preferred, CSRF protected). A GET request is still
 * honoured so a plain sign-out link keeps working, but it is logged.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

if (is_post()) {
    require_csrf();
}

if (is_logged_in()) {
    logout_user();
}

// Start a fresh session purely to carry the goodbye message.
fleetra_session_start();
flash('info', 'You have been signed out of Fleetra.');

redirect('login.php?logged_out=1');
