<?php
require_once __DIR__ . '/includes/auth.php';

// Jika belum login, arahkan ke login.php
if (!currentUser()) {
    header('Location: login.php');
    exit;
}

$targetUrl = 'dashboard.php';
if (!empty($_GET['to'])) {
    $allowed = ['dashboard.php', 'scorecard.php', 'baseline.php', 'portofolio.php', 'mitra_manage.php', 'gate0.php', 'early_warning.php', 'tindak_lanjut.php', 'laporan.php', 'users_list.php'];
    if (in_array($_GET['to'], $allowed, true)) {
        $targetUrl = $_GET['to'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Processing Request — Mitra Kinerja</title>

  <!-- Google Fonts: Roboto (300, 400, 500, 700, 900) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">

  <!-- Font Awesome 6.5.2 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer">

  <style>
    *, *::before, *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #ffffff;
      --text: #000000;
      --subtext: #8e8e93;
      --track: #f0f0f3;
      --bar: #000000;
      --accent: #182F7C;
      --speed-line: rgba(0, 0, 0, 0.12);
      --speed-line-ambient: rgba(0, 0, 0, 0.05);
      --font-main: "Roboto", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    [data-theme="dark"] {
      --bg: #090d16;
      --text: #ffffff;
      --subtext: #94a3b8;
      --track: rgba(255, 255, 255, 0.12);
      --bar: #ffffff;
      --speed-line: rgba(255, 255, 255, 0.22);
      --speed-line-ambient: rgba(255, 255, 255, 0.08);
    }

    html, body {
      width: 100%;
      height: 100vh;
      height: 100dvh;
      overflow: hidden;
      background-color: var(--bg);
      color: var(--text);
      font-family: var(--font-main);
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      user-select: none;
      transition: background-color 0.3s ease, color 0.3s ease;
    }

    /* Fullscreen Viewport Container */
    .loading-container {
      position: relative;
      width: 100%;
      height: 100vh;
      height: 100dvh;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: clamp(24px, 4vh, 48px) clamp(24px, 4vw, 56px);
      z-index: 10;
    }

    /* Ambient Supersonic Flying Streaks in Background */
    .ambient-streaks {
      position: absolute;
      inset: 0;
      pointer-events: none;
      overflow: hidden;
      z-index: 1;
    }

    .ambient-streak {
      position: absolute;
      height: 1px;
      background: linear-gradient(90deg, transparent 0%, var(--speed-line-ambient) 50%, transparent 100%);
      will-change: transform;
      animation: ambientFly linear infinite;
    }

    .ambient-streak:nth-child(1) { top: 22%; width: 220px; animation-duration: 1.6s; animation-delay: 0.1s; }
    .ambient-streak:nth-child(2) { top: 38%; width: 340px; animation-duration: 2.1s; animation-delay: 0.8s; }
    .ambient-streak:nth-child(3) { top: 64%; width: 180px; animation-duration: 1.4s; animation-delay: 0.4s; }
    .ambient-streak:nth-child(4) { top: 78%; width: 280px; animation-duration: 1.9s; animation-delay: 1.1s; }

    @keyframes ambientFly {
      0% {
        transform: translate3d(120vw, 0, 0);
        opacity: 0;
      }
      20% {
        opacity: 0.7;
      }
      80% {
        opacity: 0.7;
      }
      100% {
        transform: translate3d(-50vw, 0, 0);
        opacity: 0;
      }
    }

    /* Top Header Bar */
    .loading-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      z-index: 5;
    }

    .brand-group {
      display: inline-flex;
      align-items: center;
      gap: 12px;
    }

    .brand-icon-box {
      width: 28px;
      height: 28px;
      background: var(--text);
      color: var(--bg);
      border-radius: 6px;
      display: grid;
      place-items: center;
      font-size: 13px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .brand-title {
      font-size: 15px;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--text);
    }

    .telemetry-group {
      display: inline-flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 4px;
    }

    .telemetry-chip-icon {
      font-size: 16px;
      color: var(--text);
      opacity: 0.85;
    }

    .telemetry-latency {
      font-size: 10.5px;
      font-weight: 700;
      letter-spacing: 0.14em;
      color: var(--subtext);
      text-transform: uppercase;
    }

    /* Center Stage (Supersonic Jetpack Runner + Typography + Loader) */
    .loading-center {
      margin: auto 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      z-index: 5;
    }

    /* Supersonic Flight Stage */
    .flight-stage {
      position: relative;
      width: 260px;
      height: 80px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .runner-wrap {
      position: relative;
      display: inline-block;
      animation: runnerHover 1.8s ease-in-out infinite;
      will-change: transform;
    }

    @keyframes runnerHover {
      0%, 100% {
        transform: translateY(0px) rotate(0deg);
      }
      50% {
        transform: translateY(-5px) rotate(-1deg);
      }
    }

    /* Dynamic Exhaust & Speed Lines */
    .speed-lines {
      position: absolute;
      top: 50%;
      left: -65px;
      transform: translateY(-50%);
      width: 90px;
      height: 40px;
      pointer-events: none;
    }

    .speed-line {
      position: absolute;
      height: 2px;
      background: var(--text);
      border-radius: 2px;
      will-change: transform, opacity;
      animation: exhaustDash 0.75s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }

    .speed-line-1 {
      top: 10px;
      left: 10px;
      width: 32px;
      animation-duration: 0.55s;
      animation-delay: 0s;
    }

    .speed-line-2 {
      top: 18px;
      left: -20px;
      width: 45px;
      animation-duration: 0.7s;
      animation-delay: 0.15s;
    }

    .speed-line-3 {
      top: 28px;
      left: 5px;
      width: 38px;
      animation-duration: 0.6s;
      animation-delay: 0.08s;
    }

    @keyframes exhaustDash {
      0% {
        transform: translate3d(25px, 0, 0) scaleX(0.4);
        opacity: 0;
      }
      25% {
        opacity: 0.9;
        transform: translate3d(0px, 0, 0) scaleX(1);
      }
      80% {
        opacity: 0.8;
      }
      100% {
        transform: translate3d(-35px, 0, 0) scaleX(0.6);
        opacity: 0;
      }
    }

    /* Silhouette SVG */
    .runner-svg {
      width: 175px;
      height: 52px;
      overflow: visible;
      fill: var(--text);
    }

    /* Typography */
    .loading-title {
      font-size: clamp(24px, 3.2vw, 32px);
      font-weight: 900;
      letter-spacing: -0.02em;
      color: var(--text);
      text-transform: uppercase;
      margin-top: 36px;
      line-height: 1.1;
      transition: opacity 0.25s ease;
    }

    .loading-subtitle {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.16em;
      color: var(--subtext);
      text-transform: uppercase;
      margin-top: 10px;
      transition: color 0.25s ease;
    }

    /* Horizontal Progress Track & Indicator */
    .progress-track {
      position: relative;
      width: clamp(220px, 25vw, 300px);
      height: 2.5px;
      background: var(--track);
      border-radius: 999px;
      margin-top: 22px;
      overflow: hidden;
    }

    .progress-bar {
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      width: 0%;
      background: var(--bar);
      border-radius: 999px;
      transition: width 0.12s linear;
    }

    /* Bottom Footer Bar */
    .loading-footer {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 5px;
      z-index: 5;
    }

    .system-status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--subtext);
    }

    .status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background-color: #10b981;
      box-shadow: 0 0 8px rgba(16, 185, 129, 0.7);
      display: inline-block;
      animation: dotPulse 1.8s ease-in-out infinite;
    }

    @keyframes dotPulse {
      0%, 100% {
        transform: scale(1);
        opacity: 1;
      }
      50% {
        transform: scale(1.35);
        opacity: 0.65;
      }
    }

    .protocol-text {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--subtext);
      opacity: 0.7;
    }

    /* Fade-out exit effect saat redirect selesai */
    body.redirecting {
      opacity: 0;
      transform: scale(1.02);
      transition: opacity 0.4s cubic-bezier(0.22, 1, 0.36, 1), transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    }
  </style>

  <!-- Sinkronisasi Theme dengan Preference Pengguna -->
  <script>
  (function() {
    try {
      var savedTheme = localStorage.getItem('mitra_theme') || 'light';
      document.documentElement.setAttribute('data-theme', savedTheme);
    } catch(e) {}
  })();
  </script>
</head>
<body>
  <!-- Ambient Speed Streaks Background -->
  <div class="ambient-streaks" aria-hidden="true">
    <div class="ambient-streak"></div>
    <div class="ambient-streak"></div>
    <div class="ambient-streak"></div>
    <div class="ambient-streak"></div>
  </div>

  <div class="loading-container">
    <!-- 1. Header (Logo & Telemetry Latency) -->
    <header class="loading-header">
      <div class="brand-group">
        <div class="brand-icon-box" aria-hidden="true">
          <i class="fa-solid fa-bolt"></i>
        </div>
        <span class="brand-title">VELOCITY</span>
      </div>

      <div class="telemetry-group">
        <div class="telemetry-chip-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="4" width="16" height="16" rx="2"></rect>
            <rect x="9" y="9" width="6" height="6"></rect>
            <path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"></path>
          </svg>
        </div>
        <div class="telemetry-latency">
          LATENCY: <span id="latencyNum">14</span>MS
        </div>
      </div>
    </header>

    <!-- 2. Center Stage (Supersonic Jetpack Runner, Title, Subtitle, Progress Bar) -->
    <main class="loading-center">
      <div class="flight-stage" aria-hidden="true">
        <div class="runner-wrap">
          <!-- Animated Exhaust / Speed Lines -->
          <div class="speed-lines">
            <span class="speed-line speed-line-1"></span>
            <span class="speed-line speed-line-2"></span>
            <span class="speed-line speed-line-3"></span>
          </div>

          <!-- Supersonic Jetpack Silhouette SVG (Sesuai Persis dengan Foto Referensi) -->
          <svg class="runner-svg" viewBox="0 0 200 60" xmlns="http://www.w3.org/2000/svg">
            <!-- Trailing thruster lines behind jetpack -->
            <line x1="28" y1="21" x2="68" y2="21" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            <line x1="74" y1="21" x2="98" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <line x1="104" y1="21" x2="132" y2="21" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>

            <!-- Jetpack Thruster Capsule on Back -->
            <rect x="134" y="17" width="33" height="8" rx="4" fill="currentColor"/>

            <!-- Aerodynamic Visor Head / Helmet (flat right vertical edge) -->
            <path d="M 179,22 
                     C 179,19 183,16 188,16 
                     C 193,16 196,19 196,23 
                     L 196,30 
                     C 196,32 194,33 192,33 
                     L 186,33 
                     C 181,33 179,29 179,25 Z" fill="currentColor"/>

            <!-- Aerodynamic Prone Body with Swept Wings and Needle Tip -->
            <path d="M 182,28 
                     C 182,36 177,43 164,43 
                     C 152,43 128,42 68,41 
                     C 106,39 136,38 152,36 
                     L 116,27 
                     C 134,27 154,28 168,27 
                     C 175,27 180,26 182,28 Z" fill="currentColor"/>
          </svg>
        </div>
      </div>

      <!-- Typography -->
      <h1 class="loading-title" id="loadingTitle">PROCESSING REQUEST</h1>
      <p class="loading-subtitle" id="loadingSubtitle">SYNCHRONIZING WITH GLOBAL NEURAL NETWORKS</p>

      <!-- Horizontal Progress Bar -->
      <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="progressTrack">
        <div class="progress-bar" id="progressBar"></div>
      </div>
    </main>

    <!-- 3. Bottom Footer (Systems Nominal & Vector Protocol) -->
    <footer class="loading-footer">
      <div class="system-status">
        <span class="status-dot"></span>
        <span>SYSTEMS NOMINAL</span>
      </div>
      <div class="protocol-text">
        X-RAY DELTA 4.0 // VECTOR PROTOCOL
      </div>
    </footer>
  </div>

  <script>
    (function() {
      var targetUrl = <?= json_encode($targetUrl) ?>;
      var progressBar = document.getElementById('progressBar');
      var progressTrack = document.getElementById('progressTrack');
      var loadingTitle = document.getElementById('loadingTitle');
      var loadingSubtitle = document.getElementById('loadingSubtitle');
      var latencyNum = document.getElementById('latencyNum');

      // 1. Telemetry latency fluctuation (12ms - 16ms)
      var latencyTimer = setInterval(function() {
        if (latencyNum) {
          latencyNum.textContent = Math.floor(12 + Math.random() * 5);
        }
      }, 700);

      // 2. High-precision Progress Acceleration (Durasi total ~2.2 detik)
      var startTime = performance.now();
      var duration = 2100; // 2.1 detik agar responsif dan memukau

      var steps = [
        { pct: 0.35, title: "PROCESSING REQUEST", subtitle: "SYNCHRONIZING WITH GLOBAL NEURAL NETWORKS" },
        { pct: 0.70, title: "AUTHENTICATING ACCESS", subtitle: "VERIFYING CREDENTIALS & WORKSPACE PERMISSIONS" },
        { pct: 0.95, title: "REQUEST AUTHORIZED", subtitle: "INITIALIZING DASHBOARD WORKSPACE..." }
      ];

      function updateLoader(now) {
        var elapsed = now - startTime;
        var progress = Math.min(elapsed / duration, 1);

        // Smooth cubic-bezier ease out curve
        var easeProgress = 1 - Math.pow(1 - progress, 2.4);
        var currentPct = Math.round(easeProgress * 100);

        if (progressBar) {
          progressBar.style.width = currentPct + '%';
        }
        if (progressTrack) {
          progressTrack.setAttribute('aria-valuenow', currentPct);
        }

        // Update step status text
        if (progress < 0.38) {
          loadingTitle.textContent = steps[0].title;
          loadingSubtitle.textContent = steps[0].subtitle;
        } else if (progress < 0.78) {
          loadingTitle.textContent = steps[1].title;
          loadingSubtitle.textContent = steps[1].subtitle;
        } else {
          loadingTitle.textContent = steps[2].title;
          loadingSubtitle.textContent = steps[2].subtitle;
        }

        if (progress < 1) {
          requestAnimationFrame(updateLoader);
        } else {
          clearInterval(latencyTimer);
          // Redirect transition
          setTimeout(function() {
            document.body.classList.add('redirecting');
            setTimeout(function() {
              window.location.href = targetUrl;
            }, 300);
          }, 150);
        }
      }

      requestAnimationFrame(updateLoader);
    })();
  </script>
</body>
</html>
