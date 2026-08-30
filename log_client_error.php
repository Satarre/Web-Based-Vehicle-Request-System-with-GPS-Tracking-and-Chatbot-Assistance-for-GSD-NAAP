<?php
declare(strict_types=1);
/**
 * log_client_error.php — fire-and-forget sink for error_logger.js (root path
 * matches its ENDPOINT constant). Unauthenticated by design (errors fire on the
 * login page too); strictly length-limited before hitting audit_trail.
 */
require_once __DIR__ . '/config/config.php';

http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    exit;
}

$clip = static fn(mixed $v, int $n): ?string => $v === null || $v === '' ? null : mb_substr((string) $v, 0, $n);

$action  = $clip($in['action'] ?? null, 80)        ?? 'UNKNOWN';
$type    = $clip($in['error_type'] ?? null, 20)    ?? 'JS_ERROR';
$message = $clip($in['error_message'] ?? null, 500) ?? '';
$url     = $clip($in['page_url'] ?? null, 255);

$uid = 0;
$email = trim((string) ($in['user_email'] ?? ''));
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $st = $pdo->prepare('SELECT account_id FROM account WHERE email = ? LIMIT 1');
    $st->execute([strtolower($email)]);
    $uid = (int) ($st->fetchColumn() ?: 0);
}

Audit::log($pdo, 'ERROR', 'client:' . $action, $url, $type . ' | ' . $message, $type, $uid);
