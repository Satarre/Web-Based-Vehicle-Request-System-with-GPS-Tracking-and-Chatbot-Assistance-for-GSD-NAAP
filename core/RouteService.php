<?php
declare(strict_types=1);
/**
 * core/RouteService.php — traffic-aware trip routing (Google Routes API).
 *
 * Per-trip route legs (outbound origin→destination, return destination→origin)
 * are computed server-side, stored in `trip_routes`, and re-rendered on the
 * ticket map. Reroutes originate from the vehicle's latest live GPS fix and
 * only replace the active leg's ETA/polyline — the trip's recorded origin and
 * destination are never rewritten.
 *
 * Requests use travelMode DRIVE · routingPreference TRAFFIC_AWARE_OPTIMAL ·
 * trafficModel BEST_GUESS. Only the recommended (fastest) route is requested
 * and stored.
 *
 * A freshness guard reuses a recent Google snapshot for the same origin→dest
 * instead of calling the API again, so action/page recomputes and the manual
 * refresh button never spend a request while the stored route is still current.
 *
 * All network/API failures are swallowed (logged) so routing never breaks a
 * trip mutation. `computeLeg()` accepts an injectable `$http` callable so the
 * HTTP layer is unit-testable without the network.
 */

class RouteService
{
    public const LEG_OUTBOUND = 'outbound';
    public const LEG_RETURN   = 'return';

