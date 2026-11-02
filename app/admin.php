<?php
/**
 * EduFlex — Help Desk
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';
admin_require();
auth_require_login();

$user   = auth_user();
$active = 'admin';
$title  = 'Help Desk';

/* The project team's account has no learning of its own.
 *
 * eduflex54@gmail.com is a shared address, not a participant: it has uploaded
 * nothing, answered nothing and has no mastery to look at, so every learner
 * screen would show it an empty state that means nothing. Patrick asked on
 * 2 November 2026 that it stay out of the learner area entirely.
 *
 * There used to be an exception for Settings, because that was the only place
 * the team could change its own password. app/admin_settings.php does that now,
 * so the exception is gone and the separation is complete: no learner page at
 * all, not even one.
 *
 * This runs before any output, so redirect() can still fire. The reverse
 * direction, a learner reaching an admin page, is held by admin_require() in
 * the requires slot above. */
if ($user !== null
    && admin_is_admin($user)
    && !str_starts_with($active, 'admin')) {
    redirect('admin.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — EduFlex</title>
<link href="../assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="<?= asset('../assets/css/eduflex.css') ?>" rel="stylesheet">
</head>
<body>

<div class="ef-shell">

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="ef-main">

<?php include __DIR__ . '/../partials/topbar.php'; ?>

<?php
/* Help desk, the landing screen. admin_require() ran in the gate above. */
$counts     = admin_status_counts();
$typeCounts = admin_type_counts();
$waiting    = admin_open_count();
$recent     = admin_requests('', '', 5);
$ok         = flash_get('admin_ok');
$err        = flash_get('admin_error');
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Help desk</h2>
            <p>What learners have sent the project team, and what is still waiting</p>
          </div>
        </div>

        <?php if ($ok): ?>
          <div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div>
        <?php endif; ?>

        <section class="ef-card ef-card-lg ef-admin-headline">
          <div>
            <div class="ef-admin-headline-n"><?= (int) $waiting ?></div>
            <div class="ef-admin-headline-l">
              <?= $waiting === 1 ? 'request waiting on you' : 'requests waiting on you' ?>
            </div>
          </div>
          <a class="ef-btn ef-btn-primary" href="admin_requests.php">Manage requests</a>
        </section>

        <div class="ef-admin-counts">
          <?php foreach (SUPPORT_STATUSES as $code): ?>
            <div class="ef-admin-count">
              <div class="ef-admin-count-n"><?= (int) ($counts[$code] ?? 0) ?></div>
              <div class="ef-admin-count-l"><?= e(support_status_label($code)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <section class="ef-card ef-card-lg">
          <div class="ef-card-title" style="margin-bottom:4px;">By kind</div>
          <div class="ef-card-sub" style="margin-bottom:14px;">
            What learners are actually writing in about
          </div>
          <?php foreach (SUPPORT_TYPES as $key => $unused): ?>
            <?php $n = (int) ($typeCounts[$key] ?? 0); ?>
            <div class="ef-admin-typerow">
              <span><?= e(support_type_label($key)) ?></span>
              <span class="ef-admin-typerow-n"><?= $n ?></span>
            </div>
          <?php endforeach; ?>
          <p class="ef-second" style="font-size:12px;margin-top:12px;">
            The last two are Appendix R 7.4 and 7.5.
            <a href="admin_feedback.php">Manage feedback</a>.
          </p>
        </section>

        <div class="ef-page-head" style="margin-top:22px;">
          <div><h2 style="font-size:17px;">Latest</h2></div>
        </div>

        <?php if ($recent === []): ?>
          <div class="ef-empty-state">
            <div class="ef-empty-ico"></div>
            <p><strong>Nothing yet.</strong></p>
            <p class="ef-second">
              No learner has sent a request. Submit one from the Support page as
              a learner to see this fill in.
            </p>
          </div>
        <?php else: ?>
          <?php $statusFilter = ''; $typeFilter = ''; $showActions = false; ?>
          <?php foreach ($recent as $r): ?>
            <?php include __DIR__ . '/../partials/admin_request.php'; ?>
          <?php endforeach; ?>
          <p class="ef-second" style="font-size:12px;">
            <a href="admin_requests.php">See all <?= (int) ($counts['total'] ?? 0) ?></a>
          </p>
        <?php endif; ?>

      </div>


    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
