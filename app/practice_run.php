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
require_once __DIR__ . '/../includes/attempts.php';

/* The shell prints markup as soon as the body runs, so the attempt has to be
   resolved here, while a redirect is still possible. auth_require_login()
   below handles anyone who is not signed in. */
$runner = null;
if (auth_is_logged_in() && auth_user() !== null) {
    $runnerUser = auth_user();
    $runner = attempt_load((int) ($_GET['attempt'] ?? 0), (int) $runnerUser['user_id']);
    if (!$runner['ok']) {
        flash_set('error', (string) $runner['error']);
        redirect('practice.php');
    }
}
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
$uid       = (int) $user['user_id'];
$attempt   = $runner['attempt'];
$items     = $runner['items'];
$attemptId = (int) $attempt['attempt_id'];
$finished  = $attempt['completed_at'] !== null;
$total     = count($items);
$topicName = $attempt['topic_name'] !== null ? (string) $attempt['topic_name'] : 'Untitled topic';
?>
      <div class="ef-content">

<?php if ($finished): ?>
<?php
  $review  = attempt_review($attemptId, $uid);
  $score   = (float) $attempt['score'];
  $correct = 0;
  foreach ($review as $r) { if ($r['is_correct'] === true) { $correct++; } }

  // The ring uses the same colour language as the mastery bands, so a score
  // reads the same way wherever it appears. It is the attempt score, not the
  // topic's mastery, so it carries no band label.
  $scoreTone = $score >= MASTERY_MASTERED_AT ? 'mastered'
             : ($score >= MASTERY_DEVELOPING_AT ? 'developing' : 'weak');

  $topicId = $attempt['topic_progress_id'] !== null
      ? (int) $attempt['topic_progress_id'] : 0;
  $mastery = null;
  if ($topicId > 0) {
      $stmt = db()->prepare(
          'SELECT topic_name, mastery_score, scored_items, weakness_priority
             FROM topic_progress WHERE topic_progress_id = ? AND user_id = ?'
      );
      $stmt->execute([$topicId, $uid]);
      $mastery = $stmt->fetch() ?: null;
  }
