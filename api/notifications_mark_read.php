<?php
declare(strict_types=1);
/**
 * api/notifications_mark_read.php — mark all notifications as read for the current user.
 */
require_once __DIR__ . '/../config/config.php';
$u = Auth::requireApi($pdo, ['*']);
NotificationService::markRead($pdo, (int) $u['id']);
out(true, 'Notifications marked as read.');
