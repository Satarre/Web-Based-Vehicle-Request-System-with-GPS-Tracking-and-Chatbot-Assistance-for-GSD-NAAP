<?php
/**
 * auth/mfa_verify.php — finalize a pending login with a TOTP or recovery code.
 * A normal authenticated session is only created after this succeeds.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

if (empty($_SESSION['mfa_pending_user_id']) || empty($_POST['verify_btn'])) {
    header('Location: login.php');
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    $_SESSION['login_error'] = "Invalid form token.\nPlease try again.";
    header('Location: login.php');
    exit;
}

// Per-IP throttle on MFA attempts (guards recovery-code / TOTP brute force).
if (!RateLimiter::hit($pdo, 'MFA_ATTEMPT', null,
        max(1, Settings::getInt('LOGIN_IP_MAX', 20)),
        max(1, Settings::getInt('LOGIN_IP_WINDOW_SEC', 900)))) {
    SecurityEvent::log($pdo, 'MFA_THROTTLED', (int) ($_SESSION['mfa_pending_user_id'] ?? 0));
    $_SESSION['login_error'] = "Too many attempts.\nPlease try again later.";
    header('Location: mfa_challenge.php');
    exit;
}

$uid = (int) $_SESSION['mfa_pending_user_id'];
$stmt = $pdo->prepare(
    'SELECT a.*, r.role_name FROM account a
     LEFT JOIN roles r ON r.role_id = a.role_id
     WHERE a.account_id = ? AND a.is_active = 1'
);
$stmt->execute([$uid]);
$row = $stmt->fetch();

if (!$row) {
    unset($_SESSION['mfa_pending_user_id']);
    header('Location: login.php');
    exit;
}

if (($locked = Auth::lockedMessage($row['locked_until'] ?? null)) !== null) {
    $_SESSION['login_error'] = $locked;
    header('Location: mfa_challenge.php');
    exit;
}

$code = preg_replace('/\s+/', '', (string) ($_POST['otp_code'] ?? ''));
$method = null;

if (TotpService::isEnabled($pdo, $uid)) {
    $encSecret = (string) $row['mfa_secret'];
    if (TotpService::verifyAndConsume($pdo, $uid, $encSecret, $code)) {
        $method = 'LOGIN_MFA';
    } elseif (MfaService::consume($pdo, $uid, $code)) {
        $method = 'LOGIN_MFA_RECOVERY';
    }
}

if ($method !== null) {
    unset($_SESSION['mfa_pending_user_id']);
    SecurityEvent::log($pdo, $method, $uid);
    Auth::finalize($pdo, $row);
    header('Location: ' . (Auth::LANDING[(int) $row['role_id']] ?? BASE_URL . 'index.php'));
    exit;
}

[$locked, $error] = Auth::recordLoginFailure($pdo, $row, 'Invalid or expired code', 'MFA_FAILED');
$_SESSION['login_error'] = $error;
if ($locked) {
    unset($_SESSION['mfa_pending_user_id']);
}

header('Location: mfa_challenge.php');
exit;