<?php
declare(strict_types=1);
/**
 * scripts/seed_places_trips.php — seed 29 trip tickets (Jul–Aug 2026) across the
 * NAAP GSD travel destinations: 24 COMPLETED + 5 CANCELLED. DEV ONLY.
 *
 * Idempotent: deletes only rows whose id_number LIKE 'PLACES-2026-%' (plus their
 * passengers/audit) and re-inserts them. Run:
 *   C:\xampp\php\php.exe scripts\seed_places_trips.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once dirname(__DIR__) . '/config/config.php';

/** Approximate great-circle km between two points. */
function seedKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function one(PDO $pdo, string $sql, array $args): string
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    if ($v === false || $v === null) {
        throw new RuntimeException('Lookup failed: ' . $sql . ' :: ' . implode(',', $args));
    }
    return (string) $v;
}

/* ── Places: [short name, address, lat, lon, cancelled?] ─────────────────── */
$PLACES = [
    ['NAIA Terminal 1', 'Ninoy Aquino International Airport Terminal 1, Andrews Ave, Pasay City', 14.5145, 121.0187, false],
    ['NAIA Terminal 2', 'Ninoy Aquino International Airport Terminal 2, Andrews Ave, Pasay City', 14.5096, 121.0197, false],
    ['NAIA Terminal 3', 'Ninoy Aquino International Airport Terminal 3, Newport Blvd, Pasay City', 14.5176, 121.0112, false],
    ['NAIA Terminal 4', 'Ninoy Aquino International Airport Terminal 4 (Manila Domestic), Domestic Rd, Pasay City', 14.5229, 121.0196, false],
    ['Civil Aviation Authority of the Philippines', 'Civil Aviation Authority of the Philippines, Old Domestic Rd, Pasay City', 14.5126, 121.0176, false],
    ['Civil Aviation Training Center', 'Civil Aviation Training Center, Andrews Ave, Pasay City', 14.5064, 121.0187, false],
    ['Villamor Air Base', 'Villamor Air Base, Pasay City', 14.5095, 121.0195, false],
    ['Philippine Air Force Aerospace Museum', 'Philippine Air Force Aerospace Museum, Villamor Air Base, Pasay City', 14.5118, 121.0166, false],
    ['Air Link International Aviation College', 'Air Link International Aviation College, Col. Jesus Villamor Air Base, Pasay City', 14.5096, 121.0160, false],
    ['Masters Flying School', 'Masters Flying School, Domestic Airport Rd, Pasay City', 14.5085, 121.0097, false],
    ['Leading Edge International Aviation Academy', 'Leading Edge International Aviation Academy, Pasay City', 14.5092, 121.0190, false],
    ['Asian Aerospace Corporation', 'Asian Aerospace Corporation, NAIA Complex, Pasay City', 14.5157, 121.0182, false],
    ['Manila Aerospace', 'Manila Aerospace, NAIA Complex, Pasay City', 14.5160, 121.0170, true],
    ['Boeing International Corporation', 'Boeing International Corporation, NAIA Complex, Pasay City', 14.5085, 121.0195, false],
    ['Lufthansa Technik Philippines', 'Lufthansa Technik Philippines, MacroAsia Economic Zone, Pasay City', 14.5090, 121.0180, false],
    ['MacroAsia Special Economic Zone', 'MacroAsia Special Economic Zone, NAIA Complex, Pasay City', 14.5133, 121.0180, false],
    ['MacroAsia Airport Services Corporation', 'MacroAsia Airport Services Corporation, NAIA Complex, Pasay City', 14.5161, 121.0177, false],
    ['MacroAsia SATS Inflight Services Corporation', 'MacroAsia SATS Inflight Services Corporation, NAIA Complex, Pasay City', 14.5161, 121.0177, false],
    ['MacroAsia Air Taxi Services', 'MacroAsia Air Taxi Services, NAIA Complex, Pasay City', 14.5145, 121.0187, true],
    ['Asian Aerospace Corporation FBO', 'Asian Aerospace Corporation FBO, NAIA Complex, Pasay City', 14.5150, 121.0190, false],
    ['Clark International Airport', 'Clark International Airport, Clark Freeport Zone, Mabalacat, Pampanga', 15.1859, 120.5603, false],
    ['Clark Civil Aviation Complex', 'Clark Civil Aviation Complex, Clark Freeport Zone, Mabalacat, Pampanga', 15.1806, 120.5585, true],
    ['Plaridel Community Airport', 'Plaridel Community Airport, Plaridel, Bulacan', 14.8953, 120.8506, true],
    ['Danilo Atienza Air Base', 'Danilo Atienza Air Base, Sangley Point, Cavite City', 14.4945, 120.9070, true],
    ['Philippine International Convention Center (PICC)', 'Philippine International Convention Center, CCP Complex, Pasay City', 14.5552, 120.9873, false],
    ['UP Diliman', 'University of the Philippines Diliman, Quezon City', 14.6538, 121.0685, false],
    ['Polytechnic University of the Philippines (PUP) Sta. Mesa', 'Polytechnic University of the Philippines, Anonas St, Sta. Mesa, Manila', 14.5966, 120.9924, false],
    ['Eulogio "Amang" Rodriguez Institute of Science and Technology (EARIST)', 'Eulogio Amang Rodriguez Institute of Science and Technology, Nagtahan, Sampaloc, Manila', 14.6008, 120.9940, false],
    ['UP Los Baños', 'University of the Philippines Los Baños, College, Laguna', 14.1650, 121.2410, false],
];

