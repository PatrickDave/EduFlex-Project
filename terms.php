<?php
/**
 * EduFlex — Terms of Service.
 *
 * Public, for the same reason privacy.php is: the registration form asks a
 * visitor to agree to this before they have an account, so it has to be
 * readable without one.
 *
 * Written to be honest about what a capstone prototype is rather than to
 * imitate the terms of a commercial product. A panel is more likely to respect
 * a page that says "this may lose your data and nobody is on duty" than one
 * that claims warranties the project cannot back.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/legal.php';

security_harden_error_output();
security_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terms of Service — EduFlex</title>
<link href="assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="assets/css/eduflex.css" rel="stylesheet">
</head>
<body class="ef-public">

<nav class="ef-topnav">
  <a href="index.php" class="ef-logo">EduFlex</a>
  <a href="index.php" class="ef-second" style="font-size:13px;font-weight:500;">Back to Home</a>
</nav>

<main class="ef-legal">

  <p class="ef-legal-eyebrow">Terms of Service</p>
  <h1>What EduFlex is, and what it is not</h1>
  <p class="ef-legal-lead">
    Plain terms for a student research prototype. Read section 3 even if you read nothing
    else: it is the one that affects how you should use what EduFlex tells you.
  </p>
  <p class="ef-legal-date">In effect from <?= e(LEGAL_EFFECTIVE) ?>.</p>

  <?= legal_notice() ?>

  <section>
    <h2>1. What you are using</h2>
    <p>
      EduFlex is a capstone research prototype built by BS Information Technology students
      at the University of Cebu Main Campus, College of Computer Studies. It is coursework
      and part of a study. It is not a commercial service, there is nothing to pay, there
      is no subscription, and no part of it will ever ask you for money.
    </p>
    <p>
      By creating an account you agree to these terms and to the
      <a href="privacy.php">Privacy Policy</a>.
    </p>
  </section>

  <section>
    <h2>2. What it does</h2>
    <p>
      You upload material you are studying. EduFlex reads the text, works out what topics
      it covers, generates practice questions from it, scores your answers, tracks a
      mastery figure per topic, and answers questions about that same material through a
      chat companion.
    </p>
  </section>

  <section class="ef-legal-important">
    <h2>3. What it generates can be wrong</h2>
    <p>
      The questions, the answers marked correct, the explanations and the companion's
      replies are produced by a language model. A language model can be confidently wrong.
      EduFlex checks every generated question before storing it and discards ones that are
      broken in ways it can detect, and the companion is built to answer only from your own
      material and to say so when your material does not cover something. Neither of those
      makes the output reliable.
    </p>
    <p><strong>So:</strong></p>
    <ul>
      <li>Check anything that matters against your own notes, your textbook and your
        instructor.</li>
      <li>If EduFlex marks you wrong and you believe you were right, you may well have
        been. Report it through Support; those reports are how the validation gets
        better.</li>
      <li>Do not use EduFlex as your only source for anything you are being assessed on.</li>
    </ul>
    <p>
      Nothing in EduFlex is reviewed by an instructor before you see it, and no score in
      EduFlex reaches any official grade.
    </p>
  </section>

  <section>
    <h2>4. Your account</h2>
    <ul>
      <li>Give accurate details when you register. One account per person.</li>
      <li>Your password is yours to keep safe. Anybody who has it can read everything in
        your account.</li>
      <li>
        There is no password reset, because EduFlex deliberately sends no email. If you are
        signed in you can change your password in Settings. If you are locked out entirely,
        the only remedy is a new account.
      </li>
      <li>Do not use somebody else's account, and do not let somebody else use yours.</li>
    </ul>
  </section>

  <section>
    <h2>5. The material you upload</h2>
    <ul>
      <li>
        Upload only material you are entitled to upload: your own notes, handouts you were
        given, things you have permission to use. Uploading is how the material reaches a
        language model, which is set out in section 4 of the
        <a href="privacy.php">Privacy Policy</a>.
      </li>
      <li>
        Do not upload anything confidential, anything containing other people's personal
        information, or anything an examination body has told you not to share.
      </li>
      <li>
        You keep whatever rights you already had in your material. Uploading it gives the
        project no ownership of it. It is used to run EduFlex for you, and for nothing
        else.
      </li>
      <li>
        EduFlex reads PDF, DOCX and plain text. A scanned page is an image with no text in
        it, and EduFlex cannot read it.
      </li>
    </ul>
  </section>

  <section>
    <h2>6. Using it fairly</h2>
    <p>Do not:</p>
    <ul>
      <li>Try to reach another learner's account, material or answers.</li>
      <li>Attack, overload or probe the system, or try to get it to run code.</li>
      <li>Upload anything illegal, or anything designed to damage the system or the people
        using it.</li>
      <li>Use the companion to have your assessed work written for you. That is between
        you and your institution's academic honesty rules, and those rules apply to this
        exactly as they apply to any other tool.</li>
    </ul>
  </section>

  <section class="ef-legal-important">
    <h2>7. It is a prototype, and it may lose your work</h2>
    <p>Said plainly because it is true and it affects you:</p>
    <ul>
      <li>There is no guarantee it will be available, and no guarantee it will work.</li>
      <li>
        It may be taken offline, reset or rebuilt at any point, including during the study.
        <strong>Your data could be lost without warning.</strong> Keep your own copy of any
        material you upload, and use Export my data if your scores matter to you.
      </li>
      <li>
        Nobody is on duty. Support requests are stored for the project team to read when
        they next look, and there is no promise of a reply or of a timescale.
      </li>
      <li>
        It is provided as it is, with no warranty of any kind. The project team is not
        liable for any loss arising from using it, including lost work, lost time or a
        result you got by relying on something it generated.
      </li>
    </ul>
  </section>

  <section>
    <h2>8. Taking part in the study, and stopping</h2>
    <p>
      Using EduFlex as a participant is voluntary. You may stop at any time, without giving
      a reason and without any penalty, academic or otherwise. Deleting your account in
      Settings is how you withdraw, and it removes everything belonging to you
      permanently. Export first if you want a copy.
    </p>
  </section>

  <section>
    <h2>9. Ending an account</h2>
    <ul>
      <li>You can delete yours at any time from Settings. It is immediate and cannot be
        undone.</li>
      <li>
        The project team may remove an account that is being used to attack the system or
        to harm other participants. Where it is possible to warn you first, it will.
      </li>
      <li>The whole system will eventually be shut down: it is a capstone project with an
        end date.</li>
    </ul>
  </section>

  <section>
    <h2>10. Changes to these terms</h2>
    <p>
      These terms may change as the prototype changes. The date at the top moves when they
      do. Anything that materially affects participants will be told to participants
      directly rather than only posted here.
    </p>
  </section>

  <section>
    <h2>11. Asking about any of this</h2>
    <p>
      Write to <?= legal_value(LEGAL_CONTACT_EMAIL) ?>, or use
      <a href="app/support.php">Contact Support</a> if you have an account.
      The researcher accountable for this system is <?= legal_value(LEGAL_RESEARCHER) ?>,
      supervised by <?= legal_value(LEGAL_ADVISER) ?>.
    </p>
  </section>

  <p class="ef-legal-foot">
    See also the <a href="privacy.php">Privacy Policy</a>, which sets out exactly what is
    stored about you and how to get it back or remove it.
  </p>

</main>

<?php include __DIR__ . '/partials/public_footer.php'; ?>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/eduflex.js"></script>
</body>
</html>
