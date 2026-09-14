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

  <!-- AI assistant illustration. Pure SVG so it scales and recolours with tokens. -->
  <div class="ef-hero-visual">

    <svg viewBox="0 0 600 420" role="img" aria-label="EduFlex AI assistant robot">
      <defs>
        <filter id="botShadow" x="-30%" y="-30%" width="160%" height="160%">
          <feDropShadow dx="0" dy="10" stdDeviation="13" flood-color="#0B3C5A" flood-opacity="0.16"/>
        </filter>
      </defs>

      <!-- glow + orbit -->
      <ellipse cx="300" cy="220" rx="200" ry="200" fill="var(--ef-primary-bright)" opacity=".16"/>
      <ellipse cx="300" cy="257" rx="245" ry="107" fill="none"
               stroke="var(--ef-primary-bright)" stroke-width="1.5" opacity=".5"/>

      <!-- antenna -->
      <circle cx="300" cy="60" r="10" fill="var(--ef-cyan)"/>
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
      <rect x="266" y="138" width="18" height="24" rx="9" fill="var(--ef-cyan)"/>
      <rect x="316" y="138" width="18" height="24" rx="9" fill="var(--ef-cyan)"/>
      <rect x="285" y="172" width="30" height="5" rx="2.5" fill="var(--ef-mint-light)"/>

      <!-- neck + arms -->
      <rect x="285" y="214" width="30" height="18" rx="6" fill="var(--ef-primary)"/>
      <rect x="176" y="246" width="18" height="70" rx="9" fill="var(--ef-primary)"/>
      <rect x="406" y="246" width="18" height="70" rx="9" fill="var(--ef-primary)"/>

      <!-- body -->
      <g filter="url(#botShadow)">
        <rect x="200" y="228" width="200" height="136" rx="36"
              fill="var(--ef-surface)" stroke="var(--ef-border)" stroke-width="1.5"/>
      </g>
      <rect x="248" y="252" width="104" height="60" rx="20" fill="var(--ef-surface-sky)"/>
      <rect x="266" y="270" width="68" height="6" rx="3" fill="var(--ef-primary)"/>
      <rect x="266" y="284" width="44" height="6" rx="3" fill="var(--ef-primary-bright)"/>
      <rect x="266" y="298" width="56" height="6" rx="3" fill="var(--ef-mint-light)"/>
    </svg>

    <div class="ef-float ef-float-tl">
      <span class="dot" style="background:var(--ef-mastered)"></span>
      Lecture notes analyzed
    </div>

    <div class="ef-float ef-float-br">
      <span class="dot" style="background:var(--ef-primary)"></span>
      Bloom level: Apply
    </div>

    <div class="ef-float-card">
      <span class="dot"></span>
      <div>
        <strong>AI Analysis Active</strong>
        <p>We identified 9 topics in your uploaded reviewer and queued a practice set for the weakest one.</p>
      </div>
    </div>
  </div>
</header>

<!-- ===== How it works =================================================== -->
<section id="how" style="max-width:1440px;margin:0 auto;padding:0 48px 90px;">
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

<!-- ===== Responsible AI notice ========================================== -->
<section style="max-width:1440px;margin:0 auto;padding:0 48px 80px;">
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

<footer class="ef-page-footer">
  <span>&copy; 2026 EduFlex. University of Cebu, College of Computer Studies.</span>
  <span>
    <a href="#">Privacy Policy</a>
    <a href="#">Terms of Service</a>
    <a href="#">Contact Support</a>
  </span>
</footer>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/eduflex.js"></script>
</body>
</html>
