<?php
/**
 * EduFlex — public landing page.
 *
 * The only page in the system that does not call auth_boot(), because an
 * anonymous visitor has no reason to be given a session. It therefore has to
 * ask for the security headers itself, and this block must stay above the
 * doctype: a header sent after output has started is silently dropped.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/manuscript.php';

security_harden_error_output();
security_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EduFlex — AI-Powered Personalized Learning Companion</title>
<link href="assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="assets/css/eduflex.css" rel="stylesheet">
</head>
<body class="ef-public">

<!-- ===== Top navigation ================================================= -->
<nav class="ef-topnav">
  <a href="index.php" class="ef-logo">EduFlex</a>

  <div class="ef-topnav-links">
    <a href="#features">Features</a>
    <a href="#methodology">Methodology</a>
    <a href="#how">How it Works</a>
    <a href="#resources">Resources</a>
  </div>

  <div class="ef-topnav-right">
    <a href="login.php" class="ef-second" style="font-size:15px;font-weight:500;">Sign in</a>
    <a href="register.php" class="ef-btn ef-btn-primary">Get Started</a>
  </div>
</nav>

<!-- ===== Hero =========================================================== -->
<header class="ef-hero">

  <div>
    <span class="ef-badge">Revolutionizing Education</span>

    <h1>Your AI <span class="ef-accent">Learning Companion</span></h1>

    <p class="ef-hero-sub">
      Unlock personalized growth with EduFlex. Turn your own lecture notes, reviewers
      and slides into practice activities, feedback and a clear view of what to study next.
    </p>

    <div class="ef-hero-ctas">
      <a href="register.php" class="ef-btn ef-btn-primary ef-btn-lg">Get Started for Free</a>
      <a href="#how" class="ef-btn ef-btn-ghost ef-btn-lg">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
          <path d="M6.5 5.5v5l4-2.5-4-2.5z" fill="currentColor"/>
        </svg>
        See How it Works
      </a>
    </div>
  </div>

  <?php
  /*
   | AI assistant illustration. Pure SVG so it scales and recolours with tokens,
   | and so it needs no image file and no network request.
   |
   | Every moving part is a class, never an inline animation: the motion lives in
   | eduflex.css section 14b and the cursor tracking in eduflex.js initLanding().
   | That matters because all of it has to switch off under
   | prefers-reduced-motion, and it can only do that from one place.
   |
   | data-bot is what the script looks for. Without JavaScript the robot still
   | renders and still breathes, because the idle motion is pure CSS; only the
   | eye and head tracking need the script.
   */
  ?>
  <div class="ef-hero-visual" data-hero-visual>

    <svg viewBox="0 0 600 420" role="img" aria-label="EduFlex AI assistant robot"
         class="ef-bot" data-bot>
      <defs>
        <filter id="botShadow" x="-30%" y="-30%" width="160%" height="160%">
          <feDropShadow dx="0" dy="10" stdDeviation="13" flood-color="#0B3C5A" flood-opacity="0.16"/>
        </filter>
      </defs>

      <!-- glow + orbit -->
      <ellipse class="ef-bot-glow" cx="300" cy="220" rx="200" ry="200"
               fill="var(--ef-primary-bright)" opacity=".16"/>
      <ellipse class="ef-bot-orbit" cx="300" cy="257" rx="245" ry="107" fill="none"
               stroke="var(--ef-primary-bright)" stroke-width="1.5" opacity=".5"/>

      <!-- Head, ears and antenna move together, so they are one group. -->
      <g class="ef-bot-head">

        <!-- antenna -->
        <circle class="ef-bot-antenna" cx="300" cy="60" r="10" fill="var(--ef-cyan)"/>
        <rect x="298" y="68" width="4" height="26" rx="2" fill="var(--ef-primary)"/>

        <!-- ears -->
        <rect x="201" y="128" width="14" height="34" rx="7" fill="var(--ef-primary)"/>
        <rect x="385" y="128" width="14" height="34" rx="7" fill="var(--ef-primary)"/>

        <!-- head -->
        <g filter="url(#botShadow)">
          <rect x="215" y="92" width="170" height="130" rx="40"
                fill="var(--ef-surface)" stroke="var(--ef-border)" stroke-width="1.5"/>
        </g>
        <rect x="236" y="114" width="128" height="76" rx="24" fill="var(--ef-ink)"/>

        <!-- Both eyes translate together, which is what a real glance does.
             The blink is per eye, so they can be given different timing. -->
        <g class="ef-bot-eyes">
          <rect class="ef-bot-eye" x="266" y="138" width="18" height="24" rx="9" fill="var(--ef-cyan)"/>
          <rect class="ef-bot-eye" x="316" y="138" width="18" height="24" rx="9" fill="var(--ef-cyan)"/>
        </g>

        <rect class="ef-bot-mouth" x="285" y="172" width="30" height="5" rx="2.5" fill="var(--ef-cyan)"/>
      </g>

      <!-- neck + arms -->
      <rect x="285" y="214" width="30" height="18" rx="6" fill="var(--ef-primary)"/>
      <rect class="ef-bot-arm ef-bot-arm-left" x="176" y="246" width="18" height="70" rx="9"
            fill="var(--ef-primary)"/>
      <rect class="ef-bot-arm ef-bot-arm-right" x="406" y="246" width="18" height="70" rx="9"
            fill="var(--ef-primary)"/>

      <!-- body -->
      <g filter="url(#botShadow)">
        <rect x="200" y="228" width="200" height="136" rx="36"
              fill="var(--ef-surface)" stroke="var(--ef-border)" stroke-width="1.5"/>
      </g>
      <rect x="248" y="252" width="104" height="60" rx="20" fill="var(--ef-surface-sky)"/>
      <?php
      /* The three bars read as a document being written. They pulse in sequence,
         which is the one place on the page where the illustration says
         "something is happening" rather than just sitting still.

         These used to be filled with var(--ef-mint-light), along with the mouth
         above. That token does not exist anywhere in eduflex.css, so the browser
         fell back to black and the robot had a black mouth and one black line.
         Now they use --ef-cyan, which is a real token. */
      ?>
      <rect class="ef-bot-line ef-bot-line-1" x="266" y="270" width="68" height="6" rx="3"
            fill="var(--ef-primary)"/>
      <rect class="ef-bot-line ef-bot-line-2" x="266" y="284" width="44" height="6" rx="3"
            fill="var(--ef-primary-bright)"/>
      <rect class="ef-bot-line ef-bot-line-3" x="266" y="298" width="56" height="6" rx="3"
            fill="var(--ef-cyan)"/>
    </svg>

    <div class="ef-float ef-float-tl" data-float data-depth="14">
      <span class="dot" style="background:var(--ef-mastered)"></span>
      Lecture notes analyzed
    </div>

    <div class="ef-float ef-float-br" data-float data-depth="-18">
      <span class="dot" style="background:var(--ef-primary)"></span>
      Bloom level: Apply
    </div>

    <div class="ef-float-card" data-float data-depth="9">
      <span class="dot"></span>
      <div>
        <strong>AI Analysis Active</strong>
        <p>We identified 9 topics in your uploaded reviewer and queued a practice set for the weakest one.</p>
      </div>
    </div>
  </div>
