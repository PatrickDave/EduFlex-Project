<?php
/**
 * The animated "AI at work" background behind the sign-in and sign-up cards.
 *
 * Added 20 November 2026 at Patrick's request, for login.php, register.php and
 * admin_login.php only. Included as the first thing inside <body>; the page
 * then adds the class "has-ai-bg" to .ef-auth-page so its own gradient turns
 * transparent and this layer shows through.
 *
 * Three kinds of motion, each in the cheapest medium for it:
 *
 *   CSS / SVG  energy waves, circuit traces with light running along them,
 *              floating holographic outlines, soft glows. Transform, opacity
 *              and dash offset only, so the browser can composite them.
 *   Canvas     the neural network: drifting nodes that connect and disconnect,
 *              signals travelling along the links, and a brain-shaped cluster
 *              that pulses. Driven by initAiBackground() in eduflex.js.
 *   Pointer    parallax. eduflex.js eases --ef-ai-x / --ef-ai-y toward the
 *              mouse, and each layer moves by its own depth.
 *
 * The whole layer is aria-hidden and pointer-events: none, so it can never
 * take a click or a tap meant for the form. Nothing here is fetched from
 * anywhere (CLAUDE.md rule 3); there is no image, only drawing.
 *
 * Under prefers-reduced-motion the CSS stops and the canvas draws one still
 * frame of the network, so the page keeps the look without the movement.
 */

declare(strict_types=1);

/* Two variants, chosen by the including page with $aiBgVariant:
 *
 *   'auth'     (default) fixed behind the whole sign-in or sign-up page, with
 *              the brain beside the card.
 *   'landing'  index.php's hero only, added 20 November 2026. Contained in
 *              the hero rather than fixed, because the landing page is long
 *              and a network behind the methodology tables would hurt
 *              reading. No brain: the hero's robot is already the centrepiece,
 *              and the brain's place beside a centred card does not exist in a
 *              two-column hero. The veil sits behind the headline instead.
 */