/* ── Resolve IDs once ────────────────────────────────────────────────────── */
$personnel = (int) one($pdo, 'SELECT account_id FROM account WHERE email = ?', ['personnel@naap.gov.ph']);
$director  = (int) one($pdo, 'SELECT account_id FROM account WHERE email = ?', ['director@naap.gov.ph']);
$staff     = (int) one($pdo, 'SELECT account_id FROM account WHERE email = ?', ['staff@naap.gov.ph']);
$driver1   = (int) one($pdo, 'SELECT account_id FROM account WHERE email = ?', ['driver1@naap.gov.ph']);
$driver2   = (int) one($pdo, 'SELECT account_id FROM account WHERE email = ?', ['driver2@naap.gov.ph']);
$drivers   = [$driver1, $driver2];

$vehicles = [];
foreach (['NAA-8001', 'NAA-8002', 'NAA-8003', 'NAA-8004'] as $plate) {
    $vehicles[] = (int) one($pdo, 'SELECT vehicle_id FROM vehicle WHERE plate_number = ?', [$plate]);
}

$stCompleted = (int) one($pdo, 'SELECT status_id FROM request_status WHERE status_name = ?', ['COMPLETED']);
$stCancelled = (int) one($pdo, 'SELECT status_id FROM request_status WHERE status_name = ?', ['CANCELLED']);

$ORIGIN_LAT = 14.5126;
$ORIGIN_LON = 121.0182;

$PURPOSES = [
    'Official travel — meeting and coordination',
    'Official travel — document processing and submission',
    'Official travel — inspection and site visit',
    'Official travel — seminar and training attendance',
    'Official travel — airport operations coordination',
    'Official travel — courtesy call and liaison',
];
$PASSENGERS = [
    ['JUAN', 'DELA CRUZ', 'P.'], ['MARIA', 'SANTOS', 'R.'], ['JOSE', 'RAMIREZ', 'L.'],
    ['ANNA', 'TORRES', 'M.'], ['PEDRO', 'MENDOZA', null], ['LIZA', 'VILLANUEVA', 'S.'],
    ['CARLO', 'AGUILAR', 'B.'], ['ROSA', 'NAVARRO', 'T.'], ['MIGUEL', 'FLORES', 'D.'],
    ['GRACE', 'REYES', 'A.'], ['PAOLO', 'ESPIOSA', 'C.'], ['ISABEL', 'LOPEZ', null],
];

$cols = [
    'dtt_number', 'passenger_name', 'surname', 'middle_initial', 'suffix', 'id_number', 'requester_type',
    'place_to_visit', 'place_to_visit_name', 'place_to_visit_lat', 'place_to_visit_lon',
    'purpose', 'number_of_passengers', 'email',
    'scheduled_departure', 'scheduled_return', 'status_id', 'reason',
    'approver_id', 'approved_at', 'vehicle_id', 'driver_id', 'assigned_by', 'assigned_at',
    'actual_departure_at', 'departure_lat', 'departure_lon',
    'dest_arrival_at', 'dest_arrival_lat', 'dest_arrival_lon',
    'dest_departure_at', 'dest_departure_lat', 'dest_departure_lon',
    'return_at', 'return_lat', 'return_lon',
    'odometer_start', 'odometer_end', 'distance_odometer', 'gps_distance_km', 'fuel_type',
    'fuel_tank_balance_start', 'fuel_issued_stock', 'fuel_purchased_trip', 'fuel_total_available',
    'fuel_used', 'fuel_balance_end', 'gear_oil_used', 'lubricant_oil_issued', 'grease_issued',
    'remarks', 'fuel_cost', 'easytrip_cost', 'autosweep_cost',
    'certified_by', 'certified_at', 'cancelled_by', 'cancelled_at',
    'dispatched_without_gps', 'is_late', 'submitted_at', 'created_by', 'updated_by', 'is_active',
];
$place = '(' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
$ins = $pdo->prepare('INSERT INTO trip_tickets (' . implode(',', $cols) . ') VALUES ' . $place);

