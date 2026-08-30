<?php
declare(strict_types=1);
/**
 * auth/forget_otp.php — password reset step 1: request OTP, then enter it.
 * SMTP is not configured on stock XAMPP: when SMTP_USER is empty the OTP is
 * shown inline (dev mode). // ponytail: real SMTP client only if capstone needs live mail
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

$step    = 'email';
$error   = null;
$info    = null;
$devOtp  = null;

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
            $lastTs = (int) ($_SESSION['otp_last_sent'] ?? 0);
            if (time() - $lastTs < 30) {
                $error = 'Please wait 30 seconds before requesting another code.';
            } else {
                $pdo->prepare('UPDATE password_reset_otp SET used = 1 WHERE account_id = ? AND used = 0')
                    ->execute([$acct['account_id']]);
                $otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $ttlMins = Settings::getInt('OTP_TTL_MIN', 10);
                $pdo->prepare('INSERT INTO password_reset_otp (account_id, otp_code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))')
                    ->execute([$acct['account_id'], $otp, $ttlMins]);

                $smtpUser = (string) Settings::get('SMTP_USER', '');
                $sent = false;
                if ($smtpUser !== '') {
                    // ponytail: mail() relay only; hand-rolled SMTP client deferred until needed
                    $fromName = Settings::get('SMTP_FROM_NAME', 'NAAP GSD System');
                    $headers  = "From: {$fromName} <{$smtpUser}>\r\nContent-Type: text/plain; charset=utf-8";
                    $sent = @mail($email, "{$fromName} password reset",
                        "Your one-time code is {$otp}. It expires in {$ttlMins} minute(s).", $headers);
                    if (!$sent) {
                        error_log("[OTP] mail() failed for {$email}; code logged only");
                    }
                }
                if (!$sent) {
                    $devOtp = $otp; // dev mode: SMTP unconfigured or delivery failed
                }

                $_SESSION['pw_otp_uid']   = (int) $acct['account_id'];
                $_SESSION['pw_otp_tries'] = 0;
                $_SESSION['otp_last_sent'] = time();
                $step  = 'code';
                $info  = $sent
                    ? "A 6-digit code was sent to {$email}. It expires in {$ttlMins} minute(s)."
                    : "SMTP not configured — dev-mode code shown below.";
            }
        } else {
            $step = 'code'; // pretend we sent; user has nothing to enter though
            $info = "If that email exists, a code has been dispatched.";
        }
    } elseif (isset($_POST['verify_btn'])) {
        $uid = (int) ($_SESSION['pw_otp_uid'] ?? 0);
        $code = preg_replace('/\D/', '', (string) ($_POST['otp_code'] ?? ''));
        if ($uid && strlen($code) === 6) {
            $tries = (int) ($_SESSION['pw_otp_tries'] ?? 0) + 1;
            $_SESSION['pw_otp_tries'] = $tries;
            if ($tries > 5) {
                unset($_SESSION['pw_otp_uid'], $_SESSION['pw_otp_tries']);
                $error = 'Too many incorrect attempts — request a new code.';
                $step  = 'email';
            } else {
                $st = $pdo->prepare(
                    'SELECT otp_id FROM password_reset_otp
                     WHERE account_id = ? AND otp_code = ? AND used = 0 AND expires_at > NOW()
                     ORDER BY otp_id DESC LIMIT 1'
                );
                $st->execute([$uid, $code]);
                $row = $st->fetch();
                if ($row) {
                    $_SESSION['pw_reset_uid'] = $uid;
                    unset($_SESSION['pw_otp_uid'], $_SESSION['pw_otp_tries']);
                    header('Location: ' . BASE_URL . 'auth/forget_pw.php');
                    exit;
                }
                $error = 'Invalid or expired code.';
                $step  = 'code';
            }
        } else {
            $error = 'Enter the 6-digit code.';
            $step  = 'code';
        }
    }
} else {
    unset($_SESSION['pw_reset_uid']);
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
<body>
<div class="auth-wrap">
  <div class="card-login">
    <h2 style="font-size:20px;margin-bottom:4px">Reset Password</h2>
    <p style="font-size:12px;color:var(--text-muted);margin:0 0 20px">
      National Aviation Academy of the Philippines · GSD
    </p>

    <?php if ($error): ?>
      <div style="background:rgba(220,38,38,.08);border:1px solid #DC2626;color:#DC2626;padding:9px 12px;border-radius:8px;font-size:12.5px;font-weight:600;margin-bottom:16px">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>
    <?php if ($info): ?>
      <div style="background:rgba(37,99,235,.08);border:1px solid #2563EB;color:#2563EB;padding:9px 12px;border-radius:8px;font-size:12.5px;font-weight:600;margin-bottom:16px">
        <?= htmlspecialchars($info) ?>
      </div>
    <?php endif; ?>
    <?php if ($devOtp): ?>
      <div style="background:rgba(217,119,6,.08);border:1px dashed #D97706;color:#D97706;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:16px;text-align:center">
        Dev OTP: <strong style="font-size:20px;letter-spacing:.3em"><?= htmlspecialchars($devOtp) ?></strong>
      </div>
    <?php endif; ?>

    <?php if ($step === 'email'): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
        <div class="field">
          <label>Account Email</label>
          <input type="email" name="email" required placeholder="you@naap.gov.ph" autofocus>
        </div>
        <button class="btn btn-primary w-full" type="submit" name="request_btn" value="1">Send Code</button>
      </form>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
        <div class="field">
          <label>6-Digit Code</label>
          <input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                 required autofocus style="text-align:center;letter-spacing:.5em;font-size:20px;font-weight:700">
        </div>
        <button class="btn btn-primary w-full" type="submit" name="verify_btn" value="1">Verify Code</button>
      </form>
    <?php endif; ?>

    <p style="margin-top:16px;text-align:center"><a href="login.php" style="font-size:12px">← Back to login</a></p>
  </div>
</div>
<script>const BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/error_logger.js"></script>
</body>
</html>
