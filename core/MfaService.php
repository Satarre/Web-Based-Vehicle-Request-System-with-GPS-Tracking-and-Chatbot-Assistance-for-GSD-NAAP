<?php
declare(strict_types=1);
/**
 * core/MfaService.php — TOTP enable/disable + recovery-code lifecycle.
 * Shared by the self-service MFA page and the forced-setup flow.
 * Only hashed recovery codes are stored; codes are shown once and discarded.
 */

class MfaService
{
    public const RECOVERY_COUNT = 10;
    // Ambiguity-free alphabet: no 0/O, 1/I/L.
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Persist an enabled MFA secret, bump sessions_version and mint recovery codes.
     * Returns the plaintext recovery codes (display exactly once).
     */
    public static function enable(PDO $pdo, int $accountId, string $encSecret): array
    {
        $pdo->prepare('UPDATE account SET mfa_secret = ?, mfa_enabled = 1, mfa_enabled_at = NOW(), sessions_version = sessions_version + 1 WHERE account_id = ?')
            ->execute([$encSecret, $accountId]);
        $codes = self::regenerateRecoveryCodes($pdo, $accountId, false);
        Audit::log($pdo, 'MODIFY', 'account:' . $accountId, null, 'MFA enabled');
        SecurityEvent::log($pdo, 'MFA_ENABLED', $accountId);
        SecurityNotificationService::mfaEnabled($pdo, $accountId);
        return $codes;
    }

    /** Disable MFA: clear secret, drop all recovery codes, revoke sessions. */
    public static function disable(PDO $pdo, int $accountId): void
    {
        $pdo->prepare('UPDATE account SET mfa_secret = NULL, mfa_enabled = 0, mfa_enabled_at = NULL, sessions_version = sessions_version + 1 WHERE account_id = ?')
            ->execute([$accountId]);
        $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE account_id = ?')->execute([$accountId]);
        Audit::log($pdo, 'MODIFY', 'account:' . $accountId, null, 'MFA disabled');
        SecurityEvent::log($pdo, 'MFA_DISABLED', $accountId);
        SecurityNotificationService::mfaDisabled($pdo, $accountId);
    }

    /** Replace all recovery codes (old ones invalidated). Returns plaintext codes. */
    public static function regenerateRecoveryCodes(PDO $pdo, int $accountId, bool $notify = true): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 8; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
        }

        $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE account_id = ?')->execute([$accountId]);
        $ins = $pdo->prepare('INSERT INTO mfa_recovery_codes (account_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $code) {
            // Hash the normalized (dash-free) form so login comparison matches.
            $ins->execute([$accountId, password_hash(str_replace('-', '', $code), PASSWORD_BCRYPT, ['cost' => 12])]);
        }

        if ($notify) {
            Audit::log($pdo, 'MODIFY', 'account:' . $accountId, null, 'recovery codes regenerated');
            SecurityEvent::log($pdo, 'RECOVERY_CODES_REGENERATED', $accountId);
            SecurityNotificationService::recoveryCodesRegenerated($pdo, $accountId);
        }
        return $codes;
    }

    /** Consume a recovery code (single-use). Normalizes case/dashes/spaces. */
    public static function consume(PDO $pdo, int $accountId, string $code): bool
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-]/', '', $code));
        if ($normalized === '' || !preg_match('/^[A-Z0-9]{8}$/', $normalized)) {
            return false;
        }
        $st = $pdo->prepare('SELECT recovery_id, code_hash FROM mfa_recovery_codes WHERE account_id = ? AND used_at IS NULL');
        $st->execute([$accountId]);
        foreach ($st->fetchAll() as $row) {
            if (password_verify($normalized, (string) $row['code_hash'])) {
                $pdo->prepare('UPDATE mfa_recovery_codes SET used_at = NOW() WHERE recovery_id = ?')->execute([$row['recovery_id']]);
                return true;
            }
        }
        return false;
    }
}