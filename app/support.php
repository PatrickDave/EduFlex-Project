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
auth_require_login();

$user   = auth_user();
$active = 'support';
$title  = 'Support';
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
$uid      = (int) $user['user_id'];
$ok       = flash_get('support_ok');
$err      = flash_get('support_error');
$errors   = flash_get('support_errors') ?: [];
$draft    = flash_get('support_draft') ?: [];
$requests = support_list($uid);

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
          </div>

          <div class="ef-col-fixed-360 ef-stack">

            <!-- Static FAQ. Written out, not generated, and not fake tickets. -->
            <section class="ef-card ef-card-lg">
              <div class="ef-card-title" style="margin-bottom:6px;">Common questions</div>
              <div class="ef-card-sub" style="margin-bottom:16px;">
                Answered here so you do not have to ask
              </div>

              <div class="ef-stack" style="gap:16px;">
                <div>
                  <div class="ef-list-title">EduFlex cannot read my PDF.</div>
                  <p class="ef-notif-body">
                    A scanned page is an image, and there is no text in it to extract.
                    Open the file and try to select a sentence with your cursor. If you
                    cannot, EduFlex cannot either.
                  </p>
                </div>
                <div>
                  <div class="ef-list-title">Why does my topic say "No data"?</div>
                  <p class="ef-notif-body">
                    A topic needs <?= (int) MASTERY_MIN_ITEMS ?> scored answers before
                    EduFlex will put a number on it. Fewer than that is not enough
                    evidence to call a mastery score honest.
                  </p>
                </div>
                <div>
                  <div class="ef-list-title">A generated question was wrong.</div>
                  <p class="ef-notif-body">
                    Report it with the "A generated question or answer was wrong" type
                    above, and quote the question. EduFlex checks every generated
                    question against its own material before storing it, but the check
                    cannot catch everything, and these reports are what the team uses to
                    improve it.
                  </p>
                </div>
                <div>
                  <div class="ef-list-title">Does this affect my real grades?</div>
                  <p class="ef-notif-body">
                    No. EduFlex is a study aid. Nothing in it is reported to any
                    instructor and nothing contributes to an official grade.
                  </p>
                </div>
                <div>
                  <div class="ef-list-title">Can I get my data out, or delete it?</div>
                  <p class="ef-notif-body">
                    Yes, both, from Settings, without asking anybody. Export gives you a
                    JSON file of everything stored about you. Delete removes your
                    account and all of it permanently.
                  </p>
                </div>
              </div>
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
<script src="../assets/js/eduflex.js"></script>
</body>
</html>
