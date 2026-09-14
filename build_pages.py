#!/usr/bin/env python3
"""
Generates the EduFlex app pages from a single shell template.

Every page shares one sidebar and one topbar. Editing the shell here and
re-running this script keeps all eight pages in sync. When you move to PHP,
replace SHELL with partials/sidebar.php + partials/topbar.php includes and
delete this script.

    python3 build_pages.py
"""

import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "app")
os.makedirs(OUT, exist_ok=True)

# The sidebar and topbar now live in partials/sidebar.php and
# partials/topbar.php. The shell below includes them, so nav markup is
# defined in exactly one place.


SHELL = """<?php
/**
 * EduFlex — {title}
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
{requires}
auth_require_login();

$user   = auth_user();
$active = '{active}';
$title  = '{title}';
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

{body}

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
"""


# ============================================================================
# DASHBOARD
# ============================================================================
DASHBOARD = '''<?php
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
      </div>'''



# ============================================================================
# AI LEARNING COMPANION
# ============================================================================
COMPANION = '''<?php
$uid       = (int) $user['user_id'];
$stage     = stats_stage($uid);
$resources = resource_list($uid);
$topics    = stats_topics($uid, 12);

$topicCounts = [];
foreach ($resources as $r) {
    $topicCounts[(int) $r['resource_id']] = topics_count_for_resource(
        (int) $r['resource_id'], $uid
    );
}

// The most recent readable material is what the companion talks about.
$active = null;
foreach ($resources as $r) {
    if ($r['processing_status'] === 'processed') { $active = $r; break; }
}

$thread     = chat_thread($uid);
$flashOk    = flash_get('companion_ok');
$flashError = flash_get('error');
?>
      <div style="display:flex;flex:1 1 auto;min-height:0;">

        <div class="ef-content" style="display:flex;flex-direction:column;gap:14px;min-width:0;">

          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <p class="ef-muted" style="font-size:12.5px;">
              Ask about your material, or drop a new file straight into the conversation.
            </p>
            <span style="display:flex;gap:8px;align-items:center;">
              <?php if ($thread): ?>
                <form method="post" action="actions/chat_clear.php" style="margin:0;">
                  <?= csrf_field() ?>
                  <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit">
                    Clear chat
                  </button>
                </form>
              <?php endif; ?>
              <button class="ef-rail-toggle" type="button" data-rail-toggle>
                Library
                <span class="ef-tab-count"><?= count($resources) ?></span>
              </button>
            </span>
          </div>

          <?php if ($flashOk): ?>
            <div class="ef-alert ef-alert-ok"><?= e((string) $flashOk) ?></div>
          <?php endif; ?>
          <?php if ($flashError): ?>
            <div class="ef-alert ef-alert-error"><?= e((string) $flashError) ?></div>
          <?php endif; ?>

          <div class="ef-thread" style="flex:1 1 auto;overflow-y:auto;" data-thread>
            <div class="ef-msg ef-msg-ai">
              <span class="ef-msg-avatar"></span>
              <div class="ef-bubble ef-bubble-ai">
                <?php if (!$active): ?>
                  Hello <?= e(auth_first_name()) ?>. I work from material you give me,
                  so I have nothing to go on yet. Attach a PDF, DOCX or TXT using the
                  paperclip below, or drop one into the library on the right, and I
                  will read it.
                <?php else: ?>
                  Hello <?= e(auth_first_name()) ?>. I have read
                  <strong><?= e((string) $active['title']) ?></strong>
                  and stored it as <?= (int) $active['chunk_count'] ?> sections.
                  <?php if ($stage['topics'] > 0): ?>
                    I am tracking <?= (int) $stage['topics'] ?>
                    topic<?= $stage['topics'] === 1 ? '' : 's' ?> from your materials.
                  <?php else: ?>
                    Run topic detection in the library and I will know what it covers.
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <?php foreach ($thread as $turn): ?>
              <div class="ef-msg ef-msg-user">
                <div class="ef-bubble ef-bubble-user"><?= e($turn['question']) ?></div>
              </div>
              <div class="ef-msg ef-msg-ai">
                <span class="ef-msg-avatar"></span>
                <div class="ef-bubble ef-bubble-ai"><?= nl2br(e($turn['answer'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($active): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;" data-suggestions>
              <span class="ef-chip" data-ask="Summarise the key points of this material.">
                Summarise this material</span>
              <span class="ef-chip" data-ask="Explain the main concept in simple terms.">
                Explain the main concept</span>
              <span class="ef-chip" data-ask="What are the most important terms I should know?">
                Key terms</span>
            </div>
          <?php endif; ?>

          <div class="ef-composer">
            <label style="display:flex;cursor:pointer;" title="Attach a material">
              <input type="file" data-chat-upload accept=".pdf,.docx,.txt,.md" hidden>
              <svg width="17" height="17" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M10.5 5.5 6 10a1.8 1.8 0 0 0 2.5 2.5l4.5-4.5a3.2 3.2 0 0 0-4.5-4.5L4 8"
                      stroke="var(--ef-text-2)" stroke-width="1.5" stroke-linecap="round"/>
              </svg>
            </label>
            <input type="text" data-chat-input
                   placeholder="<?= $active
                       ? 'Ask about your material...'
                       : 'Attach a document first, then ask me about it' ?>"
                   <?= $active ? '' : 'disabled' ?>>
            <button class="ef-send" type="button" aria-label="Send" data-chat-send
                    <?= $active ? '' : 'disabled' ?>>
              <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
                <path d="M3 8h9m0 0-3.5-3.5M12 8l-3.5 3.5" stroke="currentColor"
                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          </div>

          <p class="ef-note" style="text-align:center;">
            Answers come from the documents you uploaded and name the sections they
            used. The companion says so when your material does not cover a question,
            but it can still be wrong, so check anything that matters against the source.
          </p>
        </div>

        <aside class="ef-rail" data-rail>
          <div class="ef-tabs">
            <button class="ef-rail-close" type="button" data-rail-close aria-label="Close library">&times;</button>
            <button class="ef-tab is-active" type="button" data-tab="materials">
              Materials <span class="ef-tab-count" data-count-materials><?= count($resources) ?></span>
            </button>
            <button class="ef-tab" type="button" data-tab="topics">
              Topics <span class="ef-tab-count"><?= (int) $stage['topics'] ?></span>
            </button>
          </div>

          <!-- Materials panel -->
          <div class="ef-rail-body" data-panel="materials">

            <div class="ef-drop" data-drop>
              <p>Drop a PDF, DOCX or TXT here.<br>Up to 20 MB, text must be selectable.</p>
              <label class="ef-btn ef-btn-primary ef-btn-sm" style="cursor:pointer;">
                Choose a file
                <input type="file" data-rail-upload accept=".pdf,.docx,.txt,.md" hidden>
              </label>
            </div>

            <div data-material-list>
              <?php if (!$resources): ?>
                <p class="ef-note" data-empty-materials style="text-align:center;padding:10px 0;">
                  Nothing uploaded yet.
                </p>
              <?php endif; ?>

              <?php foreach ($resources as $r): ?>
                <?php $tc = $topicCounts[(int) $r['resource_id']] ?? 0; ?>
                <div class="ef-mat" data-material="<?= (int) $r['resource_id'] ?>">
                  <div class="ef-mat-top">
                    <span class="ef-mat-ico"><?= e(strtoupper((string) $r['file_type'])) ?></span>
                    <div class="ef-mat-main">
                      <div class="ef-mat-title"><?= e((string) $r['title']) ?></div>
                      <div class="ef-mat-meta">
                        <?= e(format_bytes((int) $r['file_size'])) ?>
                        <?php if ((int) $r['chunk_count'] > 0): ?>
                          &middot; <?= (int) $r['chunk_count'] ?> sections
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <?php if (!empty($r['extract_message'])): ?>
                    <p class="ef-note" style="margin-top:8px;"><?= e((string) $r['extract_message']) ?></p>
                  <?php endif; ?>

                  <div class="ef-mat-foot">
                    <span>
                      <span class="ef-band ef-band-<?= e(resource_status_band((string) $r['processing_status'])) ?>">
                        <?= e(resource_status_label((string) $r['processing_status'])) ?>
                      </span>
                      <?php if ($tc > 0): ?>
                        <span class="ef-band ef-band-mastered" style="margin-left:4px;">
                          <?= (int) $tc ?> topic<?= $tc === 1 ? '' : 's' ?>
                        </span>
                      <?php endif; ?>
                    </span>
                    <span class="ef-mat-actions">
                      <?php if ($tc === 0 && $r['processing_status'] === 'processed'): ?>
                        <button type="button" data-detect="<?= (int) $r['resource_id'] ?>">Find topics</button>
                      <?php endif; ?>
                      <button type="button" class="ef-danger"
                              data-delete="<?= (int) $r['resource_id'] ?>">Delete</button>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if (count($resources) > 4): ?>
              <a class="ef-btn ef-btn-ghost ef-btn-block ef-btn-field ef-btn-sm"
                 href="materials.php" style="margin-top:6px;">Manage all materials</a>
            <?php endif; ?>
          </div>

          <!-- Topics panel -->
          <div class="ef-rail-body" data-panel="topics" hidden>
            <?php if (!$topics): ?>
              <p class="ef-note" style="text-align:center;padding:10px 0;">
                No topics yet. Upload a material, then choose Find topics on it.
              </p>
            <?php else: ?>
              <h4>Tracked topics</h4>
              <?php foreach ($topics as $i => $t): ?>
                <?php
                  $band = mastery_band((float) $t['mastery_score'], (int) $t['scored_items']);
                  $show = $band !== 'none';
                ?>
                <div class="ef-topic<?= $i === 0 ? ' is-active' : '' ?>">
                  <span style="min-width:0;overflow-wrap:anywhere;"><?= e((string) $t['topic_name']) ?></span>
                  <span class="ef-band ef-band-<?= e($band) ?>" style="flex:0 0 auto;">
                    <?= $show ? round((float) $t['mastery_score']) . '%' : 'No data' ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <div class="ef-card ef-card-sky" style="margin-top:20px;padding:14px 16px;">
              <span class="ef-eyebrow">Responsible AI</span>
              <p class="ef-second" style="font-size:12px;margin-top:6px;line-height:1.55;">
                EduFlex assists your studying. It does not replace your instructor or
                count toward official assessment.
              </p>
            </div>
          </div>
        </aside>
        <div class="ef-rail-scrim" data-rail-scrim></div>
      </div>

      <script>
      document.addEventListener('DOMContentLoaded', function () {
        var CSRF = <?= json_encode(csrf_token()) ?>;
        var $thread = $('[data-thread]');

        /* ---- rail tabs ---- */
        $('.ef-tab').on('click', function () {
          var name = $(this).data('tab');
          $('.ef-tab').removeClass('is-active');
          $(this).addClass('is-active');
          $('[data-panel]').each(function () {
            this.hidden = $(this).data('panel') !== name;
          });
        });

        /* ---- rail slide-over on narrow screens ---- */
        function setRail(open) {
          $('[data-rail]').toggleClass('is-open', open);
          $('[data-rail-scrim]').toggleClass('is-open', open);
        }
        $('[data-rail-toggle]').on('click', function () {
          setRail(!$('[data-rail]').hasClass('is-open'));
        });
        $('[data-rail-close], [data-rail-scrim]').on('click', function () { setRail(false); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') { setRail(false); } });
        $(window).on('resize', function () { if (window.innerWidth > 1080) { setRail(false); } });

        /* ---- chat helpers ---- */
        function say(html, who) {
          var side = who === 'user' ? 'ef-msg-user' : 'ef-msg-ai';
          var bubble = who === 'user' ? 'ef-bubble-user' : 'ef-bubble-ai';
          var avatar = who === 'user' ? '' : '<span class="ef-msg-avatar"></span>';
          var $msg = $(
            '<div class="ef-msg ' + side + '">' + avatar +
            '<div class="ef-bubble ' + bubble + '">' + html + '</div></div>'
          );
          $thread.append($msg);
          $thread.scrollTop($thread[0].scrollHeight);
          return $msg;
        }

        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

        /* ---- asking the companion ---- */

        var asking = false;

        function sourceChips(sources) {
          if (!sources || !sources.length) { return ''; }
          var chips = sources.map(function (s) {
            return '<span class="ef-source-chip">' + esc(s.title) +
                   ' &middot; section ' + (parseInt(s.chunk_index, 10) + 1) + '</span>';
          }).join('');
          return '<div class="ef-sources"><span class="ef-sources-label">From your material</span>' +
                 chips + '</div>';
        }

        function ask(question) {
          if (asking || !question) { return; }
          asking = true;

          $('[data-chat-input]').val('').prop('disabled', true);
          $('[data-chat-send]').prop('disabled', true);
          $('[data-suggestions]').hide();

          say(esc(question), 'user');
          var $pending = say('<span class="ef-typing">Reading your material...</span>', 'ai');

          $.post('actions/chat_send.php', { question: question, _csrf: CSRF })
            .done(function (res) {
              if (res && res.ok) {
                var html = esc(res.answer).replace(/\\n/g, '<br>');  /* doubled: this file is a Python string */
                /* An answer the model could not ground in the material is
                   labelled as such. Saying so is the whole point of the
                   design; hiding it would make the system less trustworthy,
                   not more. */
                if (res.grounded === false) {
                  html = '<span class="ef-ungrounded">Not found in your material</span>' + html;
                }
                $pending.find('.ef-bubble').html(html + sourceChips(res.sources));
              } else {
                $pending.find('.ef-bubble').html(
                  esc((res && res.error) || 'The companion could not answer.')
                );
              }
            })
            .fail(function (xhr) {
              var msg = 'Could not reach the server.';
              try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
              $pending.find('.ef-bubble').html(esc(msg));
            })
            .always(function () {
              asking = false;
              $('[data-chat-input]').prop('disabled', false).focus();
              $('[data-chat-send]').prop('disabled', false);
              $thread.scrollTop($thread[0].scrollHeight);
            });
        }

        $('[data-chat-send]').on('click', function () {
          ask($.trim($('[data-chat-input]').val()));
        });

        $('[data-chat-input]').on('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            ask($.trim($(this).val()));
          }
        });

        $(document).on('click', '[data-ask]', function () {
          ask($(this).data('ask'));
        });

        /* ---- upload, from the composer or the rail ---- */
        function upload(file) {
          if (!file) { return; }

          var $pending = say(
            'Reading <strong>' + esc(file.name) + '</strong>...', 'ai');

          var data = new FormData();
          data.append('document', file);
          data.append('_csrf', CSRF);

          $.ajax({
            url: 'actions/upload_ajax.php',
            type: 'POST',
            data: data,
            processData: false,
            contentType: false
          }).done(function (res) {
            if (!res || !res.ok) {
              $pending.find('.ef-bubble').html(
                'I could not read <strong>' + esc(file.name) + '</strong>. ' +
                esc((res && res.error) || 'Something went wrong.'));
              return;
            }

            var html = 'I have read <strong>' + esc(res.title) + '</strong> and stored it as ' +
                       res.chunks + ' section' + (res.chunks === 1 ? '' : 's') + '.';
            if (res.warning) { html += '<p class="ef-note">' + esc(res.warning) + '</p>'; }
            html += '<div class="ef-msg-foot">' +
                    '<span class="ef-source">' + esc(res.fileType) + ' &middot; ' + esc(res.size) + '</span>' +
                    '<button class="ef-btn ef-btn-sm ef-btn-soft" data-detect="' + res.resourceId +
                    '">Find topics</button></div>';
            $pending.find('.ef-bubble').html(html);

            addMaterialCard(res);
          }).fail(function (xhr) {
            var msg = 'The upload failed.';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
            $pending.find('.ef-bubble').html(
              'I could not read <strong>' + esc(file.name) + '</strong>. ' + esc(msg));
          });
        }

        function addMaterialCard(res) {
          $('[data-empty-materials]').remove();
          var card =
            '<div class="ef-mat" data-material="' + res.resourceId + '">' +
              '<div class="ef-mat-top">' +
                '<span class="ef-mat-ico">' + esc(res.fileType) + '</span>' +
                '<div class="ef-mat-main">' +
                  '<div class="ef-mat-title">' + esc(res.title) + '</div>' +
                  '<div class="ef-mat-meta">' + esc(res.size) +
                    (res.chunks ? ' &middot; ' + res.chunks + ' sections' : '') + '</div>' +
                '</div>' +
              '</div>' +
              '<div class="ef-mat-foot">' +
                '<span class="ef-band ef-band-' + esc(res.band) + '">' + esc(res.statusLabel) + '</span>' +
                '<span class="ef-mat-actions">' +
                  (res.ok ? '<button type="button" data-detect="' + res.resourceId + '">Find topics</button>' : '') +
                  '<button type="button" class="ef-danger" data-delete="' + res.resourceId + '">Delete</button>' +
                '</span>' +
              '</div>' +
            '</div>';
          $('[data-material-list]').prepend(card);
          var $c = $('[data-count-materials]');
          $c.text(parseInt($c.text(), 10) + 1);
        }

        $('[data-chat-upload], [data-rail-upload]').on('change', function () {
          upload(this.files && this.files[0]);
          this.value = '';
        });

        /* ---- drag and drop onto the rail ---- */
        var $drop = $('[data-drop]');
        $drop.on('dragover', function (e) { e.preventDefault(); $drop.addClass('is-over'); });
        $drop.on('dragleave drop', function () { $drop.removeClass('is-over'); });
        $drop.on('drop', function (e) {
          e.preventDefault();
          var dt = e.originalEvent.dataTransfer;
          if (dt && dt.files && dt.files.length) { upload(dt.files[0]); }
        });

        /* ---- topic detection, from the rail or a chat message ---- */
        $(document).on('click', '[data-detect]', function () {
          var $btn = $(this);
          var id = $btn.data('detect');
          $btn.prop('disabled', true).text('Reading...');

          $.post('actions/detect_topics.php', { resource_id: id, _csrf: CSRF })
            .done(function (res) {
              if (res && res.ok) {
                var names = (res.names || []).slice(0, 5).map(esc).join(', ');
                say('I found ' + res.topics + ' topic' + (res.topics === 1 ? '' : 's') +
                    ' in that material' + (names ? ': ' + names : '') + '.' +
                    (res.error ? '<p class="ef-note">' + esc(res.error) + '</p>' : ''), 'ai');
                setTimeout(function () { window.location.reload(); }, 1200);
              } else {
                $btn.prop('disabled', false).text('Retry');
                say('I could not work out the topics. ' +
                    esc((res && res.error) || 'Something went wrong.'), 'ai');
              }
            })
            .fail(function (xhr) {
              $btn.prop('disabled', false).text('Retry');
              var msg = 'Could not reach the server.';
              try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
              say('I could not work out the topics. ' + esc(msg), 'ai');
            });
        });

        /* ---- delete ---- */
        $(document).on('click', '[data-delete]', function () {
          var id = $(this).data('delete');
          if (!window.confirm('Delete this material and everything generated from it?')) {
            return;
          }
          var $form = $('<form method="post" action="actions/delete_resource.php"></form>')
            .append('<input type="hidden" name="_csrf" value="' + esc(CSRF) + '">')
            .append('<input type="hidden" name="resource_id" value="' + id + '">');
          $('body').append($form);
          $form.trigger('submit');
        });
      });
      </script>'''




