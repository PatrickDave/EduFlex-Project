<?php
/**
 * EduFlex — Team Settings
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/avatar.php';
admin_require();
auth_require_login();

$user   = auth_user();
$active = 'admin_settings';
$title  = 'Team Settings';

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
<body>

<div class="ef-shell">

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="ef-main">

<?php include __DIR__ . '/../partials/topbar.php'; ?>

<?php
/* The team's own settings, separate from the learner's.

   Clicking Settings as an admin used to land on app/settings.php, which is a
   learner screen: Personal details, a plan card, an export of "your" learning,
   none of which a shared team address has. Worse, $active was 'settings' so the
   sidebar rendered the LEARNER nav and the help desk disappeared mid-session.
   Patrick spotted that on 2 November 2026.

   Kept: profile picture, change password, delete this account.
   Gone: personal details, the plan card, email verification.
   New: Users, where a participant's withdrawal request gets actioned. */
$uid = (int) $user['user_id'];

$passwordOk  = flash_get('password_ok');
$passwordErr = flash_get('password_error');
$avatarOk    = flash_get('avatar_ok');
$avatarErr   = flash_get('avatar_error');
$deleteErr   = flash_get('delete_error');
$usersOk     = flash_get('users_ok');
$usersErr    = flash_get('users_error');

