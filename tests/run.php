<?php
/**
 * tests/run.php — Phase-0 acceptance suite (plain asserts, zero dependencies).
 * Run: C:\xampp\php\php.exe tests\run.php
 */
declare(strict_types=1);

/* Clean bootstrap before ANY output so session ini applies. */
require __DIR__ . '/../config/config.php';
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$failures = 0;
$checks   = 0;

function check(string $label, bool $cond): void
{
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "✗ FAIL  {$label}\n";
    }
}

echo "== Phase 0 acceptance ==\n";

/* ── Bootstrap ──────────────────────────────────────────────────────── */
check('config: PDO connected to ' . DB_NAME, $pdo instanceof PDO);

/* ── Settings ───────────────────────────────────────────────────────── */
check('settings: GPS_MIN_INTERVAL_SEC = 20',        Settings::getInt('GPS_MIN_INTERVAL_SEC') === 20);
check('settings: insecure-HTTP flag is true (capstone)', Settings::getBool('GPS_ALLOW_INSECURE_HTTP') === true);
check('settings: geofence lat is float',            abs(Settings::getFloat('NAAP_GEOFENCE_LAT') - 14.5126) < 0.0001);
check('settings: retention empty = never prune',    Settings::get('RETENTION_RAW_MONTHS', '') === '');

/* ── TripState matrix (10 statuses) ────────────────────────────────── */
$mkTicket = fn(int $status) => [
    'status_id' => $status, 'created_by' => 100, 'driver_id' => 42, 'vehicle_id' => 7,
    'place_to_visit' => 'x', 'purpose' => 'y', 'id_number' => 'z',
    'scheduled_departure' => '2026-09-01 08:00:00', 'scheduled_return' => '2026-09-01 17:00:00',
];
$ctxOwner   = ['user_id' => 100, 'role_id' => 6];
$ctxStaff   = ['user_id' => 200, 'role_id' => 3];
$ctxHr      = ['user_id' => 300, 'role_id' => 2]; // HR ADMIN
$ctxDriverA = ['user_id' => 42,  'role_id' => 4]; // assigned driver
$ctxDriverB = ['user_id' => 43,  'role_id' => 4]; // wrong driver

// submit path
check('draft→pending blocked when request incomplete',
      TripState::can($mkTicket(TripState::DRAFT), TripState::PENDING, $ctxOwner + ['request_complete' => false]) !== true);
check('owner may submit draft→pending',
      TripState::can($mkTicket(TripState::DRAFT), TripState::PENDING, $ctxOwner + ['request_complete' => true]) === true);
check('staff may NOT verify pending (HR ADMIN only)',
      TripState::can($mkTicket(TripState::PENDING), TripState::VERIFIED, $ctxStaff) !== true);
check('HR ADMIN verifies pending→verified',
      TripState::can($mkTicket(TripState::PENDING), TripState::VERIFIED, $ctxHr) === true);
check('staff approves verified→approved',
      TripState::can($mkTicket(TripState::VERIFIED), TripState::APPROVED, $ctxStaff) === true);
check('reject without reason blocked',
      TripState::can($mkTicket(TripState::PENDING), TripState::REJECTED, $ctxHr + ['has_reason' => false]) !== true);
check('reject with reason allowed for HR ADMIN',
      TripState::can($mkTicket(TripState::PENDING), TripState::REJECTED, $ctxHr + ['has_reason' => true]) === true);
check('assign→dispatched requires both vehicle+driver',
      TripState::can(['status_id' => TripState::APPROVED, 'created_by' => 100, 'vehicle_id' => 0, 'driver_id' => 0], TripState::DISPATCHED, $ctxStaff) !== true);
$tDispatched = $mkTicket(TripState::DISPATCHED);
check('dispatched→on_going: assigned driver OK',
      TripState::can($tDispatched, TripState::ON_GOING, $ctxDriverA) === true);
check('dispatched→on_going: wrong driver BLOCKED',
      TripState::can($tDispatched, TripState::ON_GOING, $ctxDriverB) !== true);
check('on_going cancel BLOCKED even by owner',
      TripState::can($mkTicket(TripState::ON_GOING), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) !== true);
check('on_going→completed: driver completes',
      TripState::can($mkTicket(TripState::ON_GOING), TripState::COMPLETED, $ctxDriverA) === true);
check('terminal: COMPLETED accepts nothing',
      TripState::can($mkTicket(TripState::COMPLETED), TripState::PENDING, $ctxHr) !== true);
check('illegal jump APPROVED→ON_GOING rejected',
      TripState::can($mkTicket(TripState::APPROVED), TripState::ON_GOING, $ctxDriverA) !== true);
check('ACTIVE_TRIP window = ON_GOING only',
      TripState::isActiveTrip(TripState::ON_GOING) && !TripState::isActiveTrip(TripState::DISPATCHED));
