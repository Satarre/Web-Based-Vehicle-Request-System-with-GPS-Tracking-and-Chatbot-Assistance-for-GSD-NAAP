<?php
declare(strict_types=1);

/**
 * config/config.php — application bootstrap (Phase 0)
 * Include from every entrypoint: require_once __DIR__ . '/../config/config.php';
 */

/* ── Direct-access guard ─────────────────────────────────────────────── */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.php') {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/* ── Session hardening ──────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.gc_maxlifetime', '3600');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
}

/* ── Database (MySQL/MariaDB) ───────────────────────────────────────── */
define('DB_HOST', 'localhost');
define('DB_NAME', 'naap_mn_vts');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    http_response_code(500);
    error_log('[config] DB connection failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please try again later.']);
    exit;
}

/* ── Paths & locale ─────────────────────────────────────────────────── */
define('BASE_URL', '/NAAP_MN_VTS_Capstone/');
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
date_default_timezone_set('Asia/Manila');

/* ── Autoload kernel classes (core/, gps/) ──────────────────────────── */
spl_autoload_register(static function (string $class): void {
    foreach (['core', 'gps'] as $dir) {
        $file = APP_ROOT . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

require_once APP_ROOT . '/core/Settings.php';
Settings::init($pdo);

/* ── Session activity stamping ──────────────────────────────────────── */
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    $_SESSION['last_active'] = time();
}
