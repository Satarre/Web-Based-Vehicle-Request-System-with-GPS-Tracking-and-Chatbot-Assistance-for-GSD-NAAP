<?php
declare(strict_types=1);
/**
 * api/account.php — self-service account actions. JSON in / JSON out.
 * POST { action, csrf_token, ... }. Any authenticated role, every write audited.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'POST only.');
}
if (!Csrf::verifyRequest($in)) {
    out(false, 'Invalid or missing CSRF token.');
}
$u = Auth::requireApi($pdo, ['*']);
$action = (string) ($in['action'] ?? '');

try {
    switch ($action) {

        case 'profile_save': {
            $first  = trim((string) ($in['first_name'] ?? ''));
            $mi     = trim((string) ($in['middle_initial'] ?? '')) ?: null;
            $middleName = trim((string) ($in['middle_name'] ?? '')) ?: null;
            $last   = trim((string) ($in['last_name'] ?? ''));
            $suffix = trim((string) ($in['suffix'] ?? '')) ?: null;
            if ($first === '' || $last === '') {
                out(false, 'First name and last name are required.');
            }
            $name = account_display_name($first, $mi, $last, $suffix);
            $pdo->prepare('UPDATE account SET full_name = ?, first_name = ?, middle_initial = ?, middle_name = ?, last_name = ?, suffix = ? WHERE account_id = ?')
                ->execute([$name, $first, $mi, $middleName, $last, $suffix, $u['id']]);
            $_SESSION['full_name'] = $name;
            Audit::log($pdo, 'MODIFY', 'account:' . $u['id'], null, "self profile update ({$name})");
            out(true, 'Profile updated.');
        }

        case 'password_change': {
            $cur = (string) ($in['current_password'] ?? '');
            $new = (string) ($in['new_password'] ?? '');
            if ($cur === '' || $new === '') {
                out(false, 'Current and new passwords are required.');
            }
            $pwErr = PasswordPolicy::check($new);
            if ($pwErr !== null) {
                out(false, $pwErr);
            }
            if ($cur === $new) {
                out(false, 'New password must be different from the current password.');
            }
            $st = $pdo->prepare('SELECT password FROM account WHERE account_id = ?');
            $st->execute([$u['id']]);
            $row = $st->fetch();
            if (!$row || !password_verify($cur, (string) $row['password'])) {
                Audit::log($pdo, 'MODIFY', 'account:' . $u['id'], null, null, 'FAILED');
                out(false, 'Current password is incorrect.');
            }
            $pdo->prepare('UPDATE account SET password = ?, sessions_version = sessions_version + 1 WHERE account_id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $u['id']]);
            session_regenerate_id(true);
            $newV = (int) $pdo->query("SELECT sessions_version FROM account WHERE account_id = {$u['id']}")->fetchColumn();
            $_SESSION['sessions_version'] = $newV;
            Audit::log($pdo, 'MODIFY', 'account:' . $u['id'], null, 'self password change');
            SecurityEvent::log($pdo, 'PASSWORD_CHANGED', $u['id']);
            SecurityNotificationService::passwordChanged($pdo, $u['id']);
            out(true, 'Password changed successfully.');
        }

        case 'mfa_begin': {
            $secret = TotpService::generateSecret();
            $_SESSION['mfa_self_secret'] = $secret;
            out(true, 'Scan the QR code with your authenticator app.',
                ['data' => ['otpauth_uri' => TotpService::provisioningUri($secret, TotpService::labelFor((int) $u['role_id'], (string) ($u['role_name'] ?? ''))), 'secret' => $secret]]);
        }

        case 'mfa_enable': {
            $secret = (string) ($_SESSION['mfa_self_secret'] ?? '');
            if ($secret === '') {
                out(false, 'Start MFA setup first.');
            }
            if (TotpService::isEnabled($pdo, $u['id'])) {
                unset($_SESSION['mfa_self_secret']);
                out(false, 'Two-factor authentication is already enabled.');
            }
            $code = preg_replace('/\s+/', '', (string) ($in['otp_code'] ?? ''));
            if (!preg_match('/^\d{6}$/', $code) || !TOTP::verify($secret, $code)) {
                out(false, 'Invalid code. Please try again.');
            }
            $codes = MfaService::enable($pdo, $u['id'], TotpService::encrypt($secret));
            unset($_SESSION['mfa_self_secret']);
            $newV = (int) $pdo->query("SELECT sessions_version FROM account WHERE account_id = {$u['id']}")->fetchColumn();
            $_SESSION['sessions_version'] = $newV;
            out(true, 'Two-factor authentication enabled.', ['data' => ['codes' => $codes]]);
        }

        case 'mfa_disable': {
            if (!TotpService::isEnabled($pdo, $u['id'])) {
                out(false, 'Two-factor authentication is not enabled.');
            }
            $cur = (string) ($in['current_password'] ?? '');
            $otp = preg_replace('/\s+/', '', (string) ($in['otp_code'] ?? ''));
            $st = $pdo->prepare('SELECT password, mfa_secret FROM account WHERE account_id = ?');
            $st->execute([$u['id']]);
            $row = $st->fetch();
            if (!$row || !password_verify($cur, (string) $row['password'])) {
                Audit::log($pdo, 'MODIFY', 'account:' . $u['id'], null, null, 'FAILED');
                out(false, 'Current password is incorrect.');
            }
            $ok2f = TotpService::verifyAndConsume($pdo, $u['id'], (string) $row['mfa_secret'], $otp)
                || MfaService::consume($pdo, $u['id'], $otp);
            if (!$ok2f) {
                out(false, 'Invalid authenticator or recovery code.');
            }
            MfaService::disable($pdo, $u['id']);
            $newV = (int) $pdo->query("SELECT sessions_version FROM account WHERE account_id = {$u['id']}")->fetchColumn();
            $_SESSION['sessions_version'] = $newV;
            out(true, 'Two-factor authentication disabled.');
        }

        case 'mfa_regenerate_codes': {
            if (!TotpService::isEnabled($pdo, $u['id'])) {
                out(false, 'Enable two-factor authentication first.');
            }
            $cur = (string) ($in['current_password'] ?? '');
            $otp = preg_replace('/\s+/', '', (string) ($in['otp_code'] ?? ''));
            $st = $pdo->prepare('SELECT password, mfa_secret FROM account WHERE account_id = ?');
            $st->execute([$u['id']]);
            $row = $st->fetch();
            if (!$row || !password_verify($cur, (string) $row['password'])) {
                out(false, 'Current password is incorrect.');
            }
            $ok2f = TotpService::verifyAndConsume($pdo, $u['id'], (string) $row['mfa_secret'], $otp)
                || MfaService::consume($pdo, $u['id'], $otp);
            if (!$ok2f) {
                out(false, 'Invalid authenticator or recovery code.');
            }
            $codes = MfaService::regenerateRecoveryCodes($pdo, $u['id'], true);
            out(true, 'Recovery codes regenerated. Save them somewhere safe.', ['data' => ['codes' => $codes]]);
        }

        case 'email_verify_send': {
            if (!MailService::enabled($pdo)) {
                out(false, 'Email service is not configured.');
            }
            if (!RateLimiter::hit($pdo, 'OTP_SEND', $u['id'], 5, 900)) {
                out(false, 'Too many requests. Please try again later.');
            }
            if (!OtpService::canResend($pdo, $u['id'], OtpService::PURPOSE_VERIFY_EMAIL)) {
                out(false, 'Please wait 60 seconds before requesting another code.');
            }
            $otp = OtpService::generate($pdo, $u['id'], OtpService::PURPOSE_VERIFY_EMAIL);
            if (!SecurityNotificationService::sendOtp($pdo, $u['id'], OtpService::PURPOSE_VERIFY_EMAIL, $otp)) {
                OtpService::invalidate($pdo, $u['id'], OtpService::PURPOSE_VERIFY_EMAIL);
                out(false, 'Could not send the code. Please try again in a minute.');
            }
            out(true, 'A verification code has been sent to your email.');
        }

        case 'email_verify_confirm': {
            if (OtpService::verify($pdo, $u['id'], OtpService::PURPOSE_VERIFY_EMAIL, (string) ($in['otp_code'] ?? ''))) {
                $pdo->prepare('UPDATE account SET email_verified_at = NOW() WHERE account_id = ?')->execute([$u['id']]);
                SecurityEvent::log($pdo, 'EMAIL_VERIFIED', $u['id']);
                out(true, 'Email address verified.');
            }
            out(false, 'Invalid or expired code.');
        }

        default:
            out(false, 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[account] ' . $e->getMessage());
    out(false, 'Server error. Please try again.');
}
