<?php
/**
 * EduFlex — Profile Settings
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stats.php';
require_once __DIR__ . '/../includes/avatar.php';
require_once __DIR__ . '/../includes/subscription.php';
auth_require_login();

$user   = auth_user();
$active = 'settings';
$title  = 'Profile Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — EduFlex</title>
<link href="../assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="../assets/css/eduflex.css" rel="stylesheet">
</head>
<body>

<div class="ef-shell">

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="ef-main">

<?php include __DIR__ . '/../partials/topbar.php'; ?>

<?php
$uid   = (int) $user['user_id'];
$stage = stats_stage($uid);
$ok    = flash_get('profile_ok');
$err   = flash_get('profile_error');
$since = date('F Y', strtotime((string) $user['created_at']));

/* Password, export and delete each report through their own flash key, so a
   failed deletion does not look like a failed profile save. */
$passwordOk  = flash_get('password_ok');
$passwordErr = flash_get('password_error');
$exportErr   = flash_get('export_error');
$deleteErr   = flash_get('delete_error');
$avatarOk    = flash_get('avatar_ok');
$avatarErr   = flash_get('avatar_error');

$hasAvatar = avatar_has($uid);
$plan      = subscription_for($uid);
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Profile Settings</h2>
            <p>Manage your identity and learning preferences</p>
          </div>
        </div>

        <?php if ($ok): ?><div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div><?php endif; ?>
        <?php if ($passwordOk): ?><div class="ef-alert ef-alert-ok"><?= e((string) $passwordOk) ?></div><?php endif; ?>
        <?php if ($passwordErr): ?><div class="ef-alert ef-alert-error"><?= e((string) $passwordErr) ?></div><?php endif; ?>
        <?php if ($exportErr): ?><div class="ef-alert ef-alert-error"><?= e((string) $exportErr) ?></div><?php endif; ?>
        <?php if ($deleteErr): ?><div class="ef-alert ef-alert-error"><?= e((string) $deleteErr) ?></div><?php endif; ?>
        <?php if ($avatarOk): ?><div class="ef-alert ef-alert-ok"><?= e((string) $avatarOk) ?></div><?php endif; ?>
        <?php if ($avatarErr): ?><div class="ef-alert ef-alert-error"><?= e((string) $avatarErr) ?></div><?php endif; ?>

        <div class="ef-row">
          <div class="ef-col ef-stack">

            <!-- Profile picture. Its own form, because a file upload needs
                 enctype="multipart/form-data" and the details form below does
                 not; nesting forms is not allowed, and one combined form would
                 re-post the whole profile every time somebody changed a photo. -->
            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Profile picture</div>
              <div class="ef-card-sub" style="margin-bottom:18px;">
                Shown in the top bar and here. Only you ever see it: EduFlex never
                displays one learner's picture to another.
              </div>

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
                  <?php else: ?>
                    <p class="ef-muted" style="font-size:11.5px;margin-top:10px;line-height:1.6;">
                      Until you upload one, EduFlex shows your initials.
                    </p>
                  <?php endif; ?>
                </div>
              </div>
            </section>

            <form class="ef-card ef-card-lg" action="actions/save_profile.php" method="post">
              <?= csrf_field() ?>
              <div class="ef-card-title" style="margin-bottom:18px;">Personal details</div>

              <div style="display:flex;align-items:center;gap:16px;margin-bottom:22px;">
                <?= avatar_html($uid, (string) $user['full_name'], '', 'width:64px;height:64px;font-size:20px;') ?>
                <div>
                  <div style="font-size:16px;font-weight:600;"><?= e((string) $user['full_name']) ?></div>
                  <div class="ef-list-meta">Member since <?= e($since) ?></div>
                </div>
              </div>

              <div class="row g-3">
                <div class="col-12 col-md-6">
                  <div class="ef-field" style="margin-bottom:0;">
                    <label class="ef-label" for="fullname">Full Name</label>
                    <input class="ef-input" id="fullname" name="full_name" type="text"
                           value="<?= e((string) $user['full_name']) ?>" maxlength="150" required>
                  </div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="ef-field" style="margin-bottom:0;">
                    <label class="ef-label" for="mail">Email Address</label>
                    <input class="ef-input" id="mail" name="email" type="email"
                           value="<?= e((string) $user['email']) ?>" maxlength="255" required>
                  </div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="ef-field" style="margin-bottom:0;">
                    <label class="ef-label" for="program">Program</label>
                    <input class="ef-input" id="program" name="program" type="text"
                           value="<?= e((string) $user['program']) ?>" maxlength="100">
                  </div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="ef-field" style="margin-bottom:0;">
                    <label class="ef-label" for="year">Year level</label>
                    <select class="ef-input" id="year" name="year_level">
                      <?php foreach ([1,2,3,4,5] as $y): ?>
                        <option value="<?= $y ?>"<?= (int) $user['year_level'] === $y ? ' selected' : '' ?>>
                          Year <?= $y ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>

              <button class="ef-btn ef-btn-primary" type="submit" style="margin-top:20px;">Save Changes</button>
            </form>

            <!-- Change password -->
            <form class="ef-card ef-card-lg" action="actions/change_password.php" method="post">
              <?= csrf_field() ?>
              <div class="ef-card-title" style="margin-bottom:6px;">Change password</div>
              <div class="ef-card-sub" style="margin-bottom:18px;">
                Your current password is checked first, so a password cannot be changed
                from a session somebody else has got hold of. At least
                <?= (int) AUTH_PASSWORD_MIN ?> characters.
              </div>

              <div class="row g-3">
                <div class="col-12">
                  <div class="ef-field" style="margin-bottom:0;">
                    <label class="ef-label" for="current_password">Current password</label>
                    <input class="ef-input" id="current_password" name="current_password"
                           type="password" autocomplete="current-password" required>
                  </div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="ef-field" style="margin-bottom:0;">
                    <label class="ef-label" for="new_password">New password</label>
                    <input class="ef-input" id="new_password" name="new_password"
                           type="password" autocomplete="new-password"
                           minlength="<?= (int) AUTH_PASSWORD_MIN ?>" required>
                  </div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="ef-field" style="margin-bottom:0;">
                    <label class="ef-label" for="confirm_password">Confirm new password</label>
                    <input class="ef-input" id="confirm_password" name="confirm_password"
                           type="password" autocomplete="new-password"
                           minlength="<?= (int) AUTH_PASSWORD_MIN ?>" required>
                  </div>
                </div>
              </div>

              <button class="ef-btn ef-btn-primary" type="submit" style="margin-top:20px;">
                Change password
              </button>
            </form>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Your data</div>
              <div class="ef-card-sub" style="margin-bottom:16px;">
                <?= (int) $stage['resources'] ?> material<?= $stage['resources'] === 1 ? '' : 's' ?>,
                <?= (int) $stage['topics'] ?> topic<?= $stage['topics'] === 1 ? '' : 's' ?>,
                <?= (int) $stage['scored_items'] ?> scored answer<?= $stage['scored_items'] === 1 ? '' : 's' ?>
                stored against this account.
              </div>

              <p class="ef-second" style="font-size:12.5px;line-height:1.6;margin-bottom:16px;">
                The export is a single JSON file holding your account details, your
                materials list, every topic with its mastery score, and every answer you
                have given with whether it was marked correct. The uploaded files
                themselves are not included, since you already have those.
              </p>

              <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <form action="actions/export_data.php" method="post" style="margin:0;">
                  <?= csrf_field() ?>
                  <button class="ef-btn ef-btn-primary" type="submit">Export my data</button>
                </form>
                <a class="ef-btn ef-btn-ghost" href="companion.php">Manage materials</a>
              </div>
            </section>

            <!-- Delete account. Chapter III promises a participant can withdraw
                 and remove their data; this is that promise. -->
            <form class="ef-card ef-card-lg" action="actions/delete_account.php" method="post">
              <?= csrf_field() ?>
              <div class="ef-card-title" style="margin-bottom:6px;color:var(--ef-weak);">
                Delete my account
              </div>
              <div class="ef-card-sub" style="margin-bottom:16px;">
                This cannot be undone, and the project team cannot restore it for you.
              </div>

              <p class="ef-second" style="font-size:12.5px;line-height:1.6;margin-bottom:16px;">
                Deleting removes your account, your uploaded files, the text extracted
                from them, every topic and mastery score, every practice attempt and
                answer, your conversations with the companion, your notifications and
                your support requests. If you are taking part in the study and want to
                withdraw, export your data first, then delete from here. You do not have
                to ask anybody.
              </p>

              <div class="ef-field">
                <label class="ef-label" for="confirm_email">
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
            </form>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <?php
            /* Account and Access Management 5: Manage Subscription. The
               subscription row has existed since registration; this is the
               first thing that reads it. There is nothing to buy, so the card
               states the plan, what it includes, and that EduFlex will never
               ask for money. The premium tier is shown as designed and
               unavailable because the Chapter III data dictionary defines
               plan_type as "free or premium"; see includes/subscription.php. */
            ?>
            <section class="ef-card ef-card-lg">
              <div class="ef-card-head" style="margin-bottom:6px;">
                <div class="ef-card-title">Your plan</div>
                <span class="ef-band ef-band-<?= e(subscription_status_band($plan['status'])) ?>">
                  <?= e($plan['label']) ?>
                </span>
              </div>
              <div class="ef-card-sub" style="margin-bottom:14px;">
                <?= e(subscription_since($plan)) ?>
              </div>

              <p class="ef-second" style="font-size:12.5px;line-height:1.6;margin-bottom:12px;">
                <?= e($plan['summary']) ?>
              </p>

              <ul class="ef-plan-includes">
                <?php foreach ($plan['includes'] as $feature): ?>
                  <li><?= e($feature) ?></li>
                <?php endforeach; ?>
              </ul>

              <?php foreach (SUBSCRIPTION_PLANS as $key => $other): ?>
                <?php if ($key === $plan['plan'] || $other['available']) { continue; } ?>
                <div class="ef-plan-future">
                  <span class="ef-eyebrow"><?= e($other['label']) ?> &middot; not available</span>
                  <p><?= e($other['summary']) ?></p>
                  <ul class="ef-plan-includes ef-plan-includes-muted">
                    <?php foreach ($other['includes'] as $feature): ?>
                      <li><?= e($feature) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endforeach; ?>

              <p class="ef-legal-note" style="font-size:12px;margin-top:14px;">
                <?= e(subscription_cost_statement()) ?>
              </p>
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:14px;">Account</div>
              <div class="ef-list-meta" style="margin-bottom:4px;">Status</div>
              <span class="ef-band ef-band-<?= $user['account_status'] === 'active' ? 'mastered' : 'weak' ?>">
                <?= e(ucfirst((string) $user['account_status'])) ?>
              </span>
              <div style="margin-top:18px;">
                <a class="ef-btn ef-btn-ghost ef-btn-block ef-btn-field" href="../auth/logout.php">Sign out</a>
              </div>
            </section>

            <section class="ef-card ef-card-sky">
              <span class="ef-eyebrow">Responsible AI</span>
              <p class="ef-second" style="font-size:12.5px;margin-top:8px;line-height:1.6;">
                EduFlex uses a large language model to read your materials and generate
                activities. Output can contain errors. It never contributes to official grades.
              </p>
            </section>
          </div>
        </div>
      </div>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
