<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/auth.php
 *
 * Authentication foundation: session identity, login / logout,
 * login throttling, idle-timeout enforcement and route guards.
 *
 * Passwords are always stored with password_hash() and verified with
 * password_verify(). Plain-text passwords are never persisted.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/* ------------------------------------------------------------------
 | Session identity
 ------------------------------------------------------------------ */

/**
 * Return the currently authenticated user row, or null.
 * The row is fetched once per request and cached.
 *
 * @return array<string, mixed>|null
 */
function current_user(bool $refresh = false): ?array
{
    static $user     = null;
    static $resolved = false;

    if ($refresh) {
        $user     = null;
        $resolved = false;
    }

    if ($resolved) {
        return $user;
    }

    $resolved = true;
    $userId   = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return $user = null;
    }

    $record = db_one(
        'SELECT * FROM users WHERE id = ? AND status <> "deleted" LIMIT 1',
        [$userId]
    );

    if ($record === null) {
        // The account disappeared or was deactivated mid-session.
        destroy_session();

        return $user = null;
    }

    $user = $record;

    return $user;
}

/** True when a valid user is signed in. */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Signed-in user id, or 0. */
function user_id(): int
{
    $user = current_user();

    return $user === null ? 0 : (int) $user['id'];
}

/** Signed-in user's role key, or an empty string. */
function current_role(): string
{
    $user = current_user();

    return $user === null ? '' : (string) $user['role'];
}

/** Signed-in user's display name. */
function current_user_name(): string
{
    $user = current_user();

    return $user === null ? 'Guest' : (string) $user['name'];
}

/* ------------------------------------------------------------------
 | Session lifecycle
 ------------------------------------------------------------------ */

/** Session fingerprint used to detect cookie theft. */
function session_fingerprint(): string
{
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . '|fleetra');
}

/** Persist a user as the active session identity. */
function establish_session(array $user, bool $remember = false): void
{
    // Prevent session fixation: always issue a fresh id after login.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id']      = (int) $user['id'];
    $_SESSION['user_role']    = (string) $user['role'];
    $_SESSION['user_name']    = (string) $user['name'];
    $_SESSION['logged_in_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['fingerprint']  = session_fingerprint();
    $_SESSION['remember']     = $remember;

    if ($remember) {
        extend_session_cookie(SESSION_REMEMBER_SECONDS);
    }
}

/** Extend the session cookie beyond the browser session. */
function extend_session_cookie(int $seconds): void
{
    if (headers_sent()) {
        return;
    }

    $params = session_get_cookie_params();

    setcookie(session_name(), session_id(), [
        'expires'  => time() + $seconds,
        'path'     => $params['path'] ?: '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => (bool) ($params['secure'] ?? false),
        'httponly' => true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

/** Completely destroy the session and its cookie. */
function destroy_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (!headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => (bool) ($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Expire sessions that sat idle for too long, or whose fingerprint no
 * longer matches the browser that created them.
 */
function check_session_validity(): void
{
    if (!is_logged_in()) {
        return;
    }

    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    $fingerprint  = (string) ($_SESSION['fingerprint'] ?? '');

    if ($fingerprint !== '' && !hash_equals($fingerprint, session_fingerprint())) {
        fleetra_log('Session fingerprint mismatch for user #' . user_id(), 'WARNING');
        destroy_session();
        redirect('login.php?reason=session');
    }

    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_IDLE_TIMEOUT) {
        destroy_session();
        redirect('login.php?reason=timeout');
    }

    $_SESSION['last_activity'] = time();
}

/* ------------------------------------------------------------------
 | Login / logout
 ------------------------------------------------------------------ */

/** Max failed attempts before a short cooldown kicks in. */
const LOGIN_MAX_ATTEMPTS = 5;

/** Cooldown window in seconds once the limit is reached. */
const LOGIN_LOCKOUT_SECONDS = 900;

/** How long a password reset link stays valid (60 minutes). */
const PASSWORD_RESET_TTL_SECONDS = 3600;

/**
 * Throttle key for the current browser + submitted email.
 * Sessions are used so no extra table is required for a local install.
 */
function login_throttle_key(string $email): string
{
    return substr(hash('sha256', strtolower($email) . '|' . session_fingerprint()), 0, 16);
}

/** Remaining lockout seconds for this email, or 0 when not locked. */
function login_lockout_seconds(string $email): int
{
    $attempts = $_SESSION['login_attempts'][login_throttle_key($email)] ?? null;

    if (!is_array($attempts)) {
        return 0;
    }

    $count    = (int) ($attempts['count'] ?? 0);
    $lastTry  = (int) ($attempts['last'] ?? 0);

    if ($count < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }

    $elapsed = time() - $lastTry;

    return $elapsed >= LOGIN_LOCKOUT_SECONDS ? 0 : LOGIN_LOCKOUT_SECONDS - $elapsed;
}

/** Record a failed login attempt. */
function register_failed_login(string $email): void
{
    $key         = login_throttle_key($email);
    $attempts    = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'last' => 0];
    $withinWindow = (time() - (int) $attempts['last']) < LOGIN_LOCKOUT_SECONDS;

    $_SESSION['login_attempts'][$key] = [
        'count' => $withinWindow ? (int) $attempts['count'] + 1 : 1,
        'last'  => time(),
    ];
}

/** Clear throttle counters after a successful sign in. */
function clear_login_attempts(string $email): void
{
    unset($_SESSION['login_attempts'][login_throttle_key($email)]);
}

/**
 * Verify credentials and sign the user in.
 *
 * @return array{ok:bool, message?:string, user?:array<string,mixed>}
 */
function attempt_login(string $email, string $password, bool $remember = false): array
{
    $genericError = 'The email or password you entered is incorrect.';

    $lockout = login_lockout_seconds($email);
    if ($lockout > 0) {
        return [
            'ok'      => false,
            'message' => 'Too many failed attempts. Please try again in '
                . ceil($lockout / 60) . ' minute(s).',
        ];
    }

    $user = db_one(
        'SELECT * FROM users WHERE email = ? AND status <> "deleted" LIMIT 1',
        [$email]
    );

    // Always run a hash comparison so response timing does not leak
    // whether the email address exists.
    $hash = $user['password'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';

    if (!password_verify($password, $hash) || $user === null) {
        register_failed_login($email);
        fleetra_log('Failed login attempt for "' . $email . '" from ' . client_ip(), 'WARNING');

        return ['ok' => false, 'message' => $genericError];
    }

    if (($user['status'] ?? 'active') !== 'active') {
        return [
            'ok'      => false,
            'message' => 'This account is ' . str_replace('_', ' ', (string) $user['status'])
                . '. Please contact a Fleetra administrator.',
        ];
    }

    clear_login_attempts($email);
    establish_session($user, $remember);

    if (function_exists('password_needs_rehash') && password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
        db_update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], ['id' => (int) $user['id']]);
    }

    db_update('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => (int) $user['id']]);

    return ['ok' => true, 'user' => $user];
}

/** Sign the current user out and clear their session. */
function logout_user(): void
{
    $userId = user_id();

    if ($userId > 0) {
        log_activity('Signed out', 'auth', $userId, 'User signed out of Fleetra');
    }

    destroy_session();
}

/* ------------------------------------------------------------------
 | Route guards
 ------------------------------------------------------------------ */

/** Require an authenticated session, otherwise send the user to login. */
function require_login(): void
{
    check_session_validity();

    if (!is_logged_in()) {
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? null;
        flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }
}

/** Send an already authenticated visitor to their role dashboard. */
function guest_only(): void
{
    if (is_logged_in()) {
        redirect(dashboard_path(current_role()));
    }
}
