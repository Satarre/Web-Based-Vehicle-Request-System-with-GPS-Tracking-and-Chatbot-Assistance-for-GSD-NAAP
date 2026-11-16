<?php
declare(strict_types=1);
/**
 * api/gps/me.php — session-authenticated snapshot of the caller's OWN tracker
 * device (role: GPS TRACKER). Polled by tracker.php.
 * GET → { success, configured, device_uid?, …, freshness }
 * Never exposes other vehicles.
 * Freshness is POSITION age (last_recorded_at) — the link heartbeat alone can
 * look alive during a stale backlog replay. link_age_sec is reported alongside.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

$u = Auth::requireApi($pdo, [7]);

$st = $pdo->prepare(
    'SELECT g.device_uid, g.device_name, g.is_active,
            g.last_seen_at, g.last_recorded_at,
            g.last_latitude AS lat, g.last_longitude AS lng,
            g.last_speed_kph AS speed_kph, g.last_heading AS heading,
            v.plate_number, v.model_name
     FROM gps_devices g LEFT JOIN vehicle v ON v.vehicle_id = g.vehicle_id
     WHERE g.account_id = ?'
);
$st->execute([$u['id']]);
$d = $st->fetch();

if (!$d) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'configured' => false]);
    exit;
}

$freshSec   = Settings::getInt('GPS_FRESH_SEC', 45);
$delayedSec = Settings::getInt('GPS_DELAYED_SEC', 120);
$age = $d['last_recorded_at']
    ? max(0, time() - (int) strtotime((string) $d['last_recorded_at']))
    : null;
$linkAge = $d['last_seen_at']
    ? max(0, time() - (int) strtotime((string) $d['last_seen_at']))
    : null;
$freshness = $age === null
    ? 'OFFLINE'
    : ($age < $freshSec ? 'CURRENT' : ($age <= $delayedSec ? 'DELAYED' : 'OFFLINE'));

header('Content-Type: application/json');
echo json_encode([
    'success'      => true,
    'configured'   => true,
    'device_uid'   => $d['device_uid'],
    'device_name'  => $d['device_name'],
    'is_active'    => (int) $d['is_active'] === 1,
    'plate_number' => $d['plate_number'],
    'model_name'   => $d['model_name'],
    'lat'          => $d['lat'] !== null ? (float) $d['lat'] : null,
    'lng'          => $d['lng'] !== null ? (float) $d['lng'] : null,
    'speed_kph'    => $d['speed_kph'] !== null ? round((float) $d['speed_kph'], 1) : null,
    'heading'      => $d['heading'] !== null ? (float) $d['heading'] : null,
    'age_sec'      => $age,
    'link_age_sec' => $linkAge,
    'freshness'    => $freshness,
]);
