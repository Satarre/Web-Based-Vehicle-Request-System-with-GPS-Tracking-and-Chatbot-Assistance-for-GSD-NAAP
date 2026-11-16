<?php
declare(strict_types=1);
/**
 * auth/forget_pw.php — password reset step 2 (requires a short-lived
 * password-reset session token minted only after a verified OTP).
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

$rawToken = (string) ($_SESSION['pw_reset_token'] ?? '');
if ($rawToken === '') {
    header('Location: ' . BASE_URL . 'auth/forget_otp.php');
    exit;
}

// Resolve the token: must exist, belong to an active account, be unused and live.
$st = $pdo->prepare(
    'SELECT prs.account_id
     FROM password_reset_sessions prs
     JOIN account a ON a.account_id = prs.account_id AND a.is_active = 1
     WHERE prs.token_hash = ? AND prs.used_at IS NULL AND prs.expires_at > NOW()
     LIMIT 1'
);
$st->execute([hash('sha256', $rawToken)]);
$session = $st->fetch();

if (!$session) {
    unset($_SESSION['pw_reset_token']);
    header('Location: ' . BASE_URL . 'auth/forget_otp.php');
    exit;
}
$uid = (int) $session['account_id'];

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired — please try again.';
    } else {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['password2'] ?? '');
        $pwErr = PasswordPolicy::check($p1);
        if ($pwErr !== null) {
            $error = $pwErr;
        } elseif ($p1 !== $p2) {
            $error = 'Passwords do not match.';
        } else {
            // Invalidate the reset token + any OTPs, bump session version to revoke active sessions.
            $pdo->prepare('UPDATE password_reset_sessions SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL')
                ->execute([$uid]);
            OtpService::invalidate($pdo, $uid, OtpService::PURPOSE_PASSWORD_RESET);
            $pdo->prepare('UPDATE account SET password = ?, login_attempts = 0, locked_until = NULL, sessions_version = sessions_version + 1 WHERE account_id = ?')
                ->execute([password_hash($p1, PASSWORD_BCRYPT, ['cost' => 12]), $uid]);

            Audit::log($pdo, 'PASSWORD_RESET', 'account:' . $uid);
            SecurityEvent::log($pdo, 'PASSWORD_RESET', $uid);
            SecurityNotificationService::passwordReset($pdo, $uid);

            unset($_SESSION['pw_reset_token']);
            session_regenerate_id(true);
            $_SESSION['reset_done'] = true;
            header('Location: ' . BASE_URL . 'auth/login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set New Password · NAAP GSD Trip Ticket</title>
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
    <h2 class="text-xl font-bold mb-1">Set New Password</h2>
    <p class="text-text-muted text-md mt-0 mb-5">Choose a strong password (min. 8 characters, with at least one uppercase, lowercase, number and special character).</p>

    <?php if ($error): ?>
      <div class="alert alert-error mb-3.5 text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" id="pwResetForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
      <div class="mb-4">
        <label class="form-label">New Password</label>
        <input type="password" name="password" id="pw" minlength="8" required autofocus autocomplete="new-password" class="form-input">
      </div>
      <div class="mb-4">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="password2" required autocomplete="new-password" class="form-input">
      </div>
      <button class="btn btn-primary w-full" type="submit" value="1">Update Password</button>
    </form>
  </div>
</div>
<script>const BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/error_logger.js"></script>
<script src="<?= BASE_URL ?>assets/js/pages/password-policy.js"></script>
<script>
/* Client-side mirror of the server password policy; the server still validates. */
(function () {
  var f = document.getElementById('pwResetForm');
  if (!f) return;
  var p1 = document.getElementById('pw');
  var p2 = f.elements.password2;
  p1.addEventListener('input', function () { p1.setCustomValidity(''); });
  p2.addEventListener('input', function () { p2.setCustomValidity(''); });
  f.addEventListener('submit', function (e) {
    if (typeof PasswordPolicy !== 'undefined') {
      var err = PasswordPolicy.check(p1.value);
      if (err) { p1.setCustomValidity(err); p1.reportValidity(); e.preventDefault(); return; }
    }
    if (p1.value !== p2.value) {
      p2.setCustomValidity('Passwords do not match.');
      p2.reportValidity();
      e.preventDefault();
    }
  });
})();
</script>
</body>
</html>