$aiBgVariant = ($aiBgVariant ?? 'auth') === 'landing' ? 'landing' : 'auth';
?>
<div class="ef-ai-bg<?= $aiBgVariant === 'landing' ? ' is-contained' : '' ?>"
     data-variant="<?= $aiBgVariant ?>"
     data-brain="<?= $aiBgVariant === 'landing' ? 'off' : 'on' ?>"
     aria-hidden="true">

  <!-- Soft light, slowly breathing. Deepest layer: barely moves with the mouse. -->
  <div class="ef-ai-layer" style="--depth: 6;">
    <div class="ef-ai-glow ef-ai-glow-1"></div>
    <div class="ef-ai-glow ef-ai-glow-2"></div>
    <div class="ef-ai-glow ef-ai-glow-3"></div>
  </div>

  <!-- Faint grid, the "holographic floor". -->
  <div class="ef-ai-layer ef-ai-grid" style="--depth: 4;"></div>

  <!-- Energy waves along the bottom. Each SVG is two periods wide and slides
       left by exactly one period, so the loop has no seam. -->
  <div class="ef-ai-layer ef-ai-waves" style="--depth: 10;">
    <svg class="ef-ai-wave ef-ai-wave-1" viewBox="0 0 2880 320" preserveAspectRatio="none">
      <defs>
        <linearGradient id="efWaveA" x1="0" x2="1" y1="0" y2="0">
          <stop offset="0" stop-color="#38BDF8" stop-opacity=".0"/>
          <stop offset=".5" stop-color="#22D3EE" stop-opacity=".55"/>
          <stop offset="1" stop-color="#2563EB" stop-opacity=".0"/>
        </linearGradient>
      </defs>
      <path d="M0 180 C 240 120 480 240 720 180 S 1200 120 1440 180 S 1920 240 2160 180 S 2640 120 2880 180"
            fill="none" stroke="url(#efWaveA)" stroke-width="2"/>
    </svg>
    <svg class="ef-ai-wave ef-ai-wave-2" viewBox="0 0 2880 320" preserveAspectRatio="none">
      <defs>
        <linearGradient id="efWaveB" x1="0" x2="0" y1="0" y2="1">
          <stop offset="0" stop-color="#38BDF8" stop-opacity=".16"/>
          <stop offset="1" stop-color="#38BDF8" stop-opacity="0"/>
        </linearGradient>
      </defs>
      <path d="M0 200 C 360 140 720 260 1440 200 S 2520 140 2880 200 V320 H0 Z" fill="url(#efWaveB)"/>
    </svg>
    <svg class="ef-ai-wave ef-ai-wave-3" viewBox="0 0 2880 320" preserveAspectRatio="none">
      <path d="M0 220 C 240 260 480 170 720 220 S 1200 260 1440 220 S 1920 170 2160 220 S 2640 260 2880 220"
            fill="none" stroke="#2563EB" stroke-opacity=".22" stroke-width="1.2" stroke-dasharray="2 10"/>
    </svg>
  </div>

  <!-- Circuit traces in two corners. The faint copy is the board; the bright,
       short-dashed copy on top is the light running along it. -->
  <div class="ef-ai-layer ef-ai-circuit ef-ai-circuit-tr" style="--depth: 14;">
    <svg viewBox="0 0 420 300" fill="none">
      <g class="ef-ai-trace-base">
        <path d="M420 40 H300 L270 70 H160 L130 100 H40"/>
        <path d="M420 110 H330 L300 140 H210"/>
        <path d="M420 190 H350 L320 220 H250 L220 250 H120"/>
        <path d="M300 40 V0 M210 140 V190 M120 250 V300"/>
      </g>
      <g class="ef-ai-trace-run">
        <path d="M420 40 H300 L270 70 H160 L130 100 H40" style="--d: 0s;"/>
        <path d="M420 110 H330 L300 140 H210" style="--d: 1.6s;"/>
        <path d="M420 190 H350 L320 220 H250 L220 250 H120" style="--d: 3.1s;"/>
      </g>
      <g class="ef-ai-trace-pads">
        <circle cx="40" cy="100" r="4"/><circle cx="210" cy="140" r="4"/>
        <circle cx="120" cy="250" r="4"/><circle cx="160" cy="70" r="3"/>
        <circle cx="300" cy="0" r="3"/><circle cx="210" cy="190" r="3"/>
      </g>
    </svg>
  </div>
  <div class="ef-ai-layer ef-ai-circuit ef-ai-circuit-bl" style="--depth: 14;">
    <svg viewBox="0 0 420 300" fill="none">
      <g class="ef-ai-trace-base">
        <path d="M0 80 H110 L140 110 H260 L290 140 H380"/>
        <path d="M0 170 H90 L120 200 H220"/>
        <path d="M0 250 H150 L180 220 H300"/>
        <path d="M260 110 V60 M220 200 V260"/>
      </g>
      <g class="ef-ai-trace-run">
        <path d="M0 80 H110 L140 110 H260 L290 140 H380" style="--d: .8s;"/>
        <path d="M0 170 H90 L120 200 H220" style="--d: 2.4s;"/>
        <path d="M0 250 H150 L180 220 H300" style="--d: 3.9s;"/>
      </g>
      <g class="ef-ai-trace-pads">
        <circle cx="380" cy="140" r="4"/><circle cx="220" cy="200" r="4"/>
        <circle cx="300" cy="220" r="4"/><circle cx="260" cy="60" r="3"/>
        <circle cx="220" cy="260" r="3"/>
      </g>
    </svg>
  </div>

  <!-- The neural network itself. Drawn by eduflex.js; this is only the surface. -->
  <canvas class="ef-ai-canvas"></canvas>

  <!-- Holographic outlines, floating. Nearest layer: moves most with the mouse. -->
  <div class="ef-ai-layer ef-ai-holo" style="--depth: 26;">
    <svg class="ef-ai-shape ef-ai-hex" viewBox="0 0 100 100" fill="none">
      <defs>
        <linearGradient id="efHolo" x1="0" x2="1" y1="0" y2="1">
          <stop offset="0" stop-color="#38BDF8"/>
          <stop offset=".5" stop-color="#22D3EE"/>
          <stop offset="1" stop-color="#2563EB"/>
        </linearGradient>
      </defs>
      <polygon points="50,4 90,27 90,73 50,96 10,73 10,27" stroke="url(#efHolo)" stroke-width="1.6"/>
      <polygon points="50,22 74,36 74,64 50,78 26,64 26,36" stroke="url(#efHolo)" stroke-width="1" opacity=".5"/>
    </svg>
    <svg class="ef-ai-shape ef-ai-ring" viewBox="0 0 100 100" fill="none">
      <circle cx="50" cy="50" r="44" stroke="url(#efHolo)" stroke-width="1.4" stroke-dasharray="6 9"/>
      <circle cx="50" cy="50" r="30" stroke="url(#efHolo)" stroke-width="1" opacity=".55"/>
      <circle cx="50" cy="6" r="3" fill="#22D3EE"/>
    </svg>
    <svg class="ef-ai-shape ef-ai-cube" viewBox="0 0 100 100" fill="none"
         stroke="url(#efHolo)" stroke-width="1.4" stroke-linejoin="round">
      <path d="M50 10 L86 30 L50 50 L14 30 Z"/>
      <path d="M14 30 V70 L50 90 V50 M86 30 V70 L50 90"/>
    </svg>
    <svg class="ef-ai-shape ef-ai-tri" viewBox="0 0 100 100" fill="none">
      <polygon points="50,8 94,86 6,86" stroke="url(#efHolo)" stroke-width="1.4"/>
      <circle cx="50" cy="60" r="8" stroke="url(#efHolo)" stroke-width="1"/>
    </svg>
  </div>

  <!-- Small digital fragments rising and fading. -->
  <div class="ef-ai-layer ef-ai-bits" style="--depth: 18;">
    <span style="--x: 8%;  --s: 0s;   --t: 14s;">+</span>
    <span style="--x: 17%; --s: 5s;   --t: 18s;">01</span>
    <span style="--x: 29%; --s: 9s;   --t: 16s;">&lt;/&gt;</span>
    <span style="--x: 71%; --s: 2s;   --t: 17s;">+</span>
    <span style="--x: 80%; --s: 7s;   --t: 15s;">10</span>
    <span style="--x: 91%; --s: 11s;  --t: 19s;">{ }</span>
    <span style="--x: 63%; --s: 13s;  --t: 20s;">+</span>
    <span style="--x: 39%; --s: 3.5s; --t: 21s;">AI</span>
  </div>

  <!-- A gentle veil over the middle, so whatever drifts behind the card's edges
       never competes with the form. -->
  <div class="ef-ai-veil"></div>
</div>
