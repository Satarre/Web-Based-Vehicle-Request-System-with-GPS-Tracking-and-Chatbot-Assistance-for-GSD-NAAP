<?php
/**
 * core/Audit.php — single audit_trail writer.
 * Human lifecycle events only; high-volume GPS fixes must NOT go through here.
 */

class Audit
{
    public static function ip(): string
    {
        $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return ($raw === '::1') ? '127.0.0.1' : (string) $raw;
    }

    /**
     * Insert an audit row. Never throws — audit failures must not break the request.
     */
    public static function log(
        PDO $pdo,
        string $eventType,
        string $objectId,
        ?string $before = null,
        ?string $after = null,
        string $outcome = 'SUCCESS',
        ?int $userId = null
    ): void {
        try {
            $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
            $stmt = $pdo->prepare(
                'INSERT INTO audit_trail
                    (user_id, event_type, object_id, before_value, after_value, outcome, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $eventType, $objectId, $before, $after, $outcome, self::ip()]);
        } catch (Throwable $e) {
            error_log('[Audit] insert failed: ' . $e->getMessage());
        }
    }
}
