<?php
declare(strict_types=1);
/**
 * core/Gps.php — GPS ingestion subsystem.
 *
 * HttpJsonProvider : default JSON-over-HTTP adapter (normalizes raw payload).
 * Geo              : haversine + geofence helpers (settings-driven).
 * GpsIngest        : pipeline — Bearer auth → normalize → pair check →
 *                    throttle ≥ GPS_MIN_INTERVAL_SEC → heartbeat + live-cache
 *                    staleness gate → active-trip attach (ON_GOING) →
 *                    breadcrumb → transition-only geofence events.
 *                    handle() = single fix, handleBatch() = array replay
 *                    (device store-and-forward), both funnel into storeFix().
 *                    Also owns raiseGeofenceFlag(): start/complete flag.
 *
 * Live-cache honesty (v6.8): gps_devices.last_* only ever mirrors a fix that is
 * at/after the newest stored one AND captured within GPS_DELAYED_SEC of real
 * time; last_recorded_at records its capture timestamp so the tracking map can
 * badge position age (not just link age). A backdated deadzone replay therefore
 * fills gps_positions (true history) but can never drag the "live"
 * pin along stale steps — forwards or backwards.
 *
 * api/gps/locations.php is a thin HTTP wrapper around GpsIngest::handle() /
 * handleBatch(); tests exercise both directly.
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

    /** Great-circle path length (km) over consecutive [lat,lng] points. */
    public static function pathKm(array $points): float
    {
        $km = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $km += self::haversineMeters(
                (float) $points[$i - 1]['latitude'],
                (float) $points[$i - 1]['longitude'],
                (float) $points[$i]['latitude'],
                (float) $points[$i]['longitude']
            ) / 1000.0;
        }
        return $km;
    }

    /** Initial great-circle bearing in degrees (0–360). */
    public static function initialBearingDeg(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dl   = deg2rad($lng2 - $lng1);
        $y    = sin($dl) * cos($phi2);
        $x    = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dl);
        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /** Cross-track distance (m) of a point from the great-circle path A→B. */
    public static function crossTrackMeters(float $lat, float $lng, float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $d13 = self::haversineMeters($aLat, $aLng, $lat, $lng);
        if ($d13 < 1.0) {
            return 0.0;
        }
        $t13 = self::initialBearingDeg($aLat, $aLng, $lat, $lng);
        $t12 = self::initialBearingDeg($aLat, $aLng, $bLat, $bLng);
        return abs(asin(sin($d13 / 6371000.0) * sin(deg2rad($t13 - $t12))) * 6371000.0);
    }
}

final class GpsIngest
{
    /**
     * Ingest one fix with a device bearer token (hardware / external clients).
     * Returns ['code' => int, 'message' => ?string, 'stored' => bool, 'throttled' => bool].
     * 204 = accepted/stored-or-throttled (silent for devices), 4xx = rejected.
     */
    public static function handle(PDO $pdo, string $bearer, array $raw): array
    {
        try {
            $fix = (new HttpJsonProvider())->normalize($raw);
        } catch (InvalidArgumentException $e) {
            return self::result(400, $e->getMessage());
        }

        $device = self::resolveByBearer($pdo, $bearer);
        if (!$device) {
            return self::result(401, 'unknown or inactive device token');
        }

        return self::storeFix(
            $pdo,
            (int) $device['device_id'],
            $device['vehicle_id'] !== null ? (int) $device['vehicle_id'] : 0,
            $fix
        );
    }

    /**
     * Ingest a batch of fixes with one device bearer token — the store-and-
     * forward replay path after connectivity returns. Same storeFix() pipeline
     * per element, joined into a single transaction, and tolerant of individual
     * bad elements (skipped, never retried): a device may drop the batch on 204.
     * Envelope limits are strict: 1..BATCH_MAX elements, each an array.
     * Returns ['code', 'stored', 'throttled', 'skipped', 'message'].
     */
    public const BATCH_MAX = 50;

    public static function handleBatch(PDO $pdo, string $bearer, array $list): array
    {
        $count = count($list);
        if ($count === 0 || $count > self::BATCH_MAX) {
            return self::batchResult(400, 0, 0, 0, 'batch must contain 1-' . self::BATCH_MAX . ' fixes');
        }

        $device = self::resolveByBearer($pdo, $bearer);
        if (!$device) {
            return self::batchResult(401, 0, 0, 0, 'unknown or inactive device token');
        }
        $vehicleId = $device['vehicle_id'] !== null ? (int) $device['vehicle_id'] : 0;
        if (!$vehicleId) {
            return self::batchResult(400, 0, 0, 0, 'device not paired to a vehicle');
        }
        $deviceId = (int) $device['device_id'];

        $provider = new HttpJsonProvider();
        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) {
            $pdo->beginTransaction();
        }
        $stored = $throttled = $skipped = 0;
        try {
            foreach ($list as $element) {
                if (!is_array($element)) {
                    $skipped++;
                    continue;
                }
                try {
                    $fix = $provider->normalize($element);
                } catch (InvalidArgumentException) {
                    $skipped++;
                    continue;
                }
                $out = self::storeFix($pdo, $deviceId, $vehicleId, $fix);
                if ($out['code'] === 500) {
                    throw new RuntimeException('storage failure inside batch');
                }
                $stored += $out['stored'] ? 1 : 0;
                $throttled += $out['throttled'] ? 1 : 0;
            }
            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn) {
                $pdo->rollBack();
            }
            error_log('[gps-ingest-batch] ' . $e->getMessage());
            return self::batchResult(500, 0, 0, $skipped, 'storage failure');
        }

        return self::batchResult(204, $stored, $throttled, $skipped, null);
    }

    private static function batchResult(int $code, int $stored, int $throttled, int $skipped, ?string $message): array
    {
        return ['code' => $code, 'stored' => $stored, 'throttled' => $throttled, 'skipped' => $skipped, 'message' => $message];
    }

    /** Bearer → active device row (empty = unknown token). */
    private static function resolveByBearer(PDO $pdo, string $bearer): array
    {
        if ($bearer === '' || strlen($bearer) > 128) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT device_id, vehicle_id FROM gps_devices
             WHERE api_token_hash = SHA2(?, 256) AND is_active = 1'
        );
        $stmt->execute([$bearer]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ingest one fix for a hidden tracker account (session-auth browser
     * sharing from tracker.php). Same pipeline as hardware; the device is
     * resolved from the account instead of a bearer token.
     */
    public static function handleAccount(PDO $pdo, int $accountId, array $raw): array
    {
        $provider = new HttpJsonProvider();

        try {
            $fix = $provider->normalize($raw);
        } catch (InvalidArgumentException $e) {
            return self::result(400, $e->getMessage());
        }

        $stmt = $pdo->prepare(
            'SELECT device_id, vehicle_id FROM gps_devices
             WHERE account_id = ? AND is_active = 1'
        );
        $stmt->execute([$accountId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            return self::result(409, 'tracker device is not configured or disabled');
        }

        return self::storeFix(
            $pdo,
            (int) $device['device_id'],
            $device['vehicle_id'] !== null ? (int) $device['vehicle_id'] : 0,
            $fix
        );
    }

    /** Ingest result tuple shared by both entry points. */
    private static function result(int $code, ?string $message = null, bool $stored = false, bool $throttled = false): array
    {
        return ['code' => $code, 'message' => $message, 'stored' => $stored, 'throttled' => $throttled];
    }

    /**
     * Shared storage pipeline for one normalized fix:
     * throttle → geofence transition → active-trip attach → insert →
     * heartbeat + staleness-gated live cache.
     */
    private static function storeFix(PDO $pdo, int $deviceId, int $vehicleId, array $fix): array
    {
        if (!$vehicleId) {
            return self::result(400, 'device not paired to a vehicle');
        }

        // Throttle forward-time flow only. The reference is the device's NEWEST
        // stored fix (MAX(recorded_at)), never the last inserted row: during a
        // deadzone replay (oldest → newest) the last inserted row is itself a
        // backdated fix, and comparing against it would drop most of the replay.
        $minInterval = Settings::getInt('GPS_MIN_INTERVAL_SEC', 20);
        $nowT = strtotime($fix['recorded_at']);
        $maxStmt = $pdo->prepare('SELECT MAX(recorded_at) FROM gps_positions WHERE device_id = ?');
        $maxStmt->execute([$deviceId]);
        $maxTs = $maxStmt->fetchColumn();
        $maxTs = ($maxTs === false || $maxTs === null) ? null : (string) $maxTs;
        $throttled = false;
        if ($maxTs !== null) {
            $lastT = strtotime($maxTs);
            $throttled = $nowT >= $lastT && ($nowT - $lastT) < $minInterval;
        }

        // Geofence transition state = the fix immediately before this one in
        // time — correct for both in-order live fixes and backdated replays.
        $prev = $pdo->prepare(
            'SELECT inside_geofence FROM gps_positions
             WHERE device_id = ? AND recorded_at <= ?
             ORDER BY recorded_at DESC, position_id DESC LIMIT 1'
        );
        $prev->execute([$deviceId, $fix['recorded_at']]);
        $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: null;

        // Active trip (ON_GOING) attaches breadcrumb.
        $activeIds = implode(',', TripState::ACTIVE_TRIP);
        $tripStmt = $pdo->prepare(
            "SELECT ticket_id, place_to_visit_lat, place_to_visit_lon FROM trip_tickets
             WHERE vehicle_id = ? AND status_id IN ($activeIds)
             ORDER BY actual_departure_at DESC, ticket_id DESC LIMIT 1"
        );
        $tripStmt->execute([$vehicleId]);
        $tripRow  = $tripStmt->fetch(PDO::FETCH_ASSOC);
        $ticketId = $tripRow ? (int) $tripRow['ticket_id'] : null;

        $inside = Geo::insideFence($fix['lat'], $fix['lng']);

        // Join the caller's transaction when one is open (tests); else own our own.
        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) {
            $pdo->beginTransaction();
        }
        $inserted = false;
        try {
            if (!$throttled) {
                $ins = $pdo->prepare(
                    'INSERT INTO gps_positions
                       (device_id, vehicle_id, ticket_id, latitude, longitude, speed_kph, heading,
                        altitude_m, accuracy_m, ignition, inside_geofence, recorded_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE received_at = received_at'
                );
                $ins->execute([
                    $deviceId, $vehicleId, $ticketId,
                    $fix['lat'], $fix['lng'], $fix['speed_kph'], $fix['heading'],
                    $fix['altitude_m'], $fix['accuracy_m'], $fix['ignition'],
                    (int) $inside, $fix['recorded_at'],
                ]);
                // rowCount 0 = duplicate (device_id, recorded_at) replayed → no-op.
                $inserted = $ins->rowCount() > 0;

                // Transition-only geofence event vs previous known state.
                // Skip for a deduped replay so replayed payloads have no side effects.
                $prevInside = ($prevRow && $prevRow['inside_geofence'] !== null)
                    ? (bool) $prevRow['inside_geofence'] : null;
                if ($inserted && $prevInside !== null && $prevInside !== $inside) {
                    $ev = $pdo->prepare(
                        'INSERT INTO geofence_events (device_id, vehicle_id, ticket_id, event_type, occurred_at)
                         VALUES (?,?,?,?,?)'
                    );
                    $ev->execute([
                        $deviceId, $vehicleId, $ticketId,
                        $inside ? 'GEOFENCE_ENTERED' : 'GEOFENCE_EXITED',
                        $fix['recorded_at'],
                    ]);
                }
            }

            // Heartbeat always. The live-position cache updates only for a fix
            // that is BOTH at/after the newest stored one AND captured within
            // GPS_DELAYED_SEC of real time. This keeps a backdated deadzone
            // replay from dragging the live map backwards to an old position —
            // and, equally, from forward-crawling the "live" pin along stale
            // steps after a long outage: the cache freezes at the true last
            // position while history keeps filling, and a genuinely current fix
            // (hardware sends it out-of-turn, v6.8) re-acquires the cache at
            // once. last_recorded_at carries the cached fix's capture time so
            // the map can badge POSITION age, not just link age.
            $newest     = $maxTs === null || $nowT >= strtotime($maxTs);
            $freshVsNow = $maxTs === null
                || (time() - $nowT) <= max(0, Settings::getInt('GPS_DELAYED_SEC', 120));
            if ($newest && $freshVsNow) {
                $hb = $pdo->prepare(
                    'UPDATE gps_devices
                     SET last_seen_at = NOW(3), last_latitude = ?, last_longitude = ?,
                         last_speed_kph = ?, last_heading = ?, last_recorded_at = ?
                     WHERE device_id = ?'
                );
                $hb->execute([$fix['lat'], $fix['lng'], $fix['speed_kph'], $fix['heading'], $fix['recorded_at'], $deviceId]);
            } else {
                $hb = $pdo->prepare('UPDATE gps_devices SET last_seen_at = NOW(3) WHERE device_id = ?');
                $hb->execute([$deviceId]);
            }

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn) {
                $pdo->rollBack();
            }
            error_log('[gps-ingest] ' . $e->getMessage());
            return self::result(500, 'storage failure');
        }

        return self::result(204, null, $inserted, $throttled);
    }

    /**
     * Raise GEOFENCE_DEVIATION when a trip starts or completes outside the
     * campus fence. Never blocks the action. Skips silently when there is
     * nothing trustworthy to check against: unpaired vehicle (NO_TELEMETRY
     * already covers it), unconfigured geofence, no fix, or a fix older than
     * GPS_DELAYED_SEC.
     *
     * @param string $phase 'start' | 'complete'
     * @return ?string flag detail when raised, null when skipped or deduped
     */
    public static function raiseGeofenceFlag(PDO $pdo, int $ticketId, int $vehicleId, string $phase): ?string
    {
        if ($ticketId <= 0 || $vehicleId <= 0) {
            return null;
        }
        $cLat = Settings::getFloat('NAAP_GEOFENCE_LAT');
        $cLng = Settings::getFloat('NAAP_GEOFENCE_LNG');
        if ($cLat === 0.0 && $cLng === 0.0) {
            return null; // geofence not configured — nothing to verify against
        }
        $paired = $pdo->prepare('SELECT COUNT(*) FROM gps_devices WHERE vehicle_id = ? AND is_active = 1');
        $paired->execute([$vehicleId]);
        if ((int) $paired->fetchColumn() === 0) {
            return null;
        }

        $st = $pdo->prepare(
            'SELECT latitude, longitude, recorded_at FROM gps_positions
             WHERE vehicle_id = ? ORDER BY recorded_at DESC, position_id DESC LIMIT 1'
        );
        $st->execute([$vehicleId]);
        $fix = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fix) {
            return null;
        }
        $ageSec = time() - (int) strtotime((string) $fix['recorded_at']);
        if ($ageSec > max(0, Settings::getInt('GPS_DELAYED_SEC', 120))) {
            return null; // stale fix is never trusted
        }
        if (Geo::insideFence((float) $fix['latitude'], (float) $fix['longitude'])) {
            return null;
        }

        $outsideM = max(1, (int) round(Geo::haversineMeters(
            (float) $fix['latitude'], (float) $fix['longitude'], $cLat, $cLng
        ) - Settings::getFloat('NAAP_GEOFENCE_RADIUS_METERS', 500.0)));
        $detail = 'Trip ' . ($phase === 'start' ? 'started' : 'completed')
            . ' outside the campus geofence (' . $fix['latitude'] . ',' . $fix['longitude']
            . ' — about ' . $outsideM . ' m out)';

        $fl = $pdo->prepare(
            'INSERT INTO trip_flags (ticket_id, flag_type, detail)
             SELECT ?, ?, ? FROM DUAL
             WHERE NOT EXISTS (
               SELECT 1 FROM trip_flags
               WHERE ticket_id = ? AND flag_type = ? AND resolved_at IS NULL
             )'
        );
        $fl->execute([$ticketId, 'GEOFENCE_DEVIATION', $detail, $ticketId, 'GEOFENCE_DEVIATION']);
        return $fl->rowCount() > 0 ? $detail : null;
    }
}
