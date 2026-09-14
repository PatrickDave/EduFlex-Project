<?php
/**
 * EduFlex — AI Learning Companion
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stats.php';
require_once __DIR__ . '/../includes/resources.php';
require_once __DIR__ . '/../includes/topics.php';
require_once __DIR__ . '/../includes/chat.php';
auth_require_login();

$user   = auth_user();
$active = 'companion';
$title  = 'AI Learning Companion';
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
                var html = esc(res.answer).replace(/\n/g, '<br>');  /* doubled: this file is a Python string */
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
      </script>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
