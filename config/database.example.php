<?php
/**
 * EduFlex — deployment database credentials.
 *
 * Copy this file to `config/database.local.php` ON THE SERVER and fill it in.
 * That filename is in .gitignore, so the password never reaches the public
 * repository. This template is committed and must stay free of real values.
 *
 *     cp config/database.example.php config/database.local.php
 *
 * `config/database.php` loads it before its own defaults, and every constant
 * there is guarded with `defined()`, so whatever is set here wins and anything
 * left out falls back to the XAMPP default. A local XAMPP machine needs no
 * such file at all.
 *
 * On shared hosting the four values come from the control panel after you
 * create a database. The host usually prefixes both the database name and the
 * user with your account id, and DB_HOST is rarely `localhost`: on InfinityFree
 * it is an `sqlXXX.infinityfree.com` address, and using `localhost` there fails
 * with a connection error that reads like the database is down.
 */

declare(strict_types=1);

define('DB_HOST', 'sqlXXX.example-host.com');
define('DB_PORT', 3306);
define('DB_NAME', 'epiz_00000000_eduflex');
define('DB_USER', 'epiz_00000000');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
