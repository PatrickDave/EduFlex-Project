<?php
/**
 * EduFlex — Manage Feedback
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
$active = 'admin_feedback';
$title  = 'Manage Feedback';

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
/* Appendix R 7.4 "Report Incorrect AI Output" and 7.5 "Provide System
   Feedback". Same table and same form as any other request, read here for a
   different reason: one is a bug queue, the other is evidence for Chapter IV
   about the quality of generated output. */
$statusFilter = (string) ($_GET['status'] ?? '');
$typeFilter   = '';

$items  = admin_feedback($statusFilter);
$counts = admin_type_counts();
$ok     = flash_get('admin_ok');
$err    = flash_get('admin_error');

$keep = static function (string $status): string {
    return $status === '' ? 'admin_feedback.php'
                          : 'admin_feedback.php?' . http_build_query(['status' => $status]);
};
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Manage feedback</h2>
            <p>What learners reported as wrong, and what they suggested</p>
          </div>
        </div>

        <?php if ($ok): ?>
          <div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div>
        <?php endif; ?>

        <div class="ef-admin-counts">
          <?php foreach (ADMIN_FEEDBACK_TYPES as $key): ?>
            <div class="ef-admin-count">
              <div class="ef-admin-count-n"><?= (int) ($counts[$key] ?? 0) ?></div>
              <div class="ef-admin-count-l"><?= e(support_type_label($key)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="ef-admin-filters">
          <span class="ef-admin-filter-label">Status</span>
          <a class="ef-chip<?= $statusFilter === '' ? ' is-on' : '' ?>"
             href="<?= e($keep('')) ?>">All</a>
          <?php foreach (SUPPORT_STATUSES as $code): ?>
            <a class="ef-chip<?= $statusFilter === $code ? ' is-on' : '' ?>"
               href="<?= e($keep($code)) ?>"><?= e(support_status_label($code)) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if ($items === []): ?>
          <div class="ef-empty-state">
            <div class="ef-empty-ico"></div>
            <p><strong>No feedback yet.</strong></p>
            <p class="ef-second">
              Nothing has been reported as wrong and nothing has been suggested.
              A learner sends these from the Support page.
            </p>
          </div>
        <?php else: ?>
          <?php foreach ($items as $r): ?>
            <?php include __DIR__ . '/../partials/admin_request.php'; ?>
          <?php endforeach; ?>
        <?php endif; ?>

        <p class="ef-second" style="font-size:12px;margin-top:18px;">
          An AI-quality report is worth reading against the rubric run in
          docs/rubric-run.md: one is what the validator caught, the other is what
          it did not.
        </p>

      </div>


    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
