<?php
/**
 * EduFlex — Conversation
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';
admin_require();
$thread = admin_request((int) ($_GET['id'] ?? 0));
if ($thread === null) {
    flash_set('admin_error', 'That request could not be found. It may have been deleted along with the account that sent it.');
    redirect('admin_requests.php');
}
auth_require_login();

$user   = auth_user();
$active = 'admin_requests';
$title  = 'Conversation';

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
$viewerIsTeam = true;
$ok           = flash_get('admin_ok');
$err          = flash_get('admin_error');
$draft        = (string) (flash_get('admin_reply_draft') ?? '');
$firstName    = trim(explode(' ', trim((string) $thread['full_name']))[0] ?? '');
$needsReply   = admin_needs_reply($thread);
?>
      <div class="ef-content">

        <a class="ef-back-link" href="admin_requests.php">&larr; All requests</a>

        <div class="ef-page-head">
          <div style="min-width:0;">
            <h2 class="ef-thread-title">#<?= $requestId ?> &middot; <?= e((string) $thread['subject']) ?></h2>
            <p><?= e(support_type_label((string) $thread['request_type'])) ?></p>
          </div>
          <div class="ef-admin-req-pills">
            <?php if ($needsReply): ?>
              <span class="ef-reply-flag">Needs a reply</span>
            <?php endif; ?>
            <span class="ef-band ef-band-<?= e(support_status_band($status)) ?>">
              <?= e(support_status_label($status)) ?>
            </span>
          </div>
        </div>

        <?php if ($ok): ?>
          <div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div>
        <?php endif; ?>

        <div class="ef-row">
          <div class="ef-col ef-stack">

            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div class="ef-card-title">Conversation</div>
                <span class="ef-muted" style="font-size:11.5px;">
                  <?= count($replies) + 1 ?> message<?= count($replies) === 0 ? '' : 's' ?>
                </span>
              </div>
              <?php include __DIR__ . '/../partials/support_thread.php'; ?>
            </section>

            <form class="ef-card ef-card-lg ef-reply-form" action="actions/admin_reply.php" method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="request_id" value="<?= $requestId ?>">
              <label class="ef-card-title ef-reply-label" for="reply-message">
                Reply<?= $firstName !== '' ? ' to ' . e($firstName) : '' ?>
              </label>
              <textarea class="ef-input" id="reply-message" name="message" rows="5"
                        maxlength="<?= (int) SUPPORT_MESSAGE_MAX ?>"
                        placeholder="Write your reply. Be specific about what you found and what the learner should do next."
                        required><?= e($draft) ?></textarea>
              <p class="ef-note">
                The learner sees this under their request on the Support page and gets a
                notification. Nothing is emailed.
                <?php if ($status === 'open'): ?>
                  Sending moves the request to <?= e(support_status_label('in_progress')) ?>.
                <?php endif; ?>
              </p>
              <div class="ef-reply-actions">
                <button class="ef-btn ef-btn-primary" type="submit">Send reply</button>
                <?php if ($status !== 'resolved'): ?>
                  <button class="ef-btn ef-btn-ghost" type="submit" name="resolve" value="1">
                    Send and mark resolved
                  </button>
                <?php endif; ?>
              </div>
            </form>

          </div>

          <div class="ef-col-fixed-360 ef-stack">

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:12px;">From</div>
              <dl class="ef-thread-facts">
                <dt>Name</dt><dd><?= e((string) $thread['full_name']) ?></dd>
                <dt>Email</dt><dd><?= e((string) $thread['email']) ?></dd>
                <dt>Sent</dt>
                <dd><?= e(date('j M Y, g:i A', strtotime((string) $thread['created_at']) ?: time())) ?></dd>
              </dl>
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Status</div>
              <div class="ef-card-sub" style="margin-bottom:14px;">
                The learner sees the status on their Support page.
              </div>
              <div class="ef-reply-actions">
                <?php foreach (ADMIN_STATUS_ACTIONS as $next => $verb): ?>
                  <?php if ($next === $status) { continue; } ?>
                  <form method="post" action="actions/admin_status.php" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                    <input type="hidden" name="status" value="<?= e($next) ?>">
                    <input type="hidden" name="back" value="admin_thread.php">
                    <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit"><?= e($verb) ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
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
