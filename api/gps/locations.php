<?php
declare(strict_types=1);
/**
 * api/gps/locations.php — device ingestion endpoint (Bearer token auth).
 * POST { latitude, longitude, speed_kph?, heading?, altitude_m?, accuracy_m?,
 *        ignition?, recorded_at? }
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

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($header === '' && function_exists('apache_request_headers')) {
    $header = apache_request_headers()['Authorization'] ?? '';
}
$bearer = '';
if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
    $bearer = trim($m[1]);
}

$raw = $_POST ?: (json_decode(file_get_contents('php://input') ?: '', true) ?: []);

$out = GpsIngest::handle($pdo, $bearer, $raw);

if ($out['code'] === 204) {
    http_response_code(204);
    exit;
}

http_response_code($out['code']);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => $out['message']]);
