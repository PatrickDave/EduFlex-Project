<?php
/**
 * EduFlex — Dashboard
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
$active = 'dashboard';
$title  = 'Dashboard';
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
$uid    = (int) $user['user_id'];
$stage  = stats_stage($uid);
$empty  = stats_empty_state($stage, 'progress');
$topics = $empty === null ? stats_topics($uid, 6) : [];
$reco   = $empty === null ? stats_recommendation($uid) : null;
$week   = stats_week_activity($uid);
$weekTotal = array_sum(array_column($week, 'value'));
$weekMax   = max(1, max(array_column($week, 'value')));
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Welcome back, <?= e(auth_first_name()) ?>.</h2>
            <p>
              <?php if ($stage['processed'] === 0): ?>
                Let's get your first material in.
              <?php elseif ($empty !== null): ?>
                Your material is ready. Answer a set and your progress starts filling in.
              <?php elseif ($reco): ?>
                Your next practice activity is ready.
              <?php else: ?>
                <?= (int) $stage['scored_items'] ?> answers recorded across
                <?= (int) $stage['topics'] ?> topic<?= $stage['topics'] === 1 ? '' : 's' ?>.
              <?php endif; ?>
            </p>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="history.php" class="ef-btn ef-btn-ghost">Review Activity History</a>
            <a href="scores.php" class="ef-btn ef-btn-primary">Monitor Progress</a>
          </div>
        </div>

        <?php if ($empty !== null): ?>
          <?= $empty ?>
        <?php else: ?>

        <div class="ef-row">
          <div class="ef-col ef-stack">

            <section class="ef-card ef-card-lg">
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                <span class="ef-badge">Adaptive Analysis</span>
                <span class="ef-muted" style="font-size:11.5px;">
                  Based on <?= (int) $stage['scored_items'] ?> scored answers
                </span>
              </div>

              <h3 style="font-size:20px;margin-bottom:12px;">Weak topics vs. mastery</h3>

              <?php if ($reco): ?>
                <p class="ef-second" style="font-size:13.5px;line-height:1.62;">
                  <?= e((string) $reco['reason']) ?>
                </p>
                <div class="ef-card ef-card-sky" style="margin-top:16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                  <div style="flex:1 1 260px;min-width:0;">
                    <span class="ef-eyebrow">Recommended next activity</span>
                    <p style="font-size:15.5px;font-weight:600;margin:6px 0 6px;">
                      <?= e((string) $reco['recommended_activity']) ?>
                    </p>
                    <p class="ef-second" style="font-size:11.5px;">
                      <span class="ef-bloom">Bloom level: <?= e((string) $reco['recommended_level']) ?></span>
                      &nbsp;&middot;&nbsp; <?= e((string) $reco['topic_name']) ?>
                    </p>
                  </div>
                  <form method="post" action="actions/practice_next.php" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="topic_progress_id"
                           value="<?= (int) $reco['topic_progress_id'] ?>">
                    <button class="ef-btn ef-btn-primary" type="submit">Start Now</button>
                  </form>
                </div>
              <?php else: ?>
                <p class="ef-second" style="font-size:13.5px;line-height:1.62;">
                  EduFlex has not produced a recommendation yet. It picks your
                  lowest-mastery topic once at least one topic has
                  <?= MASTERY_MIN_ITEMS ?> scored answers.
                </p>
              <?php endif; ?>
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div>
                  <div class="ef-card-title">Activity this week</div>
                  <div class="ef-card-sub">Scored items completed per day</div>
                </div>
                <span class="ef-primary" style="font-size:12.5px;font-weight:600;">
                  <?= (int) $weekTotal ?> total
                </span>
              </div>
              <div class="ef-bars" data-max="<?= (int) $weekMax ?>">
                <?php foreach ($week as $d): ?>
                  <div class="ef-bar-col" data-value="<?= (int) $d['value'] ?>">
                    <span class="ef-bar"></span>
                    <span class="ef-bar-label"><?= e($d['label']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <section class="ef-card ef-card-lg">
              <div style="margin-bottom:16px;">
                <div class="ef-card-title">Topic Mastery</div>
                <div class="ef-card-sub">Weighted accuracy, min. <?= MASTERY_MIN_ITEMS ?> scored items</div>
              </div>

              <div class="ef-mastery">
                <?php foreach ($topics as $t): ?>
                  <?= mastery_row_html(
                        (string) $t['topic_name'],
                        (int) $t['scored_items'] >= MASTERY_MIN_ITEMS ? (float) $t['mastery_score'] : null,
                        (int) $t['scored_items']
                      ) ?>
                <?php endforeach; ?>
              </div>

              <?php $waiting = $stage['topics'] - $stage['scored_topics']; ?>
              <?php if ($waiting > 0): ?>
                <p class="ef-note">
                  <?= (int) $waiting ?> topic<?= $waiting === 1 ? '' : 's' ?>
                  <?= $waiting === 1 ? 'has' : 'have' ?> fewer than
                  <?= MASTERY_MIN_ITEMS ?> scored items, so no value is shown yet.
                </p>
              <?php endif; ?>
            </section>

            <div class="ef-stats">
              <div class="ef-stat">
                <div class="ef-stat-label">Practice time</div>
                <div class="ef-stat-value"><?= e(stats_practice_time($uid)) ?></div>
              </div>
              <div class="ef-stat">
                <div class="ef-stat-label">Activities</div>
                <div class="ef-stat-value"><?= (int) $stage['completed'] ?></div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