?>
        <div class="ef-page-head">
          <div>
            <h2>Result</h2>
            <p><?= e((string) $attempt['title']) ?></p>
          </div>
          <a class="ef-btn ef-btn-ghost" href="practice.php">Back to practice</a>
        </div>

        <div class="ef-runner">

          <section class="ef-card ef-card-lg" style="text-align:center;">
            <div class="ef-score-ring ef-ring-<?= e($scoreTone) ?>">
              <div class="ef-score-value"><?= round($score) ?>%</div>
              <div class="ef-score-label">Score</div>
            </div>
            <p style="font-size:14px;font-weight:600;margin-bottom:4px;">
              <?= $correct ?> of <?= $total ?> correct
            </p>
            <p class="ef-second" style="font-size:12.5px;">
              <?= e($topicName) ?> &middot; <?= e((string) $attempt['bloom_level']) ?> level
            </p>
          </section>

          <?php if ($mastery !== null): ?>
            <?php
              $mScore = (float) $mastery['mastery_score'];
              $mItems = (int) $mastery['scored_items'];
              $mBand  = mastery_band($mScore, $mItems);
            ?>
            <section class="ef-card ef-card-lg" style="margin-top:20px;">
              <div class="ef-card-head">
                <div>
                  <div class="ef-card-title">Mastery of <?= e($topicName) ?></div>
                  <div class="ef-card-sub">
                    Recalculated from all <?= $mItems ?> answer<?= $mItems === 1 ? '' : 's' ?>
                    you have given on this topic
                  </div>
                </div>
                <span class="ef-band ef-band-<?= e($mBand) ?>">
                  <?= e(mastery_band_label($mBand)) ?>
                </span>
              </div>
              <?php if ($mItems < MASTERY_MIN_ITEMS): ?>
                <p class="ef-note">
                  EduFlex needs <?= MASTERY_MIN_ITEMS ?> scored answers on a topic before
                  it reports a mastery value. You have <?= $mItems ?>. Answer
                  <?= MASTERY_MIN_ITEMS - $mItems ?> more and this becomes a number.
                </p>
              <?php else: ?>
                <div class="ef-track" style="margin-bottom:8px;">
                  <span class="ef-fill-<?= e($mBand) ?>"
                        style="width:<?= round($mScore) ?>%;"></span>
                </div>
                <p class="ef-note">
                  <?= round($mScore) ?>%. Recent answers and harder Bloom levels
                  count for more, so this is not the average of your scores.
                </p>
              <?php endif; ?>
            </section>
          <?php endif; ?>

          <section class="ef-card ef-card-lg" style="margin-top:20px;">
            <div class="ef-card-head">
              <div class="ef-card-title">Every question</div>
            </div>

            <?php
              // The stored answer may already end in punctuation. Adding a full
              // stop unconditionally produced "assumed.." on screen.
              $stop = static fn(string $t): string =>
                  preg_match('/[.!?]$/', rtrim($t)) ? rtrim($t) : rtrim($t) . '.';
            ?>
            <?php foreach ($review as $i => $r): ?>
              <div class="ef-review-item">
                <div class="ef-review-q">
                  <?= $i + 1 ?>. <?= e((string) $r['question_text']) ?>
                </div>
                <div class="ef-review-line">
                  <?php if (!$r['answered']): ?>
                    <span class="ef-review-wrong">Skipped.</span>
                  <?php elseif ($r['is_correct']): ?>
                    <span class="ef-review-right">Correct.</span>
                    You answered <?= e($stop((string) $r['user_answer'])) ?>
                  <?php else: ?>
                    <span class="ef-review-wrong">Incorrect.</span>
                    You answered <?= e($stop((string) $r['user_answer'])) ?>
                  <?php endif; ?>
                  <br>
                  The answer is <strong><?= e(rtrim((string) $r['correct_answer'], '.')) ?></strong>.
                  <?php if ($r['explanation'] !== null): ?>
                    <br><?= e((string) $r['explanation']) ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </section>

          <section class="ef-card ef-card-sky" style="margin-top:20px;">
            <span class="ef-eyebrow">Responsible AI</span>
            <p class="ef-second" style="font-size:12.5px;margin-top:8px;line-height:1.6;">
              These questions were written by a language model from your own material
              and checked automatically before being stored. They can still be wrong.
              If an answer marked correct looks incorrect to you, trust your material
              over EduFlex and report it.
            </p>
          </section>

          <div class="ef-runner-foot">
            <a class="ef-btn ef-btn-ghost" href="dashboard.php">See your dashboard</a>
            <a class="ef-btn ef-btn-primary" href="practice.php">Practise again</a>
          </div>
        </div>

<?php else: ?>
<?php
  $answeredCount = (int) $runner['answered'];
  $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
