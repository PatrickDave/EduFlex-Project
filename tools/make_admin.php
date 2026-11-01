<?php
/**
 * EduFlex — grant or revoke project team access.
 *
 *     php tools/make_admin.php                      list who has it
 *     php tools/make_admin.php eduflex54@gmail.com  grant it
 *     php tools/make_admin.php <email> --revoke     take it away
 *
 * is_admin is set by hand on purpose. Nothing in the interface grants it: no
 * promote button, no admin registration, no "first user becomes admin". A flag
 * no page can write is a flag no bug can hand to a learner, and
 * tests/admin_test.php greps the source to keep it that way.
 *
 * This is that hand, with the two checks a phpMyAdmin edit does not give you:
 * it refuses an address that has no account, so a typo cannot silently do
 * nothing, and it prints who holds the flag afterwards so you can see the
 * result rather than assume it.
 *
 * The account must exist first. Register it through the normal Sign Up form in
 * the browser the team will actually use: that gives the browser a seven day
 * trust grant, so the first sign-in does not need a code emailed to the team
 * inbox. Creating the row from the command line skips that grant.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../includes/auth.php';

$args   = array_values(array_filter(array_slice($argv, 1),
    static fn(string $a): bool => $a !== '--revoke'));
$revoke = in_array('--revoke', $argv, true);
$email  = strtolower(trim((string) ($args[0] ?? '')));

function admin_roster(): void
{
    $rows = db()->query(
        'SELECT user_id, full_name, email, is_admin FROM user ORDER BY user_id'
    )->fetchAll();

    $admins = 0;
    printf("\n  %-5s %-24s %-36s %s\n", 'ID', 'NAME', 'EMAIL', 'TEAM');
    printf("  %s\n", str_repeat('-', 76));
    foreach ($rows as $r) {
        $is = (int) $r['is_admin'] === 1;
        $admins += $is ? 1 : 0;
        printf("  %-5d %-24s %-36s %s\n",
            $r['user_id'],
            mb_substr((string) $r['full_name'], 0, 24),
            mb_substr((string) $r['email'], 0, 36),
            $is ? 'yes' : '');
    }

    printf("\n  %d of %d account%s on the project team.\n\n",
        $admins, count($rows), count($rows) === 1 ? '' : 's');

    if ($admins === 0) {
        printf("  Nobody can reach the help desk. Grant it to the team address:\n");
        printf("      php tools/make_admin.php eduflex54@gmail.com\n\n");
    }
}

if ($email === '') {
    admin_roster();
    printf("  To change it:\n");
    printf("      php tools/make_admin.php <email>\n");
    printf("      php tools/make_admin.php <email> --revoke\n\n");
    exit(0);
}

$stmt = db()->prepare('SELECT user_id, full_name, is_admin FROM user WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, sprintf(
        "\n  No account for %s.\n\n"
        . "  Register it first, through Sign Up in the browser the team will use.\n"
        . "  Doing it there rather than here gives that browser a seven day trust\n"
        . "  grant, so the first sign-in does not need a code emailed to the inbox.\n\n",
        $email
    ));
    exit(2);
}

$already = (int) $user['is_admin'] === 1;
$target   = $revoke ? 0 : 1;

if ($already === !$revoke) {
    printf("\n  %s (%s) is already %s.\n",
        $user['full_name'], $email, $revoke ? 'not on the team' : 'on the project team');
    admin_roster();
    exit(0);
}

$update = db()->prepare('UPDATE user SET is_admin = ? WHERE user_id = ?');
$update->execute([$target, (int) $user['user_id']]);

printf("\n  %s (%s) %s.\n",
    $user['full_name'],
    $email,
    $revoke ? 'no longer has help desk access' : 'can now sign in at admin_login.php');

admin_roster();
exit(0);
