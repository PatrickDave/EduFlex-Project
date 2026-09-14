<?php
/**
 * EduFlex — Materials Library
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/resources.php';
require_once __DIR__ . '/../includes/topics.php';
auth_require_login();

$user   = auth_user();
$active = 'materials';
$title  = 'Materials Library';
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
$resources = resource_list((int) $user['user_id']);
$totals    = resource_totals((int) $user['user_id']);

/* The topbar search box submits here. Filtering the already-fetched list in
   PHP rather than adding a second query keeps resource_list() as the one place
   materials are read from, and a learner has tens of files, not thousands. */
$query = trim((string) ($_GET['q'] ?? ''));
if ($query !== '') {
    $needle    = mb_strtolower($query);
    $resources = array_values(array_filter(
        $resources,
        static fn(array $r): bool =>
            str_contains(mb_strtolower((string) $r['title']), $needle)
         || str_contains(mb_strtolower((string) $r['original_name']), $needle)
    ));
}

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
                  <?php if ($query !== ''): ?>
                    <?= count($resources) ?> of <?= (int) $totals['files'] ?> matching
                    &ldquo;<?= e($query) ?>&rdquo;
                    &middot; <a href="materials.php">clear</a>
                  <?php else: ?>
                    <?= (int) $totals['files'] ?> file<?= $totals['files'] === 1 ? '' : 's' ?>
                  <?php endif; ?>
                </span>
              </div>

              <?php if (!$resources && $query !== ''): ?>
                <div class="ef-empty-state">
                  <div class="ef-empty-ico"></div>
                  <h5>No material matches that</h5>
                  <p>
                    Nothing in your library has &ldquo;<?= e($query) ?>&rdquo; in its title
                    or file name. The search looks at names only, not at the text inside
                    your documents; ask the companion if you want to search the content.
                  </p>
                  <a class="ef-btn ef-btn-ghost" href="materials.php">Show all materials</a>
                </div>
              <?php elseif (!$resources): ?>
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
      </script>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
