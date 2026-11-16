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
        7 => BASE_URL . 'tracker.php',
    ];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Verify credentials only — never touches the session. Returns [ok, error, user|null].
     * User row is returned so callers can branch (MFA challenge, forced MFA setup, …)
     * without a partially-authenticated session.
     */
    public static function attempt(PDO $pdo, string $email, string $password): array
    {
        self::start();
        $email = strtolower(trim($email));

        // Per-IP throttle across all accounts (credential stuffing / password spraying).
        $ipMax    = max(1, Settings::getInt('LOGIN_IP_MAX', 20));
        $ipWindow = max(1, Settings::getInt('LOGIN_IP_WINDOW_SEC', 900));
        if (!RateLimiter::hit($pdo, 'LOGIN_ATTEMPT', null, $ipMax, $ipWindow)) {
            SecurityEvent::log($pdo, 'LOGIN_THROTTLED');
            return [false, 'Too many login attempts. Please try again later.', null];
        }

        $stmt = $pdo->prepare(
            'SELECT a.*, r.role_name FROM account a
             LEFT JOIN roles r ON r.role_id = a.role_id
             WHERE a.email = ? AND a.is_active = 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            SecurityEvent::log($pdo, 'LOGIN_FAILED');
            return [false, 'Invalid email or password.', null];
        }

        if (($locked = self::lockedMessage($user['locked_until'] ?? null)) !== null) {
            return [false, $locked, null];
        }

        if (Settings::getBool('REQUIRE_EMAIL_VERIFICATION') && empty($user['email_verified_at'])) {
            SecurityEvent::log($pdo, 'LOGIN_BLOCKED_UNVERIFIED', (int) $user['account_id']);
            return [false, "Your email address has not been verified yet.\nCheck your inbox.", null];
        }

        if (!password_verify($password, (string) $user['password'])) {
            [, $error] = self::recordLoginFailure($pdo, $user, 'Invalid email or password');
            return [false, $error, null];
        }

        return [true, null, $user];
    }

    /** "Account locked…" message while locked_until is in the future, else null. */
    public static function lockedMessage(?string $lockedUntil): ?string
    {
        $ts = $lockedUntil ? strtotime($lockedUntil) : false;
        if ($ts === false || $ts <= time()) {
            return null;
        }
        $mins = (int) ceil(($ts - time()) / 60);
        return "Account locked.\nTry again in {$mins} minute" . ($mins > 1 ? 's' : '') . '.';
    }

    /**
     * Count one failed sign-in attempt, lock the account at the configured
     * threshold, and audit it. Shared by the password and MFA stages.
     *
     * @return array{0: bool, 1: string} [locked, user-facing message]
     */
    public static function recordLoginFailure(PDO $pdo, array $user, string $reason, string $eventType = 'LOGIN_FAILED'): array
    {
        $maxAttempts = max(1, Settings::getInt('LOGIN_MAX_ATTEMPTS', 3));
        $lockMins    = max(1, Settings::getInt('LOGIN_LOCKOUT_MINS', 1));

        // Compute the new count in PHP and bind it: MySQL evaluates SET
        // assignments left-to-right, so `login_attempts + 1` in the CASE
        // would read the already-incremented value and lock one attempt
        // early while the message below reported the true remaining count.
        $attempts = (int) $user['login_attempts'] + 1;
        $pdo->prepare('UPDATE account SET login_attempts = ?,
            locked_until = CASE WHEN ? >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE) ELSE locked_until END
            WHERE account_id = ?')
            ->execute([$attempts, $attempts, $maxAttempts, $lockMins, $user['account_id']]);

        Audit::log($pdo, 'LOGIN', 'account:' . (int) $user['account_id'], null, null, 'FAILED');
        SecurityEvent::log($pdo, $eventType, (int) $user['account_id']);

        if ($attempts >= $maxAttempts) {
            return [true, "Too many failed attempts.\nAccount locked for {$lockMins} minute(s)."];
        }
        $left = $maxAttempts - $attempts;
        return [false, $reason . "\n{$left} attempt" . ($left > 1 ? 's' : '') . ' remaining.'];
    }

    /** Populate the authenticated session for a verified user (credential or MFA stage). */
    public static function finalize(PDO $pdo, array $user): void
    {
        self::start();
        $pdo->prepare('UPDATE account SET login_attempts = 0, locked_until = NULL WHERE account_id = ?')
            ->execute([$user['account_id']]);

        session_regenerate_id(true);
        self::hydrateSession($user);
        $_SESSION['last_active'] = time();
        $_SESSION['_id_regen_at'] = time();

        Audit::log($pdo, 'LOGIN', 'account:' . (int) $user['account_id'], 'account logged out', 'account logged in');
        SecurityEvent::log($pdo, 'LOGIN', (int) $user['account_id']);
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
            'is_super_admin' => (int) ($_SESSION['is_super_admin'] ?? 0),
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
        // Server-side idle timeout (the client overlay in assets/js/idle.js is UX only).
        $idleSec = Settings::getInt('IDLE_TIMEOUT_SEC', 300);
        if ($idleSec > 0 && isset($_SESSION['last_active'])
            && (time() - (int) $_SESSION['last_active']) > $idleSec) {
            self::destroySession();
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT a.account_id, a.role_id, a.is_super_admin, a.full_name, a.email, a.locked_until, a.sessions_version, r.role_name
             FROM account a LEFT JOIN roles r ON r.role_id = a.role_id
             WHERE a.account_id = ? AND a.is_active = 1'
        );
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            self::destroySession();
            return false;
        }
        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            self::destroySession();
            return false;
        }
        // Session version bump (password change/reset) invalidates this session.
        if (isset($_SESSION['sessions_version'])
            && (int) $row['sessions_version'] !== (int) $_SESSION['sessions_version']) {
            self::destroySession();
            return false;
        }
        self::hydrateSession($row);
        $_SESSION['last_active'] = time();
        return true;
    }

    /** Copy the account identity fields into the session (login + refresh paths). */
    private static function hydrateSession(array $u): void
    {
        $_SESSION['user_id']   = (int) $u['account_id'];
        $_SESSION['role_id']   = (int) $u['role_id'];
        $_SESSION['is_super_admin'] = (int) ($u['is_super_admin'] ?? 0);
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['role_name'] = $u['role_name'];
        $_SESSION['email']     = $u['email'];
        $_SESSION['sessions_version'] = (int) ($u['sessions_version'] ?? 0);
    }

    /** role_ids where MFA is REQUIRED (configurable via MFA_REQUIRED_ROLES). */
    public static function mfaRequiredRoles(): array
    {
        return MFA_ENFORCED ? self::roleIds('MFA_REQUIRED_ROLES', '1,2,3') : [];
    }

    /** role_ids where MFA is RECOMMENDED (configurable via MFA_RECOMMENDED_ROLES). */
    public static function mfaRecommendedRoles(): array
    {
        return self::roleIds('MFA_RECOMMENDED_ROLES', '5');
    }

    /** Comma-separated role_id setting → integer list (zero/invalid values dropped). */
    private static function roleIds(string $key, string $default): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) Settings::get($key, $default)))));
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
            Audit::log($pdo, 'LOGOUT', 'account:' . (int) $_SESSION['user_id'], 'account logged in', 'account logged out');
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
