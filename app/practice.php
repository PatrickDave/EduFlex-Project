<?php
/**
 * EduFlex — Practice
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stats.php';
require_once __DIR__ . '/../includes/questions.php';
require_once __DIR__ . '/../includes/exams.php';
auth_require_login();

$user   = auth_user();
$active = 'practice';
$title  = 'Practice';
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
$uid        = (int) $user['user_id'];
$stage      = stats_stage($uid);
$activities = questions_activity_list($uid, 20);
$genTopics  = questions_generatable_topics($uid, 20);
$reco       = stats_recommendation($uid);
$flashError = flash_get('error');
$flashOk    = flash_get('practice_ok');
$examReady  = exam_is_available($uid);
$pastExams  = exam_list($uid, 5);
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Practice</h2>
            <p>Question sets written from your own materials</p>
          </div>
        </div>

        <?php if ($flashError): ?>
          <div class="ef-alert ef-alert-error"><?= e((string) $flashError) ?></div>
        <?php endif; ?>
        <?php if ($flashOk): ?>
          <div class="ef-alert ef-alert-ok"><?= e((string) $flashOk) ?></div>
        <?php endif; ?>

        <?php
        /* Learning Activity and Assessment 2: Generate Mock Examinations.
           Offered only when exam_is_available() says there are enough topics,
           so the button is never shown to somebody it would refuse. */
        ?>
        <?php if ($examReady): ?>
          <section class="ef-card ef-card-lg ef-exam-cta">
            <div class="ef-exam-cta-main">
              <div class="ef-card-title" style="margin-bottom:6px;">Mock examination</div>
              <p class="ef-second" style="font-size:12.5px;line-height:1.6;margin:0;">
                <?= (int) EXAM_QUESTION_COUNT ?> questions drawn across your weakest topics,
                the way an exam covers a whole course rather than one lesson. EduFlex uses
                questions you have not answered yet and only generates what it cannot fill,
                so this is usually instant and free.
              </p>
              <?php if ($pastExams): ?>
                <p class="ef-list-meta" style="margin-top:8px;">
                  You have taken <?= count($pastExams) ?>
                  <?= count($pastExams) === 1 ? 'examination' : 'examinations' ?> so far.
                </p>
              <?php endif; ?>
            </div>
            <form method="post" action="actions/exam_start.php" style="margin:0;">
              <?= csrf_field() ?>
              <button class="ef-btn ef-btn-primary" type="submit">Start a mock examination</button>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($stage['processed'] === 0): ?>
          <div class="ef-empty-state">
            <div class="ef-empty-ico"></div>
            <h5>Nothing to practise from yet</h5>
            <p>
              EduFlex writes questions from documents you upload. Attach a reviewer
              or lecture note in the companion and it will read the text first.
            </p>
            <a class="ef-btn ef-btn-primary" href="companion.php">Open the companion</a>
          </div>

        <?php elseif (!$genTopics): ?>
          <div class="ef-empty-state">
            <div class="ef-empty-ico"></div>
            <h5>No topics detected yet</h5>
            <p>
              Your material has been read, but EduFlex does not yet know what it
              covers. Choose <strong>Find topics</strong> on it in the companion,
              then come back and generate a set.
            </p>
            <a class="ef-btn ef-btn-primary" href="companion.php">Open the companion</a>
          </div>

        <?php else: ?>
        <div class="ef-row">
          <div class="ef-col ef-stack">

            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div>
                  <div class="ef-card-title">Generate a set</div>
                  <div class="ef-card-sub">
                    Weakest topic first. The Bloom level is chosen from your last result.
                  </div>
                </div>
              </div>

              <?php foreach ($genTopics as $t): ?>
                <?php
                  $band  = mastery_band((float) $t['mastery_score'], (int) $t['scored_items']);
                  $next  = questions_next_bloom_level((int) $t['topic_progress_id'], $uid);
                  $count = (int) $t['activity_count'];
                ?>
                <div class="ef-list-row" data-topic-row="<?= (int) $t['topic_progress_id'] ?>">
                  <span class="ef-list-ico"><?= $count ?></span>
                  <div class="ef-list-main">
                    <div class="ef-list-title"><?= e((string) $t['topic_name']) ?></div>
                    <div class="ef-list-meta">
                      <?= e((string) $t['resource_title']) ?>
                      &middot; <?= $count ?> set<?= $count === 1 ? '' : 's' ?> so far
                    </div>
                  </div>
                  <span class="ef-band ef-band-<?= e($band) ?>">
                    <?= $band === 'none' ? 'No data' : round((float) $t['mastery_score']) . '%' ?>
                  </span>
                  <span class="ef-bloom"><?= e($next) ?></span>
                  <button class="ef-btn ef-btn-primary ef-btn-sm" type="button"
                          data-generate="<?= (int) $t['topic_progress_id'] ?>">Generate</button>
                </div>
              <?php endforeach; ?>

              <p class="ef-note" style="margin-top:12px;">
                Each set is one request to the AI provider and produces
                <?= QUESTIONS_PER_SET ?> questions. Sets are stored and reused, so
                generating the same topic twice is a deliberate choice, not automatic.
              </p>
            </section>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div class="ef-card-title">Your practice sets</div>
                <span class="ef-muted" style="font-size:11.5px;"><?= count($activities) ?></span>
              </div>

              <div data-activity-list>
                <?php if (!$activities): ?>
                  <p class="ef-note" data-empty-activities style="text-align:center;padding:14px 0;">
                    None yet. Generate one above.
                  </p>
                <?php endif; ?>

                <?php foreach ($activities as $a): ?>
                  <div class="ef-list-row">
                    <span class="ef-list-ico"><?= (int) $a['item_count'] ?></span>
                    <div class="ef-list-main">
                      <div class="ef-list-title"><?= e((string) $a['title']) ?></div>
                      <div class="ef-list-meta">
                        <?= (int) $a['item_count'] ?> questions
                        &middot; <?= e((string) $a['difficulty_level']) ?>
                        <?php if ((int) $a['attempts'] > 0): ?>
                          &middot; attempted <?= (int) $a['attempts'] ?>&times;
                        <?php endif; ?>
                      </div>
                    </div>
                    <span class="ef-bloom"><?= e((string) $a['bloom_level']) ?></span>
                    <form method="post" action="actions/attempt_start.php" style="margin:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="activity_id" value="<?= (int) $a['activity_id'] ?>">
                      <button class="ef-btn <?= (int) $a['attempts'] > 0 ? 'ef-btn-ghost' : 'ef-btn-primary' ?> ef-btn-sm"
                              type="submit">
                        <?= (int) $a['attempts'] > 0 ? 'Retry' : 'Start' ?>
                      </button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>

              <?php if ($activities): ?>
                <p class="ef-note" style="margin-top:12px;">
                  Answers are saved as you give them. Leaving a set part way through
                  keeps your place, and finishing one recalculates the mastery of its
                  topic straight away.
                </p>
              <?php endif; ?>
            </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <?php if ($reco): ?>
              <section class="ef-card ef-card-sky">
                <span class="ef-eyebrow">Recommended</span>
                <p style="font-size:15px;font-weight:600;margin:8px 0 6px;">
                  <?= e((string) $reco['topic_name']) ?>
                </p>
                <p class="ef-second" style="font-size:12.5px;line-height:1.6;">
                  <?= e((string) $reco['reason']) ?>
                </p>
                <form method="post" action="actions/practice_next.php"
                      style="margin:12px 0 0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="topic_progress_id"
                         value="<?= (int) $reco['topic_progress_id'] ?>">
                  <button class="ef-btn ef-btn-primary ef-btn-sm ef-btn-block" type="submit">
                    Practise this now
                  </button>
                </form>
                <p class="ef-note" style="margin-top:8px;">
                  Reuses a stored set if you have one you have not attempted, and
                  writes a new one only if you do not.
                </p>
              </section>
            <?php endif; ?>

            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:14px;">Your progress</div>
              <div class="ef-stats" style="grid-template-columns:1fr 1fr;">
                <div class="ef-stat">
                  <div class="ef-stat-label">Sets</div>
                  <div class="ef-stat-value"><?= count($activities) ?></div>
                </div>
                <div class="ef-stat">
                  <div class="ef-stat-label">Answers</div>
                  <div class="ef-stat-value"><?= (int) $stage['scored_items'] ?></div>
                </div>
              </div>
              <p class="ef-note" style="margin-top:12px;">
                A topic needs <?= MASTERY_MIN_ITEMS ?> scored answers before EduFlex
                puts a mastery value on it.
              </p>
            </section>

            <section class="ef-card ef-card-sky">
              <span class="ef-eyebrow">Responsible AI</span>
              <p class="ef-second" style="font-size:12.5px;margin-top:8px;line-height:1.6;">
                Questions are generated from your material and checked automatically
                before they are stored, but they can still be wrong. Report anything
                that looks incorrect rather than trusting it.
              </p>
            </section>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <script>
      document.addEventListener('DOMContentLoaded', function () {
        var CSRF = <?= json_encode(csrf_token()) ?>;

        $(document).on('click', '[data-generate]', function () {
          var $btn = $(this);
          var $row = $btn.closest('[data-topic-row]');
          var id = $btn.data('generate');

          $btn.prop('disabled', true).text('Writing...');
          $row.find('[data-gen-note]').remove();

          $.post('actions/generate_activity.php', { topic_progress_id: id, _csrf: CSRF })
            .done(function (res) {
              if (res && res.ok) {
                $btn.text('Done');
                var msg = res.items + ' questions at ' + res.bloom + ' level.';
                if (res.rejected > 0) {
                  msg += ' ' + res.rejected + ' discarded in validation.';
                }
                $row.after('<p class="ef-note" data-gen-note style="margin:0 0 10px 50px;">' +
                           $('<div>').text(msg).html() + '</p>');
                // Long enough to actually read the result before the list refreshes.
                setTimeout(function () { window.location.reload(); }, 2600);
              } else {
                $btn.prop('disabled', false).text('Retry');
                $row.after('<p class="ef-note" data-gen-note style="margin:0 0 10px 50px;">' +
                  $('<div>').text((res && res.error) || 'Generation failed.').html() + '</p>');
              }
            })
            .fail(function (xhr) {
              $btn.prop('disabled', false).text('Retry');
              var msg = 'Could not reach the server.';
              try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
              $row.after('<p class="ef-note" data-gen-note style="margin:0 0 10px 50px;">' +
                $('<div>').text(msg).html() + '</p>');
            });
        });
      });
      </script>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
