<?php
declare(strict_types=1);
/**
 * core/TotpService.php — RFC 6238 TOTP wrapper (existing core/TOTP.php)
 * with AES-256-GCM encryption at rest for the stored secret.
 *
 * The secret must be recoverable for verification, so it is ENCRYPTED
 * (openssl) rather than hashed. The key comes from APP_SECRET_KEY
 * (config/secrets.php or environment) — never from source.
 */

class TotpService
{
    private const CIPHER = 'aes-256-gcm';
    private const ISSUER = 'NAAP GSD Trip Ticket';

    /** Raw 32-byte hex key; empty string when not configured. */
    public static function secretKey(): string
    {
        return defined('APP_SECRET_KEY') ? (string) APP_SECRET_KEY : (string) getenv('APP_SECRET_KEY');
    }

    public static function generateSecret(): string
    {
        return TOTP::generateSecret();
    }

    /**
     * otpauth:// provisioning URI. Rendered to a QR code locally in the browser
     * (assets/js/qr-render.js) — the secret is never sent to an external service.
     */
    public static function provisioningUri(string $secret, string $email): string
    {
        return TOTP::getUri($secret, $email, self::ISSUER);
    }

    /**
     * Readable TOTP account label for a role — used as the authenticator
     * account label instead of the email. Canonical labels for the current
     * role set; generic acronym-aware title-case fallback for any future role.
     */
    public static function labelFor(int $roleId, string $roleName): string
    {
        $canonical = [
            1 => 'Administrator',
            2 => 'Supervising Administrative Officer',
            3 => 'GSD Dispatcher',
            4 => 'Driver',
            5 => 'Guard',
            6 => 'Personnel',
        ];
        if (isset($canonical[$roleId])) {
            return $canonical[$roleId];
        }
        $words = preg_split('/[\s_\-]+/', strtolower(trim($roleName)), -1, PREG_SPLIT_NO_EMPTY);
        $label = implode(' ', array_map(
            static fn(string $w) => in_array(strtoupper($w), ['MIS', 'HR', 'GSD', 'NAAP', 'ID'], true)
                ? strtoupper($w)
                : ucfirst($w),
            $words
        ));
        return $label !== '' ? $label : 'Account';
    }

    /** Encrypt a plaintext secret; payload = base64(iv || ciphertext || tag). */
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, hex2bin(self::secretKey()), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $cipher . $tag);
    }

    /** Decrypt a stored payload; false on any failure (bad key/tamper). */
    public static function decrypt(string $payload): string|false
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            return false;
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, -16);
        $cipher = substr($raw, 12, -16);
        return openssl_decrypt($cipher, self::CIPHER, hex2bin(self::secretKey()), OPENSSL_RAW_DATA, $iv, $tag);
    }

    /**
     * Pure verification against the encrypted stored secret (±1 window), with
     * no replay state. Prefer verifyAndConsume() on authentication paths.
     */
    public static function verifyEncrypted(string $encSecret, string $code): bool
    {
        if ($encSecret === '') {
            return false;
        }
        $secret = self::decrypt($encSecret);
        return $secret !== false && TOTP::verify($secret, $code);
    }

    /**
     * Verify a user code against the encrypted stored secret (±1 window) and
     * consume its time step for the account. Returns true only when the code is
     * valid AND its step is newer than the last accepted step — a replayed code
     * within the same window is rejected.
     */
    public static function verifyAndConsume(PDO $pdo, int $accountId, string $encSecret, string $code): bool
    {
        if ($encSecret === '') {
            return false;
        }
        $secret = self::decrypt($encSecret);
        if ($secret === false) {
            return false;
        }
        $step = TOTP::verifyStep($secret, $code);
        if ($step === null) {
            return false;
        }
        $upd = $pdo->prepare(
            'UPDATE account SET mfa_last_step = ?
             WHERE account_id = ? AND (mfa_last_step IS NULL OR mfa_last_step < ?)'
        );
        $upd->execute([$step, $accountId, $step]);
        return $upd->rowCount() > 0;
    }

    /** Whether an account currently has MFA enabled with a recoverable secret. */
    public static function isEnabled(PDO $pdo, int $accountId): bool
    {
        $st = $pdo->prepare('SELECT mfa_enabled, mfa_secret FROM account WHERE account_id = ?');
        $st->execute([$accountId]);
        $row = $st->fetch();
        return $row && (int) $row['mfa_enabled'] === 1 && !empty($row['mfa_secret']);
    }
}