$hasAvatar = avatar_has($uid);
$learners  = admin_users();
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Team settings</h2>
            <p>This account, and the learners taking part in the study</p>
          </div>
        </div>

        <section class="ef-card ef-card-lg">
          <div class="ef-card-title" style="margin-bottom:6px;">Profile picture</div>
          <div class="ef-card-sub" style="margin-bottom:18px;">
            Shown in the top bar. Until you upload one, EduFlex shows initials.
          </div>

          <?php if ($avatarOk): ?>
            <div class="ef-alert ef-alert-ok"><?= e((string) $avatarOk) ?></div>
          <?php endif; ?>
          <?php if ($avatarErr): ?>
            <div class="ef-alert ef-alert-error"><?= e((string) $avatarErr) ?></div>
          <?php endif; ?>

          <div class="ef-avatar-edit">
            <?= avatar_html($uid, (string) $user['full_name'], 'ef-avatar-xl') ?>

            <div class="ef-avatar-edit-main">
              <form action="actions/save_avatar.php" method="post"
                    enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="ef-field" style="margin-bottom:12px;">
                  <label class="ef-label" for="avatar">
                    Choose an image
                    <span class="ef-muted">JPEG, PNG, GIF or WebP, up to 3 MB</span>
                  </label>
                  <input class="ef-input" type="file" id="avatar" name="avatar"
                         accept="image/jpeg,image/png,image/gif,image/webp" required>
                </div>
                <button class="ef-btn ef-btn-primary" type="submit">
                  <?= $hasAvatar ? 'Replace picture' : 'Upload picture' ?>
                </button>
              </form>

              <?php if ($hasAvatar): ?>
                <form action="actions/delete_avatar.php" method="post"
                      style="margin-top:10px;">
                  <?= csrf_field() ?>
                  <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit">
                    Remove picture
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <form class="ef-card ef-card-lg" action="actions/change_password.php" method="post">
          <?= csrf_field() ?>
          <div class="ef-card-title" style="margin-bottom:6px;">Change password</div>
          <div class="ef-card-sub" style="margin-bottom:16px;">
            A shared team account, so change it when a member leaves the project
          </div>

          <?php if ($passwordOk): ?>
            <div class="ef-alert ef-alert-ok"><?= e((string) $passwordOk) ?></div>
          <?php endif; ?>
          <?php if ($passwordErr): ?>
            <div class="ef-alert ef-alert-error"><?= e((string) $passwordErr) ?></div>
          <?php endif; ?>

          <div class="ef-field">
            <label class="ef-label" for="current_password">Current password</label>
            <div class="ef-password">
              <input class="ef-input" type="password" id="current_password"
                     name="current_password" autocomplete="current-password" required>
              <?= password_toggle_html('current_password') ?>
            </div>
          </div>
          <div class="ef-field">
            <label class="ef-label" for="new_password">New password</label>
            <div class="ef-password">
              <input class="ef-input" type="password" id="new_password"
                     name="new_password" autocomplete="new-password"
                     minlength="8" required>
              <?= password_toggle_html('new_password') ?>
            </div>
            <p class="ef-note">At least 8 characters.</p>
          </div>
          <div class="ef-field">
            <label class="ef-label" for="confirm_password">Confirm new password</label>
            <div class="ef-password">
              <input class="ef-input" type="password" id="confirm_password"
                     name="confirm_password" autocomplete="new-password"
                     minlength="8" required>
              <?= password_toggle_html('confirm_password') ?>
            </div>
          </div>
          <button class="ef-btn ef-btn-primary" type="submit">Change password</button>
        </form>

        <section class="ef-card ef-card-lg">
          <div class="ef-card-title" style="margin-bottom:6px;">Users</div>
          <div class="ef-card-sub" style="margin-bottom:16px;">
            Everyone taking part, and what is stored under each account
          </div>

          <?php if ($usersOk): ?>
            <div class="ef-alert ef-alert-ok"><?= e((string) $usersOk) ?></div>
          <?php endif; ?>
          <?php if ($usersErr): ?>
            <div class="ef-alert ef-alert-error"><?= e((string) $usersErr) ?></div>
          <?php endif; ?>

          <?php if ($learners === []): ?>
            <div class="ef-empty-state">
              <div class="ef-empty-ico"></div>
              <h5>No learners yet</h5>
              <p>
                Accounts appear here as participants register. Team accounts are
                never listed, so this page cannot be used to act on another admin.
              </p>
            </div>
          <?php else: ?>
            <div class="ef-admin-users">
              <?php foreach ($learners as $u): ?>
                <details class="ef-admin-user">
                  <summary>
                    <span class="ef-admin-user-name"><?= e((string) $u['full_name']) ?></span>
                    <?php /* Written out rather than "0M 0T 0A". This screen
                             gets shown to a panel, and an abbreviation nobody
                             can expand is worse than a line that wraps. */ ?>
                    <span class="ef-admin-user-counts">
                      <?= (int) $u['materials'] ?> materials
                      &middot; <?= (int) $u['topics'] ?> topics
                      &middot; <?= (int) $u['attempts'] ?> attempts
                    </span>
                  </summary>

                  <div class="ef-admin-user-body">
                    <p class="ef-second" style="font-size:12.5px;margin:0 0 14px;line-height:1.7;">
                      <?= e((string) $u['email']) ?><br>
                      Joined <?= e(date('j M Y', strtotime((string) $u['created_at']))) ?>
                      &middot; <?= (int) $u['materials'] ?> material<?= (int) $u['materials'] === 1 ? '' : 's' ?>
                      &middot; <?= (int) $u['topics'] ?> topic<?= (int) $u['topics'] === 1 ? '' : 's' ?>
                      &middot; <?= (int) $u['attempts'] ?> attempt<?= (int) $u['attempts'] === 1 ? '' : 's' ?>
                      &middot; <?= (int) $u['requests'] ?> support request<?= (int) $u['requests'] === 1 ? '' : 's' ?>
                      <?php if (($u['email_verified_at'] ?? null) === null): ?>
                        <br>Email not verified.
                      <?php endif; ?>
                    </p>

                    <form action="actions/admin_user_export.php" method="post"
                          style="margin:0 0 18px;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                      <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit">
                        Export their data
                      </button>
                    </form>

                    <?php /* Typing the address is the whole safety here. The
                             person deleting is not the person being deleted,
                             and none of this can be undone. */ ?>
                    <form action="actions/admin_user_delete.php" method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                      <div class="ef-field">
                        <label class="ef-label ef-label-plain" for="confirm_<?= (int) $u['user_id'] ?>">
                          To delete, type <strong><?= e((string) $u['email']) ?></strong>
                        </label>
                        <input class="ef-input" type="text"
                               id="confirm_<?= (int) $u['user_id'] ?>"
                               name="confirm_email" autocomplete="off" spellcheck="false"
                               placeholder="<?= e((string) $u['email']) ?>" required>
                      </div>
                      <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit"
                              style="border-color:var(--ef-weak);color:var(--ef-weak);">
                        Delete permanently
                      </button>
                      <p class="ef-note" style="margin-top:10px;">
                        Removes their materials, topics, attempts, answers,
                        conversations, notifications and support requests, and
                        their uploaded files from disk.
                      </p>
                    </form>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <p class="ef-note" style="margin-top:16px;">
            This is the withdrawal mechanism Chapter III promises, actioned on a
            participant's behalf. They can do both themselves from their own
            Settings, without asking anybody.
          </p>
        </section>

        <form class="ef-card ef-card-lg" action="actions/delete_account.php" method="post">
          <?= csrf_field() ?>
          <div class="ef-card-title" style="margin-bottom:6px;color:var(--ef-weak);">
            Delete my account
          </div>
          <div class="ef-card-sub" style="margin-bottom:16px;">
            Removes this team account. Learners and their data are untouched.
          </div>

          <?php if ($deleteErr): ?>
            <div class="ef-alert ef-alert-error"><?= e((string) $deleteErr) ?></div>
          <?php endif; ?>

          <div class="ef-field">
            <label class="ef-label ef-label-plain" for="confirm_email">
              Type <strong><?= e((string) $user['email']) ?></strong> to confirm
            </label>
            <input class="ef-input" id="confirm_email" name="confirm_email" type="text"
                   placeholder="<?= e((string) $user['email']) ?>"
                   autocomplete="off" spellcheck="false" required>
          </div>

          <button class="ef-btn ef-btn-ghost" type="submit"
                  style="border-color:var(--ef-weak);color:var(--ef-weak);">
            Delete my account permanently
          </button>
          <p class="ef-note" style="margin-top:10px;">
            If this is the last team account, nobody can reach the help desk
            until another is granted with tools/make_admin.php.
          </p>
        </form>

      </div>


    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
