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

if (!empty($_SESSION['user_id'])) {
    Audit::log($pdo, 'LOGOUT', 'account:' . (int) $_SESSION['user_id']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}
http_response_code(204);
