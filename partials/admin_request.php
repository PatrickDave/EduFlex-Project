<?php
/**
 * One request in the help desk, with its status buttons.
 *
 * Included in a loop by app/admin_requests.php and app/admin_feedback.php, and
 * by the recent list on app/admin.php. One file so the three cannot drift apart
 * about what a request looks like or which moves are offered.
 *
 * Expects in scope:
 *   $r             the row from admin_requests() or admin_feedback()
 *   $statusFilter  current status filter, carried through the form ('' if none)
 *   $typeFilter    current type filter, carried through the form ('' if none)
 *   $showActions   optional; false renders the card read-only
 *
 * Every value is escaped here rather than by the caller, because a partial that
 * trusts its includer is one include away from being wrong.
 */

declare(strict_types=1);

$status       = (string) ($r['status'] ?? 'open');
$statusFilter = $statusFilter ?? '';
$typeFilter   = $typeFilter ?? '';
$showActions  = $showActions ?? true;
$replyCount   = (int) ($r['reply_count'] ?? 0);
$needsReply   = admin_needs_reply($r);
$threadHref   = 'admin_thread.php?id=' . (int) $r['request_id'];
?>
<section class="ef-card ef-admin-req">

  <div class="ef-admin-req-head">
    <div style="min-width:0;">
      <div class="ef-card-title" style="margin-bottom:4px;">
        <a class="ef-admin-req-link" href="<?= e($threadHref) ?>">
          #<?= (int) $r['request_id'] ?> &middot; <?= e((string) $r['subject']) ?>
        </a>
      </div>
      <div class="ef-second" style="font-size:12.5px;">
        <?= e((string) $r['full_name']) ?>
        &middot; <?= e((string) $r['email']) ?>
        &middot; <?= e((string) $r['created_at']) ?>
      </div>
    </div>
    <div class="ef-admin-req-pills">
      <?php if ($needsReply): ?>
        <span class="ef-reply-flag"><?= $replyCount === 0 ? 'Needs a reply' : 'Learner replied' ?></span>
      <?php endif; ?>
      <span class="ef-band ef-band-<?= e(support_status_band($status)) ?>">
        <?= e(support_status_label($status)) ?>
      </span>
    </div>
  </div>

  <div class="ef-admin-req-type">
    <?= e(support_type_label((string) $r['request_type'])) ?>
  </div>

  <p class="ef-admin-req-body"><?= nl2br(e((string) $r['message'])) ?></p>

  <div class="ef-admin-req-thread">
    <span class="ef-second">
      <?= $replyCount === 0
            ? 'No replies yet'
            : $replyCount . ' ' . ($replyCount === 1 ? 'reply' : 'replies') ?>
    </span>
    <a class="ef-btn ef-btn-primary ef-btn-sm" href="<?= e($threadHref) ?>">
      <?= $replyCount === 0 ? 'Reply' : 'Open conversation' ?>
    </a>
  </div>

  <?php if ($showActions): ?>
    <div class="ef-admin-req-actions">
      <?php foreach (ADMIN_STATUS_ACTIONS as $next => $verb): ?>
        <?php if ($next === $status) { continue; } ?>
        <form method="post" action="actions/admin_status.php" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
          <input type="hidden" name="status" value="<?= e($next) ?>">
          <input type="hidden" name="status_filter" value="<?= e($statusFilter) ?>">
          <input type="hidden" name="type_filter" value="<?= e($typeFilter) ?>">
          <input type="hidden" name="back" value="<?= e(basename($_SERVER['SCRIPT_NAME'] ?? 'admin.php')) ?>">
          <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit">
            <?= e($verb) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</section>