</header>

<!-- ===== How it works =================================================== -->
<section id="how" style="max-width:1440px;margin:0 auto;padding:0 clamp(18px, 5vw, 48px) 90px;">
  <div class="ef-page-head" style="margin-bottom:28px;">
    <div>
      <h2>How EduFlex works</h2>
      <p>Four steps, driven by material you already have.</p>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-md-6 col-lg-3">
      <div class="ef-card h-100">
        <span class="ef-badge">Step 1</span>
        <h3 class="ef-card-title" style="margin:12px 0 6px;">Upload your material</h3>
        <p class="ef-second" style="font-size:13px;">
          Add lecture notes, reviewers or slides as PDF or DOCX. EduFlex extracts the text
          and identifies the topics inside it.
        </p>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="ef-card h-100">
        <span class="ef-badge">Step 2</span>
        <h3 class="ef-card-title" style="margin:12px 0 6px;">Practice what it generates</h3>
        <p class="ef-second" style="font-size:13px;">
          Questions are generated per topic and tagged to a level of Bloom's Taxonomy,
          from Remember through to Create.
        </p>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="ef-card h-100">
        <span class="ef-badge">Step 3</span>
        <h3 class="ef-card-title" style="margin:12px 0 6px;">See where you are weak</h3>
        <p class="ef-second" style="font-size:13px;">
          Every scored answer updates a mastery value per topic. Below 70 percent is
          flagged weak. Under five answers, EduFlex says so instead of guessing.
        </p>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="ef-card h-100">
        <span class="ef-badge">Step 4</span>
        <h3 class="ef-card-title" style="margin:12px 0 6px;">Get the next activity</h3>
        <p class="ef-second" style="font-size:13px;">
          EduFlex picks your lowest-mastery topic and generates the next set at the
          Bloom level you are ready for.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- ===== Features ======================================================= -->
