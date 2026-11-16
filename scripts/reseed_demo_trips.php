<?php
declare(strict_types=1);
/**
 * scripts/reseed_demo_trips.php — WIPE all existing trip tickets (+ dependents)
 * and seed 20 demo tickets across 6 destinations in varied non-ON_GOING stages.
 * DEV ONLY. Destructive: deletes every trip_tickets row.
 */
require_once __DIR__ . '/../config/config.php';

function accId(PDO $pdo, string $email): int {
    $st = $pdo->prepare('SELECT account_id FROM account WHERE email = ? AND is_active = 1');
    $st->execute([$email]);
    $id = (int) $st->fetchColumn();
    if (!$id) { throw new RuntimeException("Missing account: $email"); }
    return $id;
}

$DEST = [
    // [label, name, lat, lon]
    'picc'     => ['Philippine International Convention Center', 'PICC, CCP Complex, Pasay City', 14.5552, 120.9873],
    'pup'      => ['Polytechnic University of the Philippines', 'PUP Sta. Mesa, Manila', 14.5966, 120.9924],
    'naap-pamp'=> ['National Aviation Academy of the Philippines - Fernando Airbase Pampanga', 'Fernando Air Base, Floridablanca, Pampanga', 15.0360, 120.6940],
    'naia3'    => ['NAIA Terminal 3', 'Newport Blvd, Pasay City', 14.5176, 121.0112],
    'naia1'    => ['NAIA Terminal 1', 'Andrews Ave, Pasay City', 14.5145, 121.0187],
    'clark'    => ['Clark International Airport', 'Clark Freeport Zone, Mabalacat, Pampanga', 15.1857, 120.5603],
];

// status_id => [weight-distribution for 20 tickets]
$STATUS = [
    2 => 'PENDING',
    4 => 'APPROVED',
    5 => 'DISPATCHED',
    7 => 'COMPLETED',
    8 => 'CANCELLED',
    9 => 'REJECTED',
    10 => 'RESCHEDULED',
];

