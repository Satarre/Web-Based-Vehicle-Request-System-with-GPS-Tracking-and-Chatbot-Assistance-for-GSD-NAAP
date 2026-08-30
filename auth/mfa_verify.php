<?php
/**
 * auth/mfa_verify.php — finalize login after valid TOTP code.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

if (empty($_SESSION['mfa_pending_user_id']) || empty($_POST['otp_code'])) {
    header('Location: login.php');
    exit;
}

$uid  = (int) $_SESSION['mfa_pending_user_id'];
$stmt = $pdo->prepare('SELECT mfa_enabled, mfa_secret, role_id FROM account WHERE account_id = ? AND is_active = 1');
$stmt->execute([$uid]);
$row = $stmt->fetch();

$code = preg_replace('/\s+/', '', (string) $_POST['otp_code']);
if ($row && (int) $row['mfa_enabled'] === 1 && !empty($row['mfa_secret'])
    && preg_match('/^\d{6}$/', $code) && TOTP::verify((string) $row['mfa_secret'], $code)) {

    unset($_SESSION['mfa_pending_user_id']);
    session_regenerate_id(true);
    $_SESSION['user_id']     = $uid;
    $_SESSION['role_id']     = (int) $row['role_id'];
    $stmt2 = $pdo->prepare(
        'SELECT a.full_name, a.email, r.role_name FROM account a
         LEFT JOIN roles r ON r.role_id = a.role_id WHERE a.account_id = ?'
    );
    $stmt2->execute([$uid]);
    if ($u = $stmt2->fetch()) {
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['email']     = $u['email'];
        $_SESSION['role_name'] = $u['role_name'];
    }
    $_SESSION['last_active']   = time();
    $_SESSION['_id_regen_at']  = time();

    Audit::log($pdo, 'LOGIN', 'account:' . $uid);
    header('Location: ' . (Auth::LANDING[(int) $row['role_id']] ?? BASE_URL . 'index.php'));
    exit;
}

$_SESSION['login_error'] = 'Invalid or expired code. Check your authenticator and try again.';
header('Location: login.php');
exit;
