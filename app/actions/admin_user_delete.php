<?php
/**
 * Delete one learner and everything stored with them, on their behalf.
 *
 * The withdrawal half of the Chapter III ethics commitment, actioned by the
 * team. A participant who emails "please remove my data" gets it done here
 * rather than being told to find a button.
 *
 * Three things stand between a stray click and a participant's whole record:
 *
 *   admin_require(), so only the team can reach this at all.
 *
 *   admin_user(), which returns null for a team account as well as a missing
 *   one, so a crafted id cannot delete another admin through a screen meant
 *   for participants.
 *
 *   auth_delete_account(), which refuses unless the typed email matches the
 *   account being deleted. That check was written for the learner deleting
 *   themselves and it is worth more here, where the person typing is not the
 *   person being deleted and nothing can be undone.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin.php';

admin_require();

/* Where to go afterwards, from a fixed set rather than the POST as given.
   'account' is the Manage User Accounts details screen, added 19 November
   2026; anything else is Team Settings, where this action started. An
   unchecked return field is an open redirect wearing a different hat. */
$fromAccount = (string) ($_POST['from'] ?? '') === 'account';
$back = $fromAccount
    ? '../admin_user.php?id=' . (int) ($_POST['user_id'] ?? 0)
    : '../admin_settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('users_error', 'Your session expired. Please try again.');
    redirect($back);
}

$target = admin_user((int) ($_POST['user_id'] ?? 0));

if ($target === null) {
    flash_set('users_error', 'That learner no longer exists.');
    redirect($back);
}

$typed = trim((string) ($_POST['confirm_email'] ?? ''));

if ($typed === '') {
    flash_set('users_error',
        'Type the learner\'s email address to confirm. Nothing was deleted.');
    redirect($back);
}

$result = auth_delete_account((int) $target['user_id'], $typed);

if (!$result['ok']) {
    /* auth_delete_account() says "Type YOUR OWN email address exactly", which
       is right when a learner deletes themselves and wrong here: the address
       being typed belongs to somebody else. Reworded rather than changed at
       source, because the learner's own Settings still needs the original. */
    $mismatch = str_contains((string) $result['error'], 'your own email');

    flash_set('users_error', $mismatch
        ? sprintf('That did not match. Type %s exactly to delete that account. '
                  . 'Nothing was deleted.', (string) $target['email'])
        : (string) $result['error']);
    redirect($back);
}

flash_set('users_ok', sprintf(
    '%s has been deleted, with everything stored under that account%s.',
    (string) $target['email'],
    $result['files'] > 0
        ? sprintf(' and %d uploaded file%s',
            $result['files'], $result['files'] === 1 ? '' : 's')
        : ''
));
// The account page no longer exists, so a delete from there lands on the list.
redirect($fromAccount ? '../admin_users.php' : $back);
