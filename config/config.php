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
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.gc_maxlifetime', '3600');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
}

/* ── Paths & locale ─────────────────────────────────────────────────── */
define('APP_ROOT', dirname(__DIR__));

/* ── Secrets (config/secrets.php is gitignored; env vars override) ────
 * Loaded BEFORE the DB constants so config/secrets.php can provide
 * DB_HOST/DB_NAME/DB_USER/DB_PASS too.
 * APP_SECRET_KEY: AES-256-GCM key used to encrypt TOTP secrets at rest.
 * APP_ENV: 'dev'|'prod' — gates dev-only behavior (MFA toggle, inline OTP).
 * GOOGLE_MAPS_API_KEY: client-side Maps JS key (secrets or env; still echoed
 *   to the browser — restrict it by HTTP referrer in Google Cloud Console).
 * GOOGLE_ROUTES_API_KEY: server-side key (secrets or env); required for server
 *   routing/snapping, restrict it by IP. Never reuse the browser key server-side.
 * SMTP_HOST/PORT/SECURE/USER/PASS override app_settings when set.       */
if (is_file(APP_ROOT . '/config/secrets.php')) {
    require_once APP_ROOT . '/config/secrets.php';
}
if (!defined('APP_ENV')) {
    define('APP_ENV', (string) (getenv('APP_ENV') ?: 'prod')); // safe default
}
if (!defined('APP_SECRET_KEY')) {
    define('APP_SECRET_KEY', (string) (getenv('APP_SECRET_KEY') ?: ''));
}
if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', (string) (getenv('GOOGLE_MAPS_API_KEY') ?: ''));
}
if (!defined('GOOGLE_ROUTES_API_KEY')) {
    define('GOOGLE_ROUTES_API_KEY', (string) (getenv('GOOGLE_ROUTES_API_KEY') ?: ''));
}

/* ── Dev-only MFA toggle (config/mfa_toggle.php is gitignored) ─────────
 * Loaded ONLY in dev — production always enforces MFA regardless of what
 * the toggle file (or an accidental upload) says. */
if (APP_ENV === 'dev' && is_file(APP_ROOT . '/config/mfa_toggle.php')) {
    require_once APP_ROOT . '/config/mfa_toggle.php';
}
if (!defined('MFA_ENFORCED')) {
    define('MFA_ENFORCED', true);
}
foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_FROM_NAME'] as $envKey) {
    $envVal = getenv($envKey);
    if ($envVal !== false && $envVal !== '') {
        define('ENV_' . $envKey, $envVal);
    }
}

/* ── Database (MySQL/MariaDB) — overridable via env or config/secrets.php ── */
if (!defined('DB_HOST')) define('DB_HOST', (string) (getenv('DB_HOST') ?: 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', (string) (getenv('DB_NAME') ?: 'naap_mn_vts'));
if (!defined('DB_USER')) define('DB_USER', (string) (getenv('DB_USER') ?: 'root'));
if (!defined('DB_PASS')) define('DB_PASS', (string) (getenv('DB_PASS') ?: ''));

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
    $pdo->exec("SET time_zone = '+08:00'");
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
/* BASE_URL may be defined in config/secrets.php (or an env var) for the
 * deployment. Hostinger at the domain root → '/' ; in a subfolder →
 * '/subfolder/'. Default keeps the local XAMPP path working. */
if (!defined('BASE_URL')) {
    $envBase = (string) (getenv('BASE_URL') ?: '');
    define('BASE_URL', $envBase !== '' ? rtrim($envBase, '/') . '/' : '/NAAP_MN_VTS_Capstone/');
}
ini_set('date.timezone', 'Asia/Manila');
date_default_timezone_set('Asia/Manila');

/* ── Shared JSON-exit for api/*.php ─────────────────────────────────── */
if (!function_exists('out')) {
    function out(bool $ok, string $msg = '', array $extra = []): never
    {
        http_response_code($ok ? 200 : 400);
        echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
        exit;
    }
}

/* ── Normalized account name → display full_name ("LAST, FIRST M. SUFFIX") ── */
if (!function_exists('account_display_name')) {
    function account_display_name(string $first, ?string $mi, string $last, ?string $suffix): string
    {
        $first  = trim($first);
        $last   = trim($last);
        $mi     = trim((string) $mi);
        $suffix = trim((string) $suffix);
        $name   = $last !== '' ? strtoupper($last) . ', ' . strtoupper($first) : strtoupper($first);
        if ($mi !== '') {
            $mi = strtoupper($mi);
            if (strlen($mi) === 1) {
                $mi .= '.';
            }
            $name .= ' ' . $mi;
        }
        if ($suffix !== '') {
            $name .= ' ' . strtoupper($suffix);
        }
        return $name;
    }
}

/* ── Passenger name parts → composed display name ("LAST, FIRST M.") ── */
if (!function_exists('passenger_display_name')) {
    function passenger_display_name(string $first, ?string $mi, string $last): string
    {
        $first = trim($first);
        $last  = trim($last);
        $mi    = trim((string) $mi);
        $name  = $last !== '' ? strtoupper($last) . ', ' . strtoupper($first) : strtoupper($first);
        if ($mi !== '') {
            $mi = strtoupper($mi);
            if (strlen($mi) === 1) {
                $mi .= '.';
            }
            $name .= ' ' . $mi;
        }
        return trim($name);
    }
}

/* ── Autoload kernel classes (core/, gps/) + Composer vendors ──────── */
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    // Multi-class file: Geo/GpsIngest/HttpJsonProvider live in core/Gps.php.
    if (in_array($class, ['Geo', 'GpsIngest', 'HttpJsonProvider'], true)) {
        $multi = APP_ROOT . '/core/Gps.php';
        if (is_file($multi)) {
            require_once $multi;
            return;
        }
    }
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

/* ── Session activity stamping ────────────────────────────────────────
 * Intentionally NOT done here: Auth::refresh() checks IDLE_TIMEOUT_SEC
 * against the previous last_active, then stamps it. Stamping before the
 * check (as this file previously did) made the server-side idle timeout
 * impossible to ever trigger. */
