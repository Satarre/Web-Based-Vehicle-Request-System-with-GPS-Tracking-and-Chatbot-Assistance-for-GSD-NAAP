<?php
/**
 * auth/login_auth.php — credential handler.
 * Verifies credentials (never exposes whether an email exists), then:
 *   - MFA enabled  -> pending session, redirect to MFA challenge
 *   - MFA required by role policy and not enabled -> forced MFA setup
 *   - otherwise    -> finalize login and land on the role dashboard
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

if (empty($_POST['login_btn'])) {
    header('Location: login.php');
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    $_SESSION['login_error'] = "Invalid form token.\nPlease try again.";
    header('Location: login.php');
    exit;
}

[$ok, $error, $user] = Auth::attempt($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));

if (!$ok || !$user) {
    $_SESSION['login_error'] = $error ?? 'Invalid email or password.';
    header('Location: login.php');
    exit;
}

$uid = (int) $user['account_id'];

// Second factor: MFA already enabled → challenge; role policy requires it but
// it is not set up → forced setup. Otherwise finish the login now.
$mfaOn = MFA_ENFORCED && TotpService::isEnabled($pdo, $uid);
$setup = !$mfaOn && in_array((int) $user['role_id'], Auth::mfaRequiredRoles(), true);
if (!$mfaOn && !$setup) {
    Auth::finalize($pdo, $user);
    header('Location: ' . (Auth::LANDING[$user['role_id']] ?? BASE_URL . 'index.php'));
    exit;
}

// Park the half-authenticated session until the second factor passes.
$_SESSION['mfa_pending_user_id'] = $uid;
if ($setup) {
    $_SESSION['mfa_setup_required'] = true;
}
foreach (['user_id', 'role_id', 'full_name', 'role_name', 'email'] as $k) {
    unset($_SESSION[$k]);
}
header('Location: ' . ($setup ? 'mfa_setup.php' : 'mfa_challenge.php'));
exit;