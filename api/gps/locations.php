<?php
declare(strict_types=1);
/**
 * api/gps/locations.php — device ingestion endpoint (Bearer token auth).
 * POST single fix { latitude, longitude, speed_kph?, heading?, altitude_m?,
 *                   accuracy_m?, ignition?, recorded_at? }
 * POST batch replay [ {…fix…}, … ] (1..GpsIngest::BATCH_MAX) — v6.8
 * store-and-forward catch-up; same pipeline, one transaction.
 * Success = 204 no body (devices never parse responses). Errors = JSON.
 * MUST stay web-accessible (no .htaccess deny) — auth is inside PHP.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Gps.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

// HTTPS is mandatory unless explicitly allowed (dev/capstone LAN only). Keeps
// the device bearer token off the wire. Flip GPS_ALLOW_INSECURE_HTTP=false in
// MIS → System Settings → GPS Telemetry for production.
if (!Settings::getBool('GPS_ALLOW_INSECURE_HTTP', true)
    && empty($_SERVER['HTTPS']) && (string) ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'HTTPS required']);
    exit;
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($header === '' && function_exists('apache_request_headers')) {
    $header = apache_request_headers()['Authorization'] ?? '';
}
$bearer = '';
if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
    $bearer = trim($m[1]);
}

$input = file_get_contents('php://input');
$in = is_string($input) ? json_decode($input, true) : null;
$raw = $_POST ?: (is_array($in) ? $in : []);

// JSON list = batch replay of a store-and-forward backlog (v6.8); anything else
// keeps the classic single-fix contract ($_POST form submissions are always
// single-fix). Both funnel into the same GpsIngest pipeline. List check is
// manual — array_is_list() needs PHP 8.1 and this stack still runs 8.0.
$isList = is_array($raw) && $raw !== [] && array_keys($raw) === range(0, count($raw) - 1);
if ($isList) {
    $out = GpsIngest::handleBatch($pdo, $bearer, $raw);
} else {
    $out = GpsIngest::handle($pdo, $bearer, $raw);
}

// Durable security logging for device auth/ingestion failures (flood-capped).
if ($out['code'] !== 204) {
    $event = match ($out['code']) {
        401     => 'DEVICE_AUTH_FAILED',
        400     => 'DEVICE_PAYLOAD_INVALID',
        default => 'DEVICE_INGEST_FAILED',
    };
    if (SecurityEvent::recentByIp($pdo, $event, 60) < 30) {
        SecurityEvent::log($pdo, $event);
    }
}

if ($out['code'] === 204) {
    http_response_code(204);
    exit;
}

http_response_code($out['code']);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => $out['message']]);
