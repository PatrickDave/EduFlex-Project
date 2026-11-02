<?php
/**
 * EduFlex — Support
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/legal.php';
auth_require_login();

$user   = auth_user();
$active = 'support';
$title  = 'Support';

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
$uid      = (int) $user['user_id'];
$ok       = flash_get('support_ok');
$err      = flash_get('support_error');
$errors   = flash_get('support_errors') ?: [];
$draft    = flash_get('support_draft') ?: [];
$requests = support_list($uid);

/* The experience box below the request list. Its own flash keys, so a failed
   request and a failed feedback cannot overwrite one another's message. */
$feedbackOk    = flash_get('feedback_ok');
$feedbackError = flash_get('feedback_error');
$feedbackDraft = (string) (flash_get('feedback_draft') ?? '');

$draftType    = (string) ($draft['request_type'] ?? '');
$draftSubject = (string) ($draft['subject'] ?? '');
$draftMessage = (string) ($draft['message'] ?? '');
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Support</h2>
            <p>Tell the project team what went wrong, and see what you have already sent</p>
          </div>
        </div>

        <?php if ($ok): ?><div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div><?php endif; ?>
        <?php if (!empty($errors['form'])): ?>
          <div class="ef-alert ef-alert-error"><?= e((string) $errors['form']) ?></div>
        <?php endif; ?>

        <div class="ef-alert ef-alert-info">
          EduFlex is a capstone prototype, not a product. There is no helpdesk on duty.
          What you send here is stored against your account for the project team to read,
          and you will see it in the list below. Nothing is emailed to anybody.
        </div>

        <div class="ef-row">
          <div class="ef-col ef-stack">

            <!-- New request -->
            <form class="ef-card ef-card-lg" action="actions/support_submit.php" method="post">
              <?= csrf_field() ?>
              <div class="ef-card-title" style="margin-bottom:6px;">Send a request</div>
              <div class="ef-card-sub" style="margin-bottom:18px;">
                The more precisely you describe what you did and what happened, the more
                use it is to the team.
              </div>

              <div class="ef-field">
                <label class="ef-label" for="request_type">What is this about</label>
                <select class="ef-input<?= isset($errors['request_type']) ? ' is-invalid' : '' ?>"
                        id="request_type" name="request_type" required>
                  <option value="">Choose one</option>
                  <?php foreach (SUPPORT_TYPES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $draftType === $key ? ' selected' : '' ?>>
                      <?= e($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if (isset($errors['request_type'])): ?>
                  <div class="ef-error"><?= e((string) $errors['request_type']) ?></div>
                <?php endif; ?>
              </div>

              <div class="ef-field">
                <label class="ef-label" for="subject">Subject</label>
                <input class="ef-input<?= isset($errors['subject']) ? ' is-invalid' : '' ?>"
                       id="subject" name="subject" type="text" maxlength="255"
                       value="<?= e($draftSubject) ?>"
                       placeholder="One line, for example: practice set would not load" required>
                <?php if (isset($errors['subject'])): ?>
                  <div class="ef-error"><?= e((string) $errors['subject']) ?></div>
                <?php endif; ?>
              </div>

              <div class="ef-field">
                <label class="ef-label" for="message">Details</label>
                <textarea class="ef-input<?= isset($errors['message']) ? ' is-invalid' : '' ?>"
                          id="message" name="message" rows="6"
                          maxlength="<?= (int) SUPPORT_MESSAGE_MAX ?>"
                          placeholder="What were you doing, what did you expect, and what happened instead?"
                          required><?= e($draftMessage) ?></textarea>
                <?php if (isset($errors['message'])): ?>
                  <div class="ef-error"><?= e((string) $errors['message']) ?></div>
                <?php endif; ?>
              </div>

              <button class="ef-btn ef-btn-primary" type="submit">Record this request</button>
            </form>

            <!-- This learner's own requests -->
            <section class="ef-card ef-card-lg">
              <div class="ef-card-head">
                <div class="ef-card-title">Your requests</div>
                <span class="ef-muted" style="font-size:11.5px;">
                  <?= count($requests) ?> sent
                </span>
              </div>

              <?php if (!$requests): ?>
                <div class="ef-empty-state">
                  <div class="ef-empty-ico"></div>
                  <h5>You have not sent anything</h5>
                  <p>
                    Requests you send appear here with their status, so you can see what
                    the team has looked at. Nothing is shown to other learners.
                  </p>
                </div>
              <?php else: ?>
                <?php foreach ($requests as $r): ?>
                  <?php $status = (string) $r['status']; ?>
                  <div class="ef-list-row ef-notif">
                    <span class="ef-list-ico">#<?= (int) $r['request_id'] ?></span>
                    <div class="ef-list-main">
                      <div class="ef-notif-head">
                        <span class="ef-list-title"><?= e((string) $r['subject']) ?></span>
                        <span class="ef-band ef-band-<?= e(support_status_band($status)) ?>">
                          <?= e(support_status_label($status)) ?>
                        </span>
                        <span class="ef-list-meta" style="margin-top:0;">
                          <?= e(date('j M Y', strtotime((string) $r['created_at']))) ?>
                        </span>
                      </div>
                      <div class="ef-list-meta">
                        <?= e(support_type_label((string) $r['request_type'])) ?>
                      </div>
                      <div class="ef-notif-body"><?= nl2br(e((string) $r['message'])) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>

              <?php /* Appendix R 7.5, Provide System Feedback.
                       Separate from the request form above on purpose. A
                       request is "something is wrong, please look"; this is
                       "here is how using EduFlex felt", which is what Chapter V
                       actually needs. Stored as an ordinary suggestion so it
                       lands in the team's Manage Feedback screen with
                       everything else: no second table, no second queue to
                       forget about. */ ?>
              <section class="ef-card ef-card-lg">
                <div class="ef-card-title" style="margin-bottom:6px;">
                  How has EduFlex been for you?
                </div>
                <div class="ef-card-sub" style="margin-bottom:14px;">
                  Not a problem report. Tell the team what worked, what did not,
                  and what you would change.
                </div>

                <?php if ($feedbackOk): ?>
                  <div class="ef-alert ef-alert-ok"><?= e((string) $feedbackOk) ?></div>
                <?php endif; ?>
                <?php if ($feedbackError): ?>
                  <div class="ef-alert ef-alert-error"><?= e((string) $feedbackError) ?></div>
                <?php endif; ?>

                <form action="actions/feedback_submit.php" method="post">
                  <?= csrf_field() ?>
                  <div class="ef-field">
                    <label class="ef-label" for="experience">Your experience</label>
                    <textarea class="ef-input" id="experience"
                              name="experience" rows="5"
                              minlength="<?= SUPPORT_MESSAGE_MIN ?>"
                              maxlength="<?= SUPPORT_MESSAGE_MAX ?>"
                              placeholder="The practice questions were useful, but..."
                              required><?= e($feedbackDraft) ?></textarea>
                    <p class="ef-note">
                      At least <?= SUPPORT_MESSAGE_MIN ?> characters. Your name is
                      attached, and only the project team can read it.
                    </p>
                  </div>
                  <button class="ef-btn ef-btn-primary" type="submit">Send feedback</button>
                </form>
              </section>
          </div>

          <div class="ef-col-fixed-360 ef-stack">

            <?php
            /* The "Who to Contact" block from the approved consent form. It sits
               above the FAQ because a participant who wants a person should not
               have to scroll past a list of answers to find one. Rendered by
               legal_contact_block() so this and privacy.php cannot disagree
               about a phone number. */
            ?>
            <section class="ef-card ef-card-lg ef-legal-inline">
              <div class="ef-card-title" style="margin-bottom:6px;">Who to contact</div>
              <div class="ef-card-sub" style="margin-bottom:14px;">
                A real person, for anything this page does not answer
              </div>
              <?= legal_contact_block(true) ?>
            </section>

            <?php /* The answers themselves live on app/help.php, Appendix R 7.3.
                     They used to be repeated here, and two copies of the same
                     text is two places to update and one place to forget. */ ?>
              <section class="ef-card ef-card-lg">
                <div class="ef-card-title" style="margin-bottom:6px;">Before you write</div>
                <div class="ef-card-sub" style="margin-bottom:14px;">
                  Most questions are already answered
                </div>
                <p class="ef-second" style="font-size:13px;margin-bottom:14px;">
                  Why a PDF will not read, what "No data" means, how mastery is
                  worked out, and why the companion sometimes refuses to answer.
                </p>
                <a class="ef-btn ef-btn-ghost" href="help.php">Browse Help and FAQ</a>
              </section>

            <section class="ef-card ef-card-sky">
              <span class="ef-eyebrow">Responsible AI</span>
              <p class="ef-second" style="font-size:12.5px;margin-top:8px;line-height:1.6;">
                EduFlex answers only from material you upload, and it says so when your
                material does not contain the answer. If it ever answers confidently
                from something you did not give it, that is a defect worth reporting.
              </p>
            </section>
          </div>
        </div>
      </div>

    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
