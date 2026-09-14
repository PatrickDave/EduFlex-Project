<?php
/**
 * EduFlex — Notifications
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
auth_require_login();

$user   = auth_user();
$active = '';
$title  = 'Notifications';
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
$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';
$totals = notifications_totals($uid);
$rows   = notifications_list($uid, NOTIFY_PAGE_SIZE, $filter);
$ok     = flash_get('notifications_ok');
$err    = flash_get('notifications_error');
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Notifications</h2>
            <p>What EduFlex has done with your material, and what it suggests next</p>
          </div>
          <?php if ($totals['unread'] > 0): ?>
            <form method="post" action="actions/notifications_read.php" style="margin:0;">
              <?= csrf_field() ?>
              <input type="hidden" name="scope" value="all">
              <input type="hidden" name="filter" value="<?= e($filter) ?>">
              <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit">
                Mark all read
              </button>
            </form>
          <?php endif; ?>
        </div>

        <?php if ($ok): ?><div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div><?php endif; ?>

        <div class="ef-row">
          <div class="ef-col ef-stack">
            <section class="ef-card ef-card-lg">

              <div class="ef-tabs">
                <a class="ef-tab<?= $filter === 'all' ? ' is-active' : '' ?>"
                   href="notifications.php">
                  All <span class="ef-tab-count"><?= (int) $totals['total'] ?></span>
                </a>
                <a class="ef-tab<?= $filter === 'unread' ? ' is-active' : '' ?>"
                   href="notifications.php?filter=unread">
                  Unread <span class="ef-tab-count"><?= (int) $totals['unread'] ?></span>
                </a>
              </div>

              <?php if ($totals['total'] === 0): ?>
                <div class="ef-empty-state">
                  <div class="ef-empty-ico"></div>
                  <h5>Nothing to report yet</h5>
                  <p>
                    EduFlex writes a notification when it finishes reading a document,
                    when it works out what topics that document covers, when a topic of
                    yours reaches the mastered band, and when it changes its mind about
                    what you should practise next. Upload something and the first one
                    will appear here.
                  </p>
                  <a class="ef-btn ef-btn-primary" href="companion.php">Open the companion</a>
                </div>

              <?php elseif (!$rows): ?>
                <div class="ef-empty-state">
                  <div class="ef-empty-ico"></div>
                  <h5>Nothing unread</h5>
                  <p>You have read all <?= (int) $totals['total'] ?> of your notifications.</p>
                  <a class="ef-btn ef-btn-ghost" href="notifications.php">Show all</a>
                </div>

              <?php else: ?>
                <?php foreach ($rows as $n): ?>
                  <?php
                    $type   = (string) $n['notification_type'];
                    $label  = notifications_type_label($type);
                    $unread = (int) $n['is_read'] === 0;
                  ?>
                  <div class="ef-list-row ef-notif<?= $unread ? ' is-unread' : '' ?>">
                    <span class="ef-list-ico"><?= e(mb_substr($label, 0, 1)) ?></span>

                    <div class="ef-list-main">
                      <div class="ef-notif-head">
                        <span class="ef-list-title"><?= e((string) $n['title']) ?></span>
                        <span class="ef-band ef-band-<?= e(notifications_type_band($type)) ?>">
                          <?= e($label) ?>
                        </span>
                        <span class="ef-list-meta" style="margin-top:0;">
                          <?= e(notifications_when((string) $n['created_at'])) ?>
                        </span>
                      </div>
                      <div class="ef-notif-body"><?= e((string) $n['message']) ?></div>
                    </div>

                    <?php if ($unread): ?>
                      <form method="post" action="actions/notifications_read.php" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="notification_id"
                               value="<?= (int) $n['notification_id'] ?>">
                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                        <button class="ef-btn ef-btn-ghost ef-btn-sm" type="submit">
                          Mark read
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>

                <?php if (count($rows) >= NOTIFY_PAGE_SIZE): ?>
                  <p class="ef-muted" style="font-size:11.5px;margin-top:14px;">
                    Showing the most recent <?= (int) NOTIFY_PAGE_SIZE ?>.
                  </p>
                <?php endif; ?>
              <?php endif; ?>
            </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">
            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">What gets reported</div>
              <div class="ef-card-sub" style="margin-bottom:14px;">
                Four events, all of them things you did
              </div>
              <ul class="ef-second" style="font-size:12.5px;line-height:1.7;padding-left:18px;margin:0;">
                <li>A document finished being read and split into sections.</li>
                <li>Topic detection found topics in one of your documents.</li>
                <li>A topic of yours reached the mastered band, at
                    <?= (int) MASTERY_MASTERED_AT ?>% or above.</li>
                <li>EduFlex changed which topic it thinks you should practise next.</li>
              </ul>
              <p class="ef-muted" style="font-size:11.5px;margin-top:14px;line-height:1.6;">
                Nothing is written when you open a page. Each of these is recorded by the
                action that caused it, so the count only moves when something actually
                happened.
              </p>
            </section>

            <section class="ef-card ef-card-sky">
              <span class="ef-eyebrow">No preferences yet</span>
              <p class="ef-second" style="font-size:12.5px;margin-top:8px;line-height:1.6;">
                EduFlex has no notification settings to turn on or off. Rather than show
                you switches that do nothing, there are none. All four events above are
                always recorded, and nothing is emailed to you.
              </p>
            </section>
          </div>
        </div>
      </div>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