# ============================================================================
# PRACTICE
# ============================================================================
PRACTICE = '''<?php
$uid        = (int) $user['user_id'];
$stage      = stats_stage($uid);
$activities = questions_activity_list($uid, 20);
$genTopics  = questions_generatable_topics($uid, 20);
$reco       = stats_recommendation($uid);
$flashError = flash_get('error');
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
      </script>'''




# ============================================================================
# GROWTH INSIGHTS
# ============================================================================
GROWTH = '''<?php
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
      </div>'''



# ============================================================================
# MONITOR SCORES
# ============================================================================
SCORES = '''<?php
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
      </div>'''



# ============================================================================
# MATERIALS
# ============================================================================
MATERIALS = '''<?php
$resources = resource_list((int) $user['user_id']);
$totals    = resource_totals((int) $user['user_id']);
$topicCounts = [];
foreach ($resources as $r) {
    $topicCounts[(int) $r['resource_id']] = topics_count_for_resource(
        (int) $r['resource_id'], (int) $user['user_id']
    );
}
$flashOk    = flash_get('materials_ok');
$flashError = flash_get('materials_error');
$processId  = flash_get('materials_process');
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Materials Library</h2>
            <p>Upload lecture notes, reviewers and slides. EduFlex reads the text so it can build your activities.</p>
          </div>
        </div>

        <?php if ($flashOk): ?>
          <div class="ef-alert ef-alert-ok"><?= e((string) $flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
          <div class="ef-alert ef-alert-error"><?= e((string) $flashError) ?></div>
        <?php endif; ?>

        <div class="ef-row">
          <div class="ef-col ef-stack">

            <!-- Upload -->
            <form class="ef-empty-state" style="padding:28px 24px;text-align:left;"
                  action="actions/upload.php" method="post" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                <div class="ef-empty-ico" style="margin:0;flex:0 0 auto;"></div>
                <div style="flex:1 1 260px;min-width:0;">
                  <h5 style="margin-bottom:4px;">Add a document</h5>
                  <p style="margin:0 0 14px;max-width:none;">
                    PDF, DOCX or TXT, up to 20 MB. The text must be selectable.
                    Scanned pages are images, and EduFlex cannot read them.
                  </p>

                  <div class="ef-field" style="margin-bottom:12px;">
                    <label class="ef-label" for="document">File</label>
                    <input class="ef-input" type="file" id="document" name="document"
                           accept=".pdf,.docx,.txt,.md" required>
                  </div>

                  <div class="ef-field" style="margin-bottom:14px;">
                    <label class="ef-label" for="title">Title <span class="ef-muted">optional</span></label>
                    <input class="ef-input" type="text" id="title" name="title"
                           placeholder="Defaults to the file name" maxlength="255">
                  </div>

                  <button class="ef-btn ef-btn-primary" type="submit">Upload and analyze</button>
                </div>
              </div>
            </form>

            <!-- List -->
            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div class="ef-card-title">Your materials</div>
                <span class="ef-muted" style="font-size:11.5px;">
                  <?= (int) $totals['files'] ?> file<?= $totals['files'] === 1 ? '' : 's' ?>
                </span>
              </div>

              <?php if (!$resources): ?>
                <div class="ef-empty-state">
                  <div class="ef-empty-ico"></div>
                  <h5>Nothing uploaded yet</h5>
                  <p>
                    EduFlex builds every practice activity from material you provide.
                    Upload your first reviewer above to get started.
                  </p>
                </div>
              <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="ef-table">
                  <thead>
                    <tr><th>File</th><th>Type</th><th>Text</th><th>Topics</th><th>Status</th><th></th></tr>
                  </thead>
                  <tbody>
                  <?php foreach ($resources as $r): ?>
                    <tr data-resource-row="<?= (int) $r['resource_id'] ?>">
                      <td>
                        <strong><?= e((string) $r['title']) ?></strong>
                        <div class="ef-list-meta">
                          <?= e(format_bytes((int) $r['file_size'])) ?>
                          &middot; <?= e(date('j M Y', strtotime((string) $r['uploaded_at']))) ?>
                        </div>
                      </td>
                      <td class="ef-second"><?= e(UPLOAD_ALLOWED[$r['file_type']] ?? strtoupper((string) $r['file_type'])) ?></td>
                      <td class="ef-second">
                        <?php if ((int) $r['chunk_count'] > 0): ?>
                          <?= number_format((int) $r['chunk_count']) ?> chunk<?= (int) $r['chunk_count'] === 1 ? '' : 's' ?>
                          <div class="ef-list-meta"><?= number_format((int) $r['char_count']) ?> characters</div>
                        <?php else: ?>
                          <span class="ef-muted">&mdash;</span>
                        <?php endif; ?>
                      </td>
                      <td data-topics-cell="<?= (int) $r['resource_id'] ?>">
                        <?php $tc = $topicCounts[(int) $r['resource_id']] ?? 0; ?>
                        <?php if ($tc > 0): ?>
                          <strong><?= (int) $tc ?></strong>
                          <div class="ef-list-meta">detected</div>
                        <?php elseif ($r['processing_status'] === 'processed'): ?>
                          <button class="ef-btn ef-btn-soft ef-btn-sm" type="button"
                                  data-detect="<?= (int) $r['resource_id'] ?>">Detect topics</button>
                        <?php else: ?>
                          <span class="ef-muted">&mdash;</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="ef-band ef-band-<?= e(resource_status_band((string) $r['processing_status'])) ?>"
                              data-status-cell>
                          <?= e(resource_status_label((string) $r['processing_status'])) ?>
                        </span>
                        <?php if (!empty($r['extract_message'])): ?>
                          <div class="ef-note" style="max-width:320px;"><?= e((string) $r['extract_message']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td style="text-align:right;">
                        <form action="actions/delete_resource.php" method="post"
                              onsubmit="return confirm('Delete this material and everything generated from it?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="resource_id" value="<?= (int) $r['resource_id'] ?>">
                          <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit"
                                  style="color:var(--ef-weak);">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
                </div>
              <?php endif; ?>
            </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Library</div>
              <div class="ef-card-sub" style="margin-bottom:16px;">What EduFlex can read so far</div>
              <div class="ef-stats" style="grid-template-columns:1fr 1fr;">
                <div class="ef-stat">
                  <div class="ef-stat-label">Analyzed</div>
                  <div class="ef-stat-value"><?= (int) $totals['processed'] ?></div>
                </div>
                <div class="ef-stat">
                  <div class="ef-stat-label">Topics</div>
                  <div class="ef-stat-value"><?= number_format(array_sum($topicCounts)) ?></div>
                </div>
              </div>
              <p class="ef-note" style="margin-top:12px;">
                <?= number_format((int) $totals['chunks']) ?> sections of about 700 words
                stored. EduFlex samples across them to find topics, rather than
                sending every section, so a long document stays inside a free quota.
              </p>
            </section>

            <section class="ef-card ef-card-sky">
              <span class="ef-eyebrow">What happens next</span>
              <p class="ef-second" style="font-size:12.5px;margin-top:8px;line-height:1.6;">
                Detect topics on a material and EduFlex reads it to find what it
                covers. Those topics become the units your mastery is tracked
                against. Question generation is next.
              </p>
            </section>
          </div>
        </div>
      </div>

      <script>
      // After an upload the server hands back a resource id to process.
      // Extraction runs here rather than during the upload request, so a long
      // PDF cannot time out the page load.
      // jQuery is loaded at the end of the document, so wait for parsing to
      // finish before touching $.
      document.addEventListener('DOMContentLoaded', function () {
        var pending = <?= $processId ? (int) $processId : 'null' ?>;
        if (!pending) { return; }

        var $cell = $('[data-resource-row="' + pending + '"]').find('[data-status-cell]');
        $cell.text('Extracting...');

        $.post('actions/process.php', {
          resource_id: pending,
          _csrf: <?= json_encode(csrf_token()) ?>
        }).done(function (res) {
          if (res && res.ok) {
            $cell.removeClass().addClass('ef-band ef-band-' + res.band).text(res.statusLabel);
          } else {
            $cell.removeClass().addClass('ef-band ef-band-weak').text('Failed');
          }
          window.location.reload();
        }).fail(function () {
          $cell.removeClass().addClass('ef-band ef-band-weak').text('Failed');
        });
      });

      // Topic detection makes several model calls, so it can take a while.
      document.addEventListener('DOMContentLoaded', function () {
        $(document).on('click', '[data-detect]', function () {
          var $btn = $(this);
          $btn.prop('disabled', true).text('Reading...');

          $.post('actions/detect_topics.php', {
            resource_id: $btn.data('detect'),
            _csrf: <?= json_encode(csrf_token()) ?>
          }).done(function (res) {
            if (res && res.ok) {
              window.location.reload();
            } else {
              $btn.prop('disabled', false).text('Retry');
              $btn.closest('td').append(
                '<div class="ef-note">' + ((res && res.error) || 'Topic detection failed.') + '</div>');
            }
          }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Retry');
            var msg = 'Could not reach the server.';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
            $btn.closest('td').append('<div class="ef-note">' + msg + '</div>');
          });
        });
      });
      </script>'''



