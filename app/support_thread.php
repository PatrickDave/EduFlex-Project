<?php
/**
 * EduFlex — Support
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/support.php';
auth_require_login();
$thread = support_request_for_user((int) (auth_user()['user_id'] ?? 0), (int) ($_GET['id'] ?? 0));
if ($thread === null) {
    flash_set('support_error', 'That request could not be found.');
    redirect('support.php');
}
auth_require_login();

$user   = auth_user();
$active = 'support';
$title  = 'Support';

/* The project team's account has no learning of its own.
 *
 * eduflex54@gmail.com is a shared address, not a participant: it has uploaded
 * nothing, answered nothing and has no mastery to look at, so every learner
 * screen would show it an empty state that means nothing. Patrick asked on
 * 2 November 2026 that it stay out of the learner area entirely.
 *
 * There used to be an exception for Settings, because that was the only place
 * the team could change its own password. app/admin_settings.php does that now,
 * so the exception is gone and the separation is complete: no learner page at
 * all, not even one.
 *
 * This runs before any output, so redirect() can still fire. The reverse
 * direction, a learner reaching an admin page, is held by admin_require() in
 * the requires slot above. */
if ($user !== null
    && admin_is_admin($user)
    && !str_starts_with($active, 'admin')) {
    redirect('admin.php');
}

/* The "Login Successful" welcome, once, on the first page after signing in.
   includes/auth.php sets the marker in auth_complete_login(), which only a
   successful sign-in reaches. Read and cleared here so a refresh does not
   replay it. Learners and the team both see it (the team since 24 November
   2026, at Patrick's request), each with its own message: see
   partials/login_welcome.php. A learner who tries the admin door is signed
   out by admin_login.php, which destroys the session and the marker with it,
   so this never plays for a refused sign-in on either door. */
$loginWelcome = false;
$loginWelcomeTeam = false;
if (isset($_SESSION['_login_welcome'])) {
    unset($_SESSION['_login_welcome']);
    $loginWelcome = $user !== null;
    $loginWelcomeTeam = $loginWelcome && admin_is_admin($user);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — EduFlex</title>
<link href="../assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="<?= asset('../assets/css/eduflex.css') ?>" rel="stylesheet">
</head>
<body<?= $loginWelcome ? ' class="ef-entering"' : '' ?>>

<?php if ($loginWelcome): include __DIR__ . '/../partials/login_welcome.php'; endif; ?>

<div class="ef-shell">

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="ef-main">

<?php include __DIR__ . '/../partials/topbar.php'; ?>

<?php
$requestId    = (int) $thread['request_id'];
$replies      = support_replies($requestId);
$status       = (string) $thread['status'];
$viewerIsTeam = false;
$ok           = flash_get('support_ok');
$err          = flash_get('support_error');
$draft        = (string) (flash_get('support_reply_draft') ?? '');
$teamReplies  = count(array_filter($replies, static fn(array $r): bool => (int) $r['from_team'] === 1));

$statusNote = match ($status) {
    'open'        => 'Recorded. The project team has not picked this up yet.',
    'in_progress' => 'The project team is looking at this.',
    'resolved'    => 'The team marked this resolved. If it is not, reply below and it reopens.',
    'closed'      => 'Closed. Replies are turned off. Send a new request if you still need help.',
    default       => '',
};
?>
      <div class="ef-content">

        <a class="ef-back-link" href="support.php">&larr; All your requests</a>

        <div class="ef-page-head">
          <div style="min-width:0;">
            <h2 class="ef-thread-title"><?= e((string) $thread['subject']) ?></h2>
            <p>
              #<?= $requestId ?> &middot; <?= e(support_type_label((string) $thread['request_type'])) ?>
              &middot; sent <?= e(date('j M Y', strtotime((string) $thread['created_at']) ?: time())) ?>
            </p>
          </div>
          <span class="ef-band ef-band-<?= e(support_status_band($status)) ?>">
            <?= e(support_status_label($status)) ?>
          </span>
        </div>

        <?php if ($ok): ?><div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div><?php endif; ?>

        <div class="ef-row">
          <div class="ef-col ef-stack">

            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div class="ef-card-title">Conversation with the project team</div>
                <span class="ef-muted" style="font-size:11.5px;">
                  <?= $teamReplies === 0 ? 'No reply yet' : $teamReplies . ' from the team' ?>
                </span>
              </div>
              <?php include __DIR__ . '/../partials/support_thread.php'; ?>
              <?php if ($teamReplies === 0): ?>
                <p class="ef-note" style="margin-top:14px;">
                  Nobody is on duty, so a reply comes when the team next reviews requests.
                  You will get a notification when it does.
                </p>
              <?php endif; ?>
            </section>

            <?php if ($status === 'closed'): ?>
              <div class="ef-alert ef-alert-info" style="margin:0;">
                This request is closed, so it cannot take more replies.
                <a href="support.php">Send a new request</a> if you still need help.
              </div>
            <?php else: ?>
              <form class="ef-card ef-card-lg ef-reply-form" action="actions/support_reply.php" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                <label class="ef-card-title ef-reply-label" for="reply-message">Write a reply</label>
                <textarea class="ef-input" id="reply-message" name="message" rows="4"
                          maxlength="<?= (int) SUPPORT_MESSAGE_MAX ?>"
                          placeholder="Add detail, answer the team's question, or say whether it worked."
                          required><?= e($draft) ?></textarea>
                <p class="ef-note">
                  Only you and the project team can read this conversation.
                  <?php if ($status === 'resolved'): ?>
                    Replying reopens the request.
                  <?php endif; ?>
                </p>
                <div class="ef-reply-actions">
                  <button class="ef-btn ef-btn-primary" type="submit">Send reply</button>
                </div>
              </form>
            <?php endif; ?>

          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Where this stands</div>
              <p class="ef-card-sub" style="margin:0;"><?= e($statusNote) ?></p>
            </section>
          </div>
        </div>

      </div>


    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
