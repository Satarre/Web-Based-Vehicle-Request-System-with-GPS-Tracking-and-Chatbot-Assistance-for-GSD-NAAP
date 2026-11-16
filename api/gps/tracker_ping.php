<?php
declare(strict_types=1);
/**
 * api/gps/tracker_ping.php — session-authenticated ingestion for hidden
 * tracker accounts (browser sharing on tracker.php). Role GPS TRACKER only.
 *
 * POST { latitude, longitude, speed_kph?, heading?, altitude_m?, accuracy_m?,
 *        recorded_at? } → JSON { success, stored, throttled }
 * Feeds the exact same pipeline as hardware: GpsIngest::handleAccount().
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'POST only.');
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
if (!Csrf::verifyRequest($in)) {
    out(false, 'Invalid or missing CSRF token.');
}

$u = Auth::requireApi($pdo, [7]);

// Flood guard: normal cadence is 1 fix per GPS_MIN_INTERVAL_SEC; allow bursts
// for offline-queue flushes. Over-cap attempts are rejected, not recorded.
if (!RateLimiter::hit($pdo, 'TRACKER_PING', (int) $u['id'], 30, 60)) {
    SecurityEvent::log($pdo, 'TRACKER_PING_FAILED', (int) $u['id']);
    out(false, 'Too many updates — please slow down.');
}

$res = GpsIngest::handleAccount($pdo, (int) $u['id'], $in);

if ($res['code'] === 204) {
    echo json_encode([
        'success'   => true,
        'message'   => '',
        'stored'    => (bool) $res['stored'],
        'throttled' => (bool) $res['throttled'],
    ]);
    exit;
}

// Durable, flood-capped logging for abnormal ingest failures.
if (SecurityEvent::recentByIp($pdo, 'TRACKER_PING_FAILED', 60) < 30) {
    SecurityEvent::log($pdo, 'TRACKER_PING_FAILED', (int) $u['id']);
}
http_response_code($res['code'] >= 400 ? $res['code'] : 400);
echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Rejected.']);
