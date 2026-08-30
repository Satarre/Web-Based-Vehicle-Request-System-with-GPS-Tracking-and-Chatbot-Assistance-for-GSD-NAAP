<?php
/**
 * auth/login.php — branded login portal.
 * POSTs to login_auth.php; renders session errors.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();
$error = $_SESSION['login_error'] ?? null;
$mfaPending = !empty($_SESSION['mfa_pending_user_id']);
$resetDone = !empty($_SESSION['reset_done']);
unset($_SESSION['login_error'], $_SESSION['reset_done']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · NAAP GSD Trip Ticket</title>
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
<body>
<div class="page-wrap">
  <div class="login-hero relative overflow-hidden max-lg:hidden flex flex-col items-center justify-center gap-5 p-10 text-center text-white">
    <!-- Background slideshow: crossfades every 5s (JS below) -->
    <div class="absolute inset-0 z-0">
      <img src="<?= BASE_URL ?>assets/img/loginhero1.jpg" alt=""
           class="hero-slide absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ease-in-out"
           style="opacity:1">
      <img src="<?= BASE_URL ?>assets/img/loginhero2.jpg" alt=""
           class="hero-slide absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ease-in-out"
           style="opacity:0">
      <img src="<?= BASE_URL ?>assets/img/loginhero3.jpg" alt=""
           class="hero-slide absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ease-in-out"
           style="opacity:0">
    </div>
    <!-- Navy veil so white hero text stays readable -->
    <div class="login-hero-veil"></div>
    <!-- Hero content sits above the veil -->
    <div class="login-hero-content">
      <img src="<?= BASE_URL ?>assets/img/naap_logo_white.png" alt="NAAP seal" class="h-28 w-auto">
      <h1 class="text-3xl font-extrabold tracking-tight max-w-lg">General Services Department<br>Digital Trip Ticket System</h1>
    </div>
  </div>
  <div class="flex-1 flex items-center justify-center p-6">
    <div class="login-card">
      <img src="<?= BASE_URL ?>assets/img/VTS_main.png" alt="VTS logo" class="logo-main block h-11 mx-auto mb-3.5">
      <img src="<?= BASE_URL ?>assets/img/VTS_white.png" alt="VTS logo" class="logo-dark block h-11 mx-auto mb-3.5" style="display:none">
      <h2 class="text-center text-xl font-bold mb-1">Sign In</h2>
      <p class="text-center text-sm text-text-muted mt-0 mb-5">
        National Aviation Academy of the Philippines · GSD
      </p>

      <?php if ($resetDone): ?>
        <div class="alert alert-info">Password updated — you can sign in now.</div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($mfaPending): ?>
        <!-- MFA challenge (two-factor enabled account) -->
        <form method="post" action="mfa_verify.php" autocomplete="off">
          <div class="mb-4">
            <label class="form-label">Two-Factor Code</label>
            <input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}"
                   maxlength="6" placeholder="000000" required autofocus
                   class="form-input text-center tracking-widest text-xl font-bold">
          </div>
          <button class="btn btn-primary w-full" type="submit">Verify &amp; Sign In</button>
        </form>
        <p class="mt-3.5 text-center">
          <a href="login_cancel_mfa.php" class="text-sm">← Back to login</a>
        </p>
      <?php else: ?>
        <form method="post" action="login_auth.php" autocomplete="off">
          <div class="mb-4">
            <label class="form-label">Email</label>
            <input type="email" name="email" required placeholder="you@naap.gov.ph" autofocus class="form-input">
          </div>
          <div class="mb-4">
            <label class="form-label">Password</label>
            <input type="password" name="password" id="pw" required placeholder="••••••••" class="form-input">
          </div>
          <button class="btn btn-primary w-full" type="submit" name="login_btn" value="1">Sign In</button>
        </form>
        <p class="mt-4 text-center">
          <a href="forget_otp.php" class="text-sm">Forgot password?</a>
        </p>
      <?php endif; ?>
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
  var slides = document.querySelectorAll('.hero-slide');
  if (slides.length < 2) return;
  var i = 0;
  setInterval(function(){
    var prev = i;
    i = (i + 1) % slides.length;
    var nextEl = slides[i];
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
</body>
</html>
