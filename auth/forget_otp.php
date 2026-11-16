<?php
declare(strict_types=1);
/**
 * auth/forget_otp.php — password reset step 1: request OTP, then enter it.
 * OTP is stored hashed (single-use, 5 min TTL, 5 attempts, 60s resend cooldown,
 * server-side rate limit). Responses never reveal whether an email exists.
 * When SMTP is unconfigured the code is shown inline (dev mode only).
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

$step  = 'email';
$error = null;
$info  = null;
$devOtp = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired — please try again.';
    } elseif (isset($_POST['request_btn'])) {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $st = $pdo->prepare('SELECT account_id FROM account WHERE email = ? AND is_active = 1');
        $st->execute([$email]);
        $acct = $st->fetch();

        // Uniform response either way — no account enumeration.
        if ($acct) {
            if (!RateLimiter::hit($pdo, 'OTP_SEND', (int) $acct['account_id'], 5, 900)) {
                $error = 'Too many requests. Please try again later.';
            } elseif (!OtpService::canResend($pdo, (int) $acct['account_id'], OtpService::PURPOSE_PASSWORD_RESET)) {
                $error = 'Please wait 60 seconds before requesting another code.';
            } else {
                $otp = OtpService::generate($pdo, (int) $acct['account_id'], OtpService::PURPOSE_PASSWORD_RESET);
                $sent = MailService::enabled($pdo)
                    ? SecurityNotificationService::sendOtp($pdo, (int) $acct['account_id'], OtpService::PURPOSE_PASSWORD_RESET, $otp)
                    : false;

                if ($sent) {
                    $info = "A 6-digit code was sent to {$email}\nThe code expires in " . (int) Settings::getInt('OTP_TTL_MIN', OtpService::TTL_MIN) . " minute(s)";
                } elseif (APP_ENV === 'dev' && !MailService::enabled($pdo)) {
                    $devOtp = $otp; // dev only: SMTP unconfigured — show code inline
                    $info = 'SMTP not configured — dev-mode code shown below.';
                } else {
                    // Configured but delivery failed (or prod): drop the code so it can't be brute-forced.
                    OtpService::invalidate($pdo, (int) $acct['account_id'], OtpService::PURPOSE_PASSWORD_RESET);
                    $error = 'We could not send the code. Please try again in a minute.';
                }

                if ($info !== null || $devOtp !== null) {
                    $_SESSION['pw_otp_uid'] = (int) $acct['account_id'];
                    $step = 'code';
                }
            }
        } else {
            $step = 'code'; // pretend we sent; user has nothing to enter though
            $info = 'If that email exists, a code has been dispatched.';
        }
    } elseif (isset($_POST['verify_btn'])) {
        $uid = (int) ($_SESSION['pw_otp_uid'] ?? 0);
        $code = preg_replace('/\D/', '', (string) ($_POST['otp_code'] ?? ''));
        if ($uid > 0 && OtpService::verify($pdo, $uid, OtpService::PURPOSE_PASSWORD_RESET, $code)) {
            // Verified OTP -> mint a short-lived password-reset session token.
            $token = bin2hex(random_bytes(32));
            $ttlMin = 10;
            $pdo->prepare('UPDATE password_reset_sessions SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL')
                ->execute([$uid]);
            $pdo->prepare('INSERT INTO password_reset_sessions (account_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))')
                ->execute([$uid, hash('sha256', $token), $ttlMin]);
            unset($_SESSION['pw_otp_uid']);
            $_SESSION['pw_reset_token'] = $token;
            header('Location: ' . BASE_URL . 'auth/forget_pw.php');
            exit;
        }
        $error = $uid > 0
            ? 'Invalid or expired code.'
            : 'Your session has expired — request a new code.';
        $step = 'code';
    }
} else {
    unset($_SESSION['pw_reset_token']);
}

if ($step === 'code' && empty($_SESSION['pw_otp_uid'])) {
    $step = 'email';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password · NAAP GSD Trip Ticket</title>
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
    <h2 class="text-xl font-bold mb-1">Reset Password</h2>
    <p class="text-text-muted text-md mt-0 mb-5">
      National Aviation Academy of the Philippines · GSD
    </p>

    <?php if ($error): ?>
      <div class="alert alert-error mb-3.5 text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
      <div class="alert alert-info mb-3.5 text-center"><?= nl2br(htmlspecialchars($info)) ?></div>
    <?php endif; ?>
    <?php if ($devOtp): ?>
      <div class="alert alert-warning mb-3.5 text-center">
        Dev OTP: <strong class="text-xl tracking-30"><?= htmlspecialchars($devOtp) ?></strong>
      </div>
    <?php endif; ?>

    <?php if ($step === 'email'): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
        <div class="mb-4">
          <label class="form-label">Account Email</label>
          <input type="email" name="email" required placeholder="you@naap.gov.ph" autofocus autocomplete="email" class="form-input">
        </div>
        <button class="btn btn-primary w-full" type="submit" name="request_btn" value="1">Send Code</button>
      </form>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
        <div class="mb-4">
          <label class="form-label">6-Digit Code</label>
          <input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                 required autofocus placeholder="000000" autocomplete="one-time-code"
                 class="form-input text-center tracking-50 text-xl font-bold">
        </div>
        <button class="btn btn-primary w-full" type="submit" name="verify_btn" value="1">Verify Code</button>
      </form>
    <?php endif; ?>

    <p class="mt-3.5 text-center"><a href="<?= BASE_URL ?>auth/login.php" class="text-md">← Back to login</a></p>
  </div>
</div>
<script>const BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/error_logger.js"></script>
</body>
</html>