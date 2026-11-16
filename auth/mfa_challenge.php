<?php
/**
 * auth/mfa_challenge.php — standalone two-factor challenge for a pending login.
 * Accepts an authenticator (TOTP) code or a single-use recovery code.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

if (empty($_SESSION['mfa_pending_user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int) $_SESSION['mfa_pending_user_id'];
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

$st = $pdo->prepare('SELECT email, full_name, mfa_enabled, mfa_secret FROM account WHERE account_id = ? AND is_active = 1');
$st->execute([$uid]);
$acct = $st->fetch();

// Account gone / MFA disabled mid-challenge -> back to credentials.
if (!$acct || (int) $acct['mfa_enabled'] !== 1 || empty($acct['mfa_secret'])) {
    unset($_SESSION['mfa_pending_user_id']);
    header('Location: login.php');
    exit;
}

$useRecovery = ($_GET['mode'] ?? '') === 'recovery';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Two-Factor · NAAP GSD Trip Ticket</title>
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
    <h2 class="text-xl font-bold mb-1">Two-Factor Authentication</h2>
    <p class="text-text-muted text-md mt-0 mb-5">
      <?= $useRecovery
        ? 'Enter one of your unused recovery codes.'
        : 'Enter the 6-digit code from your authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password, or any compatible app).' ?>
    </p>

    <?php if ($error): ?>
      <div class="alert alert-error mb-3.5 text-center"><?= nl2br(htmlspecialchars($error)) ?></div>
    <?php endif; ?>

    <form method="post" action="mfa_verify.php" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
      <div class="mb-4">
        <label class="form-label"><?= $useRecovery ? 'Recovery Code' : '6-Digit Code' ?></label>
        <?php if ($useRecovery): ?>
          <input type="text" name="otp_code" pattern="[A-Za-z0-9]{4}-[A-Za-z0-9]{4}|[A-Za-z0-9]{8}"
                 maxlength="9" placeholder="XXXX-XXXX" required autofocus autocomplete="one-time-code"
                 class="form-input text-center tracking-25 text-xl font-bold" autocapitalize="characters">
        <?php else: ?>
          <input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                 placeholder="000000" required autofocus autocomplete="one-time-code"
                 class="form-input text-center tracking-40 text-xl font-bold">
        <?php endif; ?>
      </div>
      <button class="btn btn-primary w-full" type="submit" name="verify_btn" value="1">Verify &amp; Sign In</button>
    </form>

    <p class="mt-3.5 text-center">
      <?php if ($useRecovery): ?>
        <a href="<?= BASE_URL ?>auth/mfa_challenge.php" class="text-md">← Use authenticator code instead</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>auth/mfa_challenge.php?mode=recovery" class="text-md">Lost your phone? Use a recovery code</a>
      <?php endif; ?>
    </p>
    <p class="mt-1 text-center">
      <a href="<?= BASE_URL ?>auth/login_cancel_mfa.php" class="text-md">← Back to login</a>
    </p>
  </div>
</div>
<script>const BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/error_logger.js"></script>
</body>
</html>