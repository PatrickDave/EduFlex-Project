<?php
/**
 * EduFlex — Help and FAQ
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/legal.php';
auth_require_login();

$user   = auth_user();
$active = 'help';
$title  = 'Help and FAQ';

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
/* Appendix R 7.3, Browse Help and FAQ.

   A page of its own rather than a card at the bottom of Support. A learner
   looking for an answer and a learner reporting a problem want different
   things, and burying the answers under a form meant most people never saw
   them. Support now links here instead of repeating it.

   Every answer is written out. Nothing on this page is generated, so it costs
   no provider calls and says the same thing every time it is read. */
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Help and FAQ</h2>
            <p>How EduFlex works, and the questions learners ask most</p>
          </div>
        </div>

        <section class="ef-card ef-card-lg">
          <div class="ef-card-title" style="margin-bottom:6px;">Getting started</div>
          <div class="ef-card-sub" style="margin-bottom:16px;">
            Four steps, driven by material you already have
          </div>
          <ol class="ef-help-steps">
            <li>
              <strong>Upload your material.</strong>
              Open the AI Learning Companion and attach a PDF, DOCX or TXT.
              EduFlex reads it and splits it into sections.
            </li>
            <li>
              <strong>Find the topics.</strong>
              EduFlex works out what the document covers and lists each topic
              separately, so your progress is tracked per topic rather than per file.
            </li>
            <li>
              <strong>Practise.</strong>
              Generate a set of questions on any topic, or take a mock
              examination spanning your weakest few.
            </li>
            <li>
              <strong>Watch the mastery move.</strong>
              Every scored answer updates that topic's mastery. Monitor Scores
              and Growth Insights show where you are strong and where you are not.
            </li>
          </ol>
        </section>

        <section class="ef-card ef-card-lg">
          <div class="ef-card-title" style="margin-bottom:6px;">Common questions</div>
          <div class="ef-card-sub" style="margin-bottom:16px;">
            Answered here so you do not have to ask
          </div>

          <?php
          /* <details> rather than a JavaScript accordion: it opens with the
             keyboard, it is searchable with the browser's own find, and it
             still works if the script fails to load. */
          $faq = [
              'EduFlex cannot read my PDF.' =>
                  'A scanned page is an image, and there is no text in it to extract. '
                . 'Open the file and try to select a sentence with your cursor. If you '
                . 'cannot, EduFlex cannot either. Uploading the same material as a DOCX '
                . 'usually works.',

              'Why does my topic say "No data"?' =>
                  'A topic needs ' . (int) MASTERY_MIN_ITEMS . ' scored answers before '
                . 'EduFlex will put a number on it. Fewer than that is not enough '
                . 'evidence to call a mastery score honest, so it says so instead of '
                . 'guessing.',

              'How is my mastery score worked out?' =>
                  'It is arithmetic, not a model. Each answer is weighted by the '
                . 'Bloom\'s Taxonomy level of the question and by how recent it is, so '
                . 'a recent answer counts for more than an old one and a harder '
                . 'question counts for more than an easier one. The whole history is '
                . 'recalculated each time, so the number cannot drift.',

              'What do the mastery bands mean?' =>
                  'Mastered is ' . (int) MASTERY_MASTERED_AT . ' per cent and above, '
                . 'developing is ' . (int) MASTERY_DEVELOPING_AT . ' to '
                . ((int) MASTERY_MASTERED_AT - 1) . ', and weak is below '
                . (int) MASTERY_DEVELOPING_AT . '. Under '
                . (int) MASTERY_MIN_ITEMS . ' scored answers you get "No data" rather '
                . 'than a band.',

              'Why does the companion say it cannot answer?' =>
                  'By design. The companion answers only from the material you '
                . 'uploaded. If your document does not cover something, it says so '
                . 'instead of filling the gap from general knowledge, and it shows '
                . 'which passages it used. An answer you cannot trace back to your own '
                . 'reviewer is not useful for studying.',

              'A generated question was wrong.' =>
                  'Please report it from the Support page, choosing "A generated '
                . 'question or answer was wrong" and quoting the question. EduFlex '
                . 'checks every generated question before storing it, but the check '
                . 'cannot catch everything, and these reports are what the project '
                . 'team uses to improve it.',

              'Does any of this affect my real grades?' =>
                  'No. EduFlex is a study aid. Nothing in it is reported to any '
                . 'instructor and nothing contributes to an official grade.',

              'Who can see what I upload?' =>
                  'You, and the project team, who can read the database as part of '
                . 'running the study. No other learner can see your materials, your '
                . 'conversations, your answers or your scores. The Privacy Policy sets '
                . 'this out in full.',

              'Can I get my data out, or delete it?' =>
                  'Yes, both, from Settings, without asking anybody. Export gives you '
                . 'a JSON file of everything stored about you. Delete removes your '
                . 'account and all of it permanently, and it cannot be undone.',
          ];
          ?>

          <div class="ef-help-faq">
            <?php foreach ($faq as $question => $answer): ?>
              <details class="ef-help-item">
                <summary><?= e($question) ?></summary>
                <p><?= e($answer) ?></p>
              </details>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="ef-card ef-card-sky">
          <span class="ef-eyebrow">Still stuck?</span>
          <p class="ef-second" style="font-size:13px;margin:8px 0 14px;">
            If none of the above answers it, send the project team a request and
            it will be read. Nobody is on duty, so expect an answer when the team
            next reviews requests rather than straight away.
          </p>
          <a class="ef-btn ef-btn-primary" href="support.php">Go to Support</a>
        </section>

        <section class="ef-card ef-card-lg ef-legal-inline">
          <div class="ef-card-title" style="margin-bottom:6px;">Who to contact</div>
          <div class="ef-card-sub" style="margin-bottom:14px;">
            A real person, for anything this page does not answer
          </div>
          <?= legal_contact_block(true) ?>
        </section>

      </div>


    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
