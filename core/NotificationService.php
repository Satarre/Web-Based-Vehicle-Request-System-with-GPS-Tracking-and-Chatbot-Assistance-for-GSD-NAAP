<?php
declare(strict_types=1);
/**
 * core/NotificationService.php — real-time notification helper for SSE push.
 */
class NotificationService
{
    /** Insert a notification row (fire-and-forget — never throws). */
    public static function notify(PDO $pdo, int $userId, string $type, ?int $ticketId, string $message): void
    {
        try {
            $st = $pdo->prepare(
                'INSERT INTO notifications (user_id, ticket_id, type, message, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $st->execute([$userId, $ticketId, $type, $message]);
        } catch (Throwable $e) {
            error_log('[NotificationService] insert failed: ' . $e->getMessage());
        }
    }

    /** Notify all users with a given role about a ticket event. */
    public static function notifyRole(PDO $pdo, int $roleId, string $type, ?int $ticketId, string $message): void
    {
        try {
            $st = $pdo->prepare(
                'SELECT account_id FROM account WHERE role_id = ? AND is_active = 1'
            );
            $st->execute([$roleId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                self::notify($pdo, (int) $uid, $type, $ticketId, $message);
            }
        } catch (Throwable $e) {
            error_log('[NotificationService] notifyRole failed: ' . $e->getMessage());
        }
    }

    /** Notify a specific user. */
    public static function notifyUser(PDO $pdo, int $userId, string $type, ?int $ticketId, string $message): void
    {
        self::notify($pdo, $userId, $type, $ticketId, $message);
    }

    /** Get unread notifications for a user (newest first, capped). */
    public static function unread(PDO $pdo, int $userId, int $limit = 20): array
    {
        $st = $pdo->prepare(
            'SELECT id, ticket_id, type, message, created_at
             FROM notifications WHERE user_id = ? AND is_read = 0
             ORDER BY created_at DESC LIMIT ?'
        );
        $st->execute([$userId, $limit]);
        return $st->fetchAll();
    }

    /** Unread count for badge display. */
    public static function unreadCount(PDO $pdo, int $userId): int
    {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
        );
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /** Mark all notifications for a user as read. */
    public static function markRead(PDO $pdo, int $userId): void
    {
        try {
            $st = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
            $st->execute([$userId]);
        } catch (Throwable $e) {
            error_log('[NotificationService] markRead failed: ' . $e->getMessage());
        }
    }
}