<?php
/* The seven modules of the Chapter III functional decomposition, rendered from
   includes/manuscript.php so this page and the manuscript stay in step. Two
   sub-modules are absent because they are not built; the reason is recorded
   where the array is defined. */
?>
<section id="features" class="ef-section">
  <div class="ef-page-head" style="margin-bottom:28px;">
    <div>
      <h2>What EduFlex does</h2>
      <p>Seven modules, built around material you already have.</p>
    </div>
  </div>

  <div class="ef-module-grid">
    <?php foreach (MANUSCRIPT_MODULES as $i => $module): ?>
      <article class="ef-card ef-module">
        <span class="ef-badge">Module <?= $i + 1 ?></span>
        <h3 class="ef-card-title" style="margin:12px 0 6px;"><?= e($module['title']) ?></h3>
        <p class="ef-second" style="font-size:12.5px;margin-bottom:12px;">
          <?= e($module['blurb']) ?>
        </p>
        <ul class="ef-module-items">
          <?php foreach ($module['items'] as $item): ?>
            <li><?= e($item) ?></li>
          <?php endforeach; ?>
        </ul>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- ===== Methodology ==================================================== -->
<section id="methodology" class="ef-section ef-section-tinted">
  <div class="ef-page-head" style="margin-bottom:28px;">
    <div>
      <h2>Methodology</h2>
      <p>How the study was designed, and how the system decides what you know.</p>
    </div>
  </div>

  <div class="ef-row">
    <div class="ef-col ef-stack">

      <section class="ef-card ef-card-lg">
        <div class="ef-card-title" style="margin-bottom:6px;">How the study was designed</div>
        <div class="ef-card-sub" style="margin-bottom:16px;">
          Chapter III, Design and Methodology
        </div>
        <p class="ef-second" style="font-size:13.5px;line-height:1.7;">
          The research uses both quantitative and qualitative surveys in an exploratory
          stage, followed by an iterative process of development and assessment. Thirty
          selected students of the University of Cebu Main Campus, College of Computer
          Studies were surveyed on their current study practices, their difficulties with
          independent learning, their experience of existing digital learning tools, and
          what they would expect from an AI-powered learning companion.
        </p>
        <p class="ef-second" style="font-size:13.5px;line-height:1.7;">
          Those results defined the user requirements and the key features. The system was
          then designed, developed, and put through functional, integration, performance,
          usability and AI-output testing, before being evaluated by selected students in
          terms of functionality, usability, performance, AI-generated output quality, and
          support for self-directed learning.
        </p>
        <p class="ef-legal-note" style="font-size:12.5px;">
          The survey covered 30 selected students in a single academic environment, so the
          findings are exploratory. They are not generalised to all students or to other
          institutions. EduFlex is a supplementary learning companion: it does not replace
          classroom instruction, an instructor's judgement, or official assessment.
        </p>
      </section>

      <section class="ef-card ef-card-lg">
        <div class="ef-card-title" style="margin-bottom:6px;">How mastery is calculated</div>
        <div class="ef-card-sub" style="margin-bottom:16px;">
          Arithmetic, not a model call. Deterministic, and the same every time.
        </div>
        <p class="ef-second" style="font-size:13.5px;line-height:1.7;">
          Your mastery of a topic is a weighted percentage of the answers you have given on
          it. Two things decide how much an answer counts: the level of thinking it
          demanded, and how recently you gave it.
        </p>

        <pre class="ef-formula">M = ( SUM(w<sub>i</sub> &times; c<sub>i</sub>) / SUM(w<sub>i</sub>) ) &times; 100

