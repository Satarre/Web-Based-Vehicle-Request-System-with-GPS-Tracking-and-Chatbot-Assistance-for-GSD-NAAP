<?php
declare(strict_types=1);
/**
 * auth/forget_pw.php — password reset step 2 (requires verified OTP stage).
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

$uid = (int) ($_SESSION['pw_reset_uid'] ?? 0);
if (!$uid) {
    header('Location: ' . BASE_URL . 'auth/forget_otp.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired — please try again.';
    } else {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['password2'] ?? '');
        if (strlen($p1) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($p1 !== $p2) {
            $error = 'Passwords do not match.';
        } else {
            $pdo->prepare('UPDATE account SET password = ?, login_attempts = 0, locked_until = NULL WHERE account_id = ?')
                ->execute([password_hash($p1, PASSWORD_BCRYPT, ['cost' => 12]), $uid]);
            $pdo->prepare('UPDATE password_reset_otp SET used = 1 WHERE account_id = ? AND used = 0')
                ->execute([$uid]);
            Audit::log($pdo, 'PASSWORD_RESET', 'account:' . $uid);
            unset($_SESSION['pw_reset_uid']);
            $_SESSION['login_error'] = null;
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
<body>
<div class="auth-wrap">
  <div class="card-login">
    <h2 style="font-size:20px;margin-bottom:4px">Set New Password</h2>
    <p style="font-size:12px;color:var(--text-muted);margin:0 0 20px">Choose a strong password (min. 8 characters).</p>

    <?php if ($error): ?>
      <div style="background:rgba(220,38,38,.08);border:1px solid #DC2626;color:#DC2626;padding:9px 12px;border-radius:8px;font-size:12.5px;font-weight:600;margin-bottom:16px">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
      <div class="field">
        <label>New Password</label>
        <input type="password" name="password" id="pw" minlength="8" required autofocus>
      </div>
      <div class="field">
        <label>Confirm Password</label>
        <input type="password" name="password2" required>
      </div>
      <button class="btn btn-primary w-full" type="submit" value="1">Update Password</button>
    </form>
  </div>
</div>
<script>const BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/error_logger.js"></script>
</body>
</html>
