<?php
/**
 * EduFlex — database connection.
 *
 * Returns a single shared PDO instance. Every query in the application goes
 * through this. Never open a second connection, and never build SQL by
 * concatenating user input; use prepared statements.
 *
 * Default XAMPP credentials are root with an empty password. Change these
 * before the system is deployed anywhere other than a local machine.
 */

declare(strict_types=1);

/* Deployment credentials go in config/database.local.php, NOT here.
 *
 * This file is tracked by git. Typing a live database password into it and
 * committing publishes that password to a public repository, where rewriting
 * history does not reliably take it back. config/database.local.php is in
 * .gitignore for the same reason config/ai.php is.
 *
 * It is loaded first and wins, because each constant below is only defined if
 * nothing has defined it already. Copy config/database.example.php to
 * database.local.php on the server and fill it in. A machine without that file
 * keeps the XAMPP defaults, so no teammate has to do anything. */
$localDatabaseConfig = __DIR__ . '/database.local.php';
if (is_file($localDatabaseConfig)) {
    require_once $localDatabaseConfig;
}

defined('DB_HOST')    || define('DB_HOST', '127.0.0.1');
defined('DB_PORT')    || define('DB_PORT', 3306);
defined('DB_NAME')    || define('DB_NAME', 'eduflex');
defined('DB_USER')    || define('DB_USER', 'root');
defined('DB_PASS')    || define('DB_PASS', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

/**
 * Injected connection, used by the test suite. Production code never calls
 * this; it exists so tests can run the real auth logic against a throwaway
 * database instead of your development data.
 */
function db_set_connection(?PDO $pdo): void
{
    $GLOBALS['__eduflex_pdo_override'] = $pdo;
}

/**
 * @return PDO the shared connection
 */
function db(): PDO
{
    if (isset($GLOBALS['__eduflex_pdo_override'])
        && $GLOBALS['__eduflex_pdo_override'] instanceof PDO) {
        return $GLOBALS['__eduflex_pdo_override'];
    }

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
            // Throw on error rather than failing silently.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Plain associative arrays, no duplicated numeric keys.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, not client-side emulation.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never show a raw database error to a user; it leaks structure.
        error_log('EduFlex DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('The system cannot reach the database right now. '
           . 'Check that MySQL is running in the XAMPP Control Panel.');
    }

    return $pdo;
}
