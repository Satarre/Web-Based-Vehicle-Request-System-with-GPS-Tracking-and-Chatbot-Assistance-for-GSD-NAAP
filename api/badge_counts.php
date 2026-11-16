<?php
declare(strict_types=1);
/**
 * api/badge_counts.php — lightweight fallback for badge count polling.
 * Returns { pending, approved, notifications } for the current user's role.
 */
require_once __DIR__ . '/../config/config.php';
$u = Auth::requireApi($pdo, ['*']);
$roleId = (int) $u['role_id'];
$userId = (int) $u['id'];

$pending  = 0;
$approved = 0;

if ($roleId === 2) {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM trip_tickets
         WHERE is_active = 1 AND status_id = 2
           AND (scheduled_departure IS NULL OR scheduled_departure >= NOW())'
    );
    $st->execute();
    $pending = (int) $st->fetchColumn();
}

if ($roleId === 3) {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM trip_tickets
         WHERE is_active = 1 AND status_id = 4
           AND (scheduled_departure IS NULL OR scheduled_departure >= NOW())'
    );
    $st->execute();
    $approved = (int) $st->fetchColumn();
}

$st = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$st->execute([$userId]);
$notifCount = (int) $st->fetchColumn();

out(true, '', [
    'pending'       => $pending,
    'approved'      => $approved,
    'notifications' => $notifCount,
]);
