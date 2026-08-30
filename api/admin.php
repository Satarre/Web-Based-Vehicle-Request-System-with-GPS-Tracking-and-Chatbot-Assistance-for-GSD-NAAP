<?php
declare(strict_types=1);
/**
 * api/admin.php — Phase 3 MIS ADMIN mutations. JSON in / JSON out.
 * POST { action, csrf_token, ... }. Role 1 only, every write audited.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

function out(bool $ok, string $msg = '', array $extra = []): never
{
    if (!$ok) {
        http_response_code(400);
    }
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
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
$u = Auth::requireApi($pdo, [1, 2]);
$action = (string) ($in['action'] ?? '');
$clip = static fn(?string $s, int $n): ?string => ($s === null || trim($s) === '') ? null : mb_substr(trim($s), 0, $n);

try {
    switch ($action) {

        case 'vehicle_save': {
            $vid   = (int) ($in['vehicle_id'] ?? 0);
            $plate = strtoupper(trim((string) ($in['plate_number'] ?? '')));
            $model = trim((string) ($in['model_name'] ?? ''));
            $brand = $clip($in['brand'] ?? null, 50);
            $typeId = (int) ($in['type_id'] ?? 0);
            $cap   = max(1, min(999, (int) ($in['capacity'] ?? 1)));
            $fuel  = (($in['default_fuel_type'] ?? '') === 'Diesel') ? 'Diesel' : 'Gasoline';
            if ($plate === '' || $model === '' || !$typeId) {
                out(false, 'Plate number, model, and type are required.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM vehicle WHERE plate_number = ? AND vehicle_id != ?');
            $st->execute([$plate, $vid]);
            if ((int) $st->fetchColumn() > 0) {
                out(false, "Plate number {$plate} is already registered.");
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM vehicle_types WHERE type_id = ? AND is_active = 1');
            $st->execute([$typeId]);
            if (!(int) $st->fetchColumn()) {
                out(false, 'Invalid vehicle type.');
            }
            if ($vid > 0) {
                $pdo->prepare('UPDATE vehicle SET plate_number=?, model_name=?, brand=?, type_id=?, capacity=?, default_fuel_type=?, is_available=? WHERE vehicle_id=?')
                    ->execute([$plate, $model, $brand, $typeId, $cap, $fuel, !empty($in['is_available']) ? 1 : 0, $vid]);
                Audit::log($pdo, 'MODIFY', 'vehicle:' . $vid, null, "updated {$plate}");
                out(true, 'Vehicle updated.');
            }
            $pdo->prepare('INSERT INTO vehicle (plate_number, model_name, brand, type_id, capacity, default_fuel_type, is_available, managed_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$plate, $model, $brand, $typeId, $cap, $fuel, !empty($in['is_available']) ? 1 : 0, $u['id']]);
            $newId = (int) $pdo->lastInsertId();
            Audit::log($pdo, 'CREATE', 'vehicle:' . $newId, null, "registered {$plate}");
            out(true, "Vehicle {$plate} registered.");
        }

        case 'vehicle_toggle': {
            $vid = (int) ($in['vehicle_id'] ?? 0);
            $st  = $pdo->prepare('SELECT plate_number, is_active FROM vehicle WHERE vehicle_id = ?');
            $st->execute([$vid]);
            $v = $st->fetch();
            if (!$v) {
                out(false, 'Vehicle not found.');
            }
            $to = (int) $v['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE vehicle SET is_active = ? WHERE vehicle_id = ?')->execute([$to, $vid]);
            Audit::log($pdo, 'MODIFY', 'vehicle:' . $vid, 'active=' . $v['is_active'], 'active=' . $to);
            out(true, $to ? 'Vehicle activated.' : 'Vehicle deactivated.');
        }

        case 'type_add': {
            $name = trim((string) ($in['type_name'] ?? ''));
            if ($name === '') {
                out(false, 'Type name is required.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM vehicle_types WHERE type_name = ?');
            $st->execute([$name]);
            if ((int) $st->fetchColumn()) {
                out(false, 'That type already exists.');
            }
            $pdo->prepare('INSERT INTO vehicle_types (type_name) VALUES (?)')->execute([mb_substr($name, 0, 50)]);
            Audit::log($pdo, 'CREATE', 'vehicle_types:' . $pdo->lastInsertId(), null, $name);
            out(true, 'Type added.');
        }

        case 'device_register': {
            // Raw token returned ONCE here; only sha256 is stored/logged.
            $uid  = trim((string) ($in['device_uid'] ?? ''));
            $name = $clip($in['device_name'] ?? null, 100);
            if ($uid === '') {
                out(false, 'Device UID is required.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM gps_devices WHERE device_uid = ?');
            $st->execute([$uid]);
            if ((int) $st->fetchColumn()) {
                out(false, 'Device UID already registered.');
            }
            $raw   = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $raw);
            $pdo->prepare('INSERT INTO gps_devices (device_uid, device_name, manufacturer, model, protocol, api_token_hash, installed_at, registered_by) VALUES (?,?,?,?,?,?,NOW(),?)')
                ->execute([
                    mb_substr($uid, 0, 64), $name,
                    $clip($in['manufacturer'] ?? null, 50), $clip($in['model'] ?? null, 50),
                    in_array(($in['protocol'] ?? ''), ['http-json'], true) ? $in['protocol'] : 'http-json',
                    $hash, $u['id'],
                ]);
            Audit::log($pdo, 'CREATE', 'gps_devices:' . $pdo->lastInsertId(), null, 'registered ' . $uid);
            out(true, 'Device registered. Copy the token now — it will not be shown again.', ['api_token' => $raw]);
        }

        case 'device_pair': {
            $did = (int) ($in['device_id'] ?? 0);
            $vid = (int) ($in['vehicle_id'] ?? 0);
            $st  = $pdo->prepare('SELECT device_uid FROM gps_devices WHERE device_id = ?');
            $st->execute([$did]);
            $d = $st->fetch();
            if (!$d) {
                out(false, 'Device not found.');
            }
            if ($vid > 0) {
                $st = $pdo->prepare('SELECT plate_number FROM vehicle WHERE vehicle_id = ? AND is_active = 1');
                $st->execute([$vid]);
                $v = $st->fetch();
                if (!$v) {
                    out(false, 'Vehicle not found or inactive.');
                }
                $pdo->prepare('UPDATE gps_devices SET vehicle_id = ? WHERE device_id = ?')->execute([$vid, $did]);
                Audit::log($pdo, 'MODIFY', 'gps_devices:' . $did, null, "paired to {$v['plate_number']}");
                out(true, "Device {$d['device_uid']} paired to {$v['plate_number']}.");
            }
            $pdo->prepare('UPDATE gps_devices SET vehicle_id = NULL WHERE device_id = ?')->execute([$did]);
            Audit::log($pdo, 'MODIFY', 'gps_devices:' . $did, null, 'unpaired');
            out(true, "Device {$d['device_uid']} unpaired.");
        }

        case 'device_toggle': {
            $did = (int) ($in['device_id'] ?? 0);
            $st  = $pdo->prepare('SELECT device_uid, is_active FROM gps_devices WHERE device_id = ?');
            $st->execute([$did]);
            $d = $st->fetch();
            if (!$d) {
                out(false, 'Device not found.');
            }
            $to = (int) $d['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE gps_devices SET is_active = ? WHERE device_id = ?')->execute([$to, $did]);
            Audit::log($pdo, 'MODIFY', 'gps_devices:' . $did, 'active=' . $d['is_active'], 'active=' . $to);
            out(true, $to ? 'Device enabled.' : 'Device disabled.');
        }

        case 'account_save': {
            $aid     = (int) ($in['account_id'] ?? 0);
            $email   = strtolower(trim((string) ($in['email'] ?? '')));
            $name    = trim((string) ($in['full_name'] ?? ''));
            $roleId  = (int) ($in['role_id'] ?? 0);
            $pass    = (string) ($in['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(false, 'A valid email address is required.');
            }
            if ($name === '' || $roleId < 1 || $roleId > 6) {
                out(false, 'Full name and a valid role are required.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM account WHERE email = ? AND account_id != ?');
            $st->execute([$email, $aid]);
            if ((int) $st->fetchColumn()) {
                out(false, 'That email is already in use.');
            }
            if ($aid > 0) {
                if ($pass !== '' && strlen($pass) < 8) {
                    out(false, 'Password must be at least 8 characters.');
                }
                if ($pass !== '') {
                    $pdo->prepare('UPDATE account SET email=?, full_name=?, role_id=?, password=? WHERE account_id=?')
                        ->execute([$email, $name, $roleId, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $aid]);
                } else {
                    $pdo->prepare('UPDATE account SET email=?, full_name=?, role_id=? WHERE account_id=?')
                        ->execute([$email, $name, $roleId, $aid]);
                }
                Audit::log($pdo, 'MODIFY', 'account:' . $aid, null, "updated {$email} (role {$roleId})" . ($pass !== '' ? ' + password reset' : ''));
                out(true, 'Account updated.');
            }
            if (strlen($pass) < 8) {
                out(false, 'Password must be at least 8 characters.');
            }
            $pdo->prepare('INSERT INTO account (role_id, email, password, full_name, created_by) VALUES (?,?,?,?,?)')
                ->execute([$roleId, $email, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $name, $u['id']]);
            $newId = (int) $pdo->lastInsertId();
            Audit::log($pdo, 'CREATE', 'account:' . $newId, null, "{$email} (role {$roleId})");
            out(true, "Account {$email} created.");
        }

        case 'account_toggle': {
            $aid = (int) ($in['account_id'] ?? 0);
            if ($aid === (int) $u['id']) {
                out(false, 'You cannot deactivate your own account.');
            }
            $st = $pdo->prepare('SELECT email, is_active FROM account WHERE account_id = ?');
            $st->execute([$aid]);
            $a = $st->fetch();
            if (!$a) {
                out(false, 'Account not found.');
            }
            $to = (int) $a['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE account SET is_active = ?, login_attempts = 0, locked_until = NULL WHERE account_id = ?')->execute([$to, $aid]);
            Audit::log($pdo, 'MODIFY', 'account:' . $aid, 'active=' . $a['is_active'], 'active=' . $to);
            out(true, $to ? 'Account activated.' : 'Account deactivated.');
        }

        case 'settings_save': {
            $vals = $in['settings'] ?? null;
            if (!is_array($vals) || !$vals) {
                out(false, 'No settings submitted.');
            }
            $saved = 0;
            foreach ($vals as $key => $value) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM app_settings WHERE setting_key = ?");
                $st->execute([(string) $key]);
                if (!(int) $st->fetchColumn()) {
                    continue;
                }
                Settings::set((string) $key, trim((string) $value), $u['id']);
                $saved++;
            }
            Audit::log($pdo, 'MODIFY', 'app_settings', null, "updated {$saved} key(s): " . implode(', ', array_keys($vals)));
            out(true, "{$saved} setting(s) saved.");
        }

        case 'faq_save': {
            $fid  = (int) ($in['chatbot_id'] ?? 0);
            $kw   = trim((string) ($in['prompt_keyword'] ?? ''));
            $resp = trim((string) ($in['response_text'] ?? ''));
            if ($kw === '' || $resp === '') {
                out(false, 'Keyword and response are required.');
            }
            if ($fid > 0) {
                $pdo->prepare('UPDATE chatbot SET prompt_keyword=?, response_text=?, managed_by=? WHERE chatbot_id=?')
                    ->execute([mb_substr($kw, 0, 255), $resp, $u['id'], $fid]);
                Audit::log($pdo, 'MODIFY', 'chatbot:' . $fid, null, mb_substr($kw, 0, 80));
                out(true, 'FAQ updated.');
            }
            $pdo->prepare('INSERT INTO chatbot (prompt_keyword, response_text, managed_by, is_active, is_displayed) VALUES (?,?,?,1,1)')
                ->execute([mb_substr($kw, 0, 255), $resp, $u['id']]);
            Audit::log($pdo, 'CREATE', 'chatbot:' . $pdo->lastInsertId(), null, mb_substr($kw, 0, 80));
            out(true, 'FAQ added.');
        }

        case 'faq_delete': {
            $fid = (int) ($in['chatbot_id'] ?? 0);
            $pdo->prepare('DELETE FROM chatbot WHERE chatbot_id = ?')->execute([$fid]);
            Audit::log($pdo, 'DELETE', 'chatbot:' . $fid, null, null);
            out(true, 'FAQ deleted.');
        }

        case 'faq_toggle': {
            $fid = (int) ($in['chatbot_id'] ?? 0);
            $pdo->prepare('UPDATE chatbot SET is_active = NOT is_active WHERE chatbot_id = ?')->execute([$fid]);
            Audit::log($pdo, 'MODIFY', 'chatbot:' . $fid, null, 'toggled is_active');
            out(true, 'FAQ status updated.');
        }

        case 'feedback_delete': {
            $fid = (int) ($in['feedback_id'] ?? 0);
            $pdo->prepare('UPDATE feedback SET is_active = 0 WHERE feedback_id = ?')->execute([$fid]);
            Audit::log($pdo, 'DELETE', 'feedback:' . $fid, null, 'soft-deleted');
            out(true, 'Feedback removed.');
        }

        default:
            out(false, 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[api/admin] ' . $e->getMessage());
    out(false, 'An internal error occurred. Please try again.');
}
