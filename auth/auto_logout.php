<?php
/**
 * auth/auto_logout.php — sendBeacon receiver (tab close).
 * Skips when the session was active within the last 5 s (toolbar refresh edge case).
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();

if (!empty($_SESSION['last_active']) && (time() - (int) $_SESSION['last_active']) < 5) {
    http_response_code(204);
    exit;
}

Auth::logout($pdo);
http_response_code(204);
