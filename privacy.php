<?php
/**
 * EduFlex — Privacy Policy, and the participant information sheet for the
 * study.
 *
 * Public, like index.php: a prospective participant has to be able to read
 * this BEFORE they register, so it must not be behind auth_require_login().
 * That also means it calls security_headers() itself, above the doctype.
 *
 * Everything here about what EduFlex collects and what it does with it was
 * written from the source, not from memory. If you change what the system
 * stores or where it sends it, this page is part of the change. The five facts
 * that cannot come from code live in includes/legal.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/legal.php';
require_once __DIR__ . '/config/ai.php';

security_harden_error_output();
security_headers();

$ai = legal_ai_disclosure();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy Policy — EduFlex</title>
<link href="assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="assets/css/eduflex.css" rel="stylesheet">
</head>
<body class="ef-public">

<nav class="ef-topnav">
  <a href="index.php" class="ef-logo">EduFlex</a>
  <a href="index.php" class="ef-second" style="font-size:13px;font-weight:500;">Back to Home</a>
</nav>

<main class="ef-legal">

  <p class="ef-legal-eyebrow">Privacy Policy and participant information</p>
  <h1>What EduFlex does with your data</h1>
  <p class="ef-legal-lead">
    EduFlex is a student research project, not a product. This page says exactly what it
    stores about you, where that goes, who can see it, and how to take it back. It is
    written to be read before you decide to take part.
  </p>
  <p class="ef-legal-date">In effect from <?= e(LEGAL_EFFECTIVE) ?>.</p>

  <?= legal_notice() ?>

  <section>
    <h2>1. Who is behind this</h2>
    <p>
      EduFlex is a capstone project by BS Information Technology students at the
      University of Cebu Main Campus, College of Computer Studies. It is coursework,
      carried out for a degree. Nobody sells it, nobody advertises on it, and there is no
      company behind it.
    </p>
    <ul>
      <li>Lead researcher: <?= legal_value(LEGAL_RESEARCHER) ?></li>
      <li>Faculty adviser: <?= legal_value(LEGAL_ADVISER) ?></li>
      <li>Ethics clearance: <?= legal_value(LEGAL_ETHICS) ?></li>
      <li>Questions about your data: <?= legal_value(LEGAL_CONTACT_EMAIL) ?></li>
    </ul>
  </section>

  <section>
    <h2>2. What EduFlex stores about you</h2>
    <p>Everything in this list, and nothing outside it.</p>

    <h3>Your account</h3>
    <ul>
      <li>Your name, email address, programme and year level, as you type them.</li>
      <li>
        Your password, hashed with bcrypt. EduFlex never stores, logs or displays the
        password itself, and nobody on the project team can read it or recover it. If you
        forget it, it cannot be sent back to you.
      </li>
      <li>A profile picture, only if you upload one.</li>
    </ul>

    <h3>The material you upload</h3>
    <ul>
      <li>The file itself, on the server's disk.</li>
      <li>
        The text extracted from it, stored in overlapping sections so the system can
        search it. Practice questions and companion answers are built from these.
      </li>
    </ul>

    <h3>What you do with it</h3>
    <ul>
      <li>The topics EduFlex identifies in your material.</li>
      <li>
        Every practice question you are shown, the answer you chose, and whether it was
        marked correct.
      </li>
      <li>
        A mastery score per topic, recalculated from your whole answer history each time
        you finish a set.
      </li>
      <li>Your conversations with the AI companion: your questions and its answers.</li>
      <li>Any support request you send, including its text.</li>
    </ul>

    <h3>Sign-in security</h3>
    <ul>
      <li>
        Failed sign-in attempts only, recorded as the email tried and the IP address it
        came from. This is what stops somebody guessing passwords at your account.
        Successful sign-ins are never recorded, so this is not a log of when you study,
        and a successful sign-in deletes the failed attempts for that address.
      </li>
    </ul>
  </section>

  <section>
    <h2>3. What EduFlex does not do</h2>
    <p>
      Stated because these are the things people reasonably assume a website does.
    </p>
    <ul>
      <li>No analytics, no tracking pixels and no advertising. There is no third party
        watching you use this.</li>
      <li>
        No cookies except one session cookie, which exists so the site knows you are
        signed in. It is deleted when you sign out or close your browser.
      </li>
      <li>
        No requests to any other website. Every font, stylesheet and script is served
        from this server, which is also why EduFlex works with no internet connection.
      </li>
      <li>No email. EduFlex will never send you anything, which also means there is no
        password reset by email.</li>
      <li>Nothing is sold, shared or published. Your data is not used in any way beyond
        running the system and the study it is part of.</li>
      <li>No access to your camera, microphone or location. The browser is instructed to
        refuse them.</li>
    </ul>
  </section>

  <section>
    <h2>4. What leaves the server</h2>
    <?php if ($ai['sends']): ?>
      <p>
        EduFlex uses a language model to work out the topics in your material, to write
        practice questions from it, and to answer your questions about it. Doing that
        means sending parts of your material to the model provider.
      </p>
      <p>This system is currently configured to use
        <strong><?= e($ai['driver']) ?></strong><?php if ($ai['model'] !== ''): ?>,
        model <strong><?= e($ai['model']) ?></strong><?php endif; ?>.
      </p>
      <p>What is sent, and what is not:</p>
      <ul>
        <li>
          <strong>Sent:</strong> excerpts of the material you upload, the topic being
          practised, and the question you type into the companion. For topic detection
          EduFlex samples about twelve sections of a document rather than the whole thing.
          For a companion question it sends at most four passages.
        </li>
        <li>
          <strong>Not sent:</strong> your name, your email address, your password, your
          scores, your profile picture, and any identifier that points back to you. The
          provider receives text from a document with no indication of whose it is.
        </li>
      </ul>
      <p>
        What the provider then does with that text is governed by its own terms, not by
        this page. If your material is confidential, or belongs to somebody who has not
        agreed to this, do not upload it. Section 5 of the
        <a href="terms.php">Terms of Service</a> says the same thing.
      </p>
    <?php else: ?>
      <p>
        <strong>Nothing currently leaves this server.</strong> EduFlex is configured with
        its built-in mock provider, which produces sample questions and answers locally.
        No part of your material is transmitted anywhere.
      </p>
      <p>
        This will change when the project team configures a real language model, which the
        finished system needs in order to generate questions from your material. At that
        point excerpts of what you upload, and the questions you type into the companion,
        would be sent to that provider in order to get a reply. Your name, email,
        password, scores and picture would not be, because the system does not include
        them in what it sends.
      </p>
      <p class="ef-legal-note">
        This paragraph is generated from the system's actual configuration rather than
        written by hand, so it describes the server you are reading it on.
      </p>
    <?php endif; ?>
  </section>

  <section>
    <h2>5. Who can see your data</h2>
    <ul>
      <li>
        <strong>You.</strong> Every screen shows only your own material, topics, scores
        and conversations. The system asks the database for data belonging to your account
        specifically, on every single query, rather than filtering it afterwards.
      </li>
      <li>
        <strong>The project team, through the database.</strong> This is the part worth
        being plain about. EduFlex runs on a server the team administers, and anybody with
        access to that database can read what is in it, including your uploaded text, your
        answers and your companion conversations. That is true of essentially every system
        of this kind, and you should assume it here. The team uses that access to run and
        debug the system, and to produce the aggregate results reported in the study.
      </li>
      <li>
        <strong>Nobody else.</strong> Not other learners, not your instructors, and not
        anybody outside the project.
      </li>
    </ul>
    <p>
      Results reported in the study are aggregate. No individual participant is named or
      identifiable in the manuscript or in any presentation of it.
    </p>
  </section>

  <section>
    <h2>6. Nothing here reaches your grades</h2>
    <p>
      EduFlex is a study aid. Your scores in it are not reported to any instructor, are not
      part of any class record, and do not contribute to any official grade. Practising
      badly in EduFlex costs you nothing, which is the point: it is somewhere to be wrong
      safely.
    </p>
  </section>

  <section>
    <h2>7. What you can do about it</h2>
    <p>
      You can do all of these yourself, from
      <strong>Settings</strong>, without asking anybody and without giving a reason.
    </p>
    <ul>
      <li>
        <strong>See it.</strong> Every screen shows you your own data as the system holds
        it.
      </li>
      <li>
        <strong>Take it with you.</strong> Export my data downloads a single JSON file
        containing your account details, your materials list, every topic with its mastery
        score, and every answer you have given with whether it was marked correct.
      </li>
      <li>
        <strong>Correct it.</strong> Change your name, email, programme, year level,
        password or picture at any time.
      </li>
      <li>
        <strong>Delete it.</strong> Delete my account removes your account, your uploaded
        files, the text extracted from them, every topic and mastery score, every practice
        attempt and answer, your companion conversations, your notifications and your
        support requests. It is immediate and permanent, and the project team cannot undo
        it or recover anything afterwards.
      </li>
      <li>
        <strong>Withdraw.</strong> If you are taking part in the study and want to stop,
        deleting your account is how you withdraw. Export first if you want a copy. You do
        not have to tell anybody, and choosing to withdraw carries no penalty of any kind.
      </li>
    </ul>
  </section>

  <section>
    <h2>8. How long it is kept</h2>
    <p><?= legal_value(LEGAL_RETENTION) ?></p>
    <p>
      Whatever that period is, it does not limit you: deleting your account removes your
      data immediately, at any time, regardless of how long the study has left to run.
    </p>
  </section>

  <section>
    <h2>9. How it is protected</h2>
    <p>
      Honest summary rather than reassurance. These are the measures actually implemented:
    </p>
    <ul>
      <li>Passwords are hashed with bcrypt and are never stored or logged in readable form.</li>
      <li>Every database query uses a prepared statement, so data cannot be read out
        through a crafted input.</li>
      <li>Every form that changes anything carries a token that proves the request came
        from you, on this site.</li>
      <li>Repeated failed sign-ins against your account are refused for fifteen minutes,
        so a password cannot be guessed at speed.</li>
      <li>Your sign-in expires after two hours of inactivity, and after twelve hours
        regardless, so a session left open on a shared machine does not stay usable.</li>
      <li>Uploaded files are stored where the web server will not serve or run them, and
        are checked to be what they claim to be before being accepted.</li>
    </ul>
    <p class="ef-legal-note">
      What this does not claim: EduFlex is a student project running on ordinary
      infrastructure, and it has not been penetration tested by anybody independent. Treat
      it accordingly and do not upload anything genuinely sensitive.
    </p>
  </section>

  <section>
    <h2>10. Changes to this page</h2>
    <p>
      If what EduFlex does with your data changes, this page changes with it and the date
      at the top moves. Material changes affecting participants will be told to
      participants directly rather than only posted here.
    </p>
  </section>

  <section>
    <h2>11. Who to contact</h2>
    <?= legal_contact_block() ?>
    <p>
      You can also write to <?= legal_value(LEGAL_CONTACT_EMAIL) ?>, or use
      <a href="app/support.php">Contact Support</a> if you already have an account. You can
      ask what is held about you, ask for it to be corrected, or ask for it to be removed,
      and you will get an answer.
    </p>
  </section>

  <p class="ef-legal-foot">
    See also the <a href="terms.php">Terms of Service</a>, which covers what EduFlex is,
    what it does not promise, and what is expected of you when you use it.
  </p>

</main>

<?php include __DIR__ . '/partials/public_footer.php'; ?>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/eduflex.js"></script>
</body>
</html>
