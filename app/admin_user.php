<?php
/**
 * EduFlex — Account Details
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
$account = admin_account((int) ($_GET['id'] ?? 0));
if ($account === null) {
    flash_set('users_error', 'That account no longer exists.');
    redirect('admin_users.php');
}
auth_require_login();

$user   = auth_user();
$active = 'admin_users';
$title  = 'Account Details';

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
$aid       = (int) $account['user_id'];
$isTeam    = admin_is_admin($account);
$isActive  = (string) $account['account_status'] === 'active';
$verified  = $account['email_verified_at'] ?? null;
$requests  = admin_account_requests($aid);
$isSelf    = $aid === (int) $user['user_id'];

$ok          = flash_get('users_ok');
$err         = flash_get('users_error');
$fieldErrors = flash_get('users_field_errors') ?: [];
$draft       = flash_get('users_draft') ?: [];

$val = static fn(string $key, $fallback): string =>
    (string) (array_key_exists($key, $draft) ? $draft[$key] : $fallback);
$draftYear = (int) $val('year_level', (int) $account['year_level']);

$date = static fn($value): string =>
    $value === null || $value === '' ? '' : date('j M Y, g:i A', strtotime((string) $value) ?: time());
?>
      <div class="ef-content">

        <a class="ef-back-link" href="admin_users.php">&larr; All accounts</a>

        <div class="ef-page-head ef-account-head">
          <div class="ef-account-head-main">
            <span class="ef-avatar ef-avatar-initials ef-avatar-xl" aria-hidden="true"><?= e(avatar_initials((string) $account['full_name'])) ?></span>
            <div style="min-width:0;">
              <h2 class="ef-thread-title"><?= e((string) $account['full_name']) ?></h2>
              <p class="ef-thread-title"><?= e((string) $account['email']) ?></p>
              <div class="ef-account-pills" style="margin-top:8px;">
                <span class="ef-band ef-band-<?= e(admin_account_status_band((string) $account['account_status'])) ?>">
                  <?= e(admin_account_status_label((string) $account['account_status'])) ?>
                </span>
                <?php if ($isTeam): ?><span class="ef-band ef-band-none">Team</span><?php endif; ?>
                <?php if ($verified === null): ?><span class="ef-band ef-band-developing">Email not verified</span><?php endif; ?>
              </div>
            </div>
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
              <div class="ef-card-title" style="margin-bottom:14px;">Account details</div>
              <dl class="ef-thread-facts ef-account-facts">
                <dt>User ID</dt><dd><?= $aid ?></dd>
                <dt>Full name</dt><dd><?= e((string) $account['full_name']) ?></dd>
                <dt>Email</dt><dd><?= e((string) $account['email']) ?></dd>
                <dt>Email verified</dt>
                <dd><?= $verified === null ? 'Not verified' : e($date($verified)) ?></dd>
                <?php if (!$isTeam): ?>
                  <dt>Programme</dt><dd><?= e((string) $account['program']) ?></dd>
                  <dt>Year level</dt><dd>Year <?= (int) $account['year_level'] ?></dd>
                <?php endif; ?>
                <dt>Role</dt><dd><?= $isTeam ? 'Project team' : 'Learner' ?></dd>
                <dt>Status</dt><dd><?= e(admin_account_status_label((string) $account['account_status'])) ?></dd>
                <dt>Profile picture</dt><dd><?= empty($account['avatar_path']) ? 'None, initials shown' : 'Uploaded' ?></dd>
                <dt>Registered</dt><dd><?= e($date($account['created_at'])) ?></dd>
              </dl>
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:14px;">Activity</div>
              <div class="ef-account-stats">
                <?php foreach (['materials' => 'Materials', 'topics' => 'Topics', 'attempts' => 'Practice attempts', 'requests' => 'Support requests'] as $key => $label): ?>
                  <div class="ef-account-stat">
                    <div class="ef-admin-count-n"><?= (int) $account[$key] ?></div>
                    <div class="ef-admin-count-l"><?= e($label) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="ef-note" style="margin-top:12px;">
                Counts only. What a learner uploaded, asked and answered stays private to them.
              </p>
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div class="ef-card-title">Support requests</div>
                <span class="ef-muted" style="font-size:11.5px;"><?= count($requests) ?></span>
              </div>
              <?php if ($requests === []): ?>
                <p class="ef-second" style="margin:0;font-size:13px;">This account has not sent any.</p>
              <?php else: ?>
                <?php foreach ($requests as $r): ?>
                  <a class="ef-account-request" href="admin_thread.php?id=<?= (int) $r['request_id'] ?>">
                    <span class="ef-account-request-main">
                      <span class="ef-account-name">#<?= (int) $r['request_id'] ?> &middot; <?= e((string) $r['subject']) ?></span>
                      <span class="ef-account-email"><?= e(support_type_label((string) $r['request_type'])) ?>
                        &middot; <?= e(date('j M Y', strtotime((string) $r['created_at']) ?: time())) ?></span>
                    </span>
                    <span class="ef-band ef-band-<?= e(support_status_band((string) $r['status'])) ?>">
                      <?= e(support_status_label((string) $r['status'])) ?>
                    </span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>

          </div>

          <div class="ef-col-fixed-360 ef-stack">

            <?php if ($isTeam): ?>
              <section class="ef-card ef-card-lg">
                <div class="ef-card-title" style="margin-bottom:6px;">Team account</div>
                <p class="ef-card-sub" style="margin:0;">
                  <?= $isSelf ? 'This is the account you are signed in with.' : 'A project team account.' ?>
                  Team accounts cannot be edited, deactivated or deleted from this screen,
                  so the help desk can never be locked out by accident. Change your own
                  password and picture in Settings; grant or revoke team access with
                  tools/make_admin.php.
                </p>
              </section>
            <?php else: ?>

              <form class="ef-card ef-card-lg" action="actions/admin_user_update.php" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= $aid ?>">
                <div class="ef-card-title" style="margin-bottom:6px;">Edit details</div>
                <div class="ef-card-sub" style="margin-bottom:16px;">
                  The learner is notified of any change.
                </div>

                <div class="ef-field">
                  <label class="ef-label" for="full_name">Full name</label>
                  <input class="ef-input<?= isset($fieldErrors['full_name']) ? ' is-invalid' : '' ?>"
                         id="full_name" name="full_name" type="text" maxlength="150" required
                         value="<?= e($val('full_name', $account['full_name'])) ?>">
                  <?php if (isset($fieldErrors['full_name'])): ?>
                    <div class="ef-error"><?= e((string) $fieldErrors['full_name']) ?></div>
                  <?php endif; ?>
                </div>

                <div class="ef-field">
                  <label class="ef-label" for="email">Email</label>
                  <input class="ef-input<?= isset($fieldErrors['email']) ? ' is-invalid' : '' ?>"
                         id="email" name="email" type="email" maxlength="255" required
                         autocomplete="off" spellcheck="false"
                         value="<?= e($val('email', $account['email'])) ?>">
                  <?php if (isset($fieldErrors['email'])): ?>
                    <div class="ef-error"><?= e((string) $fieldErrors['email']) ?></div>
                  <?php endif; ?>
                  <p class="ef-note">Changing it means the learner must verify the new address.</p>
                </div>

                <div class="ef-field">
                  <label class="ef-label" for="program">Programme</label>
                  <input class="ef-input<?= isset($fieldErrors['program']) ? ' is-invalid' : '' ?>"
                         id="program" name="program" type="text" maxlength="100" required
                         value="<?= e($val('program', $account['program'])) ?>">
                  <?php if (isset($fieldErrors['program'])): ?>
                    <div class="ef-error"><?= e((string) $fieldErrors['program']) ?></div>
                  <?php endif; ?>
                </div>

                <div class="ef-field">
                  <label class="ef-label" for="year_level">Year level</label>
                  <select class="ef-input<?= isset($fieldErrors['year_level']) ? ' is-invalid' : '' ?>"
                          id="year_level" name="year_level">
                    <?php for ($y = 1; $y <= 5; $y++): ?>
                      <option value="<?= $y ?>"<?= $draftYear === $y ? ' selected' : '' ?>>Year <?= $y ?></option>
                    <?php endfor; ?>
                  </select>
                  <?php if (isset($fieldErrors['year_level'])): ?>
                    <div class="ef-error"><?= e((string) $fieldErrors['year_level']) ?></div>
                  <?php endif; ?>
                </div>

                <button class="ef-btn ef-btn-primary" type="submit">Save changes</button>
              </form>

              <section class="ef-card ef-card-lg">
                <div class="ef-card-title" style="margin-bottom:6px;">Account status</div>
                <div class="ef-card-sub" style="margin-bottom:14px;">
                  <?= $isActive
                        ? 'Deactivating stops them signing in and ends any open session. Nothing is deleted, and you can activate the account again at any time.'
                        : 'This account cannot sign in. Activating it restores access with everything it had before.' ?>
                </div>
                <form method="post" action="actions/admin_user_status.php" style="margin:0;"
                      data-confirm="<?= e($isActive
                          ? 'Deactivate ' . $account['email'] . '? They will be signed out and unable to sign in until you activate the account again.'
                          : 'Activate ' . $account['email'] . '? They will be able to sign in again.') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= $aid ?>">
                  <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                  <button class="ef-btn <?= $isActive ? 'ef-btn-ghost ef-btn-warn' : 'ef-btn-primary' ?>" type="submit">
                    <?= $isActive ? 'Deactivate account' : 'Activate account' ?>
                  </button>
                </form>
              </section>

              <section class="ef-card ef-card-lg">
                <div class="ef-card-title" style="margin-bottom:6px;">Export their data</div>
                <div class="ef-card-sub" style="margin-bottom:14px;">
                  The same JSON file the learner can download from their own Settings.
                </div>
                <form data-no-loading action="actions/admin_user_export.php" method="post" style="margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= $aid ?>">
                  <input type="hidden" name="from" value="account">
                  <button class="ef-btn ef-btn-ghost" type="submit">Download export</button>
                </form>
              </section>

              <form class="ef-card ef-card-lg ef-account-danger" action="actions/admin_user_delete.php" method="post"
                    data-confirm="<?= e('Permanently delete ' . $account['email'] . ' and everything stored under it? This cannot be undone.') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= $aid ?>">
                <input type="hidden" name="from" value="account">
                <div class="ef-card-title" style="margin-bottom:6px;color:var(--ef-weak);">Delete account</div>
                <div class="ef-card-sub" style="margin-bottom:14px;">
                  Removes their materials, topics, attempts, answers, conversations,
                  notifications, support requests and uploaded files. This cannot be undone.
                  Consider deactivating instead.
                </div>
                <div class="ef-field">
                  <label class="ef-label ef-label-plain" for="confirm_email">
                    To confirm, type <strong><?= e((string) $account['email']) ?></strong>
                  </label>
                  <input class="ef-input" type="text" id="confirm_email" name="confirm_email"
                         autocomplete="off" spellcheck="false"
                         placeholder="<?= e((string) $account['email']) ?>" required>
                </div>
                <button class="ef-btn ef-btn-ghost ef-btn-danger" type="submit">Delete permanently</button>
              </form>

            <?php endif; ?>
          </div>
        </div>

      </div>


    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
