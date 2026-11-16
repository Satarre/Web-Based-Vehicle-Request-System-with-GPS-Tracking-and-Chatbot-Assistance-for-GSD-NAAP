<?php
/**
 * auth/mfa_setup.php — forced two-factor setup for accounts whose role policy
 * requires MFA. Runs before the authenticated session is created.
 * TOTP secret is generated here, verified once, then encrypted at rest;
 * recovery codes are shown exactly once before the login completes.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

if (empty($_SESSION['mfa_pending_user_id']) || empty($_SESSION['mfa_setup_required'])) {
    header('Location: login.php');
    exit;
}
$uid = (int) $_SESSION['mfa_pending_user_id'];

$st = $pdo->prepare('SELECT a.*, r.role_name FROM account a LEFT JOIN roles r ON r.role_id = a.role_id WHERE a.account_id = ? AND a.is_active = 1');
$st->execute([$uid]);
$acct = $st->fetch();
if (!$acct) {
    unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_setup_required']);
    header('Location: login.php');
    exit;
}

// Already enabled (e.g. completed setup then session expired) -> finish login.
if (TotpService::isEnabled($pdo, $uid)) {
    unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_setup_required']);
    Auth::finalize($pdo, $acct);
    header('Location: ' . (Auth::LANDING[(int) $acct['role_id']] ?? BASE_URL . 'index.php'));
    exit;
}

$error = null;
$codesOnce = $_SESSION['mfa_setup_codes_once'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired — please try again.';
    } elseif (isset($_POST['continue_btn']) && $codesOnce !== null) {
        unset($_SESSION['mfa_setup_codes_once'], $_SESSION['mfa_pending_user_id'], $_SESSION['mfa_setup_required']);
        $fresh = $pdo->prepare('SELECT a.*, r.role_name FROM account a LEFT JOIN roles r ON r.role_id = a.role_id WHERE a.account_id = ? AND a.is_active = 1');
        $fresh->execute([$uid]);
        $fr = $fresh->fetch();
        if ($fr) {
            Auth::finalize($pdo, $fr);
        }
        header('Location: ' . (Auth::LANDING[(int) $acct['role_id']] ?? BASE_URL . 'index.php'));
        exit;
    } elseif (isset($_POST['verify_btn'])) {
        $secret = (string) ($_SESSION['mfa_setup_secret'] ?? '');
        $code = preg_replace('/\s+/', '', (string) ($_POST['otp_code'] ?? ''));
        if ($secret !== '' && preg_match('/^\d{6}$/', $code) && TOTP::verify($secret, $code)) {
            $encSecret = TotpService::encrypt($secret);
            $_SESSION['mfa_setup_codes_once'] = MfaService::enable($pdo, $uid, $encSecret);
            unset($_SESSION['mfa_setup_secret']);
            $codesOnce = $_SESSION['mfa_setup_codes_once'];
        } else {
            $error = 'Invalid code. Please try again.';
        }
    }
}

if ($codesOnce === null && empty($_SESSION['mfa_setup_secret'])) {
    $_SESSION['mfa_setup_secret'] = TotpService::generateSecret();
}
$secret = (string) ($_SESSION['mfa_setup_secret'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enable Two-Factor · NAAP GSD Trip Ticket</title>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/tailwind.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/tailwind.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<script>
(function(){var t=localStorage.getItem('naap_vts_theme')
 ||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
 if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
</head>
<body class="<?= APP_ENV !== 'prod' ? 'dev-build' : '' ?>">
<div class="auth-wrap">
  <div class="card-login">
    <?php if ($codesOnce !== null): ?>
      <h2 class="text-xl font-bold mb-1">Recovery Codes</h2>
      <p class="text-text-muted text-md mt-0 mb-5">
        Your two-factor authentication is now enabled. Save these single-use recovery codes
        somewhere safe — they let you sign in if you lose your authenticator. <strong>They are shown only once.</strong>
      </p>
      <div class="content-card p-3 mb-3.5" style="max-width:none">
        <?php foreach ($codesOnce as $c): ?>
          <div class="flex items-center justify-between py-1 border-b border-border last:border-0">
            <code class="tracking-15 font-bold"><?= htmlspecialchars($c) ?></code>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
        <div class="flex flex-wrap gap-2">
          <button class="btn btn-ghost flex-1" type="button" id="btnDownloadCodes">Download</button>
          <button class="btn btn-primary flex-1" type="submit" name="continue_btn" value="1">I saved my codes — Continue</button>
        </div>
      </form>
    <?php else: ?>
      <h2 class="text-xl font-bold mb-1">Two-Factor Authentication Required</h2>
      <p class="text-text-muted text-md mt-0 mb-4">
        Your role requires two-factor authentication. Scan the QR code with
        Google Authenticator, Microsoft Authenticator, Authy, 1Password, or any
        compatible authenticator app, then enter the 6-digit code below.
      </p>

      <?php if ($error): ?>
        <div class="alert alert-error mb-3.5 text-center"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="content-card p-3 mb-3.5 text-center" style="max-width:none">
        <div id="mfaQr" class="mx-auto" style="width:200px;height:200px"
             data-uri="<?= htmlspecialchars(TotpService::provisioningUri($secret, TotpService::labelFor((int) $acct['role_id'], (string) $acct['role_name']))) ?>"></div>
        <p class="text-text-muted text-md mt-2 mb-0">Manual entry code (fallback):</p>
        <code class="tracking-20 font-bold text-lg"><?= htmlspecialchars($secret) ?></code>
      </div>

      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
        <div class="mb-4">
          <label class="form-label">6-Digit Code</label>
          <input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                 placeholder="000000" required autofocus autocomplete="one-time-code"
                 class="form-input text-center tracking-40 text-xl font-bold">
        </div>
        <button class="btn btn-primary w-full" type="submit" name="verify_btn" value="1">Verify &amp; Enable</button>
      </form>
    <?php endif; ?>

    <p class="mt-3.5 text-center"><a href="<?= BASE_URL ?>auth/login_cancel_mfa.php" class="text-md">← Back to login</a></p>
  </div>
</div>
<script>const BASE_URL = <?= json_encode(BASE_URL) ?>;
document.getElementById('btnDownloadCodes')?.addEventListener('click', function () {
  const codes = Array.from(document.querySelectorAll('.content-card code')).map(c => c.textContent.trim());
  const blob = new Blob([codes.join('\n')], { type: 'text/plain' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'recovery-codes.txt';
  a.click();
  URL.revokeObjectURL(a.href);
});</script>
<script src="<?= BASE_URL ?>assets/js/vendor/qrcode.js"></script>
<script src="<?= BASE_URL ?>assets/js/qr-render.js"></script>
<script>
(function () {
  var el = document.getElementById('mfaQr');
  if (!el) return;
  if (!window.renderQr(el, el.dataset.uri, 200)) {
    el.textContent = 'QR code unavailable — use the manual entry code below.';
  }
})();
</script>
<script src="<?= BASE_URL ?>assets/js/error_logger.js"></script>
</body>
</html>