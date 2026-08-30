<?php
/**
 * auth/login_auth.php — credential handler.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

if (empty($_POST['login_btn'])) {
    header('Location: login.php');
    exit;
}

[$ok, $error] = Auth::login($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));

if (!$ok) {
    $_SESSION['login_error'] = $error;
    header('Location: login.php');
    exit;
}

$user = Auth::user();

// Two-factor enabled? Park the session and demand the TOTP code.
if (!empty($user['role_id'])) {
    $stmt = $pdo->prepare('SELECT mfa_enabled, mfa_secret FROM account WHERE account_id = ?');
    $stmt->execute([$user['id']]);
    $mfa = $stmt->fetch();
    if ($mfa && (int) $mfa['mfa_enabled'] === 1 && !empty($mfa['mfa_secret'])) {
        // Demote to pending state: user_id kept aside, real auth withheld.
        $_SESSION['mfa_pending_user_id'] = $user['id'];
        foreach (['user_id'] as $k) unset($_SESSION[$k]);
        header('Location: login.php'); // login.php renders the OTP form when pending
        exit;
    }
}

header('Location: ' . (Auth::LANDING[$user['role_id']] ?? BASE_URL . 'index.php'));
exit;
