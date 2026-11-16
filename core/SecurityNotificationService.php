<?php
declare(strict_types=1);
/**
 * core/SecurityNotificationService.php — all account security emails.
 * Each method is best-effort: delivery failures are logged, never surfaced.
 * No secrets (OTPs, codes, tokens, passwords) are ever logged.
 */

class SecurityNotificationService
{
    /** Load account identity; null when missing/inactive. */
    private static function account(PDO $pdo, int $accountId): ?array
    {
        $st = $pdo->prepare('SELECT account_id, email, full_name FROM account WHERE account_id = ? AND is_active = 1');
        $st->execute([$accountId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function ttlLabel(PDO $pdo): string
    {
        $m = (int) Settings::getInt('OTP_TTL_MIN', OtpService::TTL_MIN);
        return $m . ' minute' . ($m > 1 ? 's' : '');
    }

    /** One-time-code email for a given purpose. Uses $plainOtp — never stored/logged. */
    public static function sendOtp(PDO $pdo, int $accountId, string $purpose, string $plainOtp): bool
    {
        $acct = self::account($pdo, $accountId);
        if (!$acct) {
            return false;
        }
        $name = trim((string) $acct['full_name']);
        $greeting = $name !== '' ? "Hello {$name}," : 'Hello,';
        $ttl = self::ttlLabel($pdo);

        switch ($purpose) {
            case OtpService::PURPOSE_PASSWORD_RESET:
                $subject = 'Reset your NAAP GSD Digital Trip Ticket System Account Password';
                $heading = 'Password Reset Request';
                $lead = 'We received a request to reset the password for your account. Use the code below to continue.';
                break;
            case OtpService::PURPOSE_VERIFY_EMAIL:
                $subject = 'Verify Your Email Address — NAAP GSD Digital Trip Ticket System';
                $heading = 'Verify Your Email Address';
                $lead = 'Use the code below to confirm your email address for the Digital Trip Ticket System.';
                break;
        }

        $body = <<<HTML
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;">{$greeting}</p>
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;">{$lead}</p>
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:18px;text-align:center;">
  <div style="font-size:12px;color:#0369a1;text-transform:uppercase;letter-spacing:1px;">Your verification code</div>
  <div style="font-size:30px;font-weight:700;letter-spacing:6px;color:#0c4a6e;margin-top:6px;">{$plainOtp}</div>
</div>
<p style="margin:18px 0 0;font-size:13px;color:#64748b;line-height:1.6;">
  This code expires in <strong>{$ttl}</strong>. Never share this code with anyone.
</p>
<p style="margin:12px 0 0;font-size:13px;color:#64748b;line-height:1.6;">
  <strong>The NAAP GSD Digital Trip Ticket System will never ask for it.</strong>
</p>
HTML;

        return MailService::notify($pdo, $acct, $subject, $heading, $body);
    }

    /** Shared plumbing: account lookup + greeting wrapper + send. */
    private static function send(PDO $pdo, int $accountId, string $subject, string $heading, string $bodyHtml): bool
    {
        $acct = self::account($pdo, $accountId);
        if (!$acct) {
            return false;
        }
        $name = trim((string) $acct['full_name']);
        $greeting = $name !== '' ? "Hello {$name}," : 'Hello,';
        $body = <<<HTML
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;">{$greeting}</p>
{$bodyHtml}
HTML;
        return MailService::notify($pdo, $acct, $subject, $heading, $body);
    }

    /** Sent after the user changes their own password. */
    public static function passwordChanged(PDO $pdo, int $accountId): bool
    {
        $when = date('F j, Y g:i A');
        $ip = SecurityEvent::ip();
        return self::send($pdo, $accountId, 'Password Changed — NAAP GSD Digital Trip Ticket System', 'Password Changed', <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  Your password for the GSD Vehicle Trip Ticket System was changed successfully.
</p>
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  <strong>Date and time:</strong> {$when}<br>
  <strong>IP address:</strong> {$ip}
</p>
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  If you made this change, no further action is required.
</p>
<p style="margin:0;font-size:14px;line-height:1.6;">
  If you did not make this change, contact the system administrator immediately and reset your password.
</p>
HTML);
    }

    /** Sent after a password reset via the forgot-password flow. */
    public static function passwordReset(PDO $pdo, int $accountId): bool
    {
        $when = date('F j, Y g:i A');
        $ip = SecurityEvent::ip();
        return self::send($pdo, $accountId, 'Password Reset — NAAP GSD Digital Trip Ticket System', 'Password Reset', <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  Your password was reset on <strong>{$when}</strong> from IP <strong>{$ip}</strong>.
</p>
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  If you made this change, no further action is required. Your other active sessions were signed out.
</p>
<p style="margin:0;font-size:14px;line-height:1.6;">
  If you did not make this change, contact the system administrator immediately.
</p>
HTML);
    }

    public static function mfaEnabled(PDO $pdo, int $accountId): bool
    {
        $when = date('F j, Y g:i A');
        return self::send($pdo, $accountId, 'Two-Factor Authentication Enabled — NAAP GSD Digital Trip Ticket System', '2FA Enabled', <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  Two-factor authentication (2FA) was enabled on your account on <strong>{$when}</strong>.
</p>
<p style="margin:0;font-size:14px;line-height:1.6;">
  From now on, sign-in requires a one-time code from your authenticator app or a recovery code.
  If you did not enable this, contact the system administrator immediately.
</p>
HTML);
    }

    public static function mfaDisabled(PDO $pdo, int $accountId): bool
    {
        $when = date('F j, Y g:i A');
        return self::send($pdo, $accountId, 'Two-Factor Authentication Disabled — NAAP GSD Digital Trip Ticket System', '2FA Disabled', <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  Two-factor authentication (2FA) was disabled on your account on <strong>{$when}</strong>.
  All recovery codes were invalidated.
</p>
<p style="margin:0;font-size:14px;line-height:1.6;">
  If you did not disable this, contact the system administrator immediately.
</p>
HTML);
    }

    public static function recoveryCodesRegenerated(PDO $pdo, int $accountId): bool
    {
        $when = date('F j, Y g:i A');
        return self::send($pdo, $accountId, 'Recovery Codes Regenerated — NAAP GSD Digital Trip Ticket System', 'Recovery Codes Changed', <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  Your account recovery codes were regenerated on <strong>{$when}</strong>. The previous codes no longer work.
</p>
<p style="margin:0;font-size:14px;line-height:1.6;">
  If you did not do this, contact the system administrator immediately.
</p>
HTML);
    }

    /** Notify the NEW address when an administrator changes an account email. */
    public static function emailChanged(PDO $pdo, int $accountId, string $newEmail): bool
    {
        $when = date('F j, Y g:i A');
        return self::send($pdo, $accountId, 'Email Address Changed — NAAP GSD Digital Trip Ticket System', 'Email Address Changed', <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
  The email address on your account was changed to <strong>{$newEmail}</strong> on <strong>{$when}</strong>.
</p>
<p style="margin:0;font-size:14px;line-height:1.6;">
  If you did not request this change, contact the system administrator immediately.
</p>
HTML);
    }
}