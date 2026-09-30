<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * config/env.php
 *
 * A tiny, dependency-free environment loader. It lets the same code run
 * on a local XAMPP box and on a live host without editing any PHP file.
 *
 * Resolution order for every value (first one that is set wins):
 *
 *   1. A real environment variable  (PaaS / Docker / Apache SetEnv)
 *   2. A key in the project's `.env` file   (typical on shared hosting)
 *   3. The default passed by the caller     (safe local XAMPP values)
 *
 * The `.env` file is optional. When it is absent the application falls
 * back to the local defaults, so nothing changes for an existing install.
 *
 * The `.env` file sits in the project root and — like every dotfile — is
 * blocked from the browser by the root `.htaccess`.
 */

declare(strict_types=1);

// Defence in depth: never serve this file, whatever the web server allows.
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(404);
    exit;
}

/**
 * Parse a `.env` file into an associative array.
 *
 * Supports comments (`#` / `;`), blank lines, optional surrounding quotes
 * and the common `export KEY=value` form. Values may contain `=`.
 *
 * @return array<string, string>
 */
function fleetra_load_env_file(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $values = [];
    $lines  = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }

        // Allow the "export KEY=value" shell form so a .env can be sourced too.
        if (stripos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        // Strip one layer of matching quotes.
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last  = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $values[$key] = $value;
    }

    return $values;
}

/**
 * Read a configuration value from the environment or the `.env` file.
 *
 * @param string      $key     Variable name, e.g. "DB_HOST".
 * @param string|null $default Value to use when nothing is configured.
 */
function fleetra_env(string $key, ?string $default = null): ?string
{
    static $fileValues = null;

    if ($fileValues === null) {
        $fileValues = fleetra_load_env_file(dirname(__DIR__) . '/.env');
    }

    $value = getenv($key);

    if ($value === false || $value === '') {
        $value = $fileValues[$key] ?? null;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

/**
 * Boolean variant of fleetra_env().
 * Treats "1", "true", "yes" and "on" (case-insensitive) as true.
 */
function fleetra_env_bool(string $key, bool $default = false): bool
{
    $value = fleetra_env($key);

    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Integer variant of fleetra_env().
 */
function fleetra_env_int(string $key, int $default): int
{
    $value = fleetra_env($key);

    if ($value === null || !is_numeric(trim($value))) {
        return $default;
    }

    return (int) trim($value);
}
