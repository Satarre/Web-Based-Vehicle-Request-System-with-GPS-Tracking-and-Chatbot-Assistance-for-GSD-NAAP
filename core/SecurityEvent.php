<?php
declare(strict_types=1);
/**
 * core/SecurityEvent.php — append-only security event log (security_events).
 * Backs OTP rate limiting and records authentication/security milestones.
 * Never logs passwords, OTPs, TOTP secrets, recovery codes or tokens.
 */

class SecurityEvent
{
    public static function ip(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return ($raw === '::1') ? '127.0.0.1' : (string) $raw;
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /** Insert a row. Never throws — security logging must not break a request. */
    public static function log(PDO $pdo, string $eventType, ?int $accountId = null): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO security_events (account_id, event_type, ip_address, user_agent)
                 VALUES (?, ?, ?, ?)'
            )->execute([$accountId, $eventType, self::ip(), self::userAgent()]);
        } catch (Throwable $e) {
            error_log('[SecurityEvent] ' . $e->getMessage());
        }
    }

    /** Count events of one type from one IP within the window (rate limiting). */
    public static function recentByIp(PDO $pdo, string $eventType, int $windowSec): int
    {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM security_events
             WHERE event_type = ? AND ip_address = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
        );
        $st->execute([$eventType, self::ip(), max(1, $windowSec)]);
        return (int) $st->fetchColumn();
    }

    /** Count events of one type for one account within the window (rate limiting). */
    public static function recentByAccount(PDO $pdo, string $eventType, int $accountId, int $windowSec): int
    {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM security_events
             WHERE event_type = ? AND account_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
        );
        $st->execute([$eventType, $accountId, max(1, $windowSec)]);
        return (int) $st->fetchColumn();
    }
}