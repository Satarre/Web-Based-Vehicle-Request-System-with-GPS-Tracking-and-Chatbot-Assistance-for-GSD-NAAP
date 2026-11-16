<?php
declare(strict_types=1);
/**
 * api/admin.php — Phase 3 MIS ADMIN mutations. JSON in / JSON out.
 * POST { action, csrf_token, ... }. Role 1 only, every write audited.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

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
$u = Auth::requireApi($pdo, [1, 2, 3]);
$action = (string) ($in['action'] ?? '');

// Role 2 (HR/Director) may manage FAQ keywords only; role 3 (GSD Dispatcher)
// may manage vehicles/GPS devices only. Accounts, settings, and feedback
// removal stay role 1 exclusive.
$ROLE_ACTIONS = [
    2 => ['faq_save', 'faq_delete', 'faq_toggle', 'faq_display_toggle'],
    3 => ['vehicle_save', 'vehicle_toggle', 'type_save', 'type_delete',
          'device_register', 'device_pair', 'device_toggle', 'device_delete',
          'device_get', 'device_save', 'tracker_register'],
];
if (isset($ROLE_ACTIONS[$u['role_id']]) && !in_array($action, $ROLE_ACTIONS[$u['role_id']], true)) {
    out(false, 'Forbidden for your role.');
}
$clip = static fn(?string $s, int $n): ?string => ($s === null || trim($s) === '') ? null : mb_substr(trim($s), 0, $n);
$isSuper = (int) ($u['is_super_admin'] ?? 0) === 1;

/* Roles that may hold at most ONE active account at a time (SAO / GSD Dispatcher). */
$singletonRoles = [2 => 'SAO', 3 => 'GSD Dispatcher'];
$activeRoleHolder = static function (int $roleId, int $exceptId = 0) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT account_id, full_name, email FROM account
                         WHERE role_id = ? AND is_active = 1 AND account_id != ?
                         ORDER BY account_id LIMIT 1');
    $st->execute([$roleId, $exceptId]);
    return $st->fetch() ?: null;
};

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
            if ($to === 0) {
                $busy = $pdo->prepare(
                    'SELECT ticket_id, dtt_number FROM trip_tickets
                     WHERE vehicle_id = ? AND status_id IN (5, 6) AND is_active = 1 LIMIT 1'
                );
                $busy->execute([$vid]);
                $active = $busy->fetch();
                if ($active) {
                    out(false, 'Cannot deactivate — vehicle has an active trip (' . ($active['dtt_number'] ?: '#' . $active['ticket_id']) . ').');
                }
            }
            $pdo->prepare('UPDATE vehicle SET is_active = ? WHERE vehicle_id = ?')->execute([$to, $vid]);
            Audit::log($pdo, 'MODIFY', 'vehicle:' . $vid, 'active=' . $v['is_active'], 'active=' . $to);
            out(true, $to ? 'Vehicle activated.' : 'Vehicle deactivated.');
        }

        case 'type_save': {
            $tid  = (int) ($in['type_id'] ?? 0);
            $name = trim((string) ($in['type_name'] ?? ''));
            if ($name === '') {
                out(false, 'Type name is required.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM vehicle_types WHERE LOWER(type_name) = LOWER(?) AND type_id != ?');
            $st->execute([$name, $tid]);
            if ((int) $st->fetchColumn()) {
                out(false, 'That type already exists.');
            }
            if ($tid > 0) {
                $pdo->prepare('UPDATE vehicle_types SET type_name = ? WHERE type_id = ?')->execute([mb_substr($name, 0, 50), $tid]);
                Audit::log($pdo, 'MODIFY', 'vehicle_types:' . $tid, null, $name);
                out(true, 'Type updated.');
            }
            $pdo->prepare('INSERT INTO vehicle_types (type_name) VALUES (?)')->execute([mb_substr($name, 0, 50)]);
            Audit::log($pdo, 'CREATE', 'vehicle_types:' . $pdo->lastInsertId(), null, $name);
            out(true, 'Type added.');
        }

        case 'type_delete': {
            $tid = (int) ($in['type_id'] ?? 0);
            if ($tid > 0) {
                $pdo->prepare('UPDATE vehicle_types SET is_active = 0 WHERE type_id = ?')->execute([$tid]);
                Audit::log($pdo, 'DELETE', 'vehicle_types:' . $tid, null, 'soft-deleted');
            }
            out(true, 'Type removed.');
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
            $st  = $pdo->prepare('SELECT device_uid, account_id FROM gps_devices WHERE device_id = ?');
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
                // A tracker account owns at most one vehicle at a time.
                if ($d['account_id'] !== null) {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM gps_devices
                                         WHERE vehicle_id = ? AND account_id IS NOT NULL AND is_active = 1 AND device_id != ?');
                    $st->execute([$vid, $did]);
                    if ((int) $st->fetchColumn()) {
                        out(false, 'That vehicle already has a tracker account.');
                    }
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
            $st  = $pdo->prepare('SELECT device_uid, is_active, account_id FROM gps_devices WHERE device_id = ?');
            $st->execute([$did]);
            $d = $st->fetch();
            if (!$d) {
                out(false, 'Device not found.');
            }
            $to = (int) $d['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE gps_devices SET is_active = ? WHERE device_id = ?')->execute([$to, $did]);
            // A tracker account follows its device state; re-enabling also
            // clears any failed-login lockout so the tracker can sign in.
            if ($d['account_id'] !== null) {
                $pdo->prepare('UPDATE account SET is_active = ?, login_attempts = 0, locked_until = NULL WHERE account_id = ?')
                    ->execute([$to, (int) $d['account_id']]);
            }
            Audit::log($pdo, 'MODIFY', 'gps_devices:' . $did, 'active=' . $d['is_active'], 'active=' . $to);
            out(true, $to ? 'Device enabled.' : 'Device disabled.');
        }

        case 'device_delete': {
            $did = (int) ($in['device_id'] ?? 0);
            $st  = $pdo->prepare('SELECT device_uid, account_id FROM gps_devices WHERE device_id = ?');
            $st->execute([$did]);
            $d = $st->fetch();
            if (!$d) {
                out(false, 'Device not found.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM gps_positions WHERE device_id = ?')->execute([$did]);
                $pdo->prepare('DELETE FROM geofence_events WHERE device_id = ?')->execute([$did]);
                $pdo->prepare('DELETE FROM gps_devices WHERE device_id = ?')->execute([$did]);
                // Keep the hidden tracker account row (audit references) but
                // revoke it so the deleted token can never be re-enabled.
                if ($d['account_id'] !== null) {
                    $pdo->prepare('UPDATE account SET is_active = 0 WHERE account_id = ?')->execute([(int) $d['account_id']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[admin] device_delete failed: ' . $e->getMessage());
                out(false, 'Could not delete device.');
            }
            Audit::log($pdo, 'DELETE', 'gps_devices:' . $did, null, 'deleted ' . $d['device_uid']);
            out(true, "Device {$d['device_uid']} deleted.");
        }

        case 'tracker_register': {
            // Hidden tracker account + paired device. The bearer token is
            // returned ONCE here; only its sha256 is stored. The tracker then
            // posts to api/gps/locations.php exactly like a hardware unit.
            // The login password is chosen here and can sign in for status.
            $vid   = (int) ($in['vehicle_id'] ?? 0);
            $name  = $clip($in['device_name'] ?? null, 100);
            $email = strtolower(trim((string) ($in['email'] ?? '')));
            $pass  = (string) ($in['password'] ?? '');
            $uidIn = trim((string) ($in['device_uid'] ?? ''));

            $st = $pdo->prepare('SELECT plate_number FROM vehicle WHERE vehicle_id = ? AND is_active = 1');
            $st->execute([$vid]);
            $v = $st->fetch();
            if (!$v) {
                out(false, 'Vehicle not found or inactive.');
            }
            $plate = (string) $v['plate_number'];

            // One active tracker account per vehicle.
            $st = $pdo->prepare('SELECT COUNT(*) FROM gps_devices
                                 WHERE vehicle_id = ? AND account_id IS NOT NULL AND is_active = 1');
            $st->execute([$vid]);
            if ((int) $st->fetchColumn()) {
                out(false, 'This vehicle already has a tracker account.');
            }

            // UID: operator-provided, or auto-continue the GPS-NAAP-### series.
            if ($uidIn !== '') {
                if (mb_strlen($uidIn) > 64) {
                    out(false, 'Device UID must be 64 characters or fewer.');
                }
                $st = $pdo->prepare('SELECT COUNT(*) FROM gps_devices WHERE device_uid = ?');
                $st->execute([$uidIn]);
                if ((int) $st->fetchColumn()) {
                    out(false, 'Device UID already registered.');
                }
                $uid = $uidIn;
            } else {
                $n   = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(device_uid, 10) AS UNSIGNED)), 0)
                                          FROM gps_devices WHERE device_uid REGEXP '^GPS-NAAP-[0-9]+$'")->fetchColumn();
                $uid = sprintf('GPS-NAAP-%03d', $n + 1);
            }
            if ($email === '') {
                $email = strtolower($uid) . '@naap.local';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(false, 'A valid email address is required.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM account WHERE email = ?');
            $st->execute([$email]);
            if ((int) $st->fetchColumn()) {
                out(false, 'That email is already in use.');
            }
            $pwErr = PasswordPolicy::check($pass);
            if ($pwErr !== null) {
                out(false, $pwErr);
            }

            $rawToken = bin2hex(random_bytes(32));
            $aid = 0;
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO account (role_id, is_system, email, password, full_name, created_by, is_active, email_verified_at)
                     VALUES (7, 1, ?, ?, ?, ?, 1, NOW())'
                )->execute([
                    $email,
                    password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]),
                    'GPS TRACKER - ' . $plate,
                    $u['id'],
                ]);
                $aid = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO gps_devices
                       (device_uid, vehicle_id, account_id, device_name, protocol, api_token_hash, installed_at, registered_by)
                     VALUES (?,?,?,?,?,?,NOW(),?)'
                )->execute([
                    $uid, $vid, $aid,
                    $name ?? ($plate . ' Tracker'),
                    'http-json', hash('sha256', $rawToken), $u['id'],
                ]);
                $did = (int) $pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[admin] tracker_register failed: ' . $e->getMessage());
                out(false, 'Could not create the tracker account.');
            }
            Audit::log($pdo, 'CREATE', 'account:' . $aid, null, 'created hidden tracker account ' . $email);
            Audit::log($pdo, 'CREATE', 'gps_devices:' . $did, null, 'registered tracker ' . $uid . ' → ' . $plate);
            out(true, 'Tracker account created. Copy the token now — it will not be shown again.', [
                'api_token'      => $rawToken,
                'tracker_email'  => $email,
                'device_uid'     => $uid,
            ]);
        }

        case 'device_get': {
            // Edit-modal prefill, fetched on demand so a tracker's login email
            // never appears anywhere in the GPS Devices list markup.
            $did = (int) ($in['device_id'] ?? 0);
            $st = $pdo->prepare(
                'SELECT g.device_id, g.device_uid, g.device_name, g.manufacturer, g.model,
                        g.account_id, a.email
                 FROM gps_devices g LEFT JOIN account a ON a.account_id = g.account_id
                 WHERE g.device_id = ?'
            );
            $st->execute([$did]);
            $d = $st->fetch();
            if (!$d) {
                out(false, 'Device not found.');
            }
            out(true, '', ['device' => [
                'id'           => (int) $d['device_id'],
                'device_uid'   => (string) $d['device_uid'],
                'device_name'  => (string) ($d['device_name'] ?? ''),
                'manufacturer' => (string) ($d['manufacturer'] ?? ''),
                'model'        => (string) ($d['model'] ?? ''),
                'is_tracker'   => $d['account_id'] !== null,
                'email'        => (string) ($d['email'] ?? ''),
            ]]);
        }

        case 'device_save': {
            // Edit a registered device. Tracker devices (owned by a hidden
            // account) can also change their login email/password here.
            $did  = (int) ($in['device_id'] ?? 0);
            $uid  = trim((string) ($in['device_uid'] ?? ''));
            $name = $clip($in['device_name'] ?? null, 100);

            $st = $pdo->prepare('SELECT device_uid, account_id FROM gps_devices WHERE device_id = ?');
            $st->execute([$did]);
            $d = $st->fetch();
            if (!$d) {
                out(false, 'Device not found.');
            }
            if ($uid === '' || mb_strlen($uid) > 64) {
                out(false, 'Device UID is required (64 characters max).');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM gps_devices WHERE device_uid = ? AND device_id != ?');
            $st->execute([$uid, $did]);
            if ((int) $st->fetchColumn()) {
                out(false, 'Device UID already registered.');
            }

            $isTracker = $d['account_id'] !== null;
            $aid   = $isTracker ? (int) $d['account_id'] : 0;
            $email = $isTracker ? strtolower(trim((string) ($in['email'] ?? ''))) : '';
            $pass  = $isTracker ? (string) ($in['password'] ?? '') : '';
            if ($isTracker) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    out(false, 'A valid login email address is required.');
                }
                $st = $pdo->prepare('SELECT COUNT(*) FROM account WHERE email = ? AND account_id != ?');
                $st->execute([$email, $aid]);
                if ((int) $st->fetchColumn()) {
                    out(false, 'That email is already in use.');
                }
                if ($pass !== '') {
                    $pwErr = PasswordPolicy::check($pass);
                    if ($pwErr !== null) {
                        out(false, $pwErr);
                    }
                }
            }

            $pdo->beginTransaction();
            try {
                if ($isTracker) {
                    $pdo->prepare('UPDATE gps_devices SET device_uid = ?, device_name = ? WHERE device_id = ?')
                        ->execute([$uid, $name, $did]);
                    if ($pass !== '') {
                        $pdo->prepare('UPDATE account
                                       SET email = ?, password = ?, login_attempts = 0, locked_until = NULL,
                                           sessions_version = sessions_version + 1
                                       WHERE account_id = ?')
                            ->execute([$email, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $aid]);
                    } else {
                        $pdo->prepare('UPDATE account SET email = ? WHERE account_id = ?')->execute([$email, $aid]);
                    }
                } else {
                    $pdo->prepare('UPDATE gps_devices
                                   SET device_uid = ?, device_name = ?, manufacturer = ?, model = ?
                                   WHERE device_id = ?')
                        ->execute([$uid, $name, $clip($in['manufacturer'] ?? null, 50), $clip($in['model'] ?? null, 50), $did]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[admin] device_save failed: ' . $e->getMessage());
                out(false, 'Could not save the device.');
            }
            Audit::log($pdo, 'MODIFY', 'gps_devices:' . $did, 'uid=' . $d['device_uid'], 'uid=' . $uid);
            if ($isTracker && $pass !== '') {
                Audit::log($pdo, 'MODIFY', 'account:' . $aid, null, 'tracker login updated via device edit');
            }
            out(true, 'Device updated.');
        }

        case 'account_save': {
            $aid        = (int) ($in['account_id'] ?? 0);
            $email      = strtolower(trim((string) ($in['email'] ?? '')));
            $first      = trim((string) ($in['first_name'] ?? ''));
            $mi         = trim((string) ($in['middle_initial'] ?? '')) ?: null;
            $middleName = $clip($in['middle_name'] ?? null, 100);
            $last       = trim((string) ($in['last_name'] ?? ''));
            $suffix     = trim((string) ($in['suffix'] ?? '')) ?: null;
            $roleId     = (int) ($in['role_id'] ?? 0);
            $pass       = (string) ($in['password'] ?? '');
            $passConfirm = (string) ($in['password_confirm'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(false, 'A valid email address is required.');
            }
            if ($first === '' || $last === '') {
                out(false, 'First name and last name are required.');
            }
            // Role is set only at creation; it cannot be changed when editing.
            if ($aid === 0 && ($roleId < 1 || $roleId > 6)) {
                out(false, 'A valid role is required.');
            }
            // Only the super administrator may create MIS ADMIN accounts.
            if ($aid === 0 && $roleId === 1 && !$isSuper) {
                out(false, 'Only the MIS Super Administrator can create MIS ADMIN accounts.');
            }
            if ($pass !== '' && $pass !== $passConfirm) {
                out(false, 'Password and confirmation do not match.');
            }
            $name = account_display_name($first, $mi, $last, $suffix);
            $st = $pdo->prepare('SELECT COUNT(*) FROM account WHERE email = ? AND account_id != ?');
            $st->execute([$email, $aid]);
            if ((int) $st->fetchColumn()) {
                out(false, 'That email is already in use.');
            }
            if ($aid > 0) {
                if ($pass !== '') {
                    $pwErr = PasswordPolicy::check($pass);
                    if ($pwErr !== null) {
                        out(false, $pwErr);
                    }
                }
                $stOld = $pdo->prepare('SELECT email, role_id, is_system FROM account WHERE account_id = ?');
                $stOld->execute([$aid]);
                $old = $stOld->fetch();
                if (!$old) {
                    out(false, 'Account not found.');
                }
                // Hidden tracker accounts are managed from Vehicles & GPS Devices.
                if ((int) ($old['is_system'] ?? 0) === 1) {
                    out(false, 'This is a hidden tracker account — manage it from Vehicles & GPS Devices.');
                }
                // MIS ADMIN accounts are managed only by the super administrator
                // (everyone may still edit their own account).
                if ((int) $old['role_id'] === 1 && !$isSuper && $aid !== (int) $u['id']) {
                    out(false, 'Only the MIS Super Administrator can edit MIS ADMIN accounts.');
                }
                $oldEmail = (string) $old['email'];
                // role_id deliberately not updated on edit — it is immutable after creation.
                if ($pass !== '') {
                    $pdo->prepare('UPDATE account SET email=?, full_name=?, first_name=?, middle_initial=?, middle_name=?, last_name=?, suffix=?, password=?, login_attempts = 0, locked_until = NULL, sessions_version = sessions_version + 1 WHERE account_id=?')
                        ->execute([$email, $name, $first, $mi, $middleName, $last, $suffix, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $aid]);
                } else {
                    $pdo->prepare('UPDATE account SET email=?, full_name=?, first_name=?, middle_initial=?, middle_name=?, last_name=?, suffix=? WHERE account_id=?')
                        ->execute([$email, $name, $first, $mi, $middleName, $last, $suffix, $aid]);
                }
                if ($aid === (int) $u['id']) {
                    $_SESSION['full_name'] = $name;
                }
                if ($oldEmail !== '' && strtolower($oldEmail) !== $email) {
                    SecurityEvent::log($pdo, 'EMAIL_CHANGED', $aid);
                    SecurityNotificationService::emailChanged($pdo, $aid, $email);
                }
                $auditRole = $pdo->prepare('SELECT role_id FROM account WHERE account_id = ?');
                $auditRole->execute([$aid]);
                Audit::log($pdo, 'MODIFY', 'account:' . $aid, null, "updated {$email} (role {$auditRole->fetchColumn()})" . ($pass !== '' ? ' + password reset' : ''));
                out(true, 'Account updated.');
            }
            $pwErr = PasswordPolicy::check($pass);
            if ($pwErr !== null) {
                out(false, $pwErr);
            }
            // At most one ACTIVE SAO / GSD Dispatcher account at a time: the
            // current holder must be deactivated before a replacement is created.
            if (isset($singletonRoles[$roleId])) {
                $holder = $activeRoleHolder($roleId);
                if ($holder) {
                    out(false, 'An active ' . $singletonRoles[$roleId] . ' account already exists (' . $holder['full_name']
                        . '). Deactivate it before creating a new one.');
                }
            }
            $pdo->prepare('INSERT INTO account (role_id, email, password, full_name, first_name, middle_initial, middle_name, last_name, suffix, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$roleId, $email, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $name, $first, $mi, $middleName, $last, $suffix, $u['id']]);
            $newId = (int) $pdo->lastInsertId();
            Audit::log($pdo, 'CREATE', 'account:' . $newId, null, "{$email} (role {$roleId})");
            if (MailService::enabled($pdo)) {
                $otp = OtpService::generate($pdo, $newId, OtpService::PURPOSE_VERIFY_EMAIL);
                SecurityNotificationService::sendOtp($pdo, $newId, OtpService::PURPOSE_VERIFY_EMAIL, $otp);
            }
            out(true, "Account {$email} created.");
        }

        case 'account_toggle': {
            $aid = (int) ($in['account_id'] ?? 0);
            if ($aid === (int) $u['id']) {
                out(false, 'You cannot deactivate your own account.');
            }
            $st = $pdo->prepare('SELECT email, is_active, role_id, is_system FROM account WHERE account_id = ?');
            $st->execute([$aid]);
            $a = $st->fetch();
            if (!$a) {
                out(false, 'Account not found.');
            }
            // Hidden tracker accounts follow their device's state.
            if ((int) ($a['is_system'] ?? 0) === 1) {
                out(false, 'This is a hidden tracker account — enable/disable its device from Vehicles & GPS Devices.');
            }
            // MIS ADMIN accounts are activated/deactivated only by the super administrator.
            if ((int) $a['role_id'] === 1 && !$isSuper) {
                out(false, 'Only the MIS Super Administrator can activate or deactivate MIS ADMIN accounts.');
            }
            $to = (int) $a['is_active'] ? 0 : 1;
            // Reactivation cannot smuggle in a second active SAO / GSD Dispatcher.
            if ($to === 1 && isset($singletonRoles[(int) $a['role_id']])) {
                $holder = $activeRoleHolder((int) $a['role_id'], $aid);
                if ($holder) {
                    out(false, 'An active ' . $singletonRoles[(int) $a['role_id']] . ' account already exists (' . $holder['full_name']
                        . '). Deactivate it before activating this account.');
                }
            }
            $pdo->prepare('UPDATE account SET is_active = ?, login_attempts = 0, locked_until = NULL WHERE account_id = ?')->execute([$to, $aid]);
            Audit::log($pdo, 'MODIFY', 'account:' . $aid, 'active=' . $a['is_active'], 'active=' . $to);
            out(true, $to ? 'Account activated.' : 'Account deactivated.');
        }

        case 'account_unlock': {
            $aid = (int) ($in['account_id'] ?? 0);
            $st = $pdo->prepare('SELECT email, login_attempts, locked_until, role_id, is_system FROM account WHERE account_id = ?');
            $st->execute([$aid]);
            $a = $st->fetch();
            if (!$a) {
                out(false, 'Account not found.');
            }
            if ((int) ($a['is_system'] ?? 0) === 1) {
                out(false, 'This is a hidden tracker account — manage it from Vehicles & GPS Devices.');
            }
            // MIS ADMIN accounts are unlocked only by the super administrator.
            if ((int) $a['role_id'] === 1 && !$isSuper) {
                out(false, 'Only the MIS Super Administrator can unlock MIS ADMIN accounts.');
            }
            $pdo->prepare('UPDATE account SET login_attempts = 0, locked_until = NULL WHERE account_id = ?')->execute([$aid]);
            Audit::log($pdo, 'MODIFY', 'account:' . $aid,
                'attempts=' . $a['login_attempts'] . ', locked_until=' . ($a['locked_until'] ?? 'NULL'),
                'unlocked');
            out(true, 'Account unlocked.');
        }

        case 'settings_save': {
            $vals = $in['settings'] ?? null;
            if (!is_array($vals) || !$vals) {
                out(false, 'No settings submitted.');
            }
            $current = Settings::all();
            $changed = [];
            foreach ($vals as $key => $value) {
                $key = (string) $key;
                if (!array_key_exists($key, $current)) {
                    continue;                       // unknown key
                }
                $value = trim((string) $value);
                // Masked fields are submitted blank to mean "leave unchanged".
                if ($value === '' && preg_match('/PASS|SECRET|TOKEN/', $key)) {
                    continue;
                }
                if ($value === (string) $current[$key]) {
                    continue;                       // no actual change
                }
                Settings::set($key, $value, $u['id']);
                $changed[] = $key;
            }
            $count = count($changed);
            if ($count > 0) {
                Audit::log($pdo, 'MODIFY', 'app_settings', null, "updated {$count} key(s): " . implode(', ', $changed));
            }
            out(true, "{$count} change(s) made.", ['saved' => $count]);
        }

        case 'smtp_test': {
            if (!MailService::enabled($pdo)) {
                out(false, 'SMTP is not configured. Set SMTP_HOST (MIS settings) or ENV_SMTP_* in secrets.php.');
            }
            $to = trim((string) ($in['to'] ?? ''));
            if ($to === '') {
                $to = (string) $u['email'];
            }
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                out(false, 'Enter a valid recipient email address.');
            }
            $source = MailService::envOverride() ? 'ENV_SMTP_* (secrets.php)' : 'MIS SMTP settings';
            $body = '<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">'
                . 'This is a test message confirming that the GSD Trip Ticket System can send email.</p>'
                . '<p style="margin:0;font-size:13px;color:#64748b;">Sent ' . date('F j, Y g:i A')
                . ' using ' . htmlspecialchars($source) . '.</p>';
            [$html, $text] = MailService::template('SMTP Test', $body);
            if (MailService::send($pdo, $to, 'MIS Administrator', 'SMTP Test — NAAP GSD Digital Trip Ticket System', $html, $text)) {
                Audit::log($pdo, 'SMTP_TEST', 'app_settings', null, 'test email to ' . $to);
                out(true, 'Test email sent to ' . $to . '.');
            }
            out(false, 'Send failed: ' . (MailService::lastError() ?: 'unknown SMTP error'));
        }

        case 'faq_save': {
            $fid  = (int) ($in['chatbot_id'] ?? 0);
            $kw   = trim((string) ($in['prompt_keyword'] ?? ''));
            $resp = trim((string) ($in['response_text'] ?? ''));
            $syn  = $clip($in['keywords'] ?? null, 1000);
            if ($kw === '' || $resp === '') {
                out(false, 'Keyword and response are required.');
            }
            // Normalized duplicate guard (case + surrounding whitespace insensitive).
            $dup = $pdo->prepare('SELECT COUNT(*) FROM chatbot WHERE LOWER(TRIM(prompt_keyword)) = ? AND chatbot_id <> ?');
            $dup->execute([mb_strtolower(trim($kw)), $fid]);
            if ((int) $dup->fetchColumn() > 0) {
                out(false, 'A FAQ with that keyword already exists.');
            }
            try {
                if ($fid > 0) {
                    $pdo->prepare('UPDATE chatbot SET prompt_keyword=?, response_text=?, keywords=?, managed_by=? WHERE chatbot_id=?')
                        ->execute([mb_substr($kw, 0, 255), $resp, $syn, $u['id'], $fid]);
                    Audit::log($pdo, 'MODIFY', 'chatbot:' . $fid, null, mb_substr($kw, 0, 80));
                    out(true, 'FAQ updated.');
                }
                $pdo->prepare('INSERT INTO chatbot (prompt_keyword, response_text, keywords, managed_by, is_active, is_displayed) VALUES (?,?,?,?,1,1)')
                    ->execute([mb_substr($kw, 0, 255), $resp, $syn, $u['id']]);
                Audit::log($pdo, 'CREATE', 'chatbot:' . $pdo->lastInsertId(), null, mb_substr($kw, 0, 80));
                out(true, 'FAQ added.');
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) { // unique keyword_norm (race backstop)
                    out(false, 'A FAQ with that keyword already exists.');
                }
                throw $e;
            }
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

        case 'faq_display_toggle': {
            $fid = (int) ($in['chatbot_id'] ?? 0);
            $pdo->prepare('UPDATE chatbot SET is_displayed = NOT is_displayed WHERE chatbot_id = ?')->execute([$fid]);
            Audit::log($pdo, 'MODIFY', 'chatbot:' . $fid, null, 'toggled is_displayed');
            out(true, 'FAQ visibility updated.');
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
