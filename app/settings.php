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

        <div class="ef-row">
          <div class="ef-col ef-stack">

            <form class="ef-card ef-card-lg" action="actions/save_profile.php" method="post">
              <?= csrf_field() ?>
              <div class="ef-card-title" style="margin-bottom:18px;">Personal details</div>

              <div style="display:flex;align-items:center;gap:16px;margin-bottom:22px;">
                <span class="ef-avatar" style="width:64px;height:64px;"></span>
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

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Your data</div>
              <div class="ef-card-sub" style="margin-bottom:16px;">
                <?= (int) $stage['resources'] ?> material<?= $stage['resources'] === 1 ? '' : 's' ?>,
                <?= (int) $stage['topics'] ?> topic<?= $stage['topics'] === 1 ? '' : 's' ?>,
                <?= (int) $stage['scored_items'] ?> scored answer<?= $stage['scored_items'] === 1 ? '' : 's' ?>
                stored against this account.
              </div>
              <a class="ef-btn ef-btn-ghost" href="companion.php">Manage materials</a>
            </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
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
