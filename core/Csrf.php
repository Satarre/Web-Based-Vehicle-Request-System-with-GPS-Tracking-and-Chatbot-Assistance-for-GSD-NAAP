<?php
/**
 * core/Csrf.php — CSRF token mint + verify for all session-authenticated POST APIs.
 */

class Csrf
{
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Accepts token from X-CSRF-Token header, JSON body `csrf_token`, or form `_csrf`.
     * Constant-time compare; false on any mismatch/absence.
     */
    public static function verify(?string $bodyToken = null): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $expected = $_SESSION['csrf_token'] ?? '';
        $provided = $bodyToken
            ?: ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
        return $expected !== '' && $provided !== ''
            && hash_equals($expected, (string) $provided);
    }

    /** For JSON endpoints: parse php://input once and verify against it + header. */
    public static function verifyRequest(array $jsonBody = []): bool
    {
        return self::verify($jsonBody['csrf_token'] ?? null);
    }
}
