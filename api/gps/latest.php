<?php
declare(strict_types=1);
/**
 * api/gps/latest.php — session-authenticated snapshot for TrackingClient polling.
 * GET → { success, vehicles: [{ device_uid, plate_number, model_name, lat, lng,
 *          speed_kph, heading, age_sec, link_age_sec, freshness, trip }] }
 *
 * age_sec = POSITION age (gps_devices.last_recorded_at) — freshness is judged
 * from the fix itself, never the heartbeat: a deadzone replay keeps the device
 * link alive while the live cache stays frozen at the last truly current fix.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::requireApi($pdo, [1, 2, 3]);

$freshSec   = Settings::getInt('GPS_FRESH_SEC', 45);
$delayedSec = Settings::getInt('GPS_DELAYED_SEC', 120);

$devices = $pdo->query(
    'SELECT g.device_id, g.device_uid, g.last_seen_at, g.last_recorded_at,
            g.last_latitude AS lat, g.last_longitude AS lng,
            g.last_speed_kph AS speed_kph, g.last_heading AS heading,
            v.vehicle_id, v.plate_number, v.model_name
     FROM gps_devices g
     JOIN vehicle v ON v.vehicle_id = g.vehicle_id
     WHERE g.is_active = 1 AND v.is_active = 1
     ORDER BY v.plate_number'
)->fetchAll();

$freshness = static function (int $age) use ($freshSec, $delayedSec): string {
    if ($age < $freshSec)   return 'CURRENT';
    if ($age <= $delayedSec) return 'DELAYED';
    return 'OFFLINE';
};

// One query for the active trip of every fresh vehicle (avoids per-row lookups).
$tripByVehicle = [];
$vids = array_column($devices, 'vehicle_id');
if ($vids) {
    $ph = implode(',', array_fill(0, count($vids), '?'));
    $tripStmt = $pdo->prepare(
        "SELECT t.vehicle_id, t.ticket_id, t.dtt_number, s.status_name
         FROM trip_tickets t JOIN request_status s ON s.status_id = t.status_id
         WHERE t.vehicle_id IN ($ph) AND t.status_id = ?
         ORDER BY t.actual_departure_at DESC, t.ticket_id DESC"
    );
    $tripStmt->execute(array_merge($vids, [TripState::ON_GOING]));
    foreach ($tripStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($tripByVehicle[$r['vehicle_id']])) {
            $tripByVehicle[$r['vehicle_id']] = $r;
        }
    }
}

$vehicles = [];
foreach ($devices as $d) {
    // POSITION age drives freshness; LINK age (heartbeat) is reported alongside
    // so the UI can say "device seen 3 s ago · last position 47 min ago".
    $age = $d['last_recorded_at']
        ? max(0, time() - strtotime((string) $d['last_recorded_at']))
        : PHP_INT_MAX;
    $linkAge = $d['last_seen_at']
        ? max(0, time() - strtotime((string) $d['last_seen_at']))
        : PHP_INT_MAX;

    $trip = null;
    if ($age <= $delayedSec && isset($tripByVehicle[$d['vehicle_id']])) {
        $row = $tripByVehicle[$d['vehicle_id']];
        $trip = ['ticket_id' => (int) $row['ticket_id'], 'dtt' => $row['dtt_number'], 'status' => $row['status_name']];
    }

    $vehicles[] = [
        'device_uid'   => $d['device_uid'],
        'plate_number' => $d['plate_number'],
        'model_name'   => $d['model_name'],
        'lat'          => $d['lat'] !== null ? (float) $d['lat'] : null,
        'lng'          => $d['lng'] !== null ? (float) $d['lng'] : null,
        'speed_kph'    => $d['speed_kph'] !== null ? round((float) $d['speed_kph'], 1) : null,
        'heading'      => $d['heading'] !== null ? (float) $d['heading'] : null,
        'age_sec'      => $age === PHP_INT_MAX ? null : $age,
        'link_age_sec' => $linkAge === PHP_INT_MAX ? null : $linkAge,
        'freshness'    => $freshness($age),
        'trip'         => $trip,
    ];
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'vehicles' => $vehicles], JSON_PARTIAL_OUTPUT_ON_ERROR);
