<?php
declare(strict_types=1);
/**
 * api/sse.php — Server-Sent Events endpoint for real-time notifications + badge updates.
 * Long-polls the notifications table and streams events to connected clients.
 */
require_once __DIR__ . '/../config/config.php';

/* ── Auth (session-based, no Content-Type override) ───────────────────── */
Auth::start();
if (!Auth::refresh($pdo)) {
    http_response_code(401);
    exit('Not authenticated.');
}
$USER = Auth::user();

/* CRITICAL: release the session file lock before the long-lived loop.
 * Holding it blocks every other request from this browser (session_start()
 * in Auth::start()) for the entire SSE lifetime — that caused the "slow
 * page load / calendar never loads" lag. Keep the connection alive until
 * the client disconnects instead of dying at max_execution_time. */
session_write_close();
set_time_limit(0);
ignore_user_abort(false);

/* ── SSE headers ──────────────────────────────────────────────────────── */
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');          // nginx: disable proxy buffering
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');        // Apache: don't compress SSE
}
@ini_set('zlib.output_compression', '0');

/* ── Flush initial connection ─────────────────────────────────────────── */
while (ob_get_level()) { ob_end_flush(); }
if (ob_get_length() === 0) { echo str_repeat(':', 2) . "\n"; flush(); }

$userId   = (int) $USER['id'];
$roleId   = (int) $USER['role_id'];
/* Resume from the last event the client saw (EventSource sends
 * Last-Event-ID on reconnect) so notifications are not re-sent. */
$lastId   = max(0, (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0));
$sentinel = 0;      // heartbeat counter
$interval = 15;     // seconds between polls (was 10s — lighter on shared hosting)
$lastBadges = null;  // track last-sent badge values to avoid unnecessary DOM churn

while (true) {
    /* ── Check if client disconnected ─────────────────────────────── */
    if (connection_aborted()) { break; }

    /* ── 1. Stream notifications not yet toasted (exactly once) ───── */
    try {
        $st = $pdo->prepare(
            'SELECT id, ticket_id, type, message, created_at
             FROM notifications
             WHERE user_id = ? AND id > ? AND notified_at IS NULL
             ORDER BY id ASC'
        );
        $st->execute([$userId, $lastId]);
        $rows = $st->fetchAll();

        foreach ($rows as $r) {
            $lastId = max($lastId, (int) $r['id']);
            $data = json_encode([
                'id'         => (int) $r['id'],
                'ticket_id'  => $r['ticket_id'] !== null ? (int) $r['ticket_id'] : null,
                'type'       => $r['type'],
                'message'    => $r['message'],
                'created_at' => $r['created_at'],
            ], JSON_UNESCAPED_UNICODE);
            echo "id: {$lastId}\nevent: notification\ndata: {$data}\n\n";
        }

        // Mark delivered only after they were written to the stream, so a
        // dropped connection re-delivers instead of silently losing a toast.
        if ($rows) {
            flush();
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE notifications SET notified_at = NOW() WHERE id IN ($ph)")
                ->execute($ids);
        }
    } catch (Throwable $e) {
        error_log('[sse] notification query: ' . $e->getMessage());
    }

    /* ── 2. Compute + stream badge counts (only when changed) ──────── */
    try {
        $badges = computeBadgeCounts($pdo, $roleId, $userId);
        $badgeKey = json_encode($badges);
        if ($badgeKey !== $lastBadges) {
            $lastBadges = $badgeKey;
            $data = json_encode($badges, JSON_UNESCAPED_UNICODE);
            echo "event: badges\ndata: {$data}\n\n";
        }
    } catch (Throwable $e) {
        error_log('[sse] badge query: ' . $e->getMessage());
    }

    /* ── 3. Heartbeat (keeps connection alive through proxies) ────── */
    $sentinel++;
    echo ": heartbeat {$sentinel}\n\n";
    flush();

    /* ── 4. Throttle: wait before next poll ───────────────────────── */
    $start = time();
    while (time() - $start < $interval) {
        if (connection_aborted()) break 2;
        usleep(250000); // 250ms
    }
}

/* ── Helpers ──────────────────────────────────────────────────────────── */

function computeBadgeCounts(PDO $pdo, int $roleId, int $userId): array
{
    $pending  = 0;
    $approved = 0;
    $notifCount = 0;

    // Pending tickets (for SAO role 2)
    if ($roleId === 2) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM trip_tickets
             WHERE is_active = 1 AND status_id = 2
               AND (scheduled_departure IS NULL OR scheduled_departure >= NOW())'
        );
        $st->execute();
        $pending = (int) $st->fetchColumn();
    }

    // Approved tickets (for GSD role 3)
    if ($roleId === 3) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM trip_tickets
             WHERE is_active = 1 AND status_id = 4
               AND (scheduled_departure IS NULL OR scheduled_departure >= NOW())'
        );
        $st->execute();
        $approved = (int) $st->fetchColumn();
    }

    // Unread notification count (all roles)
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
    );
    $st->execute([$userId]);
    $notifCount = (int) $st->fetchColumn();

    return [
        'pending'       => $pending,
        'approved'      => $approved,
        'notifications' => $notifCount,
    ];
}
