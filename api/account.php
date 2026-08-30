<?php
declare(strict_types=1);
/**
 * api/account.php — self-service account actions. JSON in / JSON out.
 * POST { action, csrf_token, ... }. Any authenticated role, every write audited.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

function out(bool $ok, string $msg = ''): never
{
    if (!$ok) {
        http_response_code(400);
    }
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'POST only.');
}
if (!Csrf::verifyRequest($in)) {
    out(false, 'Invalid or missing CSRF token.');
}
$u = Auth::requireApi($pdo, ['*']);
$action = (string) ($in['action'] ?? '');

try {
    switch ($action) {

        case 'profile_save': {
            $name = trim((string) ($in['full_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                out(false, 'Full name is required (max 150 characters).');
            }
            $pdo->prepare('UPDATE account SET full_name = ? WHERE account_id = ?')
                ->execute([$name, $u['id']]);
            $_SESSION['full_name'] = $name;
            Audit::log($pdo, 'MODIFY', 'account:' . $u['id'], null, "self profile update ({$name})");
            out(true, 'Profile updated.');
        }

        case 'password_change': {
            $cur = (string) ($in['current_password'] ?? '');
            $new = (string) ($in['new_password'] ?? '');
            if ($cur === '' || $new === '') {
                out(false, 'Current and new passwords are required.');
            }
            if (strlen($new) < 8) {
                out(false, 'New password must be at least 8 characters.');
            }
            if ($cur === $new) {
                out(false, 'New password must be different from the current password.');
            }
            $st = $pdo->prepare('SELECT password FROM account WHERE account_id = ?');
            $st->execute([$u['id']]);
            $row = $st->fetch();
            if (!$row || !password_verify($cur, (string) $row['password'])) {
                Audit::log($pdo, 'MODIFY', 'account:' . $u['id'], null, null, 'FAILED');
                out(false, 'Current password is incorrect.');
            }
            $pdo->prepare('UPDATE account SET password = ? WHERE account_id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $u['id']]);
            Audit::log($pdo, 'MODIFY', 'account:' . $u['id'], null, 'self password change');
            out(true, 'Password changed successfully.');
        }

        default:
            out(false, 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[account] ' . $e->getMessage());
    out(false, 'Server error. Please try again.');
}