$pdo->beginTransaction();
try {
    // ---- WIPE existing trip tickets + dependents ----
    $ticketIds = $pdo->query('SELECT ticket_id FROM trip_tickets')->fetchAll(PDO::FETCH_COLUMN);
    if ($ticketIds) {
        $in = implode(',', array_fill(0, count($ticketIds), '?'));
        $del = fn(string $table, string $col) => $pdo->prepare("DELETE FROM $table WHERE $col IN ($in)")->execute($ticketIds);
        $del('trip_passengers', 'ticket_id');
        $del('trip_flags', 'ticket_id');
        $del('guard_verifications', 'ticket_id');
        $del('feedback', 'ticket_id');
        $del('gps_positions', 'ticket_id');
        $del('geofence_events', 'ticket_id');
        // audit_trail references trip_tickets:... — clean those too
        $pdo->exec("DELETE FROM audit_trail WHERE object_id LIKE 'trip_tickets:%'");
    }
    $pdo->exec('DELETE FROM trip_tickets');
    // ALTER TABLE is DDL (implicit commit) — run outside the transaction below.

    // ---- Resolve fixed actors/vehicles ----
    $personnel = accId($pdo, 'personnel@naap.gov.ph');
    $sao       = accId($pdo, 'director@naap.gov.ph');
    $dispatcher= accId($pdo, 'staff@naap.gov.ph');
    $drivers   = [accId($pdo, 'driver1@naap.gov.ph'), accId($pdo, 'driver2@naap.gov.ph')];
    $vehicles  = [1, 2, 3, 4];

    // ---- 20 demo tickets ----
    // statuses: 4 PENDING, 3 APPROVED, 3 DISPATCHED, 4 COMPLETED, 2 CANCELLED, 2 REJECTED, 2 RESCHEDULED
    $plan = [
        ['st' => 2, 'dest' => 'naia3',  'dep' => '+2 day 08:00', 'ret' => '+2 day 16:00'],
        ['st' => 2, 'dest' => 'clark',  'dep' => '+3 day 05:00', 'ret' => '+3 day 20:00'],
        ['st' => 2, 'dest' => 'pup',    'dep' => '+1 day 09:00', 'ret' => '+1 day 15:00'],
        ['st' => 2, 'dest' => 'naap-pamp', 'dep' => '+4 day 06:00', 'ret' => '+4 day 18:00'],
        ['st' => 4, 'dest' => 'picc',   'dep' => '+1 day 10:00', 'ret' => '+1 day 17:00'],
        ['st' => 4, 'dest' => 'naia1',  'dep' => '+2 day 07:00', 'ret' => '+2 day 12:00'],
        ['st' => 4, 'dest' => 'naia3',  'dep' => '+2 day 13:00', 'ret' => '+2 day 20:00'],
        ['st' => 5, 'dest' => 'clark',  'dep' => '+1 day 04:30', 'ret' => '+1 day 19:30'],
        ['st' => 5, 'dest' => 'pup',    'dep' => '+0 day 14:00', 'ret' => '+0 day 18:00'],
        ['st' => 5, 'dest' => 'naap-pamp', 'dep' => '+1 day 05:30', 'ret' => '+1 day 17:30'],
        ['st' => 7, 'dest' => 'picc',   'dep' => '-30 day 08:30', 'ret' => '-30 day 16:30'],
        ['st' => 7, 'dest' => 'naia1',  'dep' => '-21 day 06:00', 'ret' => '-21 day 11:00'],
        ['st' => 7, 'dest' => 'naia3',  'dep' => '-14 day 10:00', 'ret' => '-14 day 18:00'],
        ['st' => 7, 'dest' => 'clark',  'dep' => '-7 day 05:00',  'ret' => '-7 day 19:00'],
        ['st' => 8, 'dest' => 'pup',    'dep' => '-3 day 09:00',  'ret' => '-3 day 15:00'],
        ['st' => 8, 'dest' => 'picc',   'dep' => '-2 day 10:00',  'ret' => '-2 day 16:00'],
        ['st' => 9, 'dest' => 'naia3',  'dep' => '-4 day 08:00',  'ret' => '-4 day 14:00'],
        ['st' => 9, 'dest' => 'naap-pamp', 'dep' => '-5 day 06:00', 'ret' => '-5 day 18:00'],
        ['st' => 10,'dest' => 'clark',  'dep' => '+2 day 06:00',  'ret' => '+2 day 20:00'],
        ['st' => 10,'dest' => 'naia1',  'dep' => '+1 day 07:30',  'ret' => '+1 day 13:00'],
    ];

    $firstNames = ['JUAN','MARIA','JOSE','ANNA','PEDRO','LIZA','CARLO','ROSA','MIGUEL','GRACE','ANDRE','KATRINA','PAOLO','ISABEL','RAFAEL','SOPHIA','DANTE','EMMA','LUIS','NINA'];
    $surnames   = ['DELA CRUZ','SANTOS','RAMIREZ','TORRES','MENDOZA','VILLANUEVA','AGUILAR','NAVARRO','FLORES','REYES','ESPINOSA','GOMEZ','FERNANDEZ','LOPEZ','GARCIA','MARTINEZ','RODRIGUEZ','HERNANDEZ','DIAZ','CRUZ'];

    $ins = $pdo->prepare(
        "INSERT INTO trip_tickets (
            dtt_number, passenger_name, surname, middle_initial, id_number, requester_type,
            place_to_visit, place_to_visit_name, place_to_visit_lat, place_to_visit_lon,
            purpose, number_of_passengers, email,
            scheduled_departure, scheduled_return, status_id,
            approver_id, approved_at, vehicle_id, driver_id, assigned_by, assigned_at,
            actual_departure_at, return_at, odometer_start, odometer_end, distance_odometer,
            reason, submitted_at, created_by, updated_by
        ) VALUES (
            ?, ?, ?, ?, ?, 'Personnel', ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )"
    );

    $year = (int) date('Y');
    $seq  = 1;
    foreach ($plan as $i => $row) {
        $st   = $row['st'];
        $d    = $DEST[$row['dest']];
        $dep  = date('Y-m-d H:i:s', strtotime($row['dep']));
        $ret  = date('Y-m-d H:i:s', strtotime($row['ret']));

        $dtt = sprintf('NAAP-MN-%d-%04d', $year, $seq++);
        $passenger = $firstNames[$i % count($firstNames)] . ' ' . $surnames[$i % count($surnames)];
        $purpose   = 'Official ' . $d[0] . ' business travel';

        $approver  = null; $approvedAt = null; $vehicle = null; $driver = null; $assignedBy = null; $assignedAt = null;
        $actualDep = null; $returnAt = null; $odoStart = null; $odoEnd = null; $odoDist = null; $reason = null;

        if ($st == 4 || $st == 5 || $st == 7 || $st == 10) {
            $approver = $sao;
            $approvedAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        }
        if ($st == 5 || $st == 7 || $st == 10) {
            $vehicle = $vehicles[$i % 4];
            $driver  = $drivers[$i % 2];
            $assignedBy = $dispatcher;
            $assignedAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        }
        if ($st == 7) {
            $actualDep = $dep;
            $returnAt  = $ret;
            $odoStart  = 50000.0 + $i * 100.5;
            $odoEnd    = $odoStart + 40.0 + $i;
            $odoDist   = round($odoEnd - $odoStart, 1);
        }
        if ($st == 8)  { $reason = 'Cancelled by requester — schedule conflict.'; }
        if ($st == 9)  { $reason = 'Rejected by SAO — incomplete travel details.'; }

        $ins->execute([
            $dtt, $passenger, 'N/A', 'A.', 'DEMO-' . $year . '-' . str_pad((string)($i+1), 3, '0', STR_PAD_LEFT),
            $d[1], $d[0], $d[2], $d[3],
            $purpose, rand(2, 8), 'personnel@naap.gov.ph',
            $dep, $ret, $st,
            $approver, $approvedAt, $vehicle, $driver, $assignedBy, $assignedAt,
            $actualDep, $returnAt, $odoStart, $odoEnd, $odoDist,
            $reason, $dep, $personnel, $personnel
        ]);
    }

    $pdo->commit();

    // DDL (implicit commit) must run after the transaction.
    $pdo->exec('ALTER TABLE trip_tickets AUTO_INCREMENT = 1');

    echo "Reseed OK: " . count($plan) . " tickets seeded (NAAP-MN-{$year}-0001..)\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "Reseed FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