    private const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    /** Store one leg snapshot (upsert). Returns a summary or null when skipped.
     *  When `$offlineFallback` is true and Google fails/unreachable, a
     *  straight-line estimate (haversine distance + estimated duration) is
     *  stored with route_source='offline' instead of nothing. */
    public static function computeLeg(
        PDO $pdo,
        int $ticketId,
        string $leg,
        ?array $origin = null,
        ?callable $http = null,
        bool $offlineFallback = false
    ): ?array {
        $st = $pdo->prepare('SELECT * FROM trip_tickets WHERE ticket_id = ? AND is_active = 1');
        $st->execute([$ticketId]);
        $t = $st->fetch();
        if (!$t || !in_array($leg, [self::LEG_OUTBOUND, self::LEG_RETURN], true)) {
            return null;
        }

        $campus = [Settings::getFloat('NAAP_ORIGIN_LAT'), Settings::getFloat('NAAP_ORIGIN_LNG')];
        $dest   = [(float) ($t['place_to_visit_lat'] ?? 0), (float) ($t['place_to_visit_lon'] ?? 0)];

        if ($leg === self::LEG_OUTBOUND) {
            $orig = $origin ?? $campus;
            $to   = $dest;
        } else {
            $orig = $origin ?? $dest;
            $to   = $campus;
        }
        if (empty($orig[0]) || empty($to[0])) {
            return null; // missing campus/destination coordinates
        }

        // Freshness guard: a recent Google snapshot for the same origin→dest is
        // reused as-is (no API call). Offline estimates are never reused — they
        // exist precisely because a real route still needs fetching.
        $existing = self::snapshot($pdo, $ticketId, $leg);
        if ($existing !== null
            && ($existing['route_source'] ?? 'google') === 'google'
            && $existing['fetched_at'] !== null
            && $existing['origin_lat'] !== null && $existing['dest_lat'] !== null
            && abs((float) $existing['origin_lat'] - $orig[0]) < 1e-6
            && abs((float) $existing['origin_lon'] - $orig[1]) < 1e-6
            && abs((float) $existing['dest_lat'] - $to[0]) < 1e-6
            && abs((float) $existing['dest_lon'] - $to[1]) < 1e-6
            && (time() - strtotime((string) $existing['fetched_at'])) < Settings::getInt('ROUTE_REFRESH_MIN', 15) * 60
        ) {
            return self::summary($leg, $existing, 'google', true);
        }

        $route = $http
            ? $http($orig, $to)
            : self::request($orig, $to);

        $source = 'google';
        if (!$route) {
            if (!$offlineFallback) {
                return null;
            }
            $route  = self::estimate($orig, $to);
            $source = 'offline';
        }

        $pdo->prepare(
            'INSERT INTO trip_routes
               (ticket_id, leg, origin_lat, origin_lon, dest_lat, dest_lon,
                distance_m, duration_sec, static_duration_sec, polyline, fetched_at, route_source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
               origin_lat = VALUES(origin_lat), origin_lon = VALUES(origin_lon),
               dest_lat = VALUES(dest_lat),     dest_lon = VALUES(dest_lon),
               distance_m = VALUES(distance_m), duration_sec = VALUES(duration_sec),
               static_duration_sec = VALUES(static_duration_sec),
               polyline = VALUES(polyline),     fetched_at = NOW(),
               route_source = VALUES(route_source)'
        )->execute([$ticketId, $leg, $orig[0], $orig[1], $to[0], $to[1],
            $route['distance_m'], $route['duration_sec'], $route['static_duration_sec'], $route['polyline'], $source]);

        return self::summary($leg, $route, $source);
    }

    /** Normalized leg summary for callers/UI. `cached` = served from snapshot. */
    private static function summary(string $leg, array $r, string $source, bool $cached = false): array
    {
        $dur    = (int) ($r['duration_sec'] ?? 0);
        $static = (int) ($r['static_duration_sec'] ?? 0);
        return [
            'leg'                 => $leg,
            'distance_m'          => (int) ($r['distance_m'] ?? 0),
            'duration_sec'        => $dur,
            'static_duration_sec' => $static,
            'traffic_delay_sec'   => max(0, $dur - $static),
            'polyline'            => $r['polyline'] ?? null,
            'route_source'        => $source,
            'fetched_at'          => $r['fetched_at'] ?? date('Y-m-d H:i:s'),
            'cached'              => $cached,
        ];
    }

    /** Offline fallback: straight-line corridor + haversine distance + estimate. */
    private static function estimate(array $orig, array $to): array
    {
        $distKm   = Geo::haversineMeters($orig[0], $orig[1], $to[0], $to[1]) / 1000;
        $kph      = max(1, (int) Settings::getInt('ROUTE_ESTIMATE_KPH', 40));
        $sec      = (int) round(($distKm / $kph) * 3600);
        return [
            'distance_m'          => (int) round($distKm * 1000),
            'duration_sec'        => $sec,
            'static_duration_sec' => $sec,
            'polyline'            => self::encodePolyline([[round($orig[0], 6), round($orig[1], 6)], [round($to[0], 6), round($to[1], 6)]]),
        ];
    }

    /** True when the stored leg is an offline straight-line estimate. */
    public static function isOffline(?array $route): bool
    {
        return $route !== null && (($route['route_source'] ?? 'google') === 'offline');
    }

    /** Direct Routes API call (real transport). Returns normalized route or null. */
    private static function request(array $orig, array $to): ?array
    {
        // Server-only key. The browser key is referrer-restricted and must never
        // be sent server-side; empty key returns null (caller falls back offline).
        $key = GOOGLE_ROUTES_API_KEY;
        if (!function_exists('curl_init') || $key === '') {
            return null;
        }
        $body = json_encode([
            'origin'      => ['location' => ['latLng' => ['latitude' => $orig[0], 'longitude' => $orig[1]]]],
            'destination' => ['location' => ['latLng' => ['latitude' => $to[0],   'longitude' => $to[1]]]],
            'travelMode'           => 'DRIVE',
            'routingPreference'    => 'TRAFFIC_AWARE_OPTIMAL',
            'trafficModel'         => 'BEST_GUESS',
            'units'                => 'METRIC',
        ]);
        $mask = 'routes.duration,routes.staticDuration,routes.distanceMeters,routes.polyline.encodedPolyline';

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $key,
                'X-Goog-FieldMask: ' . $mask,
            ],
            CURLOPT_TIMEOUT        => 4,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || $resp === false) {
            error_log('[RouteService] Routes API ' . $code . ' ' . ($err ?: (string) $resp));
            return null;
        }
        $data = json_decode((string) $resp, true);
        $r    = $data['routes'][0] ?? null;
        if (!$r) {
            error_log('[RouteService] Routes API returned no route');
            return null;
        }
        return [
            'distance_m'          => (int) ($r['distanceMeters'] ?? 0),
            'duration_sec'        => (int) round(self::durSec((string) ($r['duration'] ?? ''))),
            'static_duration_sec' => (int) round(self::durSec((string) ($r['staticDuration'] ?? ''))),
            'polyline'            => $r['polyline']['encodedPolyline'] ?? null,
        ];
    }

    private static function durSec(string $dur): float
    {
        $dur = trim($dur);
        if (str_ends_with($dur, 's')) {
            $dur = substr($dur, 0, -1);
        }
        return (float) $dur;
    }

    /** Which leg is active right now: outbound until arrival, then return. */
    public static function activeLeg(array $ticket): string
    {
        return empty($ticket['dest_arrival_at']) ? self::LEG_OUTBOUND : self::LEG_RETURN;
    }

    /** Latest valid GPS fix for a vehicle: [lat, lng] or null. */
    public static function latestFix(PDO $pdo, int $vehicleId): ?array
    {
        if ($vehicleId <= 0) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT latitude, longitude FROM gps_positions
             WHERE vehicle_id = ? ORDER BY recorded_at DESC, position_id DESC LIMIT 1'
        );
        $st->execute([$vehicleId]);
        $r = $st->fetch();
        return $r ? [(float) $r['latitude'], (float) $r['longitude']] : null;
    }

    /** Read a stored leg snapshot for the UI. */
    public static function snapshot(PDO $pdo, int $ticketId, string $leg): ?array
    {
        $st = $pdo->prepare('SELECT * FROM trip_routes WHERE ticket_id = ? AND leg = ?');
        $st->execute([$ticketId, $leg]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** Decode a Google encoded polyline into [[lat,lng], …]. */
    public static function decodePolyline(string $enc): array
    {
        $points = [];
        $index  = 0;
        $lat    = 0;
        $lng    = 0;
        $len    = strlen($enc);
        while ($index < $len) {
            $b = 0; $shift = 0; $result = 0;
            do { $b = ord($enc[$index++]) - 63; $result |= ($b & 0x1f) << $shift; $shift += 5; } while ($b >= 0x20);
            $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);
            $shift = 0; $result = 0;
            do { $b = ord($enc[$index++]) - 63; $result |= ($b & 0x1f) << $shift; $shift += 5; } while ($b >= 0x20);
            $lng += ($result & 1) ? ~($result >> 1) : ($result >> 1);
            $points[] = [$lat / 1e5, $lng / 1e5];
        }
        return $points;
    }

    /** Encode [[lat,lng], …] into a Google encoded polyline string. */
    public static function encodePolyline(array $points): string
    {
        $enc = '';
        $lat = 0;
        $lng = 0;
        foreach ($points as $p) {
            $dLat = (int) round(($p[0] - $lat) * 1e5);
            $dLng = (int) round(($p[1] - $lng) * 1e5);
            $lat += $dLat / 1e5;
            $lng += $dLng / 1e5;
            $enc .= self::encodeSigned($dLat) . self::encodeSigned($dLng);
        }
        return $enc;
    }

    private static function encodeSigned(int $value): string
    {
        $value = ($value << 1) ^ ($value >> 31);
        $chunk = '';
        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1f)) + 63);
            $value >>= 5;
        }
        $chunk .= chr($value + 63);
        return $chunk;
    }

    /** Min distance (m) from a point to a polyline (vertices + great-circle segments). */
    public static function deviationMeters(string $polyline, float $lat, float $lng): float
    {
        $pts = self::decodePolyline($polyline);
        if (count($pts) < 2) {
            return PHP_FLOAT_MAX;
        }
        $best = PHP_FLOAT_MAX;
        for ($i = 0; $i < count($pts) - 1; $i++) {
            $x = Geo::crossTrackMeters($lat, $lng, $pts[$i][0], $pts[$i][1], $pts[$i + 1][0], $pts[$i + 1][1]);
            $best = min($best, $x,
                Geo::haversineMeters($lat, $lng, $pts[$i][0], $pts[$i][1]),
                Geo::haversineMeters($lat, $lng, $pts[$i + 1][0], $pts[$i + 1][1]));
        }
        return $best;
    }

    /**
     * Cron pass: recompute the active leg of every ON_GOING trip from its latest
     * GPS fix. Skips when the vehicle has moved less than ROUTE_MIN_MOVE_M from
     * the stored origin, the route is fresh, and the vehicle is not deviating —
     * so a fleet of trips costs at most ~1 request each per ROUTE_REFRESH_MIN.
     */
    public static function rerouteActiveTrips(PDO $pdo, int $intervalMin, int $devM): int
    {
        $st = $pdo->query(
            'SELECT ticket_id, vehicle_id, dest_arrival_at FROM trip_tickets
             WHERE status_id = ' . TripState::ON_GOING . ' AND is_active = 1 AND vehicle_id IS NOT NULL'
        );
        $count = 0;
        foreach ($st->fetchAll() as $t) {
            $leg  = self::activeLeg($t);
            $fix  = self::latestFix($pdo, (int) $t['vehicle_id']);
            if (!$fix) {
                continue; // no fix yet — nothing to reroute from
            }
            $snap = self::snapshot($pdo, (int) $t['ticket_id'], $leg);
            $stale = $snap === null
                || $snap['fetched_at'] === null
                || (time() - strtotime((string) $snap['fetched_at'])) > $intervalMin * 60;

            $moved = true;
            if ($snap && $snap['origin_lat'] !== null) {
                $minMoveM = (float) Settings::getInt('ROUTE_MIN_MOVE_M', 1500);
                $moved = Geo::haversineMeters((float) $fix[0], (float) $fix[1],
                    (float) $snap['origin_lat'], (float) $snap['origin_lon']) > $minMoveM;
            }

            $deviated = false;
            if ($snap && $snap['fetched_at'] !== null && !empty($snap['polyline'])) {
                $deviated = self::deviationMeters((string) $snap['polyline'], (float) $fix[0], (float) $fix[1]) > $devM;
            }

            if (!$stale && !$deviated) {
                continue;
            }
            if (!$moved && !$deviated) {
                continue; // parked and on-route — keep the current ETA
            }
            if (self::computeLeg($pdo, (int) $t['ticket_id'], $leg, [$fix[0], $fix[1]]) !== null) {
                $count++;
            }
        }
        return $count;
    }
}