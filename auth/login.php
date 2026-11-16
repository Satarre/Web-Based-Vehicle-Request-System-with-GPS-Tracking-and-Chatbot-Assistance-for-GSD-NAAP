<?php
/**
 * auth/login.php — branded login portal.
 * POSTs to login_auth.php; renders session errors.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();
$error = $_SESSION['login_error'] ?? null;
$resetDone = !empty($_SESSION['reset_done']);
$verifiedDone = !empty($_SESSION['verified_done']);
unset($_SESSION['login_error'], $_SESSION['reset_done'], $_SESSION['verified_done']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · NAAP GSD Trip Ticket</title>
<link rel="preload" as="image" href="<?= BASE_URL ?>assets/img/loginhero1.webp" media="(min-width:1024px)" fetchpriority="high">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/tailwind.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/tailwind.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<script>
(function(){var t=localStorage.getItem('naap_vts_theme')
 ||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
 if(t==='dark')document.documentElement.setAttribute('data-theme','dark');
 function syncLogos(){
   var dark=document.documentElement.hasAttribute('data-theme');
   document.querySelectorAll('.logo-main').forEach(function(el){el.style.display=dark?'none':'block';});
   document.querySelectorAll('.logo-dark').forEach(function(el){el.style.display=dark?'block':'none';});
 }
 if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',syncLogos);}
 else{syncLogos();}
 window.addEventListener('storage',syncLogos);
})();</script>
</head>
<body class="<?= APP_ENV !== 'prod' ? 'dev-build' : '' ?>">
<div class="page-wrap">
  <div class="login-hero relative overflow-hidden max-lg:hidden flex flex-col items-center justify-center gap-5 p-10 text-center text-white">
    <!-- Background slideshow: crossfades every 5s (JS below) -->
    <div class="absolute inset-0 z-0">
      <img alt="" data-src="<?= BASE_URL ?>assets/img/loginhero1.webp" fetchpriority="high" decoding="async"
           class="hero-slide absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ease-in-out"
           style="opacity:1">
      <img alt="" data-src="<?= BASE_URL ?>assets/img/loginhero2.webp" decoding="async"
           class="hero-slide absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ease-in-out"
           style="opacity:0">
      <img alt="" data-src="<?= BASE_URL ?>assets/img/loginhero3.webp" decoding="async"
           class="hero-slide absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ease-in-out"
           style="opacity:0">
    </div>
    <!-- Navy veil so white hero text stays readable -->
    <div class="login-hero-veil"></div>
    <!-- Hero content sits above the veil -->
    <div class="login-hero-content">
      <img src="<?= BASE_URL ?>assets/img/naap_logo_white.webp" alt="NAAP seal" class="h-28 w-auto">
      <h1 class="text-3xl font-extrabold tracking-tight max-w-lg">General Services Department<br>Digital Trip Ticket System</h1>
    </div>
  </div>
  <div class="flex-1 flex items-center justify-center p-6">
    <div class="login-card">
      <img src="<?= BASE_URL ?>assets/img/VTS_main.webp" alt="VTS logo" class="logo-main block h-11 mx-auto mb-3.5">
      <img src="<?= BASE_URL ?>assets/img/VTS_white.webp" alt="VTS logo" class="logo-dark block h-11 mx-auto mb-3.5" style="display:none">
      <h2 class="text-center text-xl font-bold mb-1">Sign In</h2>
      <p class="text-center text-sm text-text-muted mt-0 mb-5">
        National Aviation Academy of the Philippines · GSD
      </p>

      <?php if ($resetDone): ?>
        <div class="alert alert-info text-center">Password updated — you can sign in now.</div>
      <?php endif; ?>

      <?php if ($verifiedDone): ?>
        <div class="alert alert-info text-center">Email address verified — you can sign in now.</div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error text-center"><?= nl2br(htmlspecialchars($error)) ?></div>
      <?php endif; ?>

      <form method="post" action="login_auth.php" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
          <div class="mb-4">
            <label class="form-label">Email</label>
            <input type="email" name="email" required placeholder="you@naap.gov.ph" autofocus autocomplete="username" class="form-input">
          </div>
          <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="relative">
              <input type="password" name="password" id="pw" required placeholder="••••••••" autocomplete="current-password" class="form-input pr-10">
              <button type="button" id="pwToggle" class="icon-btn icon-btn-bare absolute inset-y-0 right-1.5 my-auto" aria-label="Show password" tabindex="-1">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
              </button>
            </div>
          </div>
          <button class="btn btn-primary w-full" type="submit" name="login_btn" value="1">Sign In</button>
        </form>
        <p class="mt-4 text-center">
          <a href="forget_otp.php" class="text-sm">Forgot password?</a>
          · <a href="verify_email.php" class="text-sm">Verify email</a>
        </p>
    </div>
  </div>
</div>
<script>const BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script>
// Hero slideshow — crossfades to the next image every 5 seconds, looping forever.
// Layering: the incoming slide is raised above the outgoing one BEFORE fading in,
// so the outgoing image (still fully visible below) masks the transparency dip;
// once the fade completes, the old slide fades out underneath.
(function(){
  /* The hero is display:none below 1024px (max-lg:hidden). Skip fetching the
   * ~426 KB of slides on tablets/phones — the preload is media-scoped too. */
  if (!window.matchMedia || !matchMedia('(min-width:1024px)').matches) return;
  var slides = document.querySelectorAll('.hero-slide');
  if (slides.length < 2) return;
  /* All slides start with only data-src (no src) so nothing paints early;
   * attach them once the page has load'ed, before they're shown. */
  function hydrate(el){ if (el && el.dataset.src && !el.getAttribute('src')) el.src = el.dataset.src; }
  hydrate(slides[0]);
  window.addEventListener('load', function(){
    setTimeout(function(){ hydrate(slides[1]); }, 300);
    setTimeout(function(){ hydrate(slides[2]); }, 1200);
  });
  var i = 0;
  setInterval(function(){
    var prev = i;
    i = (i + 1) % slides.length;
    var nextEl = slides[i];
    hydrate(nextEl);
    nextEl.style.zIndex = 2;              // stack above all other slides
    nextEl.style.opacity = 1;             // fade in over 1s
    setTimeout(function(){
      slides[prev].style.opacity = 0;     // fade out underneath
      setTimeout(function(){
        slides.forEach(function(s){ s.style.zIndex = ''; }); // restore natural order
      }, 1100);
    }, 1100);
  }, 5000);
})();
</script>
<script src="<?= BASE_URL ?>assets/js/error_logger.js"></script>
<script>
(function(){
  var pw = document.getElementById('pw');
  var btn = document.getElementById('pwToggle');
  if (!pw || !btn) return;
  btn.addEventListener('click', function(){
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.innerHTML = show
      ? '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4 4l16 16" stroke="currentColor" stroke-width="2"/></svg>'
      : '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
  });
})();
</script>
</body>
</html>
