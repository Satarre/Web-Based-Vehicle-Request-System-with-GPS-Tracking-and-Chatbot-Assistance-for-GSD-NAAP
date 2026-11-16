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

/* ── FK fixtures: real parent rows for constraint-safe inserts ───────── */
$fxUser   = (int) $pdo->query("SELECT account_id FROM account WHERE role_id = 1 AND is_active = 1 ORDER BY account_id LIMIT 1")->fetchColumn();
if ($fxUser === 0) { $fxUser = (int) $pdo->query('SELECT MIN(account_id) FROM account')->fetchColumn(); }
$fxDriver = (int) $pdo->query("SELECT account_id FROM account WHERE role_id = 4 AND is_active = 1 ORDER BY account_id LIMIT 1")->fetchColumn();
if ($fxDriver === 0) { $fxDriver = $fxUser; }
$mkFxVehicle = static function (PDO $pdo): int {
    $pdo->prepare("INSERT INTO vehicle (plate_number, model_name, type_id, capacity)
                   SELECT ?, 'FK FIXTURE', MAX(type_id), 4 FROM vehicle_types")
        ->execute(['FKFIX-' . strtoupper(bin2hex(random_bytes(3)))]);
    return (int) $pdo->lastInsertId();
};

/* ── Settings ───────────────────────────────────────────────────────── */
check('settings: GPS_MIN_INTERVAL_SEC = 20',        Settings::getInt('GPS_MIN_INTERVAL_SEC') === 20);
check('settings: insecure-HTTP flag is false (production default)', Settings::getBool('GPS_ALLOW_INSECURE_HTTP') === false);
check('settings: geofence lat is a set float',       Settings::getFloat('NAAP_GEOFENCE_LAT') !== 0.0);
check('settings: retention empty = never prune',    Settings::get('RETENTION_RAW_MONTHS', '') === '');

/* ── TripState matrix (10 statuses) ────────────────────────────────── */
$mkTicket = fn(int $status) => [
    'status_id' => $status, 'created_by' => 100, 'driver_id' => 42, 'vehicle_id' => 7,
    'place_to_visit' => 'x', 'purpose' => 'y', 'id_number' => 'z',
    'scheduled_departure' => '2026-09-01 08:00:00', 'scheduled_return' => '2026-09-01 17:00:00',
];
$ctxOwner   = ['user_id' => 100, 'role_id' => 6];
$ctxStaff   = ['user_id' => 200, 'role_id' => 3]; // GSD Dispatcher
$ctxHr      = ['user_id' => 300, 'role_id' => 2]; // Supervising Administrative Officer
$ctxDriverA = ['user_id' => 42,  'role_id' => 4]; // assigned driver
$ctxDriverB = ['user_id' => 43,  'role_id' => 4]; // wrong driver

// submit path
check('draft→pending blocked when request incomplete',
      TripState::can($mkTicket(TripState::DRAFT), TripState::PENDING, $ctxOwner + ['request_complete' => false]) !== true);
check('owner may submit draft→pending',
      TripState::can($mkTicket(TripState::DRAFT), TripState::PENDING, $ctxOwner + ['request_complete' => true]) === true);
check('GSD Dispatcher may NOT approve pending (SAO only)',
      TripState::can($mkTicket(TripState::PENDING), TripState::APPROVED, $ctxStaff) !== true);
check('SAO approves pending→approved directly (no verify step)',
      TripState::can($mkTicket(TripState::PENDING), TripState::APPROVED, $ctxHr) === true);
check('reject without reason blocked',
      TripState::can($mkTicket(TripState::PENDING), TripState::REJECTED, $ctxHr + ['has_reason' => false]) !== true);
check('reject with reason allowed for SAO at pending',
      TripState::can($mkTicket(TripState::PENDING), TripState::REJECTED, $ctxHr + ['has_reason' => true]) === true);
check('GSD Dispatcher rejects at approved→rejected',
      TripState::can($mkTicket(TripState::APPROVED), TripState::REJECTED, $ctxStaff + ['has_reason' => true]) === true);
check('assign→dispatched requires both vehicle+driver',
      TripState::can(['status_id' => TripState::APPROVED, 'created_by' => 100, 'vehicle_id' => 0, 'driver_id' => 0], TripState::DISPATCHED, $ctxStaff) !== true);
check('assign→dispatched allowed when assignment_complete ctx declares the selection',
      TripState::can(['status_id' => TripState::APPROVED, 'created_by' => 100, 'vehicle_id' => 0, 'driver_id' => 0], TripState::DISPATCHED, $ctxStaff + ['assignment_complete' => true]) === true);
$tDispatched = $mkTicket(TripState::DISPATCHED);
check('dispatched→on_going: assigned driver OK',
      TripState::can($tDispatched, TripState::ON_GOING, $ctxDriverA) === true);
check('dispatched→on_going: wrong driver BLOCKED',
      TripState::can($tDispatched, TripState::ON_GOING, $ctxDriverB) !== true);
check('dispatched→on_going: guard may NOT start (verify only)',
      TripState::can($tDispatched, TripState::ON_GOING, ['user_id' => 500, 'role_id' => 5]) !== true);
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
check('cancel allowed from DRAFT, PENDING, APPROVED, DISPATCHED',
      TripState::can($mkTicket(TripState::DRAFT), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true
      && TripState::can($mkTicket(TripState::PENDING), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true
      && TripState::can($mkTicket(TripState::APPROVED), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true
      && TripState::can($mkTicket(TripState::DISPATCHED), TripState::CANCELLED, $ctxOwner + ['has_reason' => true]) === true);
check('cancel is owner-only: dispatcher may NOT cancel a request',
      TripState::can($mkTicket(TripState::PENDING), TripState::CANCELLED, $ctxStaff + ['has_reason' => true]) !== true
      && TripState::can($mkTicket(TripState::APPROVED), TripState::CANCELLED, $ctxStaff + ['has_reason' => true]) !== true
      && TripState::can($mkTicket(TripState::DISPATCHED), TripState::CANCELLED, $ctxStaff + ['has_reason' => true]) !== true);
check('cancel is owner-only: SAO may NOT cancel a request',
      TripState::can($mkTicket(TripState::PENDING), TripState::CANCELLED, $ctxHr + ['has_reason' => true]) !== true);

/* ── Csrf ───────────────────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
check('csrf: valid token passes',       Csrf::verify($_SESSION['csrf_token']) === true);
check('csrf: forged token fails',       Csrf::verify(str_repeat('a', 64)) === false);
check('csrf: missing token fails',      Csrf::verify(null) === false);

/* ── Auth: seeded admin round-trip (no MFA on seed account) ─────────── */
/* The wrong-password probe below increments login_attempts, which persists
 * across runs and would eventually lock the seeded admin. Reset before and
 * after so repeated suite runs stay safe. */
$resetAdminLock = static function (PDO $pdo): void {
    $pdo->exec("UPDATE account SET login_attempts = 0, locked_until = NULL WHERE email = 'admin@naap.gov.ph'");
};
$resetAdminLock($pdo);
Auth::start();
[$ok, $err, $user] = Auth::attempt($pdo, 'admin@naap.gov.ph', 'WRONG-password');
check('auth: wrong password rejected', $ok === false);
[$ok, , $user] = Auth::attempt($pdo, 'ADMIN@naap.gov.ph', 'Admin@12345');
if ($ok && $user) { Auth::finalize($pdo, $user); }
check('auth: seeded admin logs in (case-insensitive email)', $ok === true);
check('auth: session populated as MIS ADMIN', ($_SESSION['role_name'] ?? '') === 'MIS ADMIN');
$resetAdminLock($pdo);
Audit::log($pdo, 'LOGIN', 'account:' . $_SESSION['user_id'], null, null, 'SUCCESS', 1);
check('audit: row written', (bool) $pdo->query(
    "SELECT COUNT(*) c FROM audit_trail WHERE event_type='LOGIN'" )->fetch()['c']);

/* ── Auth lockout: lock on the Nth failure, DB matches the message ──── */
$pdo->beginTransaction();
$fxEmail = 'ph-lock-' . bin2hex(random_bytes(4)) . '@naap.test';
$pdo->prepare("INSERT INTO account (role_id, email, password, full_name, created_by) VALUES (6, ?, ?, 'PH LOCK FIXTURE', 1)")
    ->execute([$fxEmail, password_hash('Lock@12345', PASSWORD_BCRYPT, ['cost' => 12])]);
$fxId = (int) $pdo->lastInsertId();

[, $e1] = Auth::attempt($pdo, $fxEmail, 'Wrong-1');
check('lockout: failure 1 leaves 2 attempts', str_contains((string) $e1, '2 attempts remaining'));
[, $e2] = Auth::attempt($pdo, $fxEmail, 'Wrong-2');
check('lockout: failure 2 leaves 1 attempt', str_contains((string) $e2, '1 attempt remaining'));
[, $e3] = Auth::attempt($pdo, $fxEmail, 'Wrong-3');
check('lockout: failure 3 locks the account', str_contains((string) $e3, 'Account locked'));
$lockRow = $pdo->query("SELECT login_attempts, locked_until FROM account WHERE account_id = {$fxId}")->fetch(PDO::FETCH_ASSOC);
check('lockout: DB matches the message (attempts=3, lock set)',
    (int) $lockRow['login_attempts'] === 3 && strtotime((string) $lockRow['locked_until']) > time());
[$okLocked, $eLocked] = Auth::attempt($pdo, $fxEmail, 'Lock@12345');
check('lockout: correct password rejected while locked',
    $okLocked === false && str_contains((string) $eLocked, 'Account locked'));

$pdo->prepare('UPDATE account SET login_attempts = 0, locked_until = NULL WHERE account_id = ?')->execute([$fxId]);
[$okAfter] = Auth::attempt($pdo, $fxEmail, 'Lock@12345');
check('lockout: unlock lets the correct password through', $okAfter === true);

$fxRow = $pdo->query("SELECT * FROM account WHERE account_id = {$fxId}")->fetch(PDO::FETCH_ASSOC);
[$mfaLocked, $mfaMsg] = Auth::recordLoginFailure($pdo, $fxRow, 'Invalid or expired code', 'MFA_FAILED');
check('lockout: MFA failure shares the password counter + message',
    $mfaLocked === false && str_contains($mfaMsg, '2 attempts remaining')
    && (int) $pdo->query("SELECT login_attempts FROM account WHERE account_id = {$fxId}")->fetchColumn() === 1);
check('lockout: MFA failure logged as MFA_FAILED',
    (int) $pdo->query("SELECT COUNT(*) FROM security_events WHERE account_id = {$fxId} AND event_type = 'MFA_FAILED'")->fetchColumn() === 1);

$pdo->rollBack();
check('lockout: fixture rolled back', (int) $pdo->query(
    "SELECT COUNT(*) FROM account WHERE account_id = {$fxId}")->fetchColumn() === 0);

/* ── DB shape spot-checks ───────────────────────────────────────────── */
check('db: 10 statuses seeded',   (int) $pdo->query('SELECT COUNT(*) FROM request_status')->fetchColumn() === 10);
check('db: trip_tickets has dtt_number column', (bool) $pdo->query("SHOW COLUMNS FROM trip_tickets LIKE 'dtt_number'")->fetch());
check('db: gps_positions DATETIME(3)', str_contains((string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='naap_mn_vts' AND TABLE_NAME='gps_positions' AND COLUMN_NAME='recorded_at'"
)->fetchColumn(), 'datetime'));

/* ── Chatbot matcher (pure, no DB) ──────────────────────────────────── */
echo "\n== Chatbot matcher acceptance ==\n";
$guideFaqs = [
    ['prompt_keyword' => 'create trip request', 'keywords' => 'book a vehicle|new request|file a trip'],
    ['prompt_keyword' => 'submit feedback',     'keywords' => 'rate trip|comments'],
    ['prompt_keyword' => 'view ticket',         'keywords' => 'print ticket|open ticket'],
    ['prompt_keyword' => 'car',                 'keywords' => ''],
];
check('chatbot: synonym hit', ChatbotMatcher::best($guideFaqs, 'how do i book a vehicle')['faq']['prompt_keyword'] === 'create trip request');
check('chatbot: canonical hit', ChatbotMatcher::best($guideFaqs, 'create trip request')['faq']['prompt_keyword'] === 'create trip request');
check('chatbot: unrelated question is a miss', ChatbotMatcher::best($guideFaqs, 'what is the weather today') === null);
check('chatbot: best-of-two picks the right guide', ChatbotMatcher::best($guideFaqs, 'i want to submit feedback')['faq']['prompt_keyword'] === 'submit feedback');
check('chatbot: case-insensitive', ChatbotMatcher::best([['prompt_keyword' => 'Create Trip Request', 'keywords' => '']], 'CREATE TRIP REQUEST') !== null);
check('chatbot: extra whitespace normalized', ChatbotMatcher::best([['prompt_keyword' => 'create trip request', 'keywords' => '']], '  create   trip   request  ') !== null);
check('chatbot: overlapping synonyms still match', ChatbotMatcher::best([['prompt_keyword' => 'create trip request', 'keywords' => 'book a vehicle|book vehicle|request a car']], 'request a car')['faq']['prompt_keyword'] === 'create trip request');
check('chatbot: short-token false positive avoided', ChatbotMatcher::best([['prompt_keyword' => 'car', 'keywords' => '']], 'how do i care for the vehicle') === null);
check('chatbot: short-token exact word still matches', ChatbotMatcher::best([['prompt_keyword' => 'car', 'keywords' => '']], 'car') !== null);

/* Complex wording: a 2-word phrase sharing one generic word (ticket/trip)
   must not score 0.5 and steal the match from the right guide. */
$cancelFaqA = ['prompt_keyword' => 'cancel request', 'keywords' => 'cancel ticket|withdraw request'];
$createFaqA = ['prompt_keyword' => 'create trip request', 'keywords' => 'how to request a vehicle|new request|book a vehicle|file a trip'];
check('chatbot: generic one-word overlap no longer matches',
    ChatbotMatcher::best([$cancelFaqA, $createFaqA], 'how can i submit my trip ticket') === null);
$createFaqB = ['prompt_keyword' => 'create trip request', 'keywords' => 'how to request a vehicle|new request|book a vehicle|file a trip|submit trip ticket|submit a request'];
check('chatbot: complex submit question matches create request, not cancel',
    ChatbotMatcher::best([$cancelFaqA, $createFaqB], 'how can i submit my trip ticket')['faq']['prompt_keyword'] === 'create trip request');

/* Form feedback only rides along with the form-related guides. */
$incompleteForm = ['passenger_name' => 'A', 'id_number' => '', 'place_to_visit' => 'x',
    'purpose' => 'y', 'scheduled_departure' => '2026-01-02 10:00', 'scheduled_return' => '2026-01-02 12:00'];
check('chatbot: no form warnings for an unrelated guide',
    ChatbotMatcher::formWarnings($incompleteForm, 'cancel request') === []);
check('chatbot: missing-field warnings for required fields',
    in_array('Missing: ID number.', ChatbotMatcher::formWarnings($incompleteForm, 'required fields'), true));
$completeForm = array_merge($incompleteForm, ['id_number' => 'EMP-1']);
check('chatbot: no Missing: warnings once the form is complete',
    !array_filter(ChatbotMatcher::formWarnings($completeForm, 'required fields'),
        fn($w) => str_starts_with($w, 'Missing:')));
check('chatbot: schedule conflict surfaces for schedule rules',
    ChatbotMatcher::formWarnings(
        ['scheduled_departure' => '2026-01-02 12:00', 'scheduled_return' => '2026-01-02 10:00'],
        'schedule rules') === ['Scheduled return must be after scheduled departure.']);

/* ── Phase 1: request completeness gate ─────────────────────────────── */
echo "\n== Phase 1 acceptance ==\n";
$req = [
    'passenger_name' => 'A', 'id_number' => '', 'place_to_visit' => 'x', 'purpose' => 'y',
    'scheduled_departure' => date('Y-m-d H:i:s', time() + 86400),
    'scheduled_return'    => date('Y-m-d H:i:s', time() + 172800),
    'number_of_passengers' => 1,
];
check('requestComplete: missing id_number rejected', TripState::requestComplete($req) === false);
$reqL = ['attachment_path' => 'uploads/request_letters/RL_demo.pdf'] + $req;
check('requestComplete: missing request letter rejected', TripState::requestComplete($req) === false);
check('requestComplete: filled request passes',
      TripState::requestComplete(['id_number' => 'N1'] + $reqL) === true);
$badWindow = ['scheduled_return' => $req['scheduled_departure']] + $reqL + ['id_number' => 'N1'];
check('requestComplete: return <= departure rejected', TripState::requestComplete($badWindow) === false);

/* ── Phase 1: TicketNumber + ConflictChecker (tx rolled back) ───────── */
$pdo->beginTransaction();
$year = date('Y');
$before = TicketNumber::next($pdo, (int) $year);
check('ticketnumber: NAAP-MN-YYYY-#### format', (bool) preg_match('/^NAAP-MN-\d{4}-\d{4}$/', $before));
$fxVeh1 = $mkFxVehicle($pdo);
$pdo->prepare("INSERT INTO trip_tickets
    (dtt_number, status_id, scheduled_departure, scheduled_return, vehicle_id, driver_id, created_by)
    VALUES (?, ?, '2026-09-10 08:00:00', '2026-09-10 17:00:00', ?, ?, ?)")
    ->execute([$before, TripState::DISPATCHED, $fxVeh1, $fxDriver, $fxUser]);
$insertedId = (int) $pdo->lastInsertId();
$after = TicketNumber::next($pdo, (int) $year);
check('ticketnumber: sequence continues past highest', (int) substr($after, -4) > (int) substr($before, -4));
$rtt = TicketNumber::next($pdo, (int) $year);
check('ticketnumber: NAAP-MN-YYYY-#### format (next)', (bool) preg_match('/^NAAP-MN-\d{4}-\d{4}$/', $rtt));
check('ticketnumber: single per-year sequence continues', (int) substr($rtt, -4) > (int) substr($before, -4));
$cv = ConflictChecker::vehicle($pdo, $fxVeh1, '2026-09-10 09:00:00', '2026-09-10 12:00:00');
check('conflict: overlapping vehicle booking detected',
      $cv !== null && (int) $cv['ticket_id'] === $insertedId);
$cd = ConflictChecker::driver($pdo, $fxDriver, '2026-09-11 08:00:00', '2026-09-11 17:00:00');
check('conflict: disjoint window is clear', $cd === null);
$cd2 = ConflictChecker::driver($pdo, $fxDriver, '2026-09-10 18:00:00', '2026-09-10 19:00:00');
check('conflict: adjacent-after window is clear', $cd2 === null);
$pdo->rollBack();
check('test transaction rolled back cleanly', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_tickets WHERE dtt_number LIKE 'NAAP-MN-{$year}-9000'" )->fetchColumn() === 0);

/* ── Phase 2: execution chain, manifest, guard desk, reschedule ─────── */
echo "\n== Phase 2 acceptance ==\n";
$pdo->beginTransaction();
$fxVeh2 = $mkFxVehicle($pdo);
$pdo->prepare("INSERT INTO trip_tickets
    (status_id, scheduled_departure, scheduled_return, vehicle_id, driver_id, created_by,
     passenger_name, id_number, place_to_visit, purpose, number_of_passengers)
    VALUES (?, '2026-09-12 08:00:00', '2026-09-12 17:00:00', ?, ?, ?, 'Pax', 'ID-1', 'Base X', 'Official travel', 1)")
    ->execute([TripState::DISPATCHED, $fxVeh2, $fxDriver, $fxUser]);
$t2id = (int) $pdo->lastInsertId();
$t2   = static fn(int $status): array => [
    'ticket_id' => $t2id, 'status_id' => $status, 'created_by' => $fxUser,
    'driver_id' => $fxDriver, 'vehicle_id' => $fxVeh2, 'dtt_number' => null,
    'scheduled_departure' => '2026-09-12 08:00:00', 'scheduled_return' => '2026-09-12 17:00:00',
];
$drvA = ['user_id' => $fxDriver, 'role_id' => 4];
$drvB = ['user_id' => $fxDriver + 1, 'role_id' => 4];

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
               VALUES (?, ?, 'PRE_DEPARTURE', 'VALIDATED', 1, 'gate ok')")->execute([$t2id, $fxUser]);
check('guard: verification row recorded', (int) $pdo->query(
    "SELECT COUNT(*) FROM guard_verifications WHERE ticket_id = $t2id")->fetchColumn() === 1);

$pdo->prepare("INSERT INTO trip_tickets
    (status_id, scheduled_departure, scheduled_return, vehicle_id, driver_id, created_by)
    VALUES (?, '2026-09-13 08:00:00', '2026-09-13 17:00:00', ?, ?, ?)")
    ->execute([TripState::DISPATCHED, $fxVeh2, $fxDriver, $fxUser]);
$rivalId = (int) $pdo->lastInsertId();
$rc = ConflictChecker::vehicle($pdo, $fxVeh2, '2026-09-13 09:00:00', '2026-09-13 15:00:00', $t2id);
check('reschedule: conflicting window detected', $rc !== null && (int) $rc['ticket_id'] === $rivalId);
$rcOk = ConflictChecker::vehicle($pdo, $fxVeh2, '2026-09-14 09:00:00', '2026-09-14 15:00:00', $t2id);
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
$p3email = 'phase3.test.' . bin2hex(random_bytes(4)) . '@naap.gov.ph';
$pdo->prepare("INSERT INTO account (role_id, email, password, full_name)
               VALUES (4, ?, ?, 'Phase Three Test')")->execute([$p3email, $hash]);
$p3aid = (int) $pdo->lastInsertId();
$st = $pdo->prepare('SELECT password FROM account WHERE account_id = ?');
$st->execute([$p3aid]);
check('accounts: bcrypt(12) hash verifies', password_verify('Str0ngPass!x', (string) $st->fetchColumn()));
try {
    $pdo->prepare("INSERT INTO account (role_id, email, password, full_name)
                   VALUES (4, ?, 'whatever123', 'Duplicate')")->execute([$p3email]);
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
               VALUES ('phase3 kw', 'phase3 answer', ?, 1, 1)")->execute([$fxUser]);
$p3fid = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE chatbot SET response_text = ? WHERE chatbot_id = ?')->execute(['edited answer', $p3fid]);
$pdo->prepare('DELETE FROM chatbot WHERE chatbot_id = ?')->execute([$p3fid]);
check('faq: insert/update/delete cycle', (int) $pdo->query(
    "SELECT COUNT(*) FROM chatbot WHERE chatbot_id = {$p3fid}")->fetchColumn() === 0);

// Normalized keyword duplicate protection (case + surrounding whitespace).
$pdo->prepare("INSERT INTO chatbot (prompt_keyword, response_text) VALUES ('Dup Key', 'first')")->execute();
$dupBlocked = false;
try {
    $pdo->prepare("INSERT INTO chatbot (prompt_keyword, response_text) VALUES ('  dup key ', 'second')")->execute();
} catch (PDOException $e) {
    $dupBlocked = $e->getCode() === '23000';
}
check('faq: normalized duplicate keyword rejected by DB', $dupBlocked);

$fxTicket3 = (int) $pdo->query('SELECT ticket_id FROM trip_tickets ORDER BY ticket_id LIMIT 1')->fetchColumn();
if ($fxTicket3 === 0) {
    $pdo->prepare("INSERT INTO trip_tickets (status_id, created_by, passenger_name, id_number, place_to_visit, purpose)
                   VALUES (?, ?, 'FK FIXTURE', 'FK-1', 'base', 'test')")->execute([TripState::DRAFT, $fxUser]);
    $fxTicket3 = (int) $pdo->lastInsertId();
}
$pdo->prepare("INSERT INTO feedback (ticket_id, submitter_name, details) VALUES (?, 'P3 Tester', 'test feedback')")->execute([$fxTicket3]);
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

// deadzone replay: backdated fixes arriving oldest→newest (5 s apart, below the
// 20 s live throttle) must all persist, and must not drag the cached live
// position backwards to an old fix.
$replayBase = strtotime('2026-08-26 10:00:00');
for ($i = 0; $i < 4; $i++) {
    GpsIngest::handle($pdo, $p4token, [
        'lat' => 14.70 + $i * 0.001, 'lng' => 121.20,
        'recorded_at' => date('Y-m-d H:i:s', $replayBase + $i * 5),
    ]);
}
$replayTimes = $pdo->query(
    "SELECT recorded_at FROM gps_positions WHERE device_id = {$p4did}
     AND recorded_at BETWEEN '2026-08-26 10:00:00' AND '2026-08-26 10:00:30'
     ORDER BY recorded_at"
)->fetchAll(PDO::FETCH_COLUMN);
check('replay: every backdated deadzone fix stored in time order', count($replayTimes) === 4);
$hbAfterReplay = $pdo->query("SELECT last_latitude FROM gps_devices WHERE device_id = {$p4did}")->fetchColumn();
check('replay: cached live position not overwritten by backdated fixes', (float) $hbAfterReplay === 14.61);

// ── stale-gate (v6.8): a fix NEWER than the newest stored one, but captured
// far outside GPS_DELAYED_SEC (the tail of a deadzone replay after a long
// outage), must heartbeat without moving the live cache. Fresh device so the
// cache starts empty; paired to the same trip-free vehicle NAA-8001.
$gToken = bin2hex(random_bytes(32));
$gUid   = 'GPS-PH4-' . bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO gps_devices (device_uid, api_token_hash, vehicle_id, is_active)
               VALUES (?, ?, (SELECT vehicle_id FROM vehicle WHERE plate_number = ?), 1)')
    ->execute([$gUid, hash('sha256', $gToken), 'NAA-8001']);
$gateDelayed = max(0, Settings::getInt('GPS_DELAYED_SEC', 120));

// first-ever fix may be stale (device booted mid-outage): it seeds the cache so
// the map has something to show, and last_recorded_at records its capture time.
$oldTs = time() - (4 * $gateDelayed + 120);
GpsIngest::handle($pdo, $gToken, ['lat' => 14.80, 'lng' => 121.30, 'recorded_at' => date('Y-m-d H:i:s', $oldTs)]);
$gRow1 = $pdo->query("SELECT last_latitude, last_recorded_at, last_seen_at FROM gps_devices WHERE device_uid = '{$gUid}'")->fetch(PDO::FETCH_ASSOC);
check('stale gate: first-ever stale fix seeds the live cache',
      (float) $gRow1['last_latitude'] === 14.80 && $gRow1['last_recorded_at'] !== null
      && abs(time() - strtotime((string) $gRow1['last_seen_at'])) < 60);

// replay tail: newer than the stored fix, still stale vs real time → cache must
// stay frozen on 14.80 while last_seen_at (link heartbeat) keeps updating.
$midTs = time() - (2 * $gateDelayed + 60);
GpsIngest::handle($pdo, $gToken, ['lat' => 14.81, 'lng' => 121.31, 'recorded_at' => date('Y-m-d H:i:s', $midTs)]);
$gRow2 = $pdo->query("SELECT last_latitude, last_recorded_at, last_seen_at FROM gps_devices WHERE device_uid = '{$gUid}'")->fetch(PDO::FETCH_ASSOC);
check('stale gate: backdated-but-newer replay tail never moves the live cache',
      (float) $gRow2['last_latitude'] === 14.80
      && $gRow2['last_recorded_at'] === $gRow1['last_recorded_at']
      && abs(time() - strtotime((string) $gRow2['last_seen_at'])) < 60);

// a genuinely current fix unlocks the cache again (live position jumps to it)
GpsIngest::handle($pdo, $gToken, ['lat' => 14.82, 'lng' => 121.32]);
$gRow3 = $pdo->query("SELECT last_latitude, last_recorded_at FROM gps_devices WHERE device_uid = '{$gUid}'")->fetch(PDO::FETCH_ASSOC);
check('stale gate: current fix re-acquires the live cache',
      (float) $gRow3['last_latitude'] === 14.82
      && abs(time() - strtotime((string) $gRow3['last_recorded_at'])) < 10);

// ── batch replay (v6.8): several fixes per call through the same pipeline ──
// (700 s back so the window never touches the stale-gate seed fix at −600 s;
// a shared second would hit the (device_id, recorded_at) dedupe no-op)
$batchBase = time() - 700;
$mkFix = static fn (int $i): array => [
    'lat' => 14.90 + $i * 0.0005,
    'lng' => 121.40,
    'recorded_at' => date('Y-m-d H:i:s', $batchBase + $i * 30),
];
$res = GpsIngest::handleBatch($pdo, $gToken, array_map($mkFix, [0, 1, 2]));
check('batch: spaced fixes stored in one call', $res['code'] === 204 && $res['stored'] === 3 && $res['throttled'] === 0);
check('batch: rows actually persisted', (int) $pdo->query("SELECT COUNT(*) FROM gps_positions WHERE latitude BETWEEN 14.90 AND 14.91 AND longitude = 121.40")->fetchColumn() === 3);

// dense backdated elements inside one batch are all stored — same deadzone
// replay semantics as the single-fix path (backdated fixes bypass the 20 s
// live throttle so history is never silently lost)
$res = GpsIngest::handleBatch($pdo, $gToken, [
    $mkFix(3),
    ['lat' => 14.9035, 'lng' => 121.40, 'recorded_at' => date('Y-m-d H:i:s', $batchBase + 95)],
]);
check('batch: dense backdated elements all stored (deadzone replay semantics)',
      $res['code'] === 204 && $res['stored'] === 2 && $res['throttled'] === 0);

// malformed elements are skipped, valid neighbours still stored; device may
// drop the whole batch on 204
$res = GpsIngest::handleBatch($pdo, $gToken, [$mkFix(9), ['lat' => 'bad'], $mkFix(10), 'garbage']);
check('batch: invalid elements skipped, valid ones stored',
      $res['code'] === 204 && $res['stored'] === 2 && $res['skipped'] === 2);

check('batch: empty list rejected 400', GpsIngest::handleBatch($pdo, $gToken, [])['code'] === 400);
check('batch: oversized list rejected 400', GpsIngest::handleBatch($pdo, $gToken, array_fill(0, 51, ['lat' => 1, 'lng' => 2]))['code'] === 400);
check('batch: unknown token rejected 401', GpsIngest::handleBatch($pdo, 'bogus-token', [$mkFix(20)])['code'] === 401);

// active trip attach: create an ON_GOING fixture on the vehicle, ingest again
$pdo->prepare("INSERT INTO trip_tickets
    (status_id, vehicle_id, created_by, passenger_name, id_number, place_to_visit, purpose,
     number_of_passengers, scheduled_departure, scheduled_return, updated_by)
    VALUES (?, (SELECT vehicle_id FROM vehicle WHERE plate_number = 'NAA-8001'), 1,
            'PH4 FIXTURE', 'PH4', 'base', 'test', 1, NOW(), DATE_ADD(NOW(), INTERVAL 4 HOUR), 1)")
    ->execute([TripState::ON_GOING]);
$ph4tid = (int) $pdo->lastInsertId();
GpsIngest::handle($pdo, $p4token, ['lat' => 14.62, 'lng' => 121.12, 'recorded_at' => '2026-08-26 07:20:00']);
$tid = $pdo->query("SELECT ticket_id FROM gps_positions WHERE device_id = {$p4did} ORDER BY position_id DESC LIMIT 1")->fetchColumn();
check('active trip: breadcrumb attached to ON_GOING ticket', $tid == $ph4tid);

// fence exit during an active trip no longer raises a flag — the geofence now
// gates driver start/complete instead; transitions are still logged.
GpsIngest::handle($pdo, $p4token, $inside + ['recorded_at' => '2026-08-26 07:30:00']);
GpsIngest::handle($pdo, $p4token, ['lat' => 14.63, 'lng' => 121.13, 'recorded_at' => '2026-08-26 07:40:00']);
check('geofence: exit during active trip raises no flag',
      (int) $pdo->query("SELECT COUNT(*) FROM trip_flags WHERE ticket_id = {$tid}")->fetchColumn() === 0);

$pdo->rollBack();
check('phase 4 transaction rolled back cleanly',
      (int) $pdo->query("SELECT COUNT(*) FROM gps_devices WHERE device_id = {$p4did}")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM geofence_events WHERE device_id = {$p4did}")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM trip_flags WHERE ticket_id = {$tid}")->fetchColumn() === 0);

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
    VALUES (" . TripState::ON_GOING . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,{$fxUser},
            DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 2 HOUR),
            {$p5vid}, {$fxDriver}, NOW())");
// DISPATCHED but never started well past departure
$mkP5("INSERT INTO trip_tickets
    (status_id, passenger_name, id_number, place_to_visit, purpose, number_of_passengers,
     created_by, scheduled_departure, scheduled_return, driver_id, updated_at)
    VALUES (" . TripState::DISPATCHED . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,{$fxUser},
            DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_ADD(NOW(), INTERVAL 5 HOUR), {$fxDriver}, NOW())");
// stale pending request
$mkP5("INSERT INTO trip_tickets
    (status_id, passenger_name, id_number, place_to_visit, purpose, number_of_passengers,
     created_by, scheduled_departure, scheduled_return, updated_at)
    VALUES (" . TripState::PENDING . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,{$fxUser},
            DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 8 HOUR),
            DATE_SUB(NOW(), INTERVAL 10 DAY))");
// fresh pending control — must raise nothing
$mkP5("INSERT INTO trip_tickets
    (status_id, passenger_name, id_number, place_to_visit, purpose, number_of_passengers,
     created_by, scheduled_departure, scheduled_return, updated_at)
    VALUES (" . TripState::PENDING . ",'PH5-FIXTURE','ID-PH5','Base PH5','test',1,{$fxUser},
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
check('css: .upload-progress present (upload bar)', str_contains($appCssContent, '.upload-progress')
      && str_contains($appCssContent, '.upload-progress .upload-bar'));
check('css: .upload-drop.is-uploading locks the dropzone', str_contains($appCssContent, '.upload-drop.is-uploading'));

/* ── Surface contract: every fixed/absolute overlay must be visible ───── */
$surfaceRules = [
    '.drawer'            => ['background', 'border-left'],
    '.drawer-backdrop'   => ['background'],
    '.modal-backdrop'    => ['background'],
    '.modal-close'       => ['background'],
    '.chat-panel'        => ['background', 'border'],
    '#idleOverlay'       => ['background'],
    '.c-select-list'     => ['background', 'border'],
    '.la-dropdown'       => ['background', 'border'],
    '.la-clear'          => ['background'],
    '.login-hero-veil'   => ['background'],
];
$surfaceFail = [];
foreach ($surfaceRules as $sel => $props) {
    // `[^}]*` allows the selector to be part of a selector list
    // (e.g. `.c-select-list, .la-dropdown { … }`).
    if (preg_match('/' . preg_quote($sel, '/') . '[^}]*\{[^}]*\}/', $appCssContent, $m)) {
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

/* ── Dropdown panels share one styling rule (general across all dropdowns) ── */
$panelRule = '/\.c-select-list\s*,\s*\.la-dropdown\s*\{[^}]*\}/';
$panelOk = preg_match($panelRule, $appCssContent, $pm) === 1
    && str_contains($pm[0], 'background:') && str_contains($pm[0], 'border:')
    && str_contains($pm[0], 'max-height:260px') && str_contains($pm[0], 'overflow-y:auto')
    && str_contains($pm[0], 'box-shadow:0 8px 24px');
check('css: dropdown panels (.c-select-list, .la-dropdown) share one styled rule', $panelOk);

/* ── Preflight enabled: box-sizing reset must be compiled in ──────────── */
check('tw: Preflight compiled (universal box-sizing:border-box)',
      str_contains($twCssContent ?? '', 'box-sizing:border-box'));

/* ── Page render checks (output-buffered, fake session) ──────────────── */
$pages = [
    'mis/dashboard.php', 'mis/fleet.php', 'mis/accounts.php', 'mis/audit.php',
    'mis/settings.php', 'mis/chatbot.php', 'mis/feedback.php', 'mis/reports.php',
    'staff/dashboard.php', 'staff/assignments.php', 'staff/trips.php',
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

/* ── Phase 7 — security upgrade: email OTP, TOTP at rest, rate limits ── */
echo "\n== Phase 7 acceptance ==\n";
$pdo->beginTransaction();

$adminId = (int) $pdo->query("SELECT account_id FROM account WHERE email = 'admin@naap.gov.ph'")->fetchColumn();
check('sec: seeded admin found for tests', $adminId > 0);

/* OTP lifecycle */
$otp = OtpService::generate($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET);
check('otp: generated code is 6 digits', (bool) preg_match('/^\d{6}$/', $otp));
$stored = $pdo->query("SELECT otp_hash FROM email_otps WHERE account_id = {$adminId} AND purpose = 'password_reset' ORDER BY otp_id DESC LIMIT 1")->fetchColumn();
check('otp: only hash stored, never plaintext', (string) $stored !== $otp && password_verify($otp, (string) $stored));
check('otp: single-use — first verify passes', OtpService::verify($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET, $otp) === true);
check('otp: single-use — replay fails', OtpService::verify($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET, $otp) === false);

$otp2 = OtpService::generate($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET);
check('otp: new code invalidates previous unused codes', OtpService::verify($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET, $otp2) === true
    && (int) $pdo->query("SELECT COUNT(*) FROM email_otps WHERE account_id = {$adminId} AND purpose='password_reset' AND used_at IS NULL")->fetchColumn() === 0);

$otp3 = OtpService::generate($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET);
for ($i = 0; $i < 5; $i++) { OtpService::verify($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET, '000000'); }
check('otp: exhausted after 5 failed attempts (code invalidated)', OtpService::verify($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET, $otp3) === false);
check('otp: resend blocked during 60s cooldown', OtpService::canResend($pdo, $adminId, OtpService::PURPOSE_PASSWORD_RESET) === false);

/* TOTP at rest */
$secret = TotpService::generateSecret();
$enc = TotpService::encrypt($secret);
check('totp: encrypted secret differs from plaintext', $enc !== $secret && $enc !== '');
check('totp: encrypt/decrypt round-trips', TotpService::decrypt($enc) === $secret);
check('totp: tampered payload rejected', TotpService::decrypt(substr($enc, 0, -2) . '00') === false);
// Replicate RFC 6238 code for the current 30s window to prove verifyEncrypted works
$b32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
$bin = '';
foreach (str_split(strtoupper(preg_replace('/\s+/', '', $secret))) as $ch) {
    $pos = strpos($b32, $ch);
    if ($pos === false) continue;
    $bin .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
}
$key = '';
foreach (str_split($bin, 8) as $byte) { if (strlen($byte) === 8) $key .= chr(bindec($byte)); }
$step = (int) floor(time() / 30);
$hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $step), $key, true);
$off  = ord($hash[strlen($hash) - 1]) & 0x0F;
$code = str_pad((string) (((((ord($hash[$off]) & 0x7F) << 24) | ((ord($hash[$off + 1]) & 0xFF) << 16) | ((ord($hash[$off + 2]) & 0xFF) << 8) | (ord($hash[$off + 3]) & 0xFF))) % 1000000), 6, '0', STR_PAD_LEFT);
check('totp: valid RFC 6238 code accepted', TotpService::verifyEncrypted($enc, $code) === true);
check('totp: wrong code rejected', TotpService::verifyEncrypted($enc, '000000') === false);
check('totp: APP_SECRET_KEY configured', TotpService::secretKey() !== '');
// Replay protection: the first consume succeeds, the second (same window) is rejected.
check('totp: code replay within window rejected',
    TotpService::verifyAndConsume($pdo, $adminId, $enc, $code) === true
    && TotpService::verifyAndConsume($pdo, $adminId, $enc, $code) === false);

/* security events + rate limiter */
SecurityEvent::log($pdo, 'TEST_EVENT', $adminId);
check('sec: security_events row written with ip+ua',
    (int) $pdo->query("SELECT COUNT(*) FROM security_events WHERE event_type='TEST_EVENT' AND ip_address IS NOT NULL AND user_agent IS NOT NULL")->fetchColumn() === 1);
check('rate: under cap allowed', RateLimiter::hit($pdo, 'RATE_TEST', $adminId, 2, 60) === true);
check('rate: second within cap allowed', RateLimiter::hit($pdo, 'RATE_TEST', $adminId, 2, 60) === true);
check('rate: third within cap blocked', RateLimiter::hit($pdo, 'RATE_TEST', $adminId, 2, 60) === false);

/* TOTP URI label = readable role, never the email */
$secret = TotpService::generateSecret();
check('totp: label role 1 = Administrator', TotpService::labelFor(1, 'MIS ADMIN') === 'Administrator');
check('totp: label role 3 = GSD Dispatcher', TotpService::labelFor(3, 'GSD DISPATCHER') === 'GSD Dispatcher');
check('totp: label role 4 = Driver',        TotpService::labelFor(4, 'DRIVER') === 'Driver');
check('totp: URI label is role, not email', str_contains(TOTP::getUri($secret, TotpService::labelFor(1, 'MIS ADMIN')), ':Administrator?')
    && !str_contains(TOTP::getUri($secret, TotpService::labelFor(1, 'MIS ADMIN')), '@'));

/* session-version revocation (password change/reset invalidates old sessions) */
$dbV = (int) $pdo->query("SELECT sessions_version FROM account WHERE account_id = {$adminId}")->fetchColumn();
$_SESSION['user_id'] = $adminId; $_SESSION['role_id'] = 1;
$_SESSION['full_name'] = 'x'; $_SESSION['role_name'] = 'x'; $_SESSION['email'] = 'x@x.ph';
$_SESSION['sessions_version'] = $dbV + 5; // any value differing from the DB row
check('sec: stale sessions_version session destroyed on refresh', Auth::refresh($pdo) === false);
$_SESSION['user_id'] = $adminId; $_SESSION['role_id'] = 1;
$_SESSION['full_name'] = 'x'; $_SESSION['role_name'] = 'x'; $_SESSION['email'] = 'x@x.ph';
$_SESSION['sessions_version'] = $dbV;
check('sec: current sessions_version session survives refresh', Auth::refresh($pdo) === true);

/* MailService failure paths: never throws, always explains (no network here) */
check('mail: send() with an invalid recipient fails without throwing',
    MailService::send($pdo, 'not-an-email', 'X', 'test', '<p>x</p>', 'x') === false);
check('mail: lastError() explains the failure', MailService::lastError() !== '');

$pdo->rollBack();
check('phase 7 transaction rolled back cleanly', (int) $pdo->query(
    "SELECT COUNT(*) FROM email_otps WHERE account_id = {$adminId}")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM security_events WHERE event_type IN ('TEST_EVENT','RATE_TEST')")->fetchColumn() === 0);

/* ── Phase 8 — monitoring: GPS distance, route deviation, idle, chatbot ── */
echo "\n== Phase 8 acceptance ==\n";
$pdo->beginTransaction();

/* Geo: path distance + cross-track helpers */
check('geo: pathKm ~0 for a stationary point',
    Geo::pathKm([['latitude' => 14.5, 'longitude' => 121.0], ['latitude' => 14.5, 'longitude' => 121.0]]) < 0.001);
$oneDeg = Geo::pathKm([['latitude' => 14.0, 'longitude' => 121.0], ['latitude' => 15.0, 'longitude' => 121.0]]);
check('geo: pathKm ≈ 111.2 km per degree of latitude', abs($oneDeg - 111.2) < 0.5);
check('geo: crossTrack ~0 on the corridor',
    Geo::crossTrackMeters(5.0, 0.0, 0.0, 0.0, 10.0, 0.0) < 100);
check('geo: crossTrack large off the corridor',
    Geo::crossTrackMeters(5.0, 5.0, 0.0, 0.0, 10.0, 0.0) > 100000);
$twoSeg = round(Geo::pathKm([
    ['latitude' => 14.0, 'longitude' => 121.0],
    ['latitude' => 15.0, 'longitude' => 121.0],
    ['latitude' => 16.0, 'longitude' => 121.0],
]), 1);
check('geo: two-segment path ≈ 222.4 km', abs($twoSeg - 222.4) < 1.0);

/* ROUTE_DEVIATION removed: an off-corridor fix raises no flag.
   The ticket/device fixtures below still feed the idle-detection test. */
$maxType = (int) $pdo->query('SELECT MAX(type_id) FROM vehicle_types')->fetchColumn();
$pdo->prepare("INSERT INTO vehicle (plate_number, model_name, type_id, capacity, is_available, is_active) VALUES (?,?,?,?,1,1)")
    ->execute(['PH8-' . strtoupper(bin2hex(random_bytes(2))), 'Test', $maxType, 5]);
$veh8 = (int) $pdo->lastInsertId();
$drv8 = (int) $pdo->query("SELECT account_id FROM account WHERE role_id = 4 AND is_active = 1 ORDER BY account_id LIMIT 1")->fetchColumn() ?: $fxDriver;
$pdo->prepare(
    "INSERT INTO trip_tickets (status_id, vehicle_id, driver_id, created_by, scheduled_departure, scheduled_return,
       place_to_visit, place_to_visit_lat, place_to_visit_lon, purpose, id_number, passenger_name, updated_by)
     VALUES (?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL 8 HOUR),?,?,?,?,?,?,1)"
)->execute([TripState::ON_GOING, $veh8, $drv8, 1, 'PH8 destination', 10, 10, 'test', 'T-PH8', 'PH8 TEST']);
$tid8 = (int) $pdo->lastInsertId();
$tok8 = 'ph8-token-' . bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO gps_devices (device_uid, vehicle_id, device_name, protocol, api_token_hash, is_active)
               VALUES (?,?,?,?,SHA2(?,256),1)")
    ->execute(['GPS-PH8-' . strtoupper(bin2hex(random_bytes(3))), $veh8, 'PH8', 'http-json', $tok8]);

$off1 = GpsIngest::handle($pdo, $tok8, ['latitude' => 5, 'longitude' => 5, 'speed_kph' => 40, 'recorded_at' => date('Y-m-d H:i:s', time() - 60)]);
check('route: off-corridor fix accepted 204', $off1['code'] === 204);
check('route: off-corridor fix raises no flag', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_flags WHERE ticket_id = {$tid8}")->fetchColumn() === 0);

/* IDLE_DETECTED: all fixes in the still-window below the speed threshold */
$pdo->prepare(
    "INSERT INTO trip_tickets (status_id, vehicle_id, driver_id, created_by, scheduled_departure, scheduled_return,
       place_to_visit, place_to_visit_lat, place_to_visit_lon, purpose, id_number, passenger_name, updated_by)
     VALUES (?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL 8 HOUR),?,?,?,?,?,?,1)"
)->execute([TripState::ON_GOING, $veh8, $drv8, 1, 'PH8 idle', 14.6, 121.0, 'test', 'T-PH8B', 'PH8 IDLE']);
$tidIdle = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO gps_devices (device_uid, vehicle_id, device_name, protocol, api_token_hash, is_active, last_seen_at)
               VALUES (?,?,?,?,SHA2(?,256),1,NOW())")
    ->execute(['GPS-PH8B-' . strtoupper(bin2hex(random_bytes(3))), $veh8, 'PH8B', 'http-json', 'ph8b-' . bin2hex(random_bytes(6))]);
$devB = (int) $pdo->lastInsertId();
for ($i = 0; $i < 6; $i++) {
    $pdo->prepare(
        'INSERT INTO gps_positions (device_id, vehicle_id, ticket_id, latitude, longitude, speed_kph, inside_geofence, recorded_at)
         VALUES (?,?,?,?,?,?,1,DATE_SUB(NOW(), INTERVAL ? MINUTE))'
    )->execute([$devB, $veh8, $tidIdle, 14.5264, 121.0236, 0.0, 12 - $i * 2]);
}
$raised = FlagEvaluator::run($pdo);
check('idle: IDLE_DETECTED raised for stationary trip', ($raised['IDLE_DETECTED'] ?? 0) >= 1);
check('idle: IDLE_DETECTED targets the idle ticket', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_flags WHERE ticket_id = {$tidIdle} AND flag_type = 'IDLE_DETECTED' AND resolved_at IS NULL")->fetchColumn() === 1);
check('idle: moving trip NOT flagged idle', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_flags WHERE ticket_id = {$tid8} AND flag_type = 'IDLE_DETECTED'")->fetchColumn() === 0);

/* Geofence flag: raised at start/complete only with a fresh outside fix. */
$cLat = Settings::getFloat('NAAP_GEOFENCE_LAT');
$cLng = Settings::getFloat('NAAP_GEOFENCE_LNG');
$flagSql = "SELECT COUNT(*) FROM trip_flags WHERE ticket_id = {$tid8} AND flag_type = 'GEOFENCE_DEVIATION'";
$pdo->prepare("INSERT INTO vehicle (plate_number, model_name, type_id, capacity, is_available, is_active) VALUES (?,?,?,?,1,1)")
    ->execute(['PH8-G' . strtoupper(bin2hex(random_bytes(2))), 'Test', $maxType, 5]);
$vehG = (int) $pdo->lastInsertId();
check('geofence: vehicle without paired device raises nothing',
    GpsIngest::raiseGeofenceFlag($pdo, $tid8, $vehG, 'start') === null
    && (int) $pdo->query($flagSql)->fetchColumn() === 0);
$pdo->prepare("INSERT INTO gps_devices (device_uid, vehicle_id, device_name, protocol, api_token_hash, is_active)
               VALUES (?,?,?,?,SHA2(?,256),1)")
    ->execute(['GPS-PH8G-' . strtoupper(bin2hex(random_bytes(3))), $vehG, 'PH8G', 'http-json', 'ph8g-' . bin2hex(random_bytes(6))]);
$devG = (int) $pdo->lastInsertId();
check('geofence: paired device with no fix raises nothing',
    GpsIngest::raiseGeofenceFlag($pdo, $tid8, $vehG, 'start') === null
    && (int) $pdo->query($flagSql)->fetchColumn() === 0);
$insFix = $pdo->prepare('INSERT INTO gps_positions (device_id, vehicle_id, latitude, longitude, inside_geofence, recorded_at) VALUES (?,?,?,?,?,?)');
$insFix->execute([$devG, $vehG, $cLat, $cLng, 1, date('Y-m-d H:i:s', time() - 600)]);
check('geofence: stale outside fix raises nothing',
    GpsIngest::raiseGeofenceFlag($pdo, $tid8, $vehG, 'start') === null
    && (int) $pdo->query($flagSql)->fetchColumn() === 0);
$insFix->execute([$devG, $vehG, 14.60, 121.10, 0, date('Y-m-d H:i:s', time() - 3)]);
$detail = GpsIngest::raiseGeofenceFlag($pdo, $tid8, $vehG, 'start');
check('geofence: fresh outside fix at start raises GEOFENCE_DEVIATION',
    is_string($detail) && str_contains($detail, 'started outside') && (int) $pdo->query($flagSql)->fetchColumn() === 1);
check('geofence: repeat start does not duplicate the open flag',
    GpsIngest::raiseGeofenceFlag($pdo, $tid8, $vehG, 'start') === null
    && (int) $pdo->query($flagSql)->fetchColumn() === 1);
$insFix->execute([$devG, $vehG, $cLat, $cLng, 1, date('Y-m-d H:i:s')]);
check('geofence: fresh inside fix raises nothing',
    GpsIngest::raiseGeofenceFlag($pdo, $tid8, $vehG, 'complete') === null
    && (int) $pdo->query($flagSql)->fetchColumn() === 1);

/* Monitoring schema (migration applied) + KPI SQL executes */
check('monitor: chat_conversations table exists', (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_conversations'")->fetchColumn());
$flagEnum = (string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_flags' AND COLUMN_NAME = 'flag_type'"
)->fetchColumn();
check('monitor: geofence flag present, route deviation removed',
    str_contains($flagEnum, 'GEOFENCE_DEVIATION') && !str_contains($flagEnum, 'ROUTE_DEVIATION')
    && str_contains($flagEnum, 'IDLE_DETECTED'));
check('monitor: monitoring settings seeded', Settings::getInt('ROUTE_DEVIATION_METERS', 0) === 750
    && Settings::getInt('IDLE_STILL_KPH', 0) === 5 && Settings::getInt('IDLE_STILL_MINUTES', 0) === 15
    && (string) Settings::get('GEOFENCE_GRACE_MIN', '') === '');
check('kpi: approval-time SQL executes', is_numeric($pdo->query(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, submitted_at, approved_at)), 0) / 60
     FROM trip_tickets WHERE is_active = 1 AND submitted_at IS NOT NULL AND approved_at IS NOT NULL')->fetchColumn()));
check('kpi: completion-rate SQL executes', is_numeric($pdo->query(
    'SELECT 100 * SUM(status_id = ' . TripState::COMPLETED . ')
            / NULLIF(SUM(status_id IN (' . implode(',', [TripState::APPROVED, TripState::DISPATCHED, TripState::ON_GOING, TripState::COMPLETED]) . ')), 0)
     FROM trip_tickets WHERE is_active = 1')->fetchColumn()));

$pdo->rollBack();
check('phase 8 transaction rolled back cleanly',
    (int) $pdo->query("SELECT COUNT(*) FROM trip_tickets WHERE id_number LIKE 'T-PH8%'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM gps_devices WHERE device_uid LIKE 'GPS-PH8%'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM vehicle WHERE plate_number LIKE 'PH8-%'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM trip_flags WHERE ticket_id IN ({$tid8}, {$tidIdle})")->fetchColumn() === 0);

echo "\n== Phase 9 acceptance ==\n";

/* ── TripState: reschedule workflow (APPROVED→RESCHEDULED→PENDING) ───── */
check('resched: GSD may reschedule APPROVED with reason',
      TripState::can($mkTicket(TripState::APPROVED), TripState::RESCHEDULED, $ctxStaff + ['has_reason' => true]) === true);
check('resched: GSD reschedule without reason BLOCKED',
      TripState::can($mkTicket(TripState::APPROVED), TripState::RESCHEDULED, $ctxStaff + ['has_reason' => false]) !== true);
check('resched: SAO may NOT reschedule',
      TripState::can($mkTicket(TripState::APPROVED), TripState::RESCHEDULED, $ctxHr + ['has_reason' => true]) !== true);
check('resched: ON_GOING→RESCHEDULED no longer allowed',
      TripState::can($mkTicket(TripState::ON_GOING), TripState::RESCHEDULED, $ctxStaff + ['has_reason' => true]) !== true);
check('resched: RESCHEDULED→DISPATCHED removed',
      TripState::can($mkTicket(TripState::RESCHEDULED), TripState::DISPATCHED, $ctxStaff) !== true);
check('resched: owner may RESCHEDULED→PENDING (schedule-only re-check)',
      TripState::can($mkTicket(TripState::RESCHEDULED), TripState::PENDING, $ctxOwner) === true);
check('resched: only owner may RESCHEDULED→PENDING',
      TripState::can($mkTicket(TripState::RESCHEDULED), TripState::PENDING, $ctxStaff) !== true);

/* ── requestComplete: departure must be in the future ────────────────── */
$past = ['status_id' => TripState::DRAFT, 'passenger_name' => 'A', 'id_number' => 'X',
         'place_to_visit' => 'p', 'purpose' => 'q', 'attachment_path' => 'u',
         'number_of_passengers' => 1,
         'scheduled_departure' => date('Y-m-d H:i:s', time() - 3600),
         'scheduled_return'    => date('Y-m-d H:i:s', time() + 3600)];
$future = array_replace($past, [
    'scheduled_departure' => date('Y-m-d H:i:s', time() + 3600),
    'scheduled_return'    => date('Y-m-d H:i:s', time() + 7200),
]);
check('requestComplete: past departure BLOCKED',
      TripState::requestComplete($past) === false);
check('requestComplete: future departure accepted',
      TripState::requestComplete($future) === true);

/* ── TripGuard::autoRejectPastDue (rolled-back tx) ───────────────────── */
$pdo->beginTransaction();
// neutralize pre-existing overdue rows (rolled back) so the fixture count is deterministic
$pdo->exec("UPDATE trip_tickets SET scheduled_departure = DATE_ADD(NOW(), INTERVAL 60 DAY)
            WHERE is_active = 1 AND status_id IN (" . TripState::PENDING . ', ' . TripState::APPROVED . ")
              AND scheduled_departure IS NOT NULL AND scheduled_departure < NOW()");
$ph9 = static fn(int $status, string $dep) => $pdo->prepare(
    'INSERT INTO trip_tickets (status_id, created_by, scheduled_departure, scheduled_return,
       place_to_visit, purpose, id_number, passenger_name, updated_by)
     VALUES (?,?,?,DATE_ADD(?, INTERVAL 6 HOUR),?,?,?,?,1)'
)->execute([$status, 1, $dep, $dep, 'PH9 place', 'PH9 purpose', 'PH9-' . $status . '-' . substr(md5(uniqid()), 0, 6), 'PH9 TICKET']);
$ph9(TripState::PENDING, date('Y-m-d H:i:s', time() - 3600));
$idPastPending = (int) $pdo->lastInsertId();
$ph9(TripState::APPROVED, date('Y-m-d H:i:s', time() - 7200));
$idPastApproved = (int) $pdo->lastInsertId();
$ph9(TripState::PENDING, date('Y-m-d H:i:s', time() + 3600));
$idFuturePending = (int) $pdo->lastInsertId();
$ph9(TripState::DISPATCHED, date('Y-m-d H:i:s', time() - 1800));
$idPastDispatched = (int) $pdo->lastInsertId();

$n = TripGuard::autoRejectPastDue($pdo);
check('tripguard: rejected exactly the 2 past-due PENDING/APPROVED', $n === 2);
check('tripguard: past PENDING now REJECTED with system reason',
      (string) $pdo->query("SELECT status_id FROM trip_tickets WHERE ticket_id = {$idPastPending}")->fetchColumn() === (string) TripState::REJECTED
      && (string) $pdo->query("SELECT reason FROM trip_tickets WHERE ticket_id = {$idPastPending}")->fetchColumn() === TripGuard::AUTO_REJECT_REASON);
check('tripguard: past APPROVED now REJECTED',
      (string) $pdo->query("SELECT status_id FROM trip_tickets WHERE ticket_id = {$idPastApproved}")->fetchColumn() === (string) TripState::REJECTED);
check('tripguard: future PENDING untouched',
      (string) $pdo->query("SELECT status_id FROM trip_tickets WHERE ticket_id = {$idFuturePending}")->fetchColumn() === (string) TripState::PENDING);
check('tripguard: past DISPATCHED untouched (only PENDING/APPROVED)',
      (string) $pdo->query("SELECT status_id FROM trip_tickets WHERE ticket_id = {$idPastDispatched}")->fetchColumn() === (string) TripState::DISPATCHED);
check('tripguard: AUTO_REJECT audit rows written', (int) $pdo->query(
    "SELECT COUNT(*) FROM audit_trail WHERE event_type = 'AUTO_REJECT' AND object_id IN ('trip_tickets:{$idPastPending}', 'trip_tickets:{$idPastApproved}')")->fetchColumn() === 2);
check('tripguard: idempotent — second run rejects nothing', TripGuard::autoRejectPastDue($pdo) === 0);
$pdo->rollBack();
check('phase 9 transaction rolled back cleanly', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_tickets WHERE id_number LIKE 'PH9-%'")->fetchColumn() === 0);

echo "\n== Phase 10 acceptance (traffic-aware routing) ==\n";
check('routing: trip_routes table exists', (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_routes'")->fetchColumn());
check('routing: ROUTE_REFRESH_MIN setting seeded', Settings::getInt('ROUTE_REFRESH_MIN', 0) === 15);
check('routing: ROUTE_MIN_MOVE_M setting seeded', Settings::getInt('ROUTE_MIN_MOVE_M', 0) === 1500);

/* ── Polyline decoder ── */
$enc = '_p~iF~ps|U_ulLnnqC_mqNvxq`@'; // known 3-point sample
$dec = RouteService::decodePolyline($enc);
check('routing: polyline decodes to coordinates',
      count($dec) === 3 && abs($dec[0][0] - 38.5) < 0.1 && abs($dec[0][1] - -120.2) < 0.1);

/* ── computeLeg with a fake HTTP transport (no network) ── */
$pdo->beginTransaction();
$pdo->prepare(
    'INSERT INTO trip_tickets (status_id, created_by, place_to_visit_lat, place_to_visit_lon,
       scheduled_departure, scheduled_return, place_to_visit, purpose, id_number, passenger_name, updated_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,1)'
)->execute([TripState::DISPATCHED, 1, 14.62, 121.05, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 6 HOUR)', 'PH10 dest', 'PH10 purpose', 'PH10-RT', 'PH10 TICKET']);
$t10 = (int) $pdo->lastInsertId();

$fakeHttp = static function (array $orig, array $to) {
    return [
        'distance_m'          => 12345,
        'duration_sec'        => 2000,
        'static_duration_sec' => 1800,
        'polyline'            => '_p~iF~ps|U_ulLnnqC',
    ];
};
$summary = RouteService::computeLeg($pdo, $t10, RouteService::LEG_OUTBOUND, null, $fakeHttp);
check('routing: outbound leg stored', $summary !== null && $summary['leg'] === 'outbound');
check('routing: delay = duration - static', $summary['traffic_delay_sec'] === 200);
$snap = RouteService::snapshot($pdo, $t10, RouteService::LEG_OUTBOUND);
check('routing: snapshot persists distance/durations',
      (int) $snap['distance_m'] === 12345 && (int) $snap['duration_sec'] === 2000 && (int) $snap['static_duration_sec'] === 1800);
check('routing: polyline persisted', $snap['polyline'] === '_p~iF~ps|U_ulLnnqC');

check('routing: first fetch is not marked cached', empty($summary['cached']));

/* A fresh snapshot for the same origin→dest is reused with no HTTP call. */
$httpCalls = 0;
$countHttp = static function (array $orig, array $to) use (&$httpCalls, $fakeHttp) {
    $httpCalls++;
    return $fakeHttp($orig, $to);
};
$again = RouteService::computeLeg($pdo, $t10, RouteService::LEG_OUTBOUND, null, $countHttp);
check('routing: fresh snapshot served from cache (no API call)',
      $again !== null && $again['cached'] === true && $httpCalls === 0);

/* A stale snapshot recomputes and upserts rather than duplicates. */
$pdo->prepare("UPDATE trip_routes SET fetched_at = NOW() - INTERVAL 1 HOUR WHERE ticket_id = ? AND leg = 'outbound'")
    ->execute([$t10]);
RouteService::computeLeg($pdo, $t10, RouteService::LEG_OUTBOUND, null, $countHttp);
check('routing: stale snapshot triggers a fresh API call', $httpCalls === 1);
check('routing: upsert keeps one row per leg', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_routes WHERE ticket_id = {$t10} AND leg = 'outbound'")->fetchColumn() === 1);

/* activeLeg selection */
check('routing: activeLeg outbound before arrival',
      RouteService::activeLeg(['dest_arrival_at' => null]) === 'outbound');
check('routing: activeLeg return after arrival',
      RouteService::activeLeg(['dest_arrival_at' => '2026-09-08 12:00:00']) === 'return');
$pdo->rollBack();
check('phase 10 transaction rolled back cleanly', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_tickets WHERE id_number LIKE 'PH10-%'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM trip_routes WHERE ticket_id = {$t10}")->fetchColumn() === 0);

echo "\n== Phase 11 acceptance (phase-6 features) ==\n";
$pdo->beginTransaction();

/* dtt_number is permanent: nothing after submit may rewrite it. */
$fxVeh11 = $mkFxVehicle($pdo);
$pdo->prepare("INSERT INTO trip_tickets
    (dtt_number, status_id, scheduled_departure, scheduled_return, vehicle_id, driver_id, created_by, updated_by,
     passenger_name, id_number, place_to_visit, place_to_visit_lat, place_to_visit_lon, purpose)
    VALUES (?, ?, '2026-09-20 08:00:00', '2026-09-20 17:00:00', ?, ?, ?, ?, 'Pax', 'PH11-ID', 'PH11 dest', 14.62, 121.05, 'PH11 purpose')")
    ->execute(['NAAP-MN-' . date('Y') . '-7777', TripState::APPROVED, $fxVeh11, $fxDriver, $fxUser, $fxUser]);
$ph11 = (int) $pdo->lastInsertId();
$origNum  = (string) $pdo->query("SELECT dtt_number FROM trip_tickets WHERE ticket_id = {$ph11}")->fetchColumn();

/* assign → DISPATCHED (as api/trips 'assign' does — never touches the number) */
$pdo->prepare("UPDATE trip_tickets SET vehicle_id = ?, driver_id = ?, status_id = ?, assigned_by = ?, assigned_at = NOW(), updated_by = ?
               WHERE ticket_id = ?")->execute([$fxVeh11, $fxDriver, TripState::DISPATCHED, $fxUser, $fxUser, $ph11]);
/* reschedule → RESCHEDULED (clears assignment, keeps number) */
$pdo->prepare("UPDATE trip_tickets SET status_id = ?, reason = ?, vehicle_id = NULL, driver_id = NULL, updated_by = ?
               WHERE ticket_id = ?")->execute([TripState::RESCHEDULED, 'ph11 test', $fxUser, $ph11]);
/* resubmit_rescheduled → PENDING with new window */
$pdo->prepare("UPDATE trip_tickets SET scheduled_departure = ?, scheduled_return = ?, status_id = ?, is_late = ?, updated_by = ?
               WHERE ticket_id = ?")->execute(['2026-09-21 08:00:00', '2026-09-21 17:00:00', TripState::PENDING, 1, $fxUser, $ph11]);
$afterNum = (string) $pdo->query("SELECT dtt_number FROM trip_tickets WHERE ticket_id = {$ph11}")->fetchColumn();
check('ticket-number: dtt_number never changes across dispatch/reschedule/resubmit', $origNum === $afterNum && $origNum !== '');
check('ticket-number: ticket_id stable across lifecycle', (string) $pdo->query(
    "SELECT ticket_id FROM trip_tickets WHERE dtt_number = '{$origNum}'")->fetchColumn() === (string) $ph11);

/* LATE tag: near departure (within 3 days) vs far-future */
$pdo->prepare("INSERT INTO trip_tickets (status_id, is_late, scheduled_departure, created_by, id_number)
               VALUES (?, 1, ?, ?, 'PH11-LATE')")
    ->execute([TripState::PENDING, date('Y-m-d H:i:s', time() + 86400), $fxUser]);
$lateId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO trip_tickets (status_id, is_late, scheduled_departure, created_by, id_number)
               VALUES (?, 0, ?, ?, 'PH11-OK')")
    ->execute([TripState::PENDING, date('Y-m-d H:i:s', time() + 10 * 86400), $fxUser]);
check('late: is_late column exists and holds the submit-time tag',
      (int) $pdo->query("SELECT is_late FROM trip_tickets WHERE ticket_id = {$lateId}")->fetchColumn() === 1);

/* Offline fallback: computeLeg with failing transport stores a straight-line estimate */
$failHttp = static fn(array $orig, array $to) => null;
$off = RouteService::computeLeg($pdo, $ph11, RouteService::LEG_OUTBOUND, null, $failHttp, true);
check('route: offline fallback stores a leg when Google fails', $off !== null && $off['route_source'] === 'offline' && (int) $off['distance_m'] > 0);
$offSnap = RouteService::snapshot($pdo, $ph11, RouteService::LEG_OUTBOUND);
check('route: offline snapshot flagged + polyline present',
      RouteService::isOffline($offSnap) && !empty($offSnap['polyline']) && (int) $offSnap['distance_m'] > 0);

/* Encode/decode round-trip */
$enc = RouteService::encodePolyline([[14.5126, 121.0182], [14.62, 121.05]]);
$dec = RouteService::decodePolyline($enc);
check('route: polyline encode→decode round-trip', count($dec) === 2
    && abs($dec[0][0] - 14.5126) < 0.0001 && abs($dec[1][1] - 121.05) < 0.0001);

$pdo->rollBack();
check('phase 11 transaction rolled back cleanly', (int) $pdo->query(
    "SELECT COUNT(*) FROM trip_tickets WHERE id_number LIKE 'PH11-%'")->fetchColumn() === 0);

/* ── Phase 12 — strict driver sequence support ──────────────────────── */
echo "\n== Phase 12 acceptance ==\n";
check('autoload: Geo resolves without explicit require', class_exists('Geo'));

$stopTicket = ['status_id' => TripState::ON_GOING, 'driver_id' => 42];
check('stop: assigned driver with reason allowed',
      TripState::canStop($stopTicket, ['role_id' => 4, 'user_id' => 42, 'has_reason' => true]) === true);
check('stop: wrong driver blocked',
      TripState::canStop($stopTicket, ['role_id' => 4, 'user_id' => 43, 'has_reason' => true]) !== true);
check('stop: missing reason blocked',
      TripState::canStop($stopTicket, ['role_id' => 4, 'user_id' => 42, 'has_reason' => false]) !== true);
check('stop: non-driver role blocked',
      TripState::canStop($stopTicket, ['role_id' => 3, 'user_id' => 42, 'has_reason' => true]) !== true);
check('stop: non-ongoing blocked',
      TripState::canStop(['status_id' => TripState::DISPATCHED, 'driver_id' => 42],
                         ['role_id' => 4, 'user_id' => 42, 'has_reason' => true]) !== true);

$rows12 = [
    ['latitude' => 1, 'longitude' => 1, 'recorded_at' => '2026-09-12 08:00:00'],
    ['latitude' => 2, 'longitude' => 2, 'recorded_at' => '2026-09-12 10:00:00'],
    ['latitude' => 3, 'longitude' => 3, 'recorded_at' => '2026-09-12 14:00:00'],
];
[$o12, $r12] = RoadsService::splitLegs($rows12, [2, 2], '2026-09-12 12:00:00');
check('track: split by arrival timestamp', count($o12) === 2 && count($r12) === 1);
[$o12b, $r12b] = RoadsService::splitLegs($rows12, [3, 3], null);
check('track: split by nearest destination when no arrival', count($o12b) === 3 && count($r12b) === 1);
check('track: snap failure returns null (raw fallback)',
      RoadsService::snapPoints([[1, 1], [2, 2]], static fn(array $c) => null) === null);

$pdo->beginTransaction();
$pdo->prepare("INSERT INTO trip_tickets (status_id, created_by, place_to_visit_lat, place_to_visit_lon,
    dest_arrival_at, scheduled_departure, scheduled_return, place_to_visit, purpose, id_number, passenger_name, updated_by)
  VALUES (?,?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL 6 HOUR),?,?,?,?,1)")
  ->execute([TripState::ON_GOING, 1, 14.52, 121.02, '2026-09-12 12:00:00', 'PH12 dest', 'test', 'PH12', 'PH12 T']);
$t12 = (int) $pdo->lastInsertId();
$fxVeh12 = $mkFxVehicle($pdo);
$pdo->prepare("INSERT INTO gps_devices (device_uid, api_token_hash, vehicle_id) VALUES (?, ?, ?)")
    ->execute(['FKFIX-DEV-' . strtoupper(bin2hex(random_bytes(3))), str_repeat('a', 64), $fxVeh12]);
$fxDev12 = (int) $pdo->lastInsertId();
$insC = $pdo->prepare('INSERT INTO gps_positions (device_id, vehicle_id, ticket_id, latitude, longitude, recorded_at) VALUES (?,?,?,?,?,?)');
$insC->execute([$fxDev12, $fxVeh12, $t12, 14.50, 121.00, '2026-09-12 08:00:00']);
$insC->execute([$fxDev12, $fxVeh12, $t12, 14.51, 121.01, '2026-09-12 10:00:00']);
$insC->execute([$fxDev12, $fxVeh12, $t12, 14.53, 121.03, '2026-09-12 14:00:00']);
$insC->execute([$fxDev12, $fxVeh12, $t12, 14.54, 121.04, '2026-09-12 16:00:00']);
$fakeSnap = static fn(array $chunk) => [[14.5, 121.0], [14.51, 121.01], [14.53, 121.03], [14.54, 121.04]];
$res12 = RoadsService::storeRecordedTrack($pdo, $t12, $fakeSnap);
$snapOut = $pdo->query("SELECT source FROM trip_tracks WHERE ticket_id={$t12} AND leg='outbound'")->fetchColumn();
$snapRet = $pdo->query("SELECT source FROM trip_tracks WHERE ticket_id={$t12} AND leg='return'")->fetchColumn();
check('track: snapped outbound stored', ($res12['outbound']['source'] ?? '') === 'snapped' && $snapOut === 'snapped');
check('track: snapped return stored', ($res12['return']['source'] ?? '') === 'snapped' && $snapRet === 'snapped');
$pdo->rollBack();
check('phase 12 transaction rolled back cleanly',
      (int) $pdo->query("SELECT COUNT(*) FROM trip_tracks WHERE ticket_id={$t12}")->fetchColumn() === 0
      && (int) $pdo->query("SELECT COUNT(*) FROM trip_tickets WHERE id_number='PH12'")->fetchColumn() === 0);

/* ── Phase 13 — foreign key constraints (rolled-back tx) ─────────────── */
echo "\n== Phase 13 acceptance (foreign keys) ==\n";
$fkCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetchColumn();
check('fk: 41 constraints present', $fkCount === 41);

$pdo->beginTransaction();

// enforcement: nonexistent parent is rejected
$fkBlocked = false;
try {
    $pdo->prepare("INSERT INTO trip_tickets (status_id, created_by, passenger_name, id_number, place_to_visit, purpose)
                   VALUES (?, 99999999, 'FK BAD', 'FK-B', 'base', 'test')")->execute([TripState::DRAFT]);
} catch (PDOException $e) {
    $fkBlocked = $e->getCode() === '23000';
}
check('fk: nonexistent created_by rejected by DB', $fkBlocked);

// cascade: ticket delete removes manifest rows
$fxv = $mkFxVehicle($pdo);
$pdo->prepare("INSERT INTO trip_tickets (status_id, vehicle_id, driver_id, created_by, updated_by, passenger_name, id_number, place_to_visit, purpose)
               VALUES (?, ?, ?, ?, ?, 'FK CASCADE', 'FK-C', 'base', 'test')")
    ->execute([TripState::DRAFT, $fxv, $fxDriver, $fxUser, $fxUser]);
$fkt = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO trip_passengers (ticket_id, passenger_name) VALUES (?, ?)')->execute([$fkt, 'FK Pax']);
$pdo->prepare('DELETE FROM trip_tickets WHERE ticket_id = ?')->execute([$fkt]);
check('fk: ticket delete cascades to trip_passengers',
    (int) $pdo->query("SELECT COUNT(*) FROM trip_passengers WHERE ticket_id = {$fkt}")->fetchColumn() === 0);

// ON UPDATE CASCADE: cleanup_rescheduled.php ticket_id renumber path
$pdo->prepare("INSERT INTO trip_tickets (status_id, created_by, updated_by, passenger_name, id_number, place_to_visit, purpose)
               VALUES (?, ?, ?, 'FK RENUM', 'FK-R', 'base', 'test')")->execute([TripState::DRAFT, $fxUser, $fxUser]);
$fkr = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO trip_passengers (ticket_id, passenger_name) VALUES (?, ?)')->execute([$fkr, 'FK Renum Pax']);
$newId = $fkr + 1000000;
$pdo->prepare('UPDATE trip_tickets SET ticket_id = ? WHERE ticket_id = ?')->execute([$newId, $fkr]);
check('fk: ticket_id renumber cascades to children (ON UPDATE CASCADE)',
    (int) $pdo->query("SELECT COUNT(*) FROM trip_passengers WHERE ticket_id = {$newId}")->fetchColumn() === 1);

// RESTRICT: referenced identity rows cannot be deleted
$restricted = false;
try {
    $pdo->prepare('DELETE FROM request_status WHERE status_id = ?')->execute([TripState::DRAFT]);
} catch (PDOException $e) {
    $restricted = $e->getCode() === '23000';
}
check('fk: RESTRICT blocks deleting a referenced request_status', $restricted);

$pdo->rollBack();

// orphan sweep across FK-protected ticket children
$childTables = ['trip_passengers', 'trip_routes', 'trip_tracks', 'guard_verifications', 'trip_flags',
                'feedback', 'notifications', 'gps_positions', 'geofence_events'];
$orphans = 0;
foreach ($childTables as $tbl) {
    $orphans += (int) $pdo->query("SELECT COUNT(*) FROM `{$tbl}` c
        LEFT JOIN trip_tickets p ON p.ticket_id = c.ticket_id
        WHERE c.ticket_id IS NOT NULL AND p.ticket_id IS NULL")->fetchColumn();
}
check('fk: zero orphan ticket_id references across child tables', $orphans === 0);

/* ── Hidden GPS tracker accounts (role 7 + is_system + device token) ── */
echo "\n== Hidden tracker accounts ==\n";
$colExists = static function (PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
};
check('tracker: role 7 GPS TRACKER seeded',
    (string) $pdo->query('SELECT role_name FROM roles WHERE role_id = 7')->fetchColumn() === 'GPS TRACKER');
check('tracker: schema has account.is_system + gps_devices.account_id',
    $colExists($pdo, 'account', 'is_system') && $colExists($pdo, 'gps_devices', 'account_id'));
check('tracker: one-device-per-account unique key present',
    (bool) $pdo->query("SHOW INDEX FROM gps_devices WHERE Key_name = 'uk_gps_device_account'")->fetch());

$pdo->beginTransaction();
$trkEmail = 'gps-app-test-' . bin2hex(random_bytes(3)) . '@naap.local';
$pdo->prepare("INSERT INTO account (role_id, is_system, email, password, full_name, email_verified_at)
               VALUES (7, 1, ?, ?, 'GPS TRACKER - TEST', NOW())")
    ->execute([$trkEmail, password_hash('Tracker@12345', PASSWORD_BCRYPT, ['cost' => 12])]);
$trkAid = (int) $pdo->lastInsertId();
// Tracker accounts log in like any account (they are just hidden from Accounts & Roles).
[$okTrk] = Auth::attempt($pdo, $trkEmail, 'Tracker@12345');
check('tracker: account can log in with its password', $okTrk === true);
check('tracker: login lands on the tracker page', (Auth::LANDING[7] ?? '') === BASE_URL . 'tracker.php');

$pdo->prepare("INSERT INTO gps_devices (device_uid, account_id, api_token_hash) VALUES ('GPS-APP-TEST', ?, ?)")
    ->execute([$trkAid, str_repeat('a', 64)]);
$trkDid = (int) $pdo->lastInsertId();
try {
    $pdo->prepare("INSERT INTO gps_devices (device_uid, account_id, api_token_hash) VALUES ('GPS-APP-TEST2', ?, ?)")
        ->execute([$trkAid, str_repeat('b', 64)]);
    check('tracker: second device on the same tracker account rejected', false);
} catch (PDOException $e) {
    check('tracker: second device on the same tracker account rejected', $e->getCode() === '23000');
}

/* Browser-sharing ingest (session path) shares the hardware pipeline. */
$pdo->prepare("INSERT INTO vehicle (plate_number, model_name, type_id, capacity)
               SELECT 'NAA-TRK1', 'Tracker Test', MAX(type_id), 2 FROM vehicle_types")->execute();
$trkVid = (int) $pdo->lastInsertId();
check('tracker: account ingest rejected while unpaired',
    GpsIngest::handleAccount($pdo, $trkAid, ['lat' => 14.60, 'lng' => 121.10])['code'] === 400);
$pdo->prepare('UPDATE gps_devices SET is_active = 0 WHERE device_id = ?')->execute([$trkDid]);
check('tracker: account ingest rejected while device disabled',
    GpsIngest::handleAccount($pdo, $trkAid, ['lat' => 14.60, 'lng' => 121.10])['code'] === 409);
$pdo->prepare('UPDATE gps_devices SET is_active = 1, vehicle_id = ? WHERE device_id = ?')->execute([$trkVid, $trkDid]);

$r1 = GpsIngest::handleAccount($pdo, $trkAid, ['lat' => 14.60, 'lng' => 121.10, 'recorded_at' => '2026-08-26 07:00:00']);
check('tracker: browser fix stored through the hardware pipeline', $r1['code'] === 204 && $r1['stored'] === true);
$row = $pdo->query("SELECT ticket_id FROM gps_positions WHERE device_id = {$trkDid} ORDER BY position_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('tracker: stored fix carries no trip when the vehicle is idle', $row && $row['ticket_id'] === null);

GpsIngest::handleAccount($pdo, $trkAid, ['lat' => 14.601, 'lng' => 121.101]);
$rTok = GpsIngest::handleAccount($pdo, $trkAid, ['lat' => 14.602, 'lng' => 121.102]);
check('tracker: rapid second live fix throttled', $rTok['code'] === 204 && $rTok['throttled'] === true);
$hb = $pdo->query("SELECT last_latitude FROM gps_devices WHERE device_id = {$trkDid}")->fetchColumn();
check('tracker: heartbeat kept even when throttled', abs((float) $hb - 14.602) < 0.0001);

$pdo->rollBack();
check('tracker: fixtures rolled back cleanly',
    (int) $pdo->query("SELECT COUNT(*) FROM account WHERE account_id = {$trkAid}")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM gps_devices WHERE device_uid IN ('GPS-APP-TEST','GPS-APP-TEST2')")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM vehicle WHERE plate_number = 'NAA-TRK1'")->fetchColumn() === 0);

/* ── MIS super admin + passenger manifest ───────────────────────────── */
check('passenger_display_name: composes LAST, FIRST M.',
    passenger_display_name('juan', 'm', 'dela cruz') === 'DELA CRUZ, JUAN M.');
check('passenger_display_name: single initial gets a period',
    passenger_display_name('ana', 'b', 'santos') === 'SANTOS, ANA B.');
check('passenger_display_name: no M.I. leaves a clean name',
    passenger_display_name('pedro', '', 'reyes') === 'REYES, PEDRO');
check('passenger_display_name: empty parts produce empty string',
    passenger_display_name('', '', '') === '');

$colCheck = static function (PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
};
check('schema: account.is_super_admin exists', $colCheck($pdo, 'account', 'is_super_admin'));
check('schema: middle_name columns (full middle name)',
    $colCheck($pdo, 'account', 'middle_name')
    && $colCheck($pdo, 'trip_drafts', 'middle_name')
    && $colCheck($pdo, 'trip_tickets', 'middle_name')
    && $colCheck($pdo, 'trip_draft_passengers', 'middle_name')
    && $colCheck($pdo, 'trip_passengers', 'middle_name'));
check('password policy: valid password passes',
    PasswordPolicy::check('Admin@123') === null);
check('password policy: missing number or letter class rejected',
    PasswordPolicy::check('Admin@abc') !== null && PasswordPolicy::check('admin@123') !== null);
check('schema: trip_draft_passengers structured names',
    $colCheck($pdo, 'trip_draft_passengers', 'surname')
    && $colCheck($pdo, 'trip_draft_passengers', 'first_name')
    && $colCheck($pdo, 'trip_draft_passengers', 'middle_initial'));
check('schema: trip_passengers structured names',
    $colCheck($pdo, 'trip_passengers', 'surname')
    && $colCheck($pdo, 'trip_passengers', 'first_name')
    && $colCheck($pdo, 'trip_passengers', 'middle_initial'));
check('super admin: exactly one flagged account',
    (int) $pdo->query('SELECT COUNT(*) FROM account WHERE is_super_admin = 1')->fetchColumn() === 1);

/* The submit flow snapshots draft passengers BEFORE claiming (deleting) the
 * draft row: fk_draft_pass cascades, so a later read would find nothing. Pin
 * the cascade so the submit order stays safe. */
$pdo->prepare('INSERT INTO trip_drafts (passenger_name, id_number, place_to_visit, purpose, created_by, updated_by)
               VALUES (?,?,?,?,?,?)')
    ->execute(['MANIFEST FIXTURE', 'FX', 'x', 'y', $fxUser, $fxUser]);
$fxDraft = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO trip_draft_passengers (draft_id, passenger_name, surname, first_name) VALUES (?,?,?,?)')
    ->execute([$fxDraft, 'FIXTURE, PAX', 'FIXTURE', 'PAX']);
$pdo->prepare('DELETE FROM trip_drafts WHERE draft_id = ?')->execute([$fxDraft]);
check('fk: draft delete cascades to trip_draft_passengers (submit reads manifest first)',
    (int) $pdo->query("SELECT COUNT(*) FROM trip_draft_passengers WHERE draft_id = {$fxDraft}")->fetchColumn() === 0);

/* ── Phase 14 — GSD Assistant task assistance (rule-based) ───────────── */
echo "\n== Phase 14 acceptance (assistant) ==\n";
check('assistant: class autoloads', class_exists('ChatbotAssistant'));

/* Path normalization + page maps (client path includes BASE_URL). */
check('assistant: page path strips the app base',
    ChatbotAssistant::normalizePath(BASE_URL . 'personnel/requests.php') === 'personnel/requests.php');
check('assistant: relative path passes through',
    ChatbotAssistant::normalizePath('personnel/requests.php') === 'personnel/requests.php');
check('assistant: query string is ignored',
    ChatbotAssistant::normalizePath(BASE_URL . 'ticket.php?id=7') === 'ticket.php');
check('assistant: unknown page yields no topics or actions',
    ChatbotAssistant::pageTopics('mis/settings.php') === []
    && ChatbotAssistant::pageActions('mis/settings.php') === []);
check('assistant: requests page yields topics + the new-request button',
    count(ChatbotAssistant::pageTopics('personnel/requests.php')) > 0
    && (ChatbotAssistant::pageActions('personnel/requests.php')[0]['href'] ?? '') === 'personnel/request_form.php');
check('assistant: ticket page points back to My Requests',
    (ChatbotAssistant::pageActions('ticket.php')[0]['href'] ?? '') === 'personnel/requests.php');

/* FAQ actions / follow-up chips. */
check('assistant: create-request FAQ carries the form link',
    (ChatbotAssistant::actionsFor('create trip request')[0]['href'] ?? '') === 'personnel/request_form.php');
check('assistant: status guide suggests the live status lookup',
    in_array('my request status', array_column(ChatbotAssistant::suggestionsFor('status meaning'), 'value'), true));

/* Intent routing: new phrases hit the intent, legacy phrases keep the FAQ. */
$routeFaqs = array_merge([
    ['prompt_keyword' => 'status meaning', 'keywords' => 'what does pending mean|ticket status', 'response_text' => 'x'],
    ['prompt_keyword' => 'create trip request', 'keywords' => 'book a vehicle', 'response_text' => 'x'],
], ChatbotAssistant::intentFaqs());
check('assistant: "status" routes to the status-lookup intent',
    (ChatbotMatcher::best($routeFaqs, 'status')['faq']['__intent'] ?? null) === 'status_lookup');
check('assistant: "my request status" routes to the status-lookup intent',
    (ChatbotMatcher::best($routeFaqs, 'my request status')['faq']['__intent'] ?? null) === 'status_lookup');
check('assistant: "trip details" routes to the trip-details intent',
    (ChatbotMatcher::best($routeFaqs, 'trip details')['faq']['__intent'] ?? null) === 'trip_details');
check('assistant: legacy "status meaning" still returns the FAQ',
    ChatbotMatcher::best($routeFaqs, 'status meaning')['faq']['prompt_keyword'] === 'status meaning');
check('assistant: legacy "book a vehicle" still returns the FAQ',
    ChatbotMatcher::best($routeFaqs, 'book a vehicle')['faq']['prompt_keyword'] === 'create trip request');

/* Status-based guidance mirrors the transition matrix (TripState::can). */
$gOwner   = 100;
$gTicket  = ['status_id' => TripState::DISPATCHED, 'created_by' => $gOwner, 'driver_id' => 42, 'vehicle_id' => 7];
check('assistant: DISPATCHED guidance offers the owner cancel',
    str_contains(ChatbotAssistant::guidance(TripState::DISPATCHED, $gTicket, $gOwner), 'cancel'));
check('assistant: ON_GOING guidance never offers cancel',
    !str_contains(ChatbotAssistant::guidance(TripState::ON_GOING, $gTicket + ['status_id' => TripState::ON_GOING], $gOwner), 'cancel'));
check('assistant: a non-owner never gets the cancel hint',
    !str_contains(ChatbotAssistant::guidance(TripState::PENDING, ['status_id' => TripState::PENDING, 'created_by' => $gOwner], 999), 'cancel'));
check('assistant: RESCHEDULED guidance points to Edit Schedule',
    str_contains(ChatbotAssistant::guidance(TripState::RESCHEDULED, ['status_id' => TripState::RESCHEDULED, 'created_by' => $gOwner], $gOwner), 'Edit Schedule'));
check('assistant: DRAFT guidance says it is not submitted',
    str_contains(ChatbotAssistant::guidance(TripState::DRAFT, ['status_id' => TripState::DRAFT, 'created_by' => $gOwner], $gOwner), 'Not submitted'));

/* Grouped topic browser (display only — chip values stay canonical). */
$uiRows = array_merge([
    ['prompt_keyword' => 'create trip request', 'keywords' => 'book a vehicle', 'response_text' => 'x'],
    ['prompt_keyword' => 'status meaning', 'keywords' => 'ticket status', 'response_text' => 'x'],
    ['prompt_keyword' => 'cancel request', 'keywords' => '', 'response_text' => 'x'],
    ['prompt_keyword' => 'admin added faq', 'keywords' => 'brand new', 'response_text' => 'x'],
], ChatbotAssistant::intentFaqs());
$uiGroups = ChatbotAssistant::topicGroups($uiRows);
check('assistant: first group is Requests with topics',
    ($uiGroups[0]['title'] ?? '') === 'Requests' && count($uiGroups[0]['topics'] ?? []) >= 1);
check('assistant: chips show friendly labels while values stay canonical',
    in_array('New trip request', array_column($uiGroups[0]['topics'], 'label'), true)
    && in_array('create trip request', array_column($uiGroups[0]['topics'], 'value'), true));
check('assistant: topic filter text includes synonyms', (static function () use ($uiGroups): bool {
    foreach ($uiGroups[0]['topics'] as $t) {
        if ($t['value'] === 'create trip request') {
            return in_array('book a vehicle', $t['search'], true);
        }
    }
    return false;
})());
$uiOther = $uiGroups[count($uiGroups) - 1];
check('assistant: unmapped keywords land in Other topics with their raw text',
    ($uiOther['title'] ?? '') === 'Other topics'
    && in_array('admin added faq', array_column($uiOther['topics'], 'value'), true)
    && in_array('admin added faq', array_column($uiOther['topics'], 'label'), true));
check('assistant: every topic appears in exactly one group', (static function () use ($uiRows, $uiGroups): bool {
    $seen = [];
    foreach ($uiGroups as $g) {
        foreach ($g['topics'] as $t) {
            $seen[$t['value']] = ($seen[$t['value']] ?? 0) + 1;
        }
    }
    foreach ($uiRows as $r) {
        if (($seen[$r['prompt_keyword']] ?? 0) !== 1) {
            return false;
        }
    }
    return true;
})());
check('assistant: Requests group carries the request-form shortcut',
    ($uiGroups[0]['actions'][0]['href'] ?? '') === 'personnel/request_form.php');
check('assistant: page group is labelled, and null on unknown pages',
    (ChatbotAssistant::pageGroup(BASE_URL . 'personnel/requests.php', $uiRows)['topics'][0]['label'] ?? '') !== ''
    && ChatbotAssistant::pageGroup('mis/settings.php', $uiRows) === null);

/* Guided request wizard: steps, field mapping, completeness + routing. */
$steps = ChatbotAssistant::wizardSteps();
check('wizard: seven steps in order',
    count($steps) === 7 && $steps[0]['id'] === 'who' && $steps[6]['id'] === 'review');

$accepted = ['passenger_name', 'surname', 'middle_initial', 'middle_name', 'suffix', 'id_number',
    'requester_type', 'email', 'place_to_visit', 'place_to_visit_name', 'place_to_visit_lat',
    'place_to_visit_lon', 'place_to_visit_place_id', 'purpose', 'number_of_passengers',
    'scheduled_departure', 'scheduled_return', 'passengers'];
$wizardFields = [];
$wizardFiles  = [];
foreach ($steps as $s) {
    foreach ($s['fields'] as $f) {
        if ($f['type'] === 'file') {
            $wizardFiles[] = $f['name'];
        } else {
            $wizardFields[] = $f['name'];
        }
    }
}
check('wizard: every collected field maps to a save_draft input', array_diff($wizardFields, $accepted) === []);
check('wizard: the only file field is the letter upload', $wizardFiles === ['request_letter']);

/* Wizard input affordances: placeholders, capitalisation, deferrable letter. */
$stepsNow = ChatbotAssistant::wizardSteps();
$missingPlaceholder = [];
$capped = [];
foreach ($stepsNow as $s) {
    foreach ($s['fields'] as $f) {
        if ($f['type'] === 'file' || $f['type'] === 'select') {
            continue; // uploads carry their own drop-zone hint; dropdowns need none
        }
        if (empty($f['placeholder'])) {
            $missingPlaceholder[] = $f['name'];
        }
        if (!empty($f['cap'])) {
            $capped[] = $f['name'];
        }
    }
}
check('wizard: every field carries a placeholder hint', $missingPlaceholder === []);
check('wizard: names and the ID number auto-capitalise',
    $capped === ['passenger_name', 'surname', 'suffix', 'middle_initial', 'middle_name', 'id_number']);
check('wizard: every step can be skipped (no optional flag remains)',
    !array_filter($stepsNow, static fn(array $s): bool => !empty($s['optional'])));
check('wizard: skipping stays safe because the review knows every required field',
    (static function () use ($stepsNow): bool {
        $required = [];
        foreach ($stepsNow as $s) {
            foreach ($s['fields'] as $f) {
                if (!empty($f['required'])) {
                    $required[] = $f['name'];
                }
            }
        }
        foreach (['passenger_name', 'id_number', 'place_to_visit', 'purpose',
                  'scheduled_departure', 'scheduled_return', 'request_letter'] as $need) {
            if (!in_array($need, $required, true)) {
                return false;
            }
        }
        return true;
    })());
$wizardJs = (string) file_get_contents(__DIR__ . '/../assets/js/pages/chatbot.js');
check('wizard: offers both exits by name',
    str_contains($wizardJs, 'Finish step later') && str_contains($wizardJs, 'Finish later'));
check('form: the request form capitalises the ID number too',
    str_contains((string) file_get_contents(__DIR__ . '/../personnel/request_form.php'),
        'ID Number *</label><input id="f_id_number"')
    && str_contains((string) file_get_contents(__DIR__ . '/../personnel/request_form.php'),
        'input-cap" placeholder="Employee ID"')
    && str_contains((string) file_get_contents(__DIR__ . '/../assets/js/pages/request_form.js'), "'suffix', 'id_number'"));
check('panel: loads the places autocomplete for the destination step',
    str_contains((string) file_get_contents(__DIR__ . '/../partials/chatbox.php'), 'location-autocomplete.js'));
check('panel: enlarged to 440px wide',
    str_contains((string) file_get_contents(__DIR__ . '/../assets/css/app.css'), 'min(440px'));

/* ── Upload progress wiring ──────────────────────────────────────────── */
check('upload: API.formProgress exists for XHR upload events',
    str_contains((string) file_get_contents(__DIR__ . '/../assets/js/api.js'), 'formProgress'));
check('upload: request form uses formProgress and renders the bar',
    str_contains((string) file_get_contents(__DIR__ . '/../assets/js/pages/request_form.js'), 'API.formProgress')
    && str_contains((string) file_get_contents(__DIR__ . '/../personnel/request_form.php'), 'id="uploadProgress"')
    && str_contains((string) file_get_contents(__DIR__ . '/../personnel/request_form.php'), 'id="uploadBar"'));
check('upload: dropzone advertises the PHP-aligned cap',
    str_contains((string) file_get_contents(__DIR__ . '/../personnel/request_form.php'), 'data-max-bytes='));

/* ── Draft rows pinned above the sorted ticket list ──────────────────── */
check('requests: draft rows are pinned',
    str_contains((string) file_get_contents(__DIR__ . '/../personnel/requests.php'), "'pin' => 'draft'"));
check('history: draft rows are pinned',
    str_contains((string) file_get_contents(__DIR__ . '/../personnel/history.php'), "'pin' => 'draft'"));
check('table: renders pinned rows with data-pin',
    str_contains((string) file_get_contents(__DIR__ . '/../partials/ui/table.php'), 'data-pin='));
check('table_sort: pinned rows sort first',
    str_contains((string) file_get_contents(__DIR__ . '/../assets/js/pages/table_sort.js'), "hasAttribute('data-pin')"));
check('wizard: destination step carries the geocoding origin',
    isset($steps[2]['origin']['lat']) && array_key_exists('lng', $steps[2]['origin']));
check('wizard: manifest step declares its cap and add-self helper',
    !empty($steps[4]['manifest']) && !empty($steps[4]['add_self']) && (int) $steps[4]['max'] === 999);
check('wizard: schedule step uses native datetime inputs',
    $steps[3]['fields'][0]['type'] === 'datetime-local' && $steps[3]['fields'][1]['type'] === 'datetime-local');
check('wizard: letter step accepts only the API-allowed files',
    $steps[5]['fields'][0]['accept'] === '.pdf,.png,.jpg,.jpeg,.doc,.docx');

$wizardTicket = [
    'passenger_name' => 'CARLO', 'surname' => 'LIM', 'id_number' => 'EMP-9',
    'place_to_visit' => 'NAIA T3', 'purpose' => 'Official travel', 'number_of_passengers' => 1,
    'scheduled_departure' => date('Y-m-d H:i:s', time() + 86400),
    'scheduled_return' => date('Y-m-d H:i:s', time() + 90000),
    'attachment_path' => 'uploads/request_letters/RL_x.pdf',
];
check('wizard: a filled wizard payload passes TripState::requestComplete',
    TripState::requestComplete($wizardTicket) === true);
check('wizard: the same payload without the letter is rejected',
    TripState::requestComplete(['attachment_path' => ''] + $wizardTicket) === false);

$wizFaqs = array_merge([
    ['prompt_keyword' => 'create trip request', 'keywords' => 'how to request a vehicle|new request|book a vehicle'
        . '|file a trip|submit trip ticket|submit a request|how to submit', 'response_text' => 'x'],
], ChatbotAssistant::intentFaqs());
check('wizard: "help me create a trip ticket" routes to the wizard',
    (ChatbotMatcher::best($wizFaqs, 'help me create a trip ticket')['faq']['__intent'] ?? null) === 'guided_request');
check('wizard: "create trip ticket" routes to the wizard',
    (ChatbotMatcher::best($wizFaqs, 'create trip ticket')['faq']['__intent'] ?? null) === 'guided_request');
check('wizard: the FAQ keeps its own keyword',
    ChatbotMatcher::best($wizFaqs, 'create trip request')['faq']['prompt_keyword'] === 'create trip request'
    && empty(ChatbotMatcher::best($wizFaqs, 'create trip request')['faq']['__intent']));
check('wizard: the FAQ keeps its legacy synonym',
    ChatbotMatcher::best($wizFaqs, 'submit trip ticket')['faq']['prompt_keyword'] === 'create trip request');
check('wizard: the guide offers the wizard as a follow-up',
    in_array('guided request', array_column(ChatbotAssistant::suggestionsFor('create trip request'), 'value'), true));
check('wizard: run() returns kind=wizard with the steps', (static function () use ($pdo): bool {
    $r = ChatbotAssistant::run('guided_request', $pdo, ['id' => 99999999], 'help me create a trip ticket');
    return ($r['kind'] ?? '') === 'wizard' && count($r['steps'] ?? []) === 7;
})());
check('wizard: listed in the Requests group of the topic browser',
    in_array('guided request', array_column(ChatbotAssistant::topicGroups(array_merge(
        [['prompt_keyword' => 'create trip request', 'keywords' => '', 'response_text' => 'x']],
        ChatbotAssistant::intentFaqs()
    ))[0]['topics'], 'value'), true));

/* Routing regression: the phrasings people actually type must reach the wizard.
   Runs against the live FAQ rows (same input the API builds) so a greedy
   synonym added later cannot silently hijack the wizard again. */
$liveFaqs = array_merge(
    (array) $pdo->query('SELECT prompt_keyword, response_text, keywords FROM chatbot WHERE is_active = 1 AND is_displayed = 1')->fetchAll(),
    ChatbotAssistant::intentFaqs()
);
$phrasings = [
    'help me create a trip ticket', 'help me request a trip ticket', 'help me request a trip',
    'request a trip ticket', 'request a trip', 'trip ticket request', 'new trip ticket',
    'help me fill my request', 'fill my request', 'fill out a trip ticket',
    'help me make a trip ticket', 'make a trip ticket', 'book a trip ticket',
    'file a trip ticket', 'start a trip ticket', 'i need a trip ticket',
    'i want to request a trip ticket', 'walk me through it', 'guide me',
];
$missed = [];
foreach ($phrasings as $phrase) {
    $hit = ChatbotMatcher::best($liveFaqs, $phrase);
    if (($hit['faq']['__intent'] ?? '') !== 'guided_request') {
        $missed[] = $phrase . ' => ' . ($hit['faq']['prompt_keyword'] ?? 'no match');
    }
}
check('wizard: every natural request phrasing reaches the wizard', $missed === []);
if ($missed !== []) {
    echo '    missed: ' . implode(' | ', $missed) . "\n";
}
check('wizard: the create-request guide still owns its keyword',
    ChatbotMatcher::best($liveFaqs, 'create trip request')['faq']['prompt_keyword'] === 'create trip request');
check('wizard: the create-request guide keeps its legacy synonym',
    ChatbotMatcher::best($liveFaqs, 'submit trip ticket')['faq']['prompt_keyword'] === 'create trip request');
check('wizard: the contact card no longer hijacks help questions',
    (ChatbotMatcher::best($liveFaqs, 'help me cancel')['faq']['prompt_keyword'] ?? '') !== 'contact gsd');
check('wizard: the contact card still answers its own synonyms',
    ChatbotMatcher::best($liveFaqs, 'need help')['faq']['prompt_keyword'] === 'contact gsd');
check('wizard: first chip on the requests page is the wizard',
    (ChatbotAssistant::pageTopics('personnel/requests.php')[0] ?? '') === 'guided request');
check('wizard: offered on the request form as an alternative',
    in_array('guided request', ChatbotAssistant::pageTopics('personnel/request_form.php'), true));

/* Rich answer payloads (cards / warning items / live kind). */
$cardRow = [
    'ticket_id' => 12, 'dtt_number' => 'NAAP-MN-2026-0099', 'status_id' => TripState::CANCELLED,
    'status_name' => 'CANCELLED', 'place_to_visit' => 'Terminal 3',
    'scheduled_departure' => '2026-10-18 08:00:00', 'scheduled_return' => '2026-10-18 17:00:00',
    'reason' => 'test', 'created_by' => 100,
];
$card = ChatbotAssistant::statusCard($cardRow, 100);
check('assistant: status card carries badge key, schedule text and deep link',
    $card['status_key'] === 'cancelled'
    && $card['href'] === 'ticket.php?id=12'
    && $card['schedule'] === 'Sun, Oct 18, 8:00 AM → Sun, Oct 18, 5:00 PM'
    && $card['guidance'] !== '');
check('assistant: every status has a matching .badge-* class in app.css',
    (static function (): bool {
        $css = (string) file_get_contents(__DIR__ . '/../assets/css/app.css');
        foreach ([TripState::DRAFT, TripState::PENDING, TripState::APPROVED, TripState::DISPATCHED,
                  TripState::ON_GOING, TripState::COMPLETED, TripState::CANCELLED, TripState::REJECTED,
                  TripState::RESCHEDULED] as $id) {
            if (!str_contains($css, '.badge-' . strtolower(str_replace('_', '-', TripState::name($id))))) {
                return false;
            }
        }
        return true;
    })());
check('assistant: detail card adds the assignment pair',
    ChatbotAssistant::detailCard(
        $cardRow + ['plate_number' => 'NAA-8001', 'brand' => 'Toyota', 'model_name' => 'Hiace'], 100
    )['vehicle'] === 'NAA-8001 (Toyota Hiace)');
check('assistant: live intents report kind + label for the UI',
    (static function () use ($pdo): bool {
        $r = ChatbotAssistant::run('status_lookup', $pdo, ['id' => 99999999], 'status');
        return ($r['kind'] ?? '') === 'live' && ($r['label'] ?? '') === 'Your trips' && is_array($r['cards'] ?? null);
    })());

$warnItems = ChatbotMatcher::formWarningItems(['passenger_name' => 'A', 'id_number' => ''], 'required fields');
check('assistant: form warnings carry the field they belong to',
    ($warnItems[0]['field'] ?? '') === 'id_number' && ($warnItems[0]['message'] ?? '') === 'Missing: ID number.');
check('assistant: warning strings stay in sync with the structured items',
    ChatbotMatcher::formWarnings(['passenger_name' => 'A', 'id_number' => ''], 'required fields')
        === array_column($warnItems, 'message'));
check('assistant: the schedule conflict points at the return field',
    (ChatbotMatcher::formWarningItems(
        ['scheduled_departure' => '2026-01-02 12:00', 'scheduled_return' => '2026-01-02 10:00'],
        'schedule rules'
    )[0]['field'] ?? '') === 'scheduled_return');
check('assistant: escalation carries its own My Requests button',
    (ChatbotAssistant::escalationActions()[0]['href'] ?? '') === 'personnel/requests.php');

/* Escalation text reuses the admin-editable contact GSD FAQ. */
$esc = ChatbotAssistant::escalation([['prompt_keyword' => 'contact gsd', 'response_text' => 'For help, contact the GSD office.', 'keywords' => '']]);
check('assistant: escalation message + contact GSD detail',
    str_contains($esc['message'], 'Please contact the GSD') && $esc['detail'] === 'For help, contact the GSD office.');
check('assistant: escalation degrades to the sentence alone when the FAQ is hidden',
    ChatbotAssistant::escalation([['prompt_keyword' => 'other', 'response_text' => 'x', 'keywords' => '']])['detail'] === '');

/* 3-consecutive-miss streak is read from the chat log (scoped per account). */
$pdo->beginTransaction();
$insConv = $pdo->prepare('INSERT INTO chat_conversations (account_id, question, keyword, created_at) VALUES (?,?,?,?)');
$insConv->execute([$fxUser, 'ph14 reset', 'create trip request', date('Y-m-d H:i:s')]);
check('assistant: a matched answer resets the miss streak',
    ChatbotAssistant::consecutiveMisses($pdo, $fxUser) === 0);
$insConv->execute([$fxUser, 'ph14 old miss', null, date('Y-m-d H:i:s', time() - 2 * 86400)]);
$insConv->execute([$fxUser, 'ph14 new miss', null, date('Y-m-d H:i:s', time() - 3600)]);
check('assistant: misses older than the window are not chained',
    ChatbotAssistant::consecutiveMisses($pdo, $fxUser) === 1);
$insConv->execute([$fxUser, 'ph14 miss 2', null, date('Y-m-d H:i:s')]);
check('assistant: two misses reach the escalation pre-threshold (streak+1 >= 3)',
    ChatbotAssistant::consecutiveMisses($pdo, $fxUser) + 1 >= ChatbotAssistant::ESCALATE_AFTER);
$insConv->execute([$fxUser, 'ph14 miss 3', null, date('Y-m-d H:i:s')]);
check('assistant: third consecutive miss keeps the streak sticky',
    ChatbotAssistant::consecutiveMisses($pdo, $fxUser) >= ChatbotAssistant::ESCALATE_AFTER);
check('assistant: another account is unaffected by this streak',
    ChatbotAssistant::consecutiveMisses($pdo, $fxUser + 1000000) === 0);
$pdo->rollBack();
check('phase 14 transaction rolled back cleanly',
    (int) $pdo->query("SELECT COUNT(*) FROM chat_conversations WHERE question LIKE 'ph14 %'")->fetchColumn() === 0);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