# ============================================================================
# REVIEW HISTORY
# ============================================================================
HISTORY = '''<?php
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
      </div>'''



# ============================================================================
# SETTINGS
# ============================================================================
SETTINGS = '''<?php
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
      </div>'''



STATS = "require_once __DIR__ . '/../includes/stats.php';"

# ============================================================================
# PRACTICE RUNNER
#
# Two screens in one file. While completed_at is NULL the page is the runner:
# one question at a time, an answer posted per question, feedback shown only
# after the learner has committed. Once the attempt is finished the same URL
# becomes the result screen, so a bookmarked attempt always shows its outcome.
#
# The correct answers are never rendered into the page while the attempt is
# open. They arrive one at a time in the reply to actions/attempt_answer.php.
# ============================================================================
PRACTICE_RUN_REQUIRES = """require_once __DIR__ . '/../includes/attempts.php';

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
}"""

PRACTICE_RUN = '''<?php
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
      </div>'''


PAGES = [
    ("dashboard.php", "Dashboard",             "dashboard", DASHBOARD, STATS),
    ("companion.php", "AI Learning Companion", "companion", COMPANION,
     STATS + "\nrequire_once __DIR__ . '/../includes/resources.php';"
           + "\nrequire_once __DIR__ . '/../includes/topics.php';"
           + "\nrequire_once __DIR__ . '/../includes/chat.php';"),
    ("materials.php", "Materials Library",     "materials", MATERIALS,
     "require_once __DIR__ . '/../includes/resources.php';\n"
     "require_once __DIR__ . '/../includes/topics.php';"),
    ("practice.php",  "Practice",              "practice",  PRACTICE,
     STATS + "\nrequire_once __DIR__ . '/../includes/questions.php';"),
    ("practice_run.php", "Practice",           "practice",  PRACTICE_RUN,
     PRACTICE_RUN_REQUIRES),
    ("growth.php",    "Growth Insights",       "growth",    GROWTH,    STATS),
    ("scores.php",    "Monitor Scores",        "scores",    SCORES,    STATS),
    ("history.php",   "Review History",        "history",   HISTORY,   STATS),
    ("settings.php",  "Profile Settings",      "settings",  SETTINGS,  STATS),
]

written = []
for entry in PAGES:
    filename, title, active, body = entry[0], entry[1], entry[2], entry[3]
    requires = entry[4] if len(entry) > 4 else ""
    html = SHELL.format(title=title, active=active, body=body, requires=requires)
    path = os.path.join(OUT, filename)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(html)
    written.append(filename)

print("Wrote %d pages into %s:" % (len(written), OUT))
for w in written:
    print("  app/" + w)