$pdo->beginTransaction();
try {
    /* ── Wipe previous seeded trips (+ dependents) ───────────────────────── */
    $ids = $pdo->query("SELECT ticket_id FROM trip_tickets WHERE id_number LIKE 'PLACES-2026-%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM trip_passengers WHERE ticket_id IN ($in)");
        $pdo->exec("DELETE FROM trip_routes WHERE ticket_id IN ($in)");
        $objs = implode(',', array_map(static fn(int $i): string => "'trip_tickets:$i'", array_map('intval', $ids)));
        $pdo->exec("DELETE FROM audit_trail WHERE object_id IN ($objs)");
    }
    $pdo->exec("DELETE FROM trip_tickets WHERE id_number LIKE 'PLACES-2026-%'");

    /* ── Dates: departure every 2 days from 2026-07-02, varying hour ─────── */
    $base = strtotime('2026-07-02 06:00:00');
    $seq  = 0;
    $made = ['completed' => 0, 'cancelled' => 0];
    $completedIds = [];
    $passengerIns = $pdo->prepare('INSERT INTO trip_passengers (ticket_id, passenger_name) VALUES (?, ?)');

    foreach ($PLACES as $i => [$name, $addr, $lat, $lon, $isCancelled]) {
        $seq++;
        $dtt   = sprintf('NAAP-MN-2026-%04d', $seq);
        $idnum = sprintf('PLACES-2026-%03d', $seq);

        $dep = date('Y-m-d H:i:s', $base + $i * 2 * 86400 + ($i % 4) * 3600);
        $ret = date('Y-m-d H:i:s', strtotime($dep) + 8 * 3600);

        $paxRow = $PASSENGERS[$i % count($PASSENGERS)];
        $pax    = $paxRow[0] . ' ' . $paxRow[1];
        $n      = 2 + ($i % 6);
        $purpose = $PURPOSES[$i % count($PURPOSES)] . ' — ' . $name;

        $row = [
            'dtt_number'        => $dtt,
            'passenger_name'    => $paxRow[0],
            'surname'           => $paxRow[1],
            'middle_initial'    => $paxRow[2],
            'suffix'            => null,
            'id_number'         => $idnum,
            'requester_type'    => 'Personnel',
            'place_to_visit'    => $addr,
            'place_to_visit_name' => $name,
            'place_to_visit_lat'  => $lat,
            'place_to_visit_lon'  => $lon,
            'purpose'           => $purpose,
            'number_of_passengers' => $n,
            'email'             => 'personnel@naap.gov.ph',
            'scheduled_departure' => $dep,
            'scheduled_return'    => $ret,
            'submitted_at'      => date('Y-m-d H:i:s', strtotime($dep) - 3 * 86400),
            'created_by'        => $personnel,
            'updated_by'        => $personnel,
            'is_active'         => 1,
            'dispatched_without_gps' => 0,
            'is_late'           => 0,
        ];

        if ($isCancelled) {
            $row['status_id']    = $stCancelled;
            $row['reason']       = ['Cancelled by requester — schedule conflict.',
                                    'Cancelled — vehicle unavailable for the requested schedule.',
                                    'Cancelled — activity postponed by the requesting office.',
                                    'Cancelled by requester — no longer needed.',
                                    'Cancelled — operational/scheduling conflict.'][$made['cancelled'] % 5];
            $row['cancelled_by'] = ($made['cancelled'] % 2 === 0) ? $personnel : $staff;
            $row['cancelled_at'] = date('Y-m-d H:i:s', strtotime($dep) - 86400);
            $row['updated_by']   = $row['cancelled_by'];
            $made['cancelled']++;
        } else {
            $roadKm = round(seedKm($ORIGIN_LAT, $ORIGIN_LON, $lat, $lon) * 1.25, 1);
            $odoStart = 90000.0 + $i * 173.4;
            $odoEnd   = round($odoStart + $roadKm, 1);
            $fuelUsed = round($roadKm * 0.12, 2);
            $tankStart = 40.00;
            $issued    = 20.00;
            $purchased = 0.00;
            $total     = round($tankStart + $issued + $purchased, 2);
            $fuelType  = ($i % 2 === 0) ? 'Diesel' : 'Gasoline';
            $departAt  = date('Y-m-d H:i:s', strtotime($dep) + 120);
            $arriveAt  = date('Y-m-d H:i:s', strtotime($dep) + (1800 + ($i % 20) * 60));
            $leaveAt   = date('Y-m-d H:i:s', strtotime($ret) - 1800);
            $returnAt  = date('Y-m-d H:i:s', strtotime($ret) + 300);

            $row += [
                'status_id'        => $stCompleted,
                'approver_id'      => $director,
                'approved_at'      => date('Y-m-d H:i:s', strtotime($dep) - 2 * 86400),
                'vehicle_id'       => $vehicles[$i % 4],
                'driver_id'        => $drivers[$i % 2],
                'assigned_by'      => $staff,
                'assigned_at'      => date('Y-m-d H:i:s', strtotime($dep) - 86400),
                'actual_departure_at' => $departAt,
                'departure_lat'    => $ORIGIN_LAT,
                'departure_lon'    => $ORIGIN_LON,
                'dest_arrival_at'  => $arriveAt,
                'dest_arrival_lat' => $lat,
                'dest_arrival_lon' => $lon,
                'dest_departure_at' => $leaveAt,
                'dest_departure_lat' => $lat,
                'dest_departure_lon' => $lon,
                'return_at'        => $returnAt,
                'return_lat'       => $ORIGIN_LAT,
                'return_lon'       => $ORIGIN_LON,
                'odometer_start'   => $odoStart,
                'odometer_end'     => $odoEnd,
                'distance_odometer' => round($odoEnd - $odoStart, 1),
                'gps_distance_km'  => round($roadKm - 1.5, 1),
                'fuel_type'        => $fuelType,
                'fuel_tank_balance_start' => $tankStart,
                'fuel_issued_stock' => $issued,
                'fuel_purchased_trip' => $purchased,
                'fuel_total_available' => $total,
                'fuel_used'        => $fuelUsed,
                'fuel_balance_end' => round($total - $fuelUsed, 2),
                'remarks'          => ($i % 3 === 0) ? 'Vehicle in good condition; no incidents.' : null,
                'fuel_cost'        => round($fuelUsed * 65, 2),
                'easytrip_cost'    => in_array($name, ['Clark International Airport', 'NAIA Terminal 1', 'NAIA Terminal 2'], true) ? 120.00 : 0.00,
                'autosweep_cost'   => 0.00,
                'certified_by'     => $staff,
                'certified_at'     => date('Y-m-d H:i:s', strtotime($ret) + 86400),
                'updated_by'       => $staff,
            ];
            $made['completed']++;
        }

        $ins->execute(array_map(static fn(string $c) => $row[$c] ?? null, $cols));
        $newId = (int) $pdo->lastInsertId();
        if (!$isCancelled) {
            $completedIds[] = $newId;
        }

        // Passenger manifests for the first five completed trips.
        if (!$isCancelled && $made['completed'] <= 5) {
            for ($k = 1; $k <= $n; $k++) {
                $p = $PASSENGERS[($i + $k) % count($PASSENGERS)];
                $passengerIns->execute([$newId, $p[0] . ' ' . $p[1]]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$max = (int) $pdo->query('SELECT COALESCE(MAX(ticket_id), 0) FROM trip_tickets')->fetchColumn();
$pdo->exec('ALTER TABLE trip_tickets AUTO_INCREMENT = ' . ($max + 1));

echo 'Seeded ' . $seq . " tickets (" . $made['completed'] . ' completed, ' . $made['cancelled'] . " cancelled)\n";
echo "Departures: 2026-07-02 .. " . date('Y-m-d', $base + (count($PLACES) - 1) * 2 * 86400 + 3 * 3600) . "\n";

/* ── Real road routes (Google Routes API) for the completed trips ───────────
 * Runs after commit so network calls never hold DB locks. offlineFallback=false
 * means only genuine Google routes are stored; a failure leaves that leg to the
 * map's straight-line fallback (re-run the seeder to retry). */
if (GOOGLE_ROUTES_API_KEY === '') {
    echo "Routes skipped: no Google API key configured\n";
} else {
    $routeOk = 0;
    $routeFail = 0;
    foreach ($completedIds as $tid) {
        foreach ([RouteService::LEG_OUTBOUND, RouteService::LEG_RETURN] as $leg) {
            try {
                $r = RouteService::computeLeg($pdo, (int) $tid, $leg, null, null, false);
                if ($r) { $routeOk++; } else { $routeFail++; }
            } catch (Throwable $e) {
                $routeFail++;
            }
        }
    }
    echo 'Routes: ' . $routeOk . ' computed, ' . $routeFail . " failed\n";
}