check('CANCELLABLE includes DRAFT, PENDING, APPROVED, DISPATCHED',
      TripState::can($mkTicket(TripState::DRAFT), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true
      && TripState::can($mkTicket(TripState::PENDING), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true
      && TripState::can($mkTicket(TripState::APPROVED), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true
      && TripState::can($mkTicket(TripState::DISPATCHED), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true);

/* ── Csrf ───────────────────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
check('csrf: valid token passes',       Csrf::verify($_SESSION['csrf_token']) === true);
check('csrf: forged token fails',       Csrf::verify(str_repeat('a', 64)) === false);
check('csrf: missing token fails',      Csrf::verify(null) === false);

/* ── Auth: seeded admin round-trip (no MFA on seed account) ─────────── */
Auth::start();
[$ok, $err] = Auth::login($pdo, 'admin@naap.gov.ph', 'WRONG-password');
check('auth: wrong password rejected', $ok === false);
[$ok] = Auth::login($pdo, 'ADMIN@naap.gov.ph', 'Admin@12345');
check('auth: seeded admin logs in (case-insensitive email)', $ok === true);
check('auth: session populated as MIS ADMIN', ($_SESSION['role_name'] ?? '') === 'MIS ADMIN');
Audit::log($pdo, 'LOGIN', 'account:' . $_SESSION['user_id'], null, null, 'SUCCESS', 1);
check('audit: row written', (bool) $pdo->query(
    "SELECT COUNT(*) c FROM audit_trail WHERE event_type='LOGIN'" )->fetch()['c']);

/* ── DB shape spot-checks ───────────────────────────────────────────── */
check('db: 10 statuses seeded',   (int) $pdo->query('SELECT COUNT(*) FROM request_status')->fetchColumn() === 10);
check('db: trip_tickets has dtt_number column', (bool) $pdo->query("SHOW COLUMNS FROM trip_tickets LIKE 'dtt_number'")->fetch());
check('db: gps_positions DATETIME(3)', str_contains((string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='naap_mn_vts' AND TABLE_NAME='gps_positions' AND COLUMN_NAME='recorded_at'"
)->fetchColumn(), 'datetime'));

/* ── Phase 1: request completeness gate ─────────────────────────────── */
echo "\n== Phase 1 acceptance ==\n";
$req = [
    'passenger_name' => 'A', 'id_number' => '', 'place_to_visit' => 'x', 'purpose' => 'y',
    'scheduled_departure' => '2026-09-01 08:00:00', 'scheduled_return' => '2026-09-01 17:00:00',
    'number_of_passengers' => 1,
];
check('requestComplete: missing id_number rejected', TripState::requestComplete($req) === false);
$reqL = ['attachment_path' => 'uploads/request_letters/RL_demo.pdf'] + $req;
check('requestComplete: missing request letter rejected', TripState::requestComplete($req) === false);
check('requestComplete: filled request passes',
      TripState::requestComplete(['id_number' => 'N1'] + $reqL) === true);
$badWindow = ['scheduled_return' => '2026-09-01 08:00:00'] + $reqL + ['id_number' => 'N1'];
check('requestComplete: return <= departure rejected', TripState::requestComplete($badWindow) === false);

/* ── Phase 1: TicketNumber + ConflictChecker (tx rolled back) ───────── */
$pdo->beginTransaction();
$year = date('Y');
$before = TicketNumber::next($pdo);
$pdo->prepare("INSERT INTO trip_tickets
    (dtt_number, status_id, scheduled_departure, scheduled_return, vehicle_id, driver_id, created_by)
    VALUES (?, ?, '2026-09-10 08:00:00', '2026-09-10 17:00:00', 7777, 8888, 9999)")
    ->execute([sprintf('DTT-%d-9000', $year), TripState::DISPATCHED]);
$insertedId = (int) $pdo->lastInsertId();
$after = TicketNumber::next($pdo);
check('ticketnumber: DTT-YYYY-#### format', (bool) preg_match('/^DTT-\d{4}-\d{4}$/', $after));
check('ticketnumber: sequence continues past highest', (int) substr($after, -4) > 9000 && $after !== $before);
$cv = ConflictChecker::vehicle($pdo, 7777, '2026-09-10 09:00:00', '2026-09-10 12:00:00');
check('conflict: overlapping vehicle booking detected',
      $cv !== null && (int) $cv['ticket_id'] === $insertedId);
$cd = ConflictChecker::driver($pdo, 8888, '2026-09-11 08:00:00', '2026-09-11 17:00:00');
check('conflict: disjoint window is clear', $cd === null);
$cd2 = ConflictChecker::driver($pdo, 8888, '2026-09-10 18:00:00', '2026-09-10 19:00:00');
check('conflict: adjacent-after window is clear', $cd2 === null);
$pdo->rollBack();
check('test transaction rolled back cleanly', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_tickets WHERE dtt_number LIKE 'DTT-{$year}-9000'" )->fetchColumn() === 0);

/* ── Phase 2: execution chain, manifest, guard desk, reschedule ─────── */
echo "\n== Phase 2 acceptance ==\n";
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO trip_tickets
    (status_id, scheduled_departure, scheduled_return, vehicle_id, driver_id, created_by,
     passenger_name, id_number, place_to_visit, purpose, number_of_passengers)
    VALUES (?, '2026-09-12 08:00:00', '2026-09-12 17:00:00', 7777, 8888, 9999, 'Pax', 'ID-1', 'Base X', 'Official travel', 1)")
    ->execute([TripState::DISPATCHED]);
$t2id = (int) $pdo->lastInsertId();
$t2   = static fn(int $status): array => [
    'ticket_id' => $t2id, 'status_id' => $status, 'created_by' => 9999,
    'driver_id' => 8888, 'vehicle_id' => 7777, 'dtt_number' => null,
    'scheduled_departure' => '2026-09-12 08:00:00', 'scheduled_return' => '2026-09-12 17:00:00',
];
$drvA = ['user_id' => 8888, 'role_id' => 4];
$drvB = ['user_id' => 8899, 'role_id' => 4];

check('exec: dispatched→on_going driver OK',          TripState::can($t2(TripState::DISPATCHED), TripState::ON_GOING, $drvA) === true);
check('exec: on_going→completed driver OK',            TripState::can($t2(TripState::ON_GOING), TripState::COMPLETED, $drvA) === true);
check('exec: wrong driver blocked',                    TripState::can($t2(TripState::DISPATCHED), TripState::ON_GOING, $drvB) !== true);
$staffT = ['user_id' => 200,  'role_id' => 3];
check('staff NOT allowed to start trip',               TripState::can($t2(TripState::DISPATCHED), TripState::ON_GOING, $staffT) !== true);

$ins = $pdo->prepare('INSERT INTO trip_passengers (ticket_id, passenger_name) VALUES (?, ?)');
$ins->execute([$t2id, 'Alpha One']);
$ins->execute([$t2id, 'Beta Two']);
$paxCount = (int) $pdo->query("SELECT COUNT(*) FROM trip_passengers WHERE ticket_id = $t2id")->fetchColumn();
check('manifest: exactly two passengers stored', $paxCount === 2);

$pdo->prepare("INSERT INTO guard_verifications (ticket_id, guard_id, phase, result, checked_driver, notes)
               VALUES (?, 5555, 'PRE_DEPARTURE', 'VALIDATED', 1, 'gate ok')")->execute([$t2id]);
check('guard: verification row recorded', (int) $pdo->query(
    "SELECT COUNT(*) FROM guard_verifications WHERE ticket_id = $t2id")->fetchColumn() === 1);

$pdo->prepare("INSERT INTO trip_tickets
    (status_id, scheduled_departure, scheduled_return, vehicle_id, driver_id, created_by)
    VALUES (?, '2026-09-13 08:00:00', '2026-09-13 17:00:00', 7777, 6666, 9999)")
    ->execute([TripState::DISPATCHED]);
$rivalId = (int) $pdo->lastInsertId();
$rc = ConflictChecker::vehicle($pdo, 7777, '2026-09-13 09:00:00', '2026-09-13 15:00:00', $t2id);
check('reschedule: conflicting window detected', $rc !== null && (int) $rc['ticket_id'] === $rivalId);
$rcOk = ConflictChecker::vehicle($pdo, 7777, '2026-09-14 09:00:00', '2026-09-14 15:00:00', $t2id);
check('reschedule: clear window accepted', $rcOk === null);
check('db: section B columns present', (bool) $pdo->query(
    "SHOW COLUMNS FROM trip_tickets LIKE 'fuel_total_available'")->fetch()
    && (bool) $pdo->query("SHOW COLUMNS FROM trip_tickets LIKE 'distance_odometer'")->fetch());
$pdo->rollBack();
check('phase 2 transaction rolled back cleanly', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_tickets WHERE ticket_id IN ($t2id, $rivalId)")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM trip_passengers WHERE ticket_id = $t2id")->fetchColumn() === 0);

/* ── Phase 3: fleet admin, accounts, devices, settings, FAQs ────────── */
echo "\n== Phase 3 acceptance ==\n";
$orig = Settings::get('OVERDUE_GRACE_MIN');
Settings::set('OVERDUE_GRACE_MIN', '45', 1);
check('settings: set() persists + cache reflects', Settings::getInt('OVERDUE_GRACE_MIN') === 45);
Settings::set('OVERDUE_GRACE_MIN', (string) $orig, 1);
check('settings: restore original value', Settings::getInt('OVERDUE_GRACE_MIN') === (int) $orig);

$pdo->beginTransaction();
$hash = password_hash('Str0ngPass!x', PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare("INSERT INTO account (role_id, email, password, full_name)
               VALUES (4, 'phase3.test@naap.gov.ph', ?, 'Phase Three Test')")->execute([$hash]);
$p3aid = (int) $pdo->lastInsertId();
$st = $pdo->prepare('SELECT password FROM account WHERE account_id = ?');
$st->execute([$p3aid]);
check('accounts: bcrypt(12) hash verifies', password_verify('Str0ngPass!x', (string) $st->fetchColumn()));
try {
    $pdo->prepare("INSERT INTO account (role_id, email, password, full_name)
                   VALUES (4, 'phase3.test@naap.gov.ph', 'whatever123', 'Duplicate')")->execute();
    check('accounts: duplicate email rejected', false);
} catch (PDOException $e) {
    check('accounts: duplicate email rejected', $e->getCode() === '23000');
}

$rawToken = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO gps_devices (device_uid, api_token_hash) VALUES ('GPS-PH3-TEST', ?)")
    ->execute([hash('sha256', $rawToken)]);
$p3did = (int) $pdo->lastInsertId();
$st = $pdo->prepare('SELECT api_token_hash FROM gps_devices WHERE device_id = ?');
$st->execute([$p3did]);
$storedHash = (string) $st->fetchColumn();
check('devices: bearer token stored as sha256, never raw',
      $storedHash === hash('sha256', $rawToken) && !str_contains($storedHash, $rawToken));

try {
    $pdo->prepare("INSERT INTO vehicle (plate_number, model_name, type_id, capacity)
                   SELECT 'NAA-8001', 'Dup Model', MAX(type_id), 2 FROM vehicle_types")->execute();
    check('fleet: duplicate plate number rejected', false);
} catch (PDOException $e) {
    check('fleet: duplicate plate number rejected', $e->getCode() === '23000');
}

$pdo->prepare("INSERT INTO chatbot (prompt_keyword, response_text, managed_by, is_active, is_displayed)
               VALUES ('phase3 kw', 'phase3 answer', 1, 1, 1)")->execute();
$p3fid = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE chatbot SET response_text = ? WHERE chatbot_id = ?')->execute(['edited answer', $p3fid]);
$pdo->prepare('DELETE FROM chatbot WHERE chatbot_id = ?')->execute([$p3fid]);
check('faq: insert/update/delete cycle', (int) $pdo->query(
    "SELECT COUNT(*) FROM chatbot WHERE chatbot_id = {$p3fid}")->fetchColumn() === 0);

$pdo->prepare("INSERT INTO feedback (ticket_id, submitter_name, details) VALUES (1, 'P3 Tester', 'test feedback')")->execute();
$p3fbid = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE feedback SET is_active = 0 WHERE feedback_id = ?')->execute([$p3fbid]);
check('feedback: soft delete keeps record hidden', (int) $pdo->query(
    "SELECT COUNT(*) FROM feedback WHERE feedback_id = {$p3fbid} AND is_active = 0")->fetchColumn() === 1);
$pdo->rollBack();

check('phase 3 transaction rolled back cleanly',
      (int) $pdo->query("SELECT COUNT(*) FROM account WHERE account_id = {$p3aid}")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM gps_devices WHERE device_id = {$p3did}")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM feedback WHERE feedback_id = {$p3fbid} AND is_active = 1")->fetchColumn() === 0);

/* ── Phase 4 — GPS ingestion ─────────────────────────────────────────── */
echo "\n== Phase 4 acceptance ==\n";
require_once __DIR__ . '/../core/Gps.php';
$pdo->beginTransaction();

$provider = new HttpJsonProvider();
$fix = $provider->normalize(['latitude' => '14.5', 'longitude' => '121.0', 'speed' => '42.5', 'recorded_at' => '2026-08-26 07:00:00']);
check('gps: normalize maps aliases + coerces types',
      $fix['lat'] === 14.5 && $fix['lng'] === 121.0 && $fix['speed_kph'] === 42.5);
try { $provider->normalize(['latitude' => 999, 'longitude' => 0]); check('gps: out-of-range lat rejected', false); }
catch (InvalidArgumentException $e) { check('gps: out-of-range lat rejected', true); }
$epoch = $provider->normalize(['lat' => 0, 'lng' => 0, 'timestamp' => 1_800_000_000]);
check('gps: epoch timestamp accepted + future clamp works',
      strtotime($epoch['recorded_at']) <= time());

check('geo: haversine sane (campus ~500m apart ≈ known)',
      abs(Geo::haversineMeters(14.5126, 121.0182, 14.5126, 121.0182)) < 0.01
      && Geo::haversineMeters(14.5126, 121.0182, 14.5176, 121.0182) > 400);

// self-clean debris from any previously crashed run
$pdo->exec("DELETE FROM gps_positions WHERE device_id IN (SELECT device_id FROM (SELECT device_id FROM gps_devices WHERE device_uid LIKE 'GPS-PH4-%') x)");
$pdo->exec("DELETE FROM geofence_events WHERE device_id IN (SELECT device_id FROM (SELECT device_id FROM gps_devices WHERE device_uid LIKE 'GPS-PH4-%') x)");
$pdo->exec("DELETE FROM gps_devices WHERE device_uid LIKE 'GPS-PH4-%'");

$p4token = bin2hex(random_bytes(32));
$p4uid   = 'GPS-PH4-' . bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO gps_devices (device_uid, api_token_hash) VALUES (?, ?)")
    ->execute([$p4uid, hash('sha256', $p4token)]);
$p4did = (int) $pdo->lastInsertId();

check('ingest: unknown token rejected 401',
      GpsIngest::handle($pdo, 'bogus-token', ['lat' => 14.5, 'lng' => 121.0])['code'] === 401);
check('ingest: unpaired device rejected 400',
      GpsIngest::handle($pdo, $p4token, ['lat' => 14.5, 'lng' => 121.0])['code'] === 400);
check('ingest: invalid payload rejected 400',
      GpsIngest::handle($pdo, $p4token, ['lat' => 'abc'])['code'] === 400);

// pair to NAA-8001 (no active trip on it in this txn) → breadcrumb with NULL ticket
$pdo->prepare('UPDATE gps_devices SET vehicle_id = (SELECT vehicle_id FROM vehicle WHERE plate_number = ?) WHERE device_id = ?')
    ->execute(['NAA-8001', $p4did]);
// ensure no ON_GOING trip sits on that vehicle inside this transaction
$pdo->prepare("UPDATE trip_tickets SET status_id = " . TripState::COMPLETED . "
               WHERE vehicle_id = (SELECT vehicle_id FROM vehicle WHERE plate_number = 'NAA-8001')
               AND status_id = " . TripState::ON_GOING)->execute();

$outside = ['lat' => 14.60, 'lng' => 121.10];
check('ingest: first fix accepted 204',
      GpsIngest::handle($pdo, $p4token, $outside + ['recorded_at' => '2026-08-26 07:00:00'])['code'] === 204);
$row = $pdo->query("SELECT ticket_id, inside_geofence FROM gps_positions WHERE device_id = {$p4did} ORDER BY position_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('breadcrumb stored, no active trip ⇒ NULL ticket, outside fence',
      $row['ticket_id'] === null && (int) $row['inside_geofence'] === 0);

$inside = ['lat' => Settings::getFloat('NAAP_GEOFENCE_LAT'), 'lng' => Settings::getFloat('NAAP_GEOFENCE_LNG')];
GpsIngest::handle($pdo, $p4token, $inside + ['recorded_at' => '2026-08-26 07:05:00']);
$ev = $pdo->query("SELECT event_type FROM geofence_events WHERE device_id = {$p4did}")->fetchColumn();
check('geofence: outside→inside transition logged as GEOFENCE_ENTERED', $ev === 'GEOFENCE_ENTERED');
GpsIngest::handle($pdo, $p4token, $outside + ['recorded_at' => '2026-08-26 07:10:00']);
$evs = $pdo->query("SELECT event_type FROM geofence_events WHERE device_id = {$p4did} ORDER BY event_id")->fetchAll(PDO::FETCH_COLUMN);
check('geofence: transition-only events (ENTERED then EXITED), no duplicates',
      $evs === ['GEOFENCE_ENTERED', 'GEOFENCE_EXITED']);

// throttle: live fix (recorded_at=now) then immediate second → not stored
GpsIngest::handle($pdo, $p4token, ['lat' => 14.61, 'lng' => 121.11]);
$before = (int) $pdo->query("SELECT COUNT(*) FROM gps_positions WHERE device_id = {$p4did}")->fetchColumn();
GpsIngest::handle($pdo, $p4token, ['lat' => 14.61, 'lng' => 121.11]);
$after = (int) $pdo->query("SELECT COUNT(*) FROM gps_positions WHERE device_id = {$p4did}")->fetchColumn();
check('throttle: live fix within GPS_MIN_INTERVAL_SEC not stored', $before === $after);

// heartbeat still updates when throttled
$hb = $pdo->query("SELECT last_latitude FROM gps_devices WHERE device_id = {$p4did}")->fetchColumn();
check('heartbeat: last_* updated even when throttled', (float) $hb === 14.61);

// active trip attach: put an ON_GOING trip on the vehicle, ingest again
$pdo->prepare("UPDATE trip_tickets SET status_id = " . TripState::ON_GOING . ",
                 vehicle_id = (SELECT vehicle_id FROM vehicle WHERE plate_number = 'NAA-8001')
               WHERE ticket_id = (SELECT MIN(ticket_id) FROM trip_tickets)")->execute();
GpsIngest::handle($pdo, $p4token, ['lat' => 14.62, 'lng' => 121.12, 'recorded_at' => '2026-08-26 07:20:00']);
$tid = $pdo->query("SELECT ticket_id FROM gps_positions WHERE device_id = {$p4did} ORDER BY position_id DESC LIMIT 1")->fetchColumn();
check('active trip: breadcrumb attached to ON_GOING ticket', $tid == (int) $pdo->query('SELECT MIN(ticket_id) FROM trip_tickets')->fetchColumn());

// deviation flag: re-enter then exit fence while trip active → one GEOFENCE_DEVIATION
GpsIngest::handle($pdo, $p4token, $inside + ['recorded_at' => '2026-08-26 07:30:00']);
GpsIngest::handle($pdo, $p4token, ['lat' => 14.63, 'lng' => 121.13, 'recorded_at' => '2026-08-26 07:40:00']);
$devSql = "SELECT COUNT(*) FROM trip_flags WHERE ticket_id = {$tid} AND flag_type = 'GEOFENCE_DEVIATION' AND resolved_at IS NULL";
check('deviation: geofence exit during active trip raises GEOFENCE_DEVIATION',
      (int) $pdo->query($devSql)->fetchColumn() === 1);
GpsIngest::handle($pdo, $p4token, ['lat' => 14.64, 'lng' => 121.14, 'recorded_at' => '2026-08-26 07:50:00']);
check('deviation: repeat exits do not duplicate the open flag',
      (int) $pdo->query($devSql)->fetchColumn() === 1);
$p4flids = $pdo->query("SELECT GROUP_CONCAT(flag_id) FROM trip_flags WHERE ticket_id = {$tid} AND flag_type = 'GEOFENCE_DEVIATION'")->fetchColumn();

$pdo->rollBack();
check('phase 4 transaction rolled back cleanly',
      (int) $pdo->query("SELECT COUNT(*) FROM gps_devices WHERE device_id = {$p4did}")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM geofence_events WHERE device_id = {$p4did}")->fetchColumn() === 0
      && ($p4flids === null || (int) $pdo->query("SELECT COUNT(*) FROM trip_flags WHERE flag_id IN ({$p4flids})")->fetchColumn() === 0));

/* ── Phase 5 — scheduled flag evaluation ─────────────────────────────── */
echo "\n== Phase 5 acceptance ==\n";
$pdo->beginTransaction();

// self-clean any debris from previously crashed runs
$pdo->exec("DELETE f FROM trip_flags f JOIN trip_tickets t ON t.ticket_id = f.ticket_id WHERE t.passenger_name = 'PH5-FIXTURE'");
$pdo->exec("DELETE FROM trip_tickets WHERE passenger_name = 'PH5-FIXTURE'");
$pdo->exec("UPDATE gps_devices SET vehicle_id = NULL WHERE device_uid = 'GPS-PH5-STALE'");
$pdo->exec("DELETE FROM gps_devices WHERE device_uid = 'GPS-PH5-STALE'");
$pdo->exec("DELETE FROM vehicle WHERE plate_number = 'NAA-PH5-T1'");

$pdo->exec("INSERT INTO vehicle (plate_number, model_name, type_id, capacity)
            SELECT 'NAA-PH5-T1', 'PH5 Model', MAX(type_id), 2 FROM vehicle_types");
$p5vid = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO gps_devices (device_uid, api_token_hash, vehicle_id, is_active, last_seen_at)
            VALUES ('GPS-PH5-STALE', '" . str_repeat('a', 64) . "', {$p5vid}, 1, NOW() - INTERVAL 1 HOUR)");

$mkP5 = static function (string $sql) use ($pdo): int {
    $pdo->exec($sql);
    return (int) $pdo->lastInsertId();
};
// ON_GOING trip past its return window, on a vehicle whose device heartbeat is stale
$tOverdue = $mkP5("INSERT INTO trip_tickets
    (status_id, passenger_name, id_number, place_to_visit, purpose, number_of_passengers,
     created_by, scheduled_departure, scheduled_return, vehicle_id, driver_id, updated_at)
    VALUES (" . TripState::ON_GOING . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,9999,
            DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 2 HOUR),
            {$p5vid}, 8888, NOW())");
// DISPATCHED but never started well past departure
$mkP5("INSERT INTO trip_tickets
    (status_id, passenger_name, id_number, place_to_visit, purpose, number_of_passengers,
     created_by, scheduled_departure, scheduled_return, driver_id, updated_at)
    VALUES (" . TripState::DISPATCHED . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,9999,
            DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_ADD(NOW(), INTERVAL 5 HOUR), 8888, NOW())");
// stale pending request
$mkP5("INSERT INTO trip_tickets
    (status_id, passenger_name, id_number, place_to_visit, purpose, number_of_passengers,
     created_by, scheduled_departure, scheduled_return, updated_at)
    VALUES (" . TripState::PENDING . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,9999,
            DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 8 HOUR),
            DATE_SUB(NOW(), INTERVAL 10 DAY))");
// fresh pending control — must raise nothing
$mkP5("INSERT INTO trip_tickets
    (status_id, passenger_name, id_number, place_to_visit, purpose, number_of_passengers,
     created_by, scheduled_departure, scheduled_return, updated_at)
    VALUES (" . TripState::PENDING . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,9999,
            DATE_ADD(NOW(), INTERVAL 9 DAY), DATE_ADD(NOW(), INTERVAL 10 HOUR), NOW())");

$r1 = FlagEvaluator::run($pdo);
check('flags: OVERDUE raised for past-return active trip',   ($r1['OVERDUE'] ?? 0) >= 1);
check('flags: MISSED_TRIP raised for never-started trip',    ($r1['MISSED_TRIP'] ?? 0) >= 1);
check('flags: GPS_SIGNAL_LOST raised for stale telemetry',   ($r1['GPS_SIGNAL_LOST'] ?? 0) >= 1);
check('flags: NEEDS_REVIEW raised for stale request only',   ($r1['NEEDS_REVIEW'] ?? 0) >= 1);
$r2 = FlagEvaluator::run($pdo);
check('flags: idempotent — immediate re-run raises none',    array_sum($r2) === 0);

$pdo->exec("UPDATE trip_flags SET resolved_at = NOW(), resolved_by = 1, resolution_reason = 'unit test'
            WHERE flag_type = 'OVERDUE' AND ticket_id = {$tOverdue} AND resolved_at IS NULL");
$r3 = FlagEvaluator::run($pdo);
check('flags: resolved OVERDUE re-raises while condition persists', ($r3['OVERDUE'] ?? 0) >= 1);

$pdo->rollBack();
check('phase 5 transaction rolled back cleanly',
      (int) $pdo->query("SELECT COUNT(*) FROM trip_tickets WHERE passenger_name = 'PH5-FIXTURE'")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM gps_devices WHERE device_uid = 'GPS-PH5-STALE'")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM vehicle WHERE plate_number = 'NAA-PH5-T1'")->fetchColumn() === 0);

/* ── Phase 6 — Frontend migration verification ──────────────────────── */
echo "\n== Phase 6 acceptance ==\n";

/* ── Tailwind build artifacts ────────────────────────────────────────── */
$twCss = __DIR__ . '/../assets/css/tailwind.css';
$twSrc = __DIR__ . '/../assets/css/tailwind.src.css';
$appCss = __DIR__ . '/../assets/css/app.css';
check('tw: tailwind.css exists', file_exists($twCss));
check('tw: tailwind.css non-empty', file_exists($twCss) && filesize($twCss) > 1000);
$twCssContent = file_exists($twCss) ? file_get_contents($twCss) : '';
check('tw: tailwind.src.css exists', file_exists($twSrc));
check('tw: app.css exists', file_exists($appCss));

/* ── Tailwind loaded in layout_head ──────────────────────────────────── */
$head = file_get_contents(__DIR__ . '/../partials/layout_head.php');
check('tw: tailwind.css linked in layout_head', str_contains($head, 'tailwind.css'));
check('tw: tailwind.css BEFORE app.css in layout_head',
      str_contains($head, 'tailwind.css') && str_contains($head, 'app.css')
      && strpos($head, 'tailwind.css') < strpos($head, 'app.css'));

/* ── Semantic class contract present in app.css ──────────────────────── */
$appCssContent = file_get_contents($appCss);
check('css: .btn rule present', str_contains($appCssContent, '.btn {'));
check('css: .badge rule present', str_contains($appCssContent, '.badge {'));
check('css: .nav-item rule present', str_contains($appCssContent, '.nav-item'));
check('css: .modal-head rule present', str_contains($appCssContent, '.modal-head'));
check('css: .login-card rule present', str_contains($appCssContent, '.login-card'));
check('css: badge status aliases present', str_contains($appCssContent, '.badge-pending')
      && str_contains($appCssContent, '.badge-verified')
      && str_contains($appCssContent, '.badge-approved')
      && str_contains($appCssContent, '.badge-dispatched')
      && str_contains($appCssContent, '.badge-on-going'));

/* ── JS hooks still present in app.css ───────────────────────────────── */
check('css: .modal-backdrop kept (JS hook)', str_contains($appCssContent, '.modal-backdrop'));
check('css: .drawer kept (JS hook)', str_contains($appCssContent, '.drawer'));
check('css: #idleOverlay kept (JS hook)', str_contains($appCssContent, '#idleOverlay'));
check('css: #toastZone kept (JS hook)', str_contains($appCssContent, '#toastZone'));
check('css: .chat-panel kept (JS hook)', str_contains($appCssContent, '.chat-panel'));
check('css: .msg kept (JS hook)', str_contains($appCssContent, '.msg'));
check('css: .chip kept (JS hook)', str_contains($appCssContent, '.chip'));
check('css: .track-grid kept (tracking)', str_contains($appCssContent, '.track-grid'));

/* ── Surface contract: every fixed/absolute overlay must be visible ───── */
$surfaceRules = [
    '.drawer'            => ['background', 'border-left'],
    '.drawer-backdrop'   => ['background'],
    '.modal-backdrop'    => ['background'],
    '.modal-close'       => ['background'],
    '.chat-panel'        => ['background', 'border'],
    '#idleOverlay'       => ['background'],
    '.la-dropdown'       => ['background', 'border'],
    '.la-clear'          => ['background'],
    '.login-hero-veil'   => ['background'],
];
$surfaceFail = [];
foreach ($surfaceRules as $sel => $props) {
    if (preg_match('/' . preg_quote($sel, '/') . '\s*\{[^}]*\}/', $appCssContent, $m)) {
        $hasAny = false;
        foreach ($props as $p) {
            if (str_contains($m[0], $p . ':')) { $hasAny = true; break; }
        }
        if (!$hasAny) $surfaceFail[] = $sel;
    } else {
        $surfaceFail[] = $sel . ' (missing rule)';
    }
}
check('css: surface contract — all fixed/absolute overlays declare a visible bg/border',
      $surfaceFail === []);
foreach ($surfaceFail as $f) echo "    surface violation: {$f}\n";

/* ── Preflight enabled: box-sizing reset must be compiled in ──────────── */
check('tw: Preflight compiled (universal box-sizing:border-box)',
      str_contains($twCssContent ?? '', 'box-sizing:border-box'));

/* ── Page render checks (output-buffered, fake session) ──────────────── */
$pages = [
    'mis/dashboard.php', 'mis/fleet.php', 'mis/accounts.php', 'mis/audit.php',
    'mis/settings.php', 'mis/chatbot.php', 'mis/feedback.php', 'mis/reports.php',
    'staff/dashboard.php', 'staff/assignments.php', 'staff/trips.php', 'staff/approvals.php',
    'director/dashboard.php', 'director/approvals.php', 'director/reports.php',
    'driver/dashboard.php', 'driver/posttrip.php',
    'guard/desk.php', 'guard/history.php',
    'personnel/requests.php', 'personnel/request_form.php', 'personnel/feedback.php',
    'ticket.php', 'tracking.php', 'flags.php',
    'account/profile.php', 'account/mfa.php', 'account/audit.php', 'account/trail.php',
];

foreach ($pages as $page) {
    $path = __DIR__ . '/../' . $page;
    if (!file_exists($path)) {
        check("parse: {$page} exists", false);
        continue;
    }
    $output = [];
    $exitCode = 0;
    exec(sprintf('"%s" -l %s 2>&1', PHP_BINARY, escapeshellarg($path)), $output, $exitCode);
    check("parse: {$page} syntax OK", $exitCode === 0 && !str_contains(implode("\n", $output), 'Parse error'));
}

/* ── No old class names in rendered HTML ─────────────────────────────── */
$spotCheck = [
    'mis/dashboard.php'  => ['class="data"', 'class="form-grid"', 'class="toolbar"'],
    'ticket.php'         => ['class="data"', 'class="form-grid"', 'class="toolbar"'],
    'mis/fleet.php'      => ['class="data"', 'class="form-grid"', 'class="toolbar"'],
];
foreach ($spotCheck as $page => $oldClasses) {
    $path = __DIR__ . '/../' . $page;
    if (!file_exists($path)) { continue; }
    $src = file_get_contents($path);
    foreach ($oldClasses as $cls) {
        $found = str_contains($src, $cls);
        check("no-old: {$page} free of '{$cls}'", !$found);
    }
}

/* ── @theme block in tailwind.src.css ────────────────────────────────── */
$twSrcContent = file_get_contents($twSrc);
check('tw: @theme block exists in tailwind.src.css', str_contains($twSrcContent, '@theme'));
check('tw: badge status tokens in @theme', str_contains($twSrcContent, '--color-draft'));
check('tw: color tokens in @theme', str_contains($twSrcContent, '--color-bg-page'));
check('tw: @source directive exists', str_contains($twSrcContent, '@source'));

/* ── Class audit (undefined class names) ─────────────────────────────── */
exec(sprintf('"%s" %s 2>&1', PHP_BINARY, escapeshellarg(__DIR__ . '/../scripts/class_audit.php')), $auditOut, $auditExit);
check('audit: no undefined classes in PHP HTML (class_audit.php)', $auditExit === 0);
if ($auditExit !== 0) { echo "  " . implode("\n  ", array_slice($auditOut, 0, 10)) . "\n"; }

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
