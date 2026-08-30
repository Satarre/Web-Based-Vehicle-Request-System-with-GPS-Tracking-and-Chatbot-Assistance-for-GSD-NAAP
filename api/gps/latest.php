<?php
declare(strict_types=1);
/**
 * api/gps/latest.php — session-authenticated snapshot for TrackingClient polling.
 * GET → { success, vehicles: [{ device_uid, plate_number, model_name, lat, lng,
 *          speed_kph, heading, age_sec, freshness, trip }] }
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::requireApi($pdo, [1, 2, 3]);

$freshSec   = Settings::getInt('GPS_FRESH_SEC', 45);
$delayedSec = Settings::getInt('GPS_DELAYED_SEC', 120);

$devices = $pdo->query(
    'SELECT g.device_id, g.device_uid, g.last_seen_at,
            g.last_latitude AS lat, g.last_longitude AS lng,
            g.last_speed_kph AS speed_kph, g.last_heading AS heading,
            v.vehicle_id, v.plate_number, v.model_name
     FROM gps_devices g
     JOIN vehicle v ON v.vehicle_id = g.vehicle_id
     WHERE g.is_active = 1 AND v.is_active = 1
     ORDER BY v.plate_number'
)->fetchAll();

$tripStmt = $pdo->prepare(
    'SELECT t.ticket_id, t.dtt_number, s.status_name
     FROM trip_tickets t JOIN request_status s ON s.status_id = t.status_id
     WHERE t.vehicle_id = ? AND t.status_id IN (6,7,8)
     ORDER BY t.actual_departure_at DESC, t.ticket_id DESC LIMIT 1'
);

$freshness = static function (int $age) use ($freshSec, $delayedSec): string {
    if ($age < $freshSec)   return 'CURRENT';
    if ($age <= $delayedSec) return 'DELAYED';
    return 'OFFLINE';
};

$vehicles = [];
foreach ($devices as $d) {
    $age = $d['last_seen_at'] ? max(0, time() - strtotime($d['last_seen_at'])) : PHP_INT_MAX;

    $trip = null;
    if ($age <= $delayedSec) {
        $tripStmt->execute([$d['vehicle_id']]);
        $row = $tripStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $trip = ['ticket_id' => (int) $row['ticket_id'], 'dtt' => $row['dtt_number'], 'status' => $row['status_name']];
        }
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
        'freshness'    => $freshness($age),
        'trip'         => $trip,
    ];
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'vehicles' => $vehicles], JSON_PARTIAL_OUTPUT_ON_ERROR);
