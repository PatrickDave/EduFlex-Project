<?php
/**
 * EduFlex — Monitor Scores
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
$active = 'scores';
$title  = 'Monitor Scores';
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
$uid     = (int) $user['user_id'];
$stage   = stats_stage($uid);
$empty   = stats_empty_state($stage, 'scores');
$topics  = $empty === null ? stats_topics($uid, 30) : [];
$bands   = stats_band_counts($uid);
$overall = stats_overall_mastery($uid);
$week    = stats_week_activity($uid);
$weekTotal = array_sum(array_column($week, 'value'));
$weekMax   = max(1, max(array_column($week, 'value')));
$overallBand = $overall === null ? 'none' : mastery_band($overall, MASTERY_MIN_ITEMS);
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Monitor Scores</h2>
            <p>Every scored activity, rolled up per topic</p>
          </div>
          <a href="practice.php" class="ef-btn ef-btn-primary">New Practice Session</a>
        </div>

        <?php if ($empty !== null): ?>
          <?= $empty ?>
        <?php else: ?>

        <div class="ef-row">
          <div class="ef-col-fixed-360 ef-stack">
            <section class="ef-card ef-card-lg" style="text-align:center;">
              <div class="ef-card-title" style="margin-bottom:6px;">Overall mastery</div>
              <div class="ef-card-sub" style="margin-bottom:18px;">
                Mean across <?= (int) $stage['scored_topics'] ?> qualifying topic<?= $stage['scored_topics'] === 1 ? '' : 's' ?>
              </div>

              <?php if ($overall === null): ?>
                <p class="ef-note" style="padding:28px 0;">
                  No topic has reached <?= MASTERY_MIN_ITEMS ?> scored answers yet.
                </p>
              <?php else: ?>
                <?php $dash = 452; $offset = (int) round($dash * (1 - $overall / 100)); ?>
                <svg width="180" height="180" viewBox="0 0 180 180" style="margin:0 auto;">
                  <circle cx="90" cy="90" r="72" fill="none" stroke="var(--ef-empty)" stroke-width="16"/>
                  <circle cx="90" cy="90" r="72" fill="none"
                          stroke="var(--ef-<?= e($overallBand) ?>)" stroke-width="16"
                          stroke-linecap="round" stroke-dasharray="<?= $dash ?>"
                          stroke-dashoffset="<?= $offset ?>" transform="rotate(-90 90 90)"/>
                  <text x="90" y="86" text-anchor="middle" font-size="34" font-weight="700"
                        fill="var(--ef-text)" font-family="Inter, sans-serif"><?= round($overall) ?>%</text>
                  <text x="90" y="108" text-anchor="middle" font-size="12"
                        fill="var(--ef-text-muted)" font-family="Inter, sans-serif">
                    <?= e(mastery_band_label($overallBand)) ?>
                  </text>
                </svg>
              <?php endif; ?>

              <?php if ($bands['pending'] > 0): ?>
                <p class="ef-note" style="margin-top:14px;">
                  <?= (int) $bands['pending'] ?> further topic<?= $bands['pending'] === 1 ? '' : 's' ?>
                  <?= $bands['pending'] === 1 ? 'has' : 'have' ?> fewer than
                  <?= MASTERY_MIN_ITEMS ?> scored items and <?= $bands['pending'] === 1 ? 'is' : 'are' ?> excluded.
                </p>
              <?php endif; ?>
            </section>

            <div class="ef-stats">
              <div class="ef-stat"><div class="ef-stat-label">Mastered</div><div class="ef-stat-value"><?= (int) $bands['mastered'] ?></div></div>
              <div class="ef-stat"><div class="ef-stat-label">Weak</div><div class="ef-stat-value"><?= (int) $bands['weak'] ?></div></div>
            </div>
          </div>

          <div class="ef-col ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div>
                  <div class="ef-card-title">Mastery by topic</div>
                  <div class="ef-card-sub">Weighted accuracy, recency-adjusted</div>
                </div>
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
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div>
                  <div class="ef-card-title">Activity this week</div>
                  <div class="ef-card-sub">Scored items completed per day</div>
                </div>
                <span class="ef-primary" style="font-size:12.5px;font-weight:600;"><?= (int) $weekTotal ?> total</span>
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
        </div>
        <?php endif; ?>
      </div>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
