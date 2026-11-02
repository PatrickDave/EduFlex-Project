<?php
/**
 * EduFlex — Growth Insights
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
$active = 'growth';
$title  = 'Growth Insights';

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
$uid    = (int) $user['user_id'];
$stage  = stats_stage($uid);
$empty  = stats_empty_state($stage, 'growth');
$topics = $empty === null ? stats_topics($uid, 20) : [];
$bands  = stats_band_counts($uid);
$week   = stats_week_activity($uid);
$weekMax = max(1, max(array_column($week, 'value')));
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Growth Insights</h2>
            <p>How your mastery has moved since you started using EduFlex</p>
          </div>
        </div>

        <?php if ($empty !== null): ?>
          <?= $empty ?>
        <?php else: ?>

        <div class="ef-stats" style="margin-bottom:20px;">
          <div class="ef-stat">
            <div class="ef-stat-label">Practice time</div>
            <div class="ef-stat-value"><?= e(stats_practice_time($uid)) ?></div>
          </div>
          <div class="ef-stat">
            <div class="ef-stat-label">Scored answers</div>
            <div class="ef-stat-value"><?= (int) $stage['scored_items'] ?></div>
          </div>
          <div class="ef-stat">
            <div class="ef-stat-label">Topics tracked</div>
            <div class="ef-stat-value"><?= (int) $stage['topics'] ?></div>
          </div>
          <div class="ef-stat">
            <div class="ef-stat-label">Topics mastered</div>
            <div class="ef-stat-value"><?= (int) $bands['mastered'] ?></div>
          </div>
        </div>

        <div class="ef-row">
          <div class="ef-col ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div>
                  <div class="ef-card-title">Answers per day</div>
                  <div class="ef-card-sub">Last seven days</div>
                </div>
              </div>
              <div class="ef-bars" data-max="<?= (int) $weekMax ?>">
                <?php foreach ($week as $d): ?>
                  <div class="ef-bar-col" data-value="<?= (int) $d['value'] ?>">
                    <span class="ef-bar"></span>
                    <span class="ef-bar-label"><?= e($d['label']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="ef-note" style="margin-top:14px;">
                Your own history only. EduFlex does not compare you against other learners.
              </p>
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:16px;">Mastery per topic</div>
              <div style="overflow-x:auto;">
              <table class="ef-table">
                <thead><tr><th>Topic</th><th>Source</th><th>Mastery</th><th>Items</th></tr></thead>
                <tbody>
                <?php foreach ($topics as $t): ?>
                  <?php
                    $band = mastery_band((float) $t['mastery_score'], (int) $t['scored_items']);
                    $show = $band !== 'none';
                  ?>
                  <tr>
                    <td><?= e((string) $t['topic_name']) ?></td>
                    <td class="ef-muted" style="font-size:11.5px;"><?= e((string) $t['resource_title']) ?></td>
                    <td>
                      <span class="ef-band ef-band-<?= e($band) ?>">
                        <?= $show ? round((float) $t['mastery_score']) . '%' : 'No data' ?>
                      </span>
                    </td>
                    <td class="ef-muted"><?= (int) $t['scored_items'] ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:14px;">Band distribution</div>
              <div class="ef-mastery">
                <div class="ef-mastery-row-top">
                  <span class="ef-mastery-name">Mastered</span>
                  <span class="ef-mastery-val" style="color:var(--ef-mastered)"><?= (int) $bands['mastered'] ?></span>
                </div>
                <div class="ef-mastery-row-top">
                  <span class="ef-mastery-name">Developing</span>
                  <span class="ef-mastery-val" style="color:var(--ef-developing)"><?= (int) $bands['developing'] ?></span>
                </div>
                <div class="ef-mastery-row-top">
                  <span class="ef-mastery-name">Weak</span>
                  <span class="ef-mastery-val" style="color:var(--ef-weak)"><?= (int) $bands['weak'] ?></span>
                </div>
                <div class="ef-mastery-row-top">
                  <span class="ef-mastery-name">Not enough data</span>
                  <span class="ef-mastery-val ef-muted"><?= (int) $bands['pending'] ?></span>
                </div>
              </div>
            </section>
          </div>
        </div>
        <?php endif; ?>
      </div>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
