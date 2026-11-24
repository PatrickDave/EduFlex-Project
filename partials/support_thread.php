<?php
/**
 * One support conversation: the original request, then every reply, oldest
 * first.
 *
 * Included by app/admin_thread.php (the team's view) and app/support_thread.php
 * (the learner's). One file so the two sides cannot drift apart about what the
 * conversation looks like. Only the labels change with who is reading: each
 * side sees its own messages as "You" on the right, and the other side's on
 * the left.
 *
 * Expects in scope:
 *   $thread        the request row (support_request_for_user() or admin_request())
 *   $replies       support_replies() for that request
 *   $viewerIsTeam  true on the team's page, false on the learner's
 *
 * Every value is escaped here rather than by the caller, because a partial that
 * trusts its includer is one include away from being wrong.
 */

declare(strict_types=1);

$learnerName = (string) ($thread['full_name'] ?? '');

/* The original request is message zero. Written by the learner, always. */
$messages = array_merge(
    [[
        'from_team'   => 0,
        'author_name' => $learnerName,
        'message'     => (string) $thread['message'],
        'created_at'  => (string) $thread['created_at'],
        'is_original' => true,
    ]],
    $replies
);
?>
<div class="ef-conv" aria-label="Conversation">
  <?php foreach ($messages as $m): ?>
    <?php
      $fromTeam = (int) ($m['from_team'] ?? 0) === 1;
      $mine     = $fromTeam === $viewerIsTeam;
      $who      = !empty($m['is_original'])
          ? ($viewerIsTeam ? ($learnerName !== '' ? $learnerName : 'Learner') : 'You')
          : support_reply_author($m, $viewerIsTeam, $learnerName);
      $when     = strtotime((string) $m['created_at']);
    ?>
    <div class="ef-conv-msg<?= $mine ? ' is-mine' : '' ?><?= $fromTeam ? ' is-team' : '' ?>">
      <div class="ef-conv-msg-meta">
        <span class="ef-conv-msg-who"><?= e($who) ?></span>
        <?php if ($fromTeam && !$viewerIsTeam): ?>
          <span class="ef-conv-msg-tag">Team</span>
        <?php endif; ?>
        <?php if (!empty($m['is_original'])): ?>
          <span class="ef-conv-msg-tag ef-conv-msg-tag-soft">Original request</span>
        <?php endif; ?>
        <span class="ef-conv-msg-when">
          <?= e($when !== false ? date('j M Y, g:i A', $when) : (string) $m['created_at']) ?>
        </span>
      </div>
      <div class="ef-conv-msg-body"><?= nl2br(e((string) $m['message'])) ?></div>
    </div>
  <?php endforeach; ?>
</div>
