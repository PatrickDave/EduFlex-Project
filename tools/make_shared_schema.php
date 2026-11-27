<?php
/**
 * EduFlex — derive a shared-hosting import file from 01_schema.sql.
 *
 *     php tools/make_shared_schema.php           write the file
 *     php tools/make_shared_schema.php --check   is it in step? changes nothing
 *
 * WHY THIS EXISTS
 *
 * `01_schema.sql` opens with DROP DATABASE, CREATE DATABASE and USE. That is
 * correct for a first local install and impossible on shared hosting, where
 * the control panel creates the one database you are allowed and the server
 * disables those statements outright.
 *
 * On 27 November 2026 Patrick imported `01_schema.sql` into InfinityFree and
 * phpMyAdmin reported both "Import has been successfully finished, 22 queries
 * executed" AND an error, `"DROP DATABASE" statements are disabled`. Both were
 * true: every table was created, and the first line failed. A green banner and
 * a red box at the same time is not a reassuring thing to see an hour before
 * you show your adviser, which is reason enough to ship a file that imports
 * cleanly.
 *
 * It is DERIVED, never hand-maintained. Editing the output instead of
 * `01_schema.sql` would give a fresh local install and a deployed site two
 * different schemas, and nothing would notice until they disagreed about a
 * column. `tests/admin_test.php` fails if the two drift.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script runs from the command line only.\n");
}

const SHARED_SCHEMA_SOURCE = __DIR__ . '/../database/01_schema.sql';
const SHARED_SCHEMA_OUTPUT = __DIR__ . '/../database/01_schema_shared_host.sql';

/**
 * Strip the three statements a shared host refuses, and say so in the header.
 *
 * Deliberately a line filter rather than a SQL parser: the three statements it
 * removes are each written on their own lines in one known file, and a parser
 * would be more code with more ways to be subtly wrong.
 */
function shared_schema_from(string $sql): string
{
    $lines  = preg_split('/\R/', $sql) ?: [];
    $out    = [];
    $skip   = false;

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        // CREATE DATABASE spans several lines and ends at its semicolon.
        if ($skip) {
            if (str_contains($line, ';')) {
                $skip = false;
            }
            continue;
        }
        if (preg_match('/^DROP\s+DATABASE\b/i', $trimmed)) {
            continue;
        }
        if (preg_match('/^USE\s+/i', $trimmed)) {
            continue;
        }
        if (preg_match('/^CREATE\s+DATABASE\b/i', $trimmed)) {
            $skip = !str_contains($line, ';');
            continue;
        }
        $out[] = $line;
    }

    $header = <<<'TEXT'
-- ============================================================================
-- EduFlex schema FOR SHARED HOSTING. DERIVED FILE: DO NOT EDIT.
--
-- Regenerate with:  php tools/make_shared_schema.php
-- Source of truth:  database/01_schema.sql
--
-- Identical to 01_schema.sql with DROP DATABASE, CREATE DATABASE and USE
-- removed. A shared host creates the one database you are allowed from its
-- control panel and disables those statements, so importing the original
-- reports an error for the first line even though every table is created.
--
-- HOW TO IMPORT
--   1. Create the database in the hosting control panel first.
--   2. phpMyAdmin, SELECT that database in the left sidebar. This matters:
--      with no database selected every CREATE TABLE fails with
--      "No database selected".
--   3. Import, choose this file, Go.
--
-- Nothing else is needed. A fresh import of this file already contains every
-- change listed in 02_migrations.sql, so do NOT import that afterwards; it is
-- only for a database that already holds data.
-- ============================================================================

TEXT;

    // Drop the original banner, which names the wrong import route, and keep
    // everything from the first real statement onward.
    $body = implode("\n", $out);
    $body = preg_replace('/\A(?:\s*--[^\n]*\n|\s*\n)+/', '', $body) ?? $body;

    return $header . "\n" . ltrim($body);
}

$source = @file_get_contents(SHARED_SCHEMA_SOURCE);
if ($source === false) {
    fwrite(STDERR, "Cannot read database/01_schema.sql\n");
    exit(2);
}

$derived = shared_schema_from($source);
$check   = in_array('--check', $argv, true);
$current = is_file(SHARED_SCHEMA_OUTPUT)
    ? (string) file_get_contents(SHARED_SCHEMA_OUTPUT)
    : null;

if ($check) {
    if ($current === null) {
        fwrite(STDERR, "database/01_schema_shared_host.sql is missing.\n"
            . "Run: php tools/make_shared_schema.php\n");
        exit(1);
    }
    if ($current !== $derived) {
        fwrite(STDERR, "database/01_schema_shared_host.sql is out of step with"
            . " 01_schema.sql.\nRun: php tools/make_shared_schema.php\n");
        exit(1);
    }
    echo "In step with 01_schema.sql.\n";
    exit(0);
}

file_put_contents(SHARED_SCHEMA_OUTPUT, $derived);

$tables = preg_match_all('/^CREATE TABLE/m', $derived);
printf("Wrote database/01_schema_shared_host.sql\n  %d CREATE TABLE statements\n", $tables);

/* Check the SQL, not the comments. The header explains which statements were
 * removed, so it names all three; searching the whole file found them there
 * and reported "still present" about its own documentation. */
$statements = implode("\n", array_filter(
    preg_split('/\R/', $derived) ?: [],
    static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
));
foreach (['DROP DATABASE', 'CREATE DATABASE', 'USE '] as $gone) {
    printf("  %-16s removed: %s\n", $gone,
        stripos($statements, $gone) === false ? 'yes' : 'NO, still present');
}
exit(0);
