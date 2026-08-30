<?php
/**
 * core/Auth.php — session authentication + RBAC guards.
 */

class Auth
{
    /** role_id => landing page after login */
    public const LANDING = [
        1 => BASE_URL . 'mis/dashboard.php',
        2 => BASE_URL . 'director/dashboard.php',
        3 => BASE_URL . 'staff/dashboard.php',
        4 => BASE_URL . 'driver/dashboard.php',
        5 => BASE_URL . 'guard/desk.php',
        6 => BASE_URL . 'personnel/requests.php',
    ];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** Attempt credential login. Returns [ok, error]. On success session is populated. */
    public static function login(PDO $pdo, string $email, string $password): array
    {
        self::start();
        $email = strtolower(trim($email));

        $stmt = $pdo->prepare(
            'SELECT a.*, r.role_name FROM account a
             LEFT JOIN roles r ON r.role_id = a.role_id
             WHERE a.email = ? AND a.is_active = 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            return [false, 'Invalid email or password.'];
        }

        $maxAttempts = Settings::getInt('LOGIN_MAX_ATTEMPTS', 3);
        $lockMins    = Settings::getInt('LOGIN_LOCKOUT_MINS', 1);

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            $mins = (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60);
            return [false, "Account locked. Try again in {$mins} minute" . ($mins > 1 ? 's' : '') . '.'];
        }
        if (!empty($user['locked_until'])) { // lock expired → reset counters
            $pdo->prepare('UPDATE account SET login_attempts = 0, locked_until = NULL WHERE account_id = ?')
                ->execute([$user['account_id']]);
        }

        if (!password_verify($password, (string) $user['password'])) {
            $attempts = (int) $user['login_attempts'] + 1;
            if ($attempts >= $maxAttempts) {
                $pdo->prepare('UPDATE account SET login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE account_id = ?')
                    ->execute([$attempts, $lockMins, $user['account_id']]);
                Audit::log($pdo, 'LOGIN', 'account:' . $user['account_id'], null, null, 'FAILED');
                return [false, "Too many failed attempts. Account locked for {$lockMins} minute(s)."];
            }
            $pdo->prepare('UPDATE account SET login_attempts = ? WHERE account_id = ?')
                ->execute([$attempts, $user['account_id']]);
            Audit::log($pdo, 'LOGIN', 'account:' . $user['account_id'], null, null, 'FAILED');
            $left = $maxAttempts - $attempts;
            return [false, "Invalid email or password. {$left} attempt" . ($left > 1 ? 's' : '') . ' remaining.'];
        }

        // Success
        $pdo->prepare('UPDATE account SET login_attempts = 0, locked_until = NULL WHERE account_id = ?')
            ->execute([$user['account_id']]);

        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['account_id'];
        $_SESSION['role_id']   = (int) $user['role_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['last_active'] = time();
        $_SESSION['_id_regen_at'] = time();

        Audit::log($pdo, 'LOGIN', 'account:' . $user['account_id']);
        return [true, null];
    }

    /** Current user row from session, or null. */
    public static function user(): ?array
    {
        self::start();
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id'        => (int) $_SESSION['user_id'],
            'role_id'   => (int) ($_SESSION['role_id'] ?? 0),
            'full_name' => (string) ($_SESSION['full_name'] ?? ''),
            'role_name' => (string) ($_SESSION['role_name'] ?? ''),
            'email'     => (string) ($_SESSION['email'] ?? ''),
        ];
    }

    /** Re-hydrate session from DB if the account vanished/deactivated mid-session. */
    public static function refresh(PDO $pdo): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT a.account_id, a.role_id, a.full_name, a.email, r.role_name
             FROM account a LEFT JOIN roles r ON r.role_id = a.role_id
             WHERE a.account_id = ? AND a.is_active = 1'
        );
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            self::destroySession();
            return false;
        }
        $_SESSION['user_id']   = (int) $row['account_id'];
        $_SESSION['role_id']   = (int) $row['role_id'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['role_name'] = $row['role_name'];
        $_SESSION['email']     = $row['email'];
        return true;
    }

    /**
     * Page guard: allow only listed roles; re-authenticates stale sessions;
     * redirects to login on failure. Periodically regenerates the session ID.
     */
    public static function requirePage(PDO $pdo, array $roles): array
    {
        self::start();
        if (!self::refresh($pdo)) {
            header('Location: ' . BASE_URL . 'auth/login.php');
            exit;
        }
        $u = self::user();
        if (!in_array($u['role_id'], $roles, true)) {
            header('Location: ' . BASE_URL . 'auth/login.php');
            exit;
        }
        $regenAfter = Settings::getInt('SESSION_REGEN_INTERVAL', 900);
        if ((time() - (int) ($_SESSION['_id_regen_at'] ?? 0)) > $regenAfter) {
            session_regenerate_id(true);
            $_SESSION['_id_regen_at'] = time();
        }
        return $u;
    }

    /** API guard variant: emits JSON error + exits instead of redirecting. */
    public static function requireApi(PDO $pdo, array $roles): array
    {
        self::start();
        header('Content-Type: application/json');
        if (!self::refresh($pdo)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
            exit;
        }
        $u = self::user();
        if ($roles !== ['*'] && !in_array($u['role_id'], $roles, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden for your role.']);
            exit;
        }
        return $u;
    }

    /** Logout with audit; safe to call when already logged out. */
    public static function logout(PDO $pdo): void
    {
        self::start();
        if (!empty($_SESSION['user_id'])) {
            Audit::log($pdo, 'LOGOUT', 'account:' . (int) $_SESSION['user_id']);
        }
        self::destroySession();
    }

    private static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }
}
