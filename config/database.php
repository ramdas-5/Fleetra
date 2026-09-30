<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * config/database.php
 *
 * The ONLY place in the project that holds database credentials.
 * Provides a shared PDO connection plus small query helpers so modules
 * never repeat connection or fetch boilerplate.
 *
 * XAMPP defaults: host "localhost", user "root", empty password.
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

require_once __DIR__ . '/config.php';

/* ------------------------------------------------------------------
 | Connection credentials (local XAMPP defaults)
 ------------------------------------------------------------------ */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'fleetra_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Return the shared PDO connection (created once per request).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $exception) {
        fleetra_log('Database connection failed: ' . $exception->getMessage());
        fleetra_fatal(
            'We could not connect to the Fleetra database. '
            . 'Please make sure MySQL is running in XAMPP and that the "'
            . DB_NAME . '" database has been imported.'
        );
    }

    return $pdo;
}

/**
 * True when the Fleetra database can actually be reached.
 *
 * Used by the public landing page, which has to render even before the
 * schema has been imported. It probes with its own short-lived connection
 * so the friendly fatal page in db() is never triggered by an optional
 * read. Credentials are still sourced only from this file.
 */
function db_available(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $probe = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT    => 2,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $probe->query('SELECT 1 FROM users LIMIT 1');

        $available = true;
    } catch (Throwable $exception) {
        $available = false;
    }

    return $available;
}

/* ------------------------------------------------------------------
 | Query helpers — every one of them uses prepared statements.
 ------------------------------------------------------------------ */

/**
 * Run a prepared statement and return it.
 *
 * @param array<string|int, mixed> $params
 */
function db_run(string $sql, array $params = []): PDOStatement
{
    try {
        $statement = db()->prepare($sql);
        $statement->execute($params);

        return $statement;
    } catch (PDOException $exception) {
        fleetra_log('Query failed: ' . $exception->getMessage() . ' | SQL: ' . $sql);

        // Give a useful hint when the schema has not been imported yet.
        $message = stripos($exception->getMessage(), 'exist') !== false
            || str_contains($exception->getMessage(), '42S02')
            ? 'The Fleetra database tables are missing or incomplete. Please import database/fleetra_db.sql in phpMyAdmin, then reload this page.'
            : 'We could not load this information right now. Please try again.';

        fleetra_fatal($message);
    }
}

/**
 * Fetch a single row (or null when nothing matches).
 *
 * @param array<string|int, mixed> $params
 * @return array<string, mixed>|null
 */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();

    return $row === false ? null : $row;
}

/**
 * Fetch all rows.
 *
 * @param array<string|int, mixed> $params
 * @return array<int, array<string, mixed>>
 */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/**
 * Fetch the first column of the first row (used for COUNT/SUM queries).
 *
 * @param array<string|int, mixed> $params
 */
function db_value(string $sql, array $params = [], mixed $default = null): mixed
{
    $value = db_run($sql, $params)->fetchColumn();

    return $value === false ? $default : $value;
}

/**
 * Execute a write statement and return the number of affected rows.
 *
 * @param array<string|int, mixed> $params
 */
function db_execute(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

/**
 * Insert a row and return its new auto-increment id.
 *
 * @param array<string, mixed> $data column => value
 */
function db_insert(string $table, array $data): int
{
    $columns      = array_keys($data);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s)',
        $table,
        implode('`, `', $columns),
        implode(', ', $placeholders)
    );

    $params = [];
    foreach ($data as $column => $value) {
        $params[':' . $column] = $value;
    }

    db_run($sql, $params);

    return (int) db()->lastInsertId();
}

/**
 * Update a row by primary key and return the number of affected rows.
 *
 * @param array<string, mixed> $data  column => value (updated columns)
 * @param array<string, mixed> $where column => value (matching columns)
 */
function db_update(string $table, array $data, array $where): int
{
    $setParts = [];
    $params   = [];

    foreach ($data as $column => $value) {
        $setParts[]            = sprintf('`%s` = :set_%s', $column, $column);
        $params[':set_' . $column] = $value;
    }

    $whereParts = [];
    foreach ($where as $column => $value) {
        $whereParts[]               = sprintf('`%s` = :where_%s', $column, $column);
        $params[':where_' . $column] = $value;
    }

    $sql = sprintf(
        'UPDATE `%s` SET %s WHERE %s',
        $table,
        implode(', ', $setParts),
        implode(' AND ', $whereParts)
    );

    return db_run($sql, $params)->rowCount();
}
