<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * config/config.php
 *
 * Central application configuration. This file is the single source of
 * truth for paths, environment flags, error handling and the session
 * bootstrap. Every entry point loads this file (usually indirectly via
 * config/database.php or includes/functions.php).
 *
 * Keep credentials OUT of this file — they live in config/database.php.
 */

declare(strict_types=1);

/*
 * Defence in depth: refuse a direct web request for this file even when the
 * web server is not honouring .htaccess (for example PHP's built-in server).
 */
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(404);
    exit;
}

/* ------------------------------------------------------------------
 | 1. Application identity
 ------------------------------------------------------------------ */

define('FLEETRA_NAME', 'Fleetra');
define('FLEETRA_TAGLINE', 'Smart Transport Management');
define('FLEETRA_VERSION', '1.0.0');

/**
 * Environment flag.
 *   'local' -> development machine (XAMPP). Verbose logs, demo helpers on.
 *   'production' -> live deployment. Minimal logging, demo helpers off.
 */
define('APP_ENV', 'local');

/** Set to true to expose demo credentials on the login screen (local use only). */
define('FLEETRA_SHOW_DEMO_CREDENTIALS', APP_ENV === 'local');

/** Business timezone used for schedules, departures and reports. */
define('APP_TIMEZONE', 'Asia/Kolkata');

/** Currency symbol used across fares, tickets and reports. */
define('APP_CURRENCY', '₹');

/* ------------------------------------------------------------------
 | 2. Paths and base URL
 ------------------------------------------------------------------ */

/** Absolute filesystem path to the project root (no trailing slash). */
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)));

/** Absolute filesystem path to the uploads directory. */
define('UPLOAD_PATH', BASE_PATH . '/uploads');

/**
 * Web path to the project root, auto-detected so the app works both at
 * http://localhost/fleetra/ and at a bare http://localhost/.
 */
$fleetraDocumentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
$fleetraProjectRoot  = realpath(BASE_PATH);
$fleetraBaseUrl      = '/';

if ($fleetraDocumentRoot && $fleetraProjectRoot) {
    $fleetraDocumentRootWeb = rtrim(str_replace('\\', '/', $fleetraDocumentRoot), '/');
    $fleetraProjectRootWeb  = str_replace('\\', '/', $fleetraProjectRoot);

    if (stripos($fleetraProjectRootWeb, $fleetraDocumentRootWeb) === 0) {
        $relative = substr($fleetraProjectRootWeb, strlen($fleetraDocumentRootWeb));
        $fleetraBaseUrl = '/' . trim($relative, '/');
        $fleetraBaseUrl = ($fleetraBaseUrl === '/') ? '/' : $fleetraBaseUrl . '/';
    }
}

/** Web path to the project root, always with a trailing slash. */
define('BASE_URL', $fleetraBaseUrl);

unset($fleetraDocumentRoot, $fleetraProjectRoot, $fleetraBaseUrl, $fleetraDocumentRootWeb, $fleetraProjectRootWeb, $relative);

/* ------------------------------------------------------------------
 | 3. Error handling
 |    Users must never see a raw SQL error or PHP stack trace. Every
 |    problem is written to logs/ and a friendly page is rendered.
 ------------------------------------------------------------------ */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$fleetraLogDir = BASE_PATH . '/logs';
if (!is_dir($fleetraLogDir)) {
    @mkdir($fleetraLogDir, 0775, true);
}
ini_set('error_log', $fleetraLogDir . '/php-error.log');
unset($fleetraLogDir);

date_default_timezone_set(APP_TIMEZONE);

/**
 * Write a message to the Fleetra application log.
 *
 * @param string $message Free-form message (no secrets, please).
 * @param string $level   Log level label, e.g. INFO / WARNING / ERROR.
 */
function fleetra_log(string $message, string $level = 'ERROR'): void
{
    $line = sprintf(
        "[%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        PHP_EOL
    );

    $file = BASE_PATH . '/logs/fleetra.log';

    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Stop execution and render a friendly, brand-consistent error page.
 * Never exposes internal details to the browser.
 */
function fleetra_fatal(string $userMessage, int $statusCode = 500): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
    }

    $headings = [403 => 'Access denied', 404 => 'Not found', 419 => 'Session expired'];
    $heading  = $headings[$statusCode] ?? 'Something went wrong';

    $title = FLEETRA_NAME . ' — ' . $heading;
    $safe  = htmlspecialchars($userMessage, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:#F8FAFC; color:#0F172A; font:15px/1.6 Inter, system-ui, -apple-system, Arial, sans-serif; padding:24px; }
  .box { background:#fff; border:1px solid #E2E8F0; border-radius:14px; padding:36px 32px; max-width:460px; width:100%;
         box-shadow:0 1px 2px rgba(15,23,42,.04); text-align:center; }
  .mark { width:44px; height:44px; margin:0 auto 18px; border-radius:12px; background:#2563EB; color:#fff;
          display:flex; align-items:center; justify-content:center; font-weight:700; font-size:16px; letter-spacing:.5px; }
  h1 { font-size:20px; margin:0 0 8px; }
  p { margin:0 0 22px; color:#64748B; font-size:14px; }
  a { display:inline-block; background:#2563EB; color:#fff; text-decoration:none; padding:10px 18px;
      border-radius:9px; font-weight:600; font-size:14px; }
  a:hover { background:#1D4ED8; }
</style>
</head>
<body>
  <div class="box">
    <div class="mark">FL</div>
    <h1>{$heading}</h1>
    <p>{$safe}</p>
    <a href="javascript:history.back()">Go back</a>
  </div>
</body>
</html>
HTML;

    exit;
}

/* ------------------------------------------------------------------
 | 4. Secure session bootstrap
 ------------------------------------------------------------------ */

define('SESSION_NAME', 'fleetra_session');

/** Idle timeout in seconds before a session is considered expired (60 minutes). */
define('SESSION_IDLE_TIMEOUT', 3600);

/** How long a "Remember me" session lasts (30 days). */
define('SESSION_REMEMBER_SECONDS', 2592000);

/**
 * Start the Fleetra session with hardened cookie settings.
 * Safe to call multiple times — it no-ops when a session is active.
 */
function fleetra_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) max(SESSION_IDLE_TIMEOUT, SESSION_REMEMBER_SECONDS));

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL,
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

fleetra_session_start();
