<?php
declare(strict_types=1);
/**
 * core/OtpService.php — hashed, single-use, time-limited email OTPs.
 *
 * - codes generated with random_int() (6 digits)
 * - only password_hash() stored, never the plaintext
 * - 5-minute expiry, max 5 verification attempts, 60s resend cooldown
 * - generating a new code invalidates previous unused codes
 */

class OtpService
{
    public const PURPOSE_VERIFY_EMAIL  = 'verify_email';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const TTL_MIN          = 5;
    public const MAX_ATTEMPTS     = 5;
    public const RESEND_COOLDOWN_SEC = 60;

    /** Generate a fresh code and return the plaintext (send it, then discard). */
    public static function generate(PDO $pdo, int $accountId, string $purpose): string
    {
        // Generating a new OTP invalidates previous unused ones for this account+purpose.
        $pdo->prepare('UPDATE email_otps SET used_at = NOW() WHERE account_id = ? AND purpose = ? AND used_at IS NULL')
            ->execute([$accountId, $purpose]);

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = max(1, Settings::getInt('OTP_TTL_MIN', self::TTL_MIN));
        $pdo->prepare(
            'INSERT INTO email_otps (account_id, purpose, otp_hash, expires_at, last_sent_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())'
        )->execute([$accountId, $purpose, password_hash($otp, PASSWORD_BCRYPT, ['cost' => 12]), $ttl]);
        return $otp;
    }

    /** Unix timestamp of the last send for account+purpose, or null. */
    public static function lastSentAt(PDO $pdo, int $accountId, string $purpose): ?int
    {
        $st = $pdo->prepare('SELECT MAX(last_sent_at) FROM email_otps WHERE account_id = ? AND purpose = ?');
        $st->execute([$accountId, $purpose]);
        $ts = $st->fetchColumn();
        return $ts ? (int) strtotime((string) $ts) : null;
    }

    public static function canResend(PDO $pdo, int $accountId, string $purpose): bool
    {
        $last = self::lastSentAt($pdo, $accountId, $purpose);
        return $last === null || (time() - $last) >= self::RESEND_COOLDOWN_SEC;
    }

    /**
     * Single-use verify. Exhausted codes (5 failed attempts) are marked used.
     * Returns true exactly once per code.
     */
    public static function verify(PDO $pdo, int $accountId, string $purpose, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }

        $st = $pdo->prepare(
            'SELECT otp_id, otp_hash, attempts FROM email_otps
             WHERE account_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY otp_id DESC LIMIT 1'
        );
        $st->execute([$accountId, $purpose]);
        $row = $st->fetch();
        if (!$row) {
            return false;
        }

        if (password_verify($code, (string) $row['otp_hash'])) {
            $pdo->prepare('UPDATE email_otps SET used_at = NOW() WHERE otp_id = ?')->execute([$row['otp_id']]);
            return true;
        }

        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            // Exhausted — mark used so it can never be tried again; a new code is required.
            $pdo->prepare('UPDATE email_otps SET used_at = NOW() WHERE otp_id = ?')->execute([$row['otp_id']]);
        } else {
            $pdo->prepare('UPDATE email_otps SET attempts = ? WHERE otp_id = ?')->execute([$attempts, $row['otp_id']]);
        }
        return false;
    }

    /** Mark every unused code for account+purpose as consumed. */
    public static function invalidate(PDO $pdo, int $accountId, string $purpose): void
    {
        $pdo->prepare('UPDATE email_otps SET used_at = NOW() WHERE account_id = ? AND purpose = ? AND used_at IS NULL')
            ->execute([$accountId, $purpose]);
    }
}