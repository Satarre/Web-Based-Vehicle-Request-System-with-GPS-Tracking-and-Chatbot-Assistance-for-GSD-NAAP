<?php
declare(strict_types=1);
/**
 * core/Gps.php — GPS ingestion subsystem.
 *
 * HttpJsonProvider : default JSON-over-HTTP adapter (normalizes raw payload).
 * Geo              : haversine + geofence helpers (settings-driven).
 * GpsIngest        : pipeline — Bearer auth → normalize → pair check →
 *                    throttle ≥ GPS_MIN_INTERVAL_SEC → heartbeat →
 *                    active-trip attach (ON_GOING) → breadcrumb →
 *                    transition-only geofence events.
 *
 * api/gps/locations.php is a thin HTTP wrapper around GpsIngest::handle();
 * tests exercise handle() directly.
 */

final class HttpJsonProvider
{
    /** Normalize raw payload; throws InvalidArgumentException on invalid fix. */
    public function normalize(array $raw): array
    {
        $lat = $raw['latitude'] ?? $raw['lat'] ?? null;
        $lng = $raw['longitude'] ?? $raw['lng'] ?? $raw['lon'] ?? null;
        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            throw new InvalidArgumentException('latitude and longitude are required numbers');
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException('coordinates out of range');
        }

        $fix = [
            'lat'         => $lat,
            'lng'         => $lng,
            'speed_kph'   => self::optNum($raw['speed_kph'] ?? $raw['speed'] ?? null, 0, 300),
            'heading'     => self::optNum($raw['heading'] ?? $raw['course'] ?? null, 0, 360),
            'altitude_m'  => self::optNum($raw['altitude_m'] ?? $raw['altitude'] ?? null, -500, 10000),
            'accuracy_m'  => self::optNum($raw['accuracy_m'] ?? $raw['accuracy'] ?? null, 0, 10000),
            'ignition'    => isset($raw['ignition']) ? (int) (bool) $raw['ignition'] : null,
        ];

        // recorded_at: accept 'Y-m-d H:i:s', ISO-8601 or epoch seconds; default now. Future = clamp.
        $ts = $raw['recorded_at'] ?? $raw['timestamp'] ?? null;
        if ($ts !== null && is_numeric($ts)) {
            $time = (int) $ts;
        } elseif ($ts !== null && ($time = strtotime((string) $ts)) !== false) {
            // fallthrough uses $time
        } else {
            $time = time();
        }
        $fix['recorded_at'] = date('Y-m-d H:i:s', min($time, time()));

        return $fix;
    }

    private static function optNum($v, float $min, float $max): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return max($min, min($max, (float) $v));
    }
}

final class Geo
{
    /** Great-circle distance in meters. */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Inside the campus geofence per app_settings center+radius. */
    public static function insideFence(float $lat, float $lng): bool
    {
        return self::haversineMeters(
            $lat,
            $lng,
            Settings::getFloat('NAAP_GEOFENCE_LAT'),
            Settings::getFloat('NAAP_GEOFENCE_LNG')
        ) <= Settings::getFloat('NAAP_GEOFENCE_RADIUS_METERS', 500.0);
    }
}

final class GpsIngest
{
    /**
     * Ingest one fix. Returns ['code' => int, 'message' => ?string].
     * 204 = accepted/stored-or-throttled (silent for devices), 4xx = rejected.
     */
    public static function handle(PDO $pdo, string $bearer, array $raw): array
    {
        $provider = new HttpJsonProvider();

        try {
            $fix = $provider->normalize($raw);
        } catch (InvalidArgumentException $e) {
            return ['code' => 400, 'message' => $e->getMessage()];
        }

        if ($bearer === '' || strlen($bearer) > 128) {
            return ['code' => 401, 'message' => 'missing bearer token'];
        }
        $stmt = $pdo->prepare(
            'SELECT device_id, vehicle_id FROM gps_devices
             WHERE api_token_hash = SHA2(?, 256) AND is_active = 1'
        );
        $stmt->execute([$bearer]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            return ['code' => 401, 'message' => 'unknown or inactive device token'];
        }
        $deviceId  = (int) $device['device_id'];
        $vehicleId = $device['vehicle_id'] !== null ? (int) $device['vehicle_id'] : 0;
        if (!$vehicleId) {
            return ['code' => 400, 'message' => 'device not paired to a vehicle'];
        }

        // Throttle forward-time flow only: drop fixes arriving < GPS_MIN_INTERVAL_SEC
        // after the last stored one on device time. Backdated replays (older than the
        // newest stored fix) always persist — history must never be silently dropped.
        $minInterval = Settings::getInt('GPS_MIN_INTERVAL_SEC', 20);
        $last = $pdo->prepare(
            'SELECT recorded_at, inside_geofence FROM gps_positions
             WHERE device_id = ? ORDER BY position_id DESC LIMIT 1'
        );
        $last->execute([$deviceId]);
        $prevRow   = $last->fetch(PDO::FETCH_ASSOC);
        $throttled = false;
        if ($prevRow) {
            $lastT = strtotime($prevRow['recorded_at']);
            $nowT  = strtotime($fix['recorded_at']);
            $throttled = $nowT >= $lastT && ($nowT - $lastT) < $minInterval;
        }

        // Active trip (ON_GOING) attaches breadcrumb.
        $activeIds = implode(',', TripState::ACTIVE_TRIP);
        $tripStmt = $pdo->prepare(
            "SELECT ticket_id FROM trip_tickets
             WHERE vehicle_id = ? AND status_id IN ($activeIds)
             ORDER BY actual_departure_at DESC, ticket_id DESC LIMIT 1"
        );
        $tripStmt->execute([$vehicleId]);
        $ticketId = $tripStmt->fetchColumn() ?: null;

        $inside = Geo::insideFence($fix['lat'], $fix['lng']);

        // Join the caller's transaction when one is open (tests); else own our own.
        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) {
            $pdo->beginTransaction();
        }
        try {
            if (!$throttled) {
                $ins = $pdo->prepare(
                    'INSERT INTO gps_positions
                       (device_id, vehicle_id, ticket_id, latitude, longitude, speed_kph, heading,
                        altitude_m, accuracy_m, ignition, inside_geofence, recorded_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $deviceId, $vehicleId, $ticketId,
                    $fix['lat'], $fix['lng'], $fix['speed_kph'], $fix['heading'],
                    $fix['altitude_m'], $fix['accuracy_m'], $fix['ignition'],
                    (int) $inside, $fix['recorded_at'],
                ]);

                // Transition-only geofence event vs previous known state.
                $prevInside = ($prevRow && $prevRow['inside_geofence'] !== null)
                    ? (bool) $prevRow['inside_geofence'] : null;
                if ($prevInside !== null && $prevInside !== $inside) {
                    $ev = $pdo->prepare(
                        'INSERT INTO geofence_events (device_id, vehicle_id, ticket_id, event_type, occurred_at)
                         VALUES (?,?,?,?,?)'
                    );
                    $ev->execute([
                        $deviceId, $vehicleId, $ticketId,
                        $inside ? 'GEOFENCE_ENTERED' : 'GEOFENCE_EXITED',
                        $fix['recorded_at'],
                    ]);

                    // Active-trip vehicle left campus → raise GEOFENCE_DEVIATION once
                    // (deduped against unresolved rows; resolution is manual by Staff/MIS).
                    if (!$inside && $ticketId !== null) {
                        $fl = $pdo->prepare(
                            'INSERT INTO trip_flags (ticket_id, flag_type, detail)
                             SELECT ?, ?, ? FROM DUAL
                             WHERE NOT EXISTS (
                               SELECT 1 FROM trip_flags
                               WHERE ticket_id = ? AND flag_type = ? AND resolved_at IS NULL
                             )'
                        );
                        $fl->execute([
                            $ticketId, 'GEOFENCE_DEVIATION',
                            'Left campus geofence at ' . $fix['lat'] . ',' . $fix['lng'],
                            $ticketId, 'GEOFENCE_DEVIATION',
                        ]);
                    }
                }
            }

            // Heartbeat + cached last-fix on the device row (always, even when throttled).
            $hb = $pdo->prepare(
                'UPDATE gps_devices
                 SET last_seen_at = NOW(3), last_latitude = ?, last_longitude = ?,
                     last_speed_kph = ?, last_heading = ?
                 WHERE device_id = ?'
            );
            $hb->execute([$fix['lat'], $fix['lng'], $fix['speed_kph'], $fix['heading'], $deviceId]);

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn) {
                $pdo->rollBack();
            }
            error_log('[gps-ingest] ' . $e->getMessage());
            return ['code' => 500, 'message' => 'storage failure'];
        }

        return ['code' => 204, 'message' => null];
    }
}
