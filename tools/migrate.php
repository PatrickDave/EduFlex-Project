<?php
/**
 * EduFlex — bring an existing database up to date without destroying it.
 *
 *     php tools/migrate.php            show what is missing, change nothing
 *     php tools/migrate.php --apply    apply what is missing
 *
 * 01_schema.sql begins with DROP DATABASE. That is right for a first install
 * and for the browser tests, and it is how a working database full of accounts,
 * uploads and mastery scores gets wiped by somebody trying to add one column.
 * This script is the alternative: it looks at what the database already has,
 * reports what is missing, and adds only that.
 *
 * It never drops a table, never drops a column, and never deletes a row. If a
 * future change genuinely needs to remove something, do it by hand and in the
 * open, not from here.
 *
 * Safe to run repeatedly. Running it against a current database reports
 * "nothing to do" and touches nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv, true);

/**
 * Every change since the first release, newest last.
 *
 * Each entry names what it adds, how to tell whether it is already there, and
 * the SQL that adds it. The check is what makes this re-runnable, and it is
 * done with information_schema rather than with IF NOT EXISTS so the script
 * behaves the same on MySQL 8, which does not support that clause on ALTER.
 */
$migrations = [
    [
        'name'  => 'login_attempt table (login rate limiting)',
        'since' => '2026-09-14',
        'check' => static fn(PDO $pdo): bool => migrate_table_exists($pdo, 'login_attempt'),
        'sql'   => "CREATE TABLE login_attempt (
                      attempt_id   INT(11)      NOT NULL AUTO_INCREMENT,
                      email        VARCHAR(255) NOT NULL,
                      ip_address   VARCHAR(45)  NOT NULL,
                      attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (attempt_id),
                      KEY idx_login_identity (email, ip_address, attempted_at),
                      KEY idx_login_ip (ip_address, attempted_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    [
        'name'  => 'user.avatar_path (profile pictures)',
        'since' => '2026-09-15',
        'check' => static fn(PDO $pdo): bool => migrate_column_exists($pdo, 'user', 'avatar_path'),
        'sql'   => "ALTER TABLE user
                      ADD COLUMN avatar_path VARCHAR(255) NULL DEFAULT NULL
                      AFTER account_status",
    ],
    [
        'name'  => 'activity_item.topic_progress_id (mock examinations)',
        'since' => '2026-09-16',
        'check' => static fn(PDO $pdo): bool =>
            migrate_column_exists($pdo, 'activity_item', 'topic_progress_id'),
        'sql'   => "ALTER TABLE activity_item
                      ADD COLUMN topic_progress_id INT(11) NULL DEFAULT NULL
                      AFTER explanation",
    ],
    [
        'name'  => 'activity_item.bloom_level (mock examinations)',
        'since' => '2026-09-16',
        'check' => static fn(PDO $pdo): bool =>
            migrate_column_exists($pdo, 'activity_item', 'bloom_level'),
        'sql'   => "ALTER TABLE activity_item
                      ADD COLUMN bloom_level VARCHAR(20) NULL DEFAULT NULL
                      AFTER topic_progress_id",
    ],
];

/* -------------------------------------------------------------------------
   Introspection
   ------------------------------------------------------------------------- */

function migrate_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function migrate_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/* -------------------------------------------------------------------------
   Run
   ------------------------------------------------------------------------- */

try {
    $pdo = db();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot reach the database. Is MySQL running in the XAMPP Control Panel?\n");
    exit(2);
}

if (!migrate_table_exists($pdo, 'user')) {
    fwrite(STDERR, <<<TEXT

    This database has no `user` table, so there is nothing to migrate. It looks
    like EduFlex has never been installed here.

    For a FIRST install, import the full schema:

        mysql -u root < database/01_schema.sql

    That drops and recreates the database, which is correct when there is
    nothing in it and destructive when there is. Never run it against a database
    you have been using; run this script instead.


    TEXT);
    exit(3);
}

printf("\nEduFlex database migration\n==========================\n\n");

// Shown up front, because it is the thing worth protecting.
$counts = [];
foreach (['user', 'learning_resource', 'topic_progress', 'activity_attempt'] as $table) {
    $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
printf("  Existing data: %d account%s, %d material%s, %d topic%s, %d attempt%s\n\n",
    $counts['user'], $counts['user'] === 1 ? '' : 's',
    $counts['learning_resource'], $counts['learning_resource'] === 1 ? '' : 's',
    $counts['topic_progress'], $counts['topic_progress'] === 1 ? '' : 's',
    $counts['activity_attempt'], $counts['activity_attempt'] === 1 ? '' : 's');

$pending = [];
foreach ($migrations as $migration) {
    $present = ($migration['check'])($pdo);
    printf("  [%s] %-46s %s\n",
        $present ? 'x' : ' ',
        $migration['name'],
        $present ? 'already present' : 'MISSING');
    if (!$present) {
        $pending[] = $migration;
    }
}

if ($pending === []) {
    printf("\nNothing to do. This database is up to date.\n\n");
    exit(0);
}

if (!$apply) {
    printf("\n%d change%s missing. Nothing has been altered.\n",
        count($pending), count($pending) === 1 ? ' is' : 's are');
    printf("Run again with --apply to add %s:\n\n", count($pending) === 1 ? 'it' : 'them');
    printf("    php tools/migrate.php --apply\n\n");
    printf("Nothing here drops a table, drops a column or deletes a row.\n\n");
    exit(0);
}

printf("\nApplying %d change%s...\n\n", count($pending), count($pending) === 1 ? '' : 's');

$applied = 0;
foreach ($pending as $migration) {
    try {
        $pdo->exec($migration['sql']);
        printf("  applied  %s\n", $migration['name']);
        $applied++;
    } catch (Throwable $e) {
        printf("  FAILED   %s\n           %s\n", $migration['name'], $e->getMessage());
        fwrite(STDERR, "\nStopped. Nothing after this point was applied, and nothing was lost.\n");
        exit(4);
    }
}

printf("\n%d change%s applied. Your data is untouched.\n\n",
    $applied, $applied === 1 ? '' : 's');
exit(0);
