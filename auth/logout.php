<?php
/**
 * EduFlex — sign out, and say so.
 *
 * Everybody lands on the landing page (index.php), learners and the team
 * alike, which is where Patrick asked sign-out to go on 24 November 2026. It
 * used to land there in silence; now a toast says so, worded for whoever
 * signed out: ?signed_out=1 for a learner, ?signed_out=team for the team.
 * (Briefly, the same day, each went to their own sign-in page instead; the
 * sign-in pages still understand the flag, harmlessly.)
 *
 * Who they were is read BEFORE auth_logout(), because afterwards there is no
 * session to ask. The message travels as a query flag, not a flash, for the
 * same reason the account-deletion notice does: auth_logout() destroys the
 * session, so a flash set before it dies with it and one set after it has
 * nowhere to live. The flag carries no name or address, only that a sign-out
 * happened, and the page removes it from the address bar once shown.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$wasTeam = auth_is_logged_in() && admin_is_admin(auth_user());

auth_logout();
redirect('../index.php?signed_out=' . ($wasTeam ? 'team' : '1'));