?>
        <div class="ef-page-head">
          <div>
            <h2><?= e((string) $attempt['title']) ?></h2>
            <p><?= e($topicName) ?> &middot; <?= e((string) $attempt['bloom_level']) ?> level</p>
          </div>
          <a class="ef-btn ef-btn-ghost" href="practice.php">Leave</a>
        </div>

        <div class="ef-runner"
             data-attempt="<?= $attemptId ?>"
             data-total="<?= $total ?>"
             data-answered="<?= $answeredCount ?>">

          <div class="ef-runner-bar"><div class="ef-runner-fill"></div></div>

          <section class="ef-card ef-card-lg">

            <?php foreach ($items as $i => $item): ?>
              <div class="ef-q" data-item="<?= (int) $item['item_id'] ?>"
                   data-index="<?= $i ?>"
                   data-done="<?= $item['answered'] ? '1' : '0' ?>" hidden>

                <div class="ef-q-count">Question <?= $i + 1 ?> of <?= $total ?></div>
                <div class="ef-q-text"><?= e((string) $item['question_text']) ?></div>

                <div class="ef-options">
                  <?php foreach ($item['options'] as $n => $opt): ?>
                    <button class="ef-option" type="button"
                            data-value="<?= e((string) $opt) ?>">
                      <span class="ef-option-key"><?= e($letters[$n] ?? (string) ($n + 1)) ?></span>
                      <span><?= e((string) $opt) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>

                <div class="ef-feedback" hidden>
                  <div class="ef-feedback-title"></div>
                  <p></p>
                </div>

                <div class="ef-runner-foot">
                  <span class="ef-note" data-hint>
                    Choose an answer. You cannot change it once it is recorded.
                  </span>
                  <span style="display:flex;gap:10px;">
                    <button class="ef-btn ef-btn-ghost ef-btn-sm" type="button" data-skip>
                      Skip
                    </button>
                    <button class="ef-btn ef-btn-primary ef-btn-sm" type="button" data-next hidden>
                      Next
                    </button>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>

          </section>

          <p class="ef-note" style="text-align:center;margin-top:16px;">
            Your answers are saved as you go. If you close this tab, reopening the
            set returns you to the question you stopped at.
          </p>
        </div>

      <script>
      document.addEventListener('DOMContentLoaded', function () {
        var CSRF    = <?= json_encode(csrf_token()) ?>;
        var $runner = $('.ef-runner');
        var attempt = $runner.data('attempt');
        var total   = parseInt($runner.data('total'), 10);
        var $qs     = $runner.find('.ef-q');

        function progress() {
          var done = $qs.filter('[data-done="1"]').length;
          $runner.find('.ef-runner-fill').css('width', (done / total * 100) + '%');
          return done;
        }

        /* Show the first question that has not been answered. A resumed
           attempt therefore reopens where the learner stopped. */
        function showNext() {
          var $pending = $qs.filter('[data-done="0"]').first();
          if (!$pending.length) { finish(); return; }
          $qs.attr('hidden', true);
          $pending.removeAttr('hidden');
        }

        function finish() {
          $runner.find('.ef-card-lg').html(
            '<p class="ef-note" style="text-align:center;padding:24px 0;">' +
            'Scoring your attempt and updating your mastery...</p>'
          );
          $.post('actions/attempt_finish.php', { attempt_id: attempt, _csrf: CSRF })
            .done(function (res) {
              if (res && res.ok) {
                window.location.href = 'practice_run.php?attempt=' + attempt;
              } else {
                $runner.find('.ef-card-lg').html(
                  '<p class="ef-note" style="text-align:center;padding:24px 0;"></p>'
                ).find('p').text((res && res.error) || 'The attempt could not be scored.');
              }
            })
            .fail(function () {
              $runner.find('.ef-card-lg').html(
                '<p class="ef-note" style="text-align:center;padding:24px 0;">' +
                'Could not reach the server. Your answers are saved; reload to finish.</p>'
              );
            });
        }

        function send($q, value, $chosen) {
          var itemId = $q.data('item');
          $q.find('.ef-option, [data-skip]').prop('disabled', true);
          if ($chosen) { $chosen.addClass('ef-option-selected'); }

          $.post('actions/attempt_answer.php', {
            attempt_id: attempt, item_id: itemId, answer: value === null ? '' : value,
            _csrf: CSRF
          })
            .done(function (res) {
              if (!res || !res.ok) {
                $q.find('.ef-option, [data-skip]').prop('disabled', false);
                $q.find('[data-hint]').text((res && res.error) || 'That answer could not be recorded.');
                return;
              }

              $q.attr('data-done', '1');

              /* Colour the options only now: this is the first moment the page
                 has been told what the right answer was. */
              $q.find('.ef-option').each(function () {
                var $o = $(this);
                if ($o.data('value') === res.answer) {
                  $o.removeClass('ef-option-selected').addClass('ef-option-right');
                } else if ($chosen && $o.is($chosen)) {
                  $o.removeClass('ef-option-selected').addClass('ef-option-wrong');
                }
              });

              var $fb = $q.find('.ef-feedback');
              $fb.removeClass('ef-feedback-right ef-feedback-wrong')
                 .addClass(res.correct ? 'ef-feedback-right' : 'ef-feedback-wrong')
                 .removeAttr('hidden');
              $fb.find('.ef-feedback-title').text(
                res.correct ? 'Correct' : (value === null ? 'Skipped' : 'Not quite')
              );
              $fb.find('p').text(res.explanation || ('The answer is ' + res.answer + '.'));

              var done = progress();
              $q.find('[data-hint]').text(done + ' of ' + total + ' answered');
              $q.find('[data-next]')
                .text(done >= total ? 'See your result' : 'Next question')
                .removeAttr('hidden');
            })
            .fail(function () {
              $q.find('.ef-option, [data-skip]').prop('disabled', false);
              $q.find('[data-hint]').text('Could not reach the server. Try that answer again.');
            });
        }

        $runner.on('click', '.ef-option', function () {
          var $o = $(this);
          send($o.closest('.ef-q'), String($o.data('value')), $o);
        });

        $runner.on('click', '[data-skip]', function () {
          send($(this).closest('.ef-q'), null, null);
        });

        $runner.on('click', '[data-next]', function () {
          showNext();
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        progress();
        showNext();
      });
      </script>
<?php endif; ?>
      </div>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
