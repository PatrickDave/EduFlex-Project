<?php
/**
 * EduFlex — Review History
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
$active = 'history';
$title  = 'Review History';
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
$uid      = (int) $user['user_id'];
$stage    = stats_stage($uid);
$empty    = stats_empty_state($stage, 'history');
$attempts = $empty === null ? stats_recent_attempts($uid, 20) : [];
$topics   = $empty === null ? stats_topics($uid, 8) : [];
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Review History</h2>
            <p>Every activity you have completed, with the mastery it produced</p>
          </div>
        </div>

        <?php if ($empty !== null): ?>
          <?= $empty ?>
        <?php else: ?>

        <div class="ef-row">
          <div class="ef-col ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div class="ef-card-title">Recent activities</div>
                <span class="ef-muted" style="font-size:11.5px;">
                  <?= count($attempts) ?> of <?= (int) $stage['attempts'] ?>
                </span>
              </div>

              <div style="overflow-x:auto;">
              <table class="ef-table">
                <thead><tr><th>Activity</th><th>Level</th><th>Score</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($attempts as $a): ?>
                  <?php
                    $done  = $a['completed_at'] !== null;
                    $score = $done && $a['score'] !== null ? (float) $a['score'] : null;
                    $band  = $score === null ? 'none' : mastery_band($score, MASTERY_MIN_ITEMS);
                  ?>
                  <tr>
                    <td>
                      <strong><?= e((string) $a['title']) ?></strong>
                      <div class="ef-list-meta">
                        <?= e(date('j M Y', strtotime((string) $a['started_at']))) ?>
                        <?php if ($a['topic_name']): ?>
                          &middot; <?= e((string) $a['topic_name']) ?>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td><span class="ef-bloom"><?= e((string) $a['bloom_level']) ?></span></td>
                    <td>
                      <?php if ($score !== null): ?>
                        <strong><?= round($score) ?>%</strong>
                      <?php else: ?>
                        <span class="ef-muted">&mdash;</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="ef-band ef-band-<?= e($band) ?>">
                        <?= $done ? e(mastery_band_label($band)) : 'Incomplete' ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Weakest topics</div>
              <div class="ef-card-sub" style="margin-bottom:16px;">What to work on next</div>
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