w<sub>i</sub> = bloom_weight &times; recency_weight
recency_weight = 1 / (1 + 0.15k)     k = how many answers ago</pre>

        <p class="ef-second" style="font-size:13.5px;line-height:1.7;">
          The Bloom weight follows the revised taxonomy of Anderson and Krathwohl (2001):
          a correct answer at a higher level demonstrates more, so it is worth more.
        </p>

        <div style="overflow-x:auto;">
          <table class="ef-table">
            <thead><tr><th>Bloom level</th><th>Weight</th><th>Band</th><th>Mastery</th></tr></thead>
            <tbody>
              <tr><td>Remember</td><td>1.0</td>
                  <td><span class="ef-band ef-band-mastered">Mastered</span></td>
                  <td>85% and above</td></tr>
              <tr><td>Understand</td><td>1.2</td>
                  <td><span class="ef-band ef-band-developing">Developing</span></td>
                  <td>70% to 84%</td></tr>
              <tr><td>Apply</td><td>1.5</td>
                  <td><span class="ef-band ef-band-weak">Weak</span></td>
                  <td>Below 70%</td></tr>
              <tr><td>Analyze</td><td>1.8</td>
                  <td><span class="ef-band ef-band-none">No data</span></td>
                  <td>Under 5 scored answers</td></tr>
              <tr><td>Evaluate</td><td>2.0</td><td></td><td></td></tr>
              <tr><td>Create</td><td>2.2</td><td></td><td></td></tr>
            </tbody>
          </table>
        </div>

        <p class="ef-legal-note" style="font-size:12.5px;">
          Below five scored answers EduFlex reports "No data" rather than a number, because
          fewer than that is not enough evidence to be honest about. Every figure is
          recomputed from your entire answer history, so it cannot drift.
        </p>
      </section>
    </div>

    <div class="ef-col-fixed-360 ef-stack">
      <section class="ef-card ef-card-lg">
        <div class="ef-card-title" style="margin-bottom:6px;">What the AI is not trusted with</div>
        <div class="ef-card-sub" style="margin-bottom:14px;">Checks that run on every generated item</div>
        <ul class="ef-second" style="font-size:12.5px;line-height:1.7;padding-left:18px;margin:0;">
          <li><strong>Answers are never trusted from the browser.</strong> Correctness is
            decided on the server against the stored answer.</li>
          <li><strong>The companion answers only from your material.</strong> Retrieval runs
            first, only those passages reach the model, and it is told to say plainly when
            they do not contain the answer.</li>
          <li><strong>Citations are verified.</strong> A citation to a passage the model was
            never given is discarded before it reaches the screen.</li>
          <li><strong>Generated questions are validated before storage.</strong> A question
            whose stated answer is not among its own options is discarded, along with ones
            with duplicate options or too few options.</li>
        </ul>
      </section>

      <section class="ef-card ef-card-sky">
        <span class="ef-eyebrow">Mastery is arithmetic</span>
        <p class="ef-second" style="font-size:12.5px;margin-top:8px;line-height:1.6;">
          No language model decides your score. The formula above is the whole of it, which
          is why the same answers always produce the same number and why it can be explained
          rather than trusted.
        </p>
      </section>
    </div>
  </div>
</section>

<!-- ===== Resources ====================================================== -->
<section id="resources" class="ef-section">
  <div class="ef-page-head" style="margin-bottom:24px;">
    <div>
      <h2>Resources</h2>
      <p>The literature this study is built on.</p>
    </div>
  </div>

  <p class="ef-second" style="font-size:13.5px;max-width:72ch;margin-bottom:24px;">
    EduFlex draws on research in self-directed learning, self-regulated learning,
    personalised learning, adaptive learning systems, and artificial intelligence in
    education. These are the works cited in the study.
  </p>

  <ol class="ef-references">
    <?php foreach (MANUSCRIPT_REFERENCES as $reference): ?>
      <li><?= manuscript_reference_html($reference) ?></li>
    <?php endforeach; ?>
  </ol>
</section>

<!-- ===== Responsible AI notice ========================================== -->
<section style="max-width:1440px;margin:0 auto;padding:0 clamp(18px, 5vw, 48px) 80px;">
  <div class="ef-card ef-card-sky" style="display:flex;gap:16px;align-items:flex-start;">
    <span style="width:22px;height:22px;border-radius:50%;background:var(--ef-primary);flex:0 0 auto;margin-top:2px;"></span>
    <div>
      <strong style="font-size:14px;">EduFlex supports your studying. It does not replace it.</strong>
      <p class="ef-second" style="font-size:13px;margin-top:6px;max-width:760px;">
        Activities and explanations are generated by a large language model from the material you
        provide. Output can be incomplete or wrong, so check it against your own notes and your
        instructor. EduFlex never contributes to your official grades.
      </p>
    </div>
  </div>
</section>

<?php include __DIR__ . '/partials/public_footer.php'; ?>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/eduflex.js"></script>
</body>
</html>
