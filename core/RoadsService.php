<?php
declare(strict_types=1);
/**
 * core/RoadsService.php — snap a completed trip's recorded breadcrumbs to roads
 * (Google Roads API snapToRoads) and persist the resulting route per leg in
 * `trip_tracks`. The raw fix path is stored as a fallback (`source='raw'`) when
 * the Roads API is unreachable or quota-limited, so the recorded trail is never
 * lost. Called best-effort from complete_trip; failures must never break it.
 *
 * HTTP is injectable (`$http`) so the split/snap/store logic is unit-testable
 * without the network — same pattern as RouteService::computeLeg.
 */

class RoadsService
{
    private const ENDPOINT  = 'https://roads.googleapis.com/v1/snapToRoads';
    private const MAX_POINTS = 100;
    private const TIMEOUT    = 5;

    /**
     * Load the ticket's breadcrumbs, split them into outbound/return using
     * dest_arrival_at (fallback: the fix nearest the destination), snap each leg
     * and upsert trip_tracks. Returns the stored legs, or [] when nothing to do.
     */
    public static function storeRecordedTrack(PDO $pdo, int $ticketId, ?callable $http = null): array
    {
        $st = $pdo->prepare('SELECT place_to_visit_lat, place_to_visit_lon, dest_arrival_at FROM trip_tickets WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        $t = $st->fetch();
        if (!$t) {
            return [];
        }

        $crumbs = $pdo->prepare('SELECT latitude, longitude, recorded_at FROM gps_positions WHERE ticket_id = ? ORDER BY recorded_at, position_id');
        $crumbs->execute([$ticketId]);
        $rows = $crumbs->fetchAll();
        if (count($rows) < 2) {
            return [];
        }

        $dest = ($t['place_to_visit_lat'] !== null && $t['place_to_visit_lon'] !== null)
            ? [(float) $t['place_to_visit_lat'], (float) $t['place_to_visit_lon']]
            : null;

        [$outbound, $return] = self::splitLegs($rows, $dest, $t['dest_arrival_at']);

        $stored = [];
        if (count($outbound) >= 2) {
            $stored['outbound'] = self::storeLeg($pdo, $ticketId, 'outbound', $outbound, $http);
        }
        if (count($return) >= 2) {
            $stored['return'] = self::storeLeg($pdo, $ticketId, 'return', $return, $http);
        }
        return $stored;
    }

    /**
     * Split breadcrumbs at the recorded arrival timestamp; when absent, use the
     * fix nearest the destination as the turnaround point.
     *
     * @return array{0: array, 1: array} [outbound, return] rows
     */
    public static function splitLegs(array $rows, ?array $dest, ?string $arrivalAt): array
    {
        if ($arrivalAt !== null) {
            $cut = strtotime($arrivalAt);
            $out = $ret = [];
            foreach ($rows as $r) {
                if (strtotime((string) $r['recorded_at']) <= $cut) {
                    $out[] = $r;
                } else {
                    $ret[] = $r;
                }
            }
            return [$out, $ret];
        }

        if ($dest === null) {
            return [$rows, []];
        }
        $bestIdx = 0;
        $best = PHP_FLOAT_MAX;
        foreach ($rows as $i => $r) {
            $d = Geo::haversineMeters((float) $r['latitude'], (float) $r['longitude'], $dest[0], $dest[1]);
            if ($d < $best) {
                $best = $d;
                $bestIdx = $i;
            }
        }
        // Keep the turnaround point in both legs so each polyline is continuous.
        return [
            array_slice($rows, 0, $bestIdx + 1),
            array_slice($rows, $bestIdx),
        ];
    }

    /**
     * Snap one leg and upsert its polyline. Falls back to the raw path.
     * Returns ['leg','source','point_count'].
     */
    private static function storeLeg(PDO $pdo, int $ticketId, string $leg, array $rows, ?callable $http): array
    {
        $raw = array_map(static fn(array $r): array => [(float) $r['latitude'], (float) $r['longitude']], $rows);

        $snapped = self::snapPoints($raw, $http);
        $source  = 'snapped';
        $points  = $snapped;
        if ($points === null || count($points) < 2) {
            $source = 'raw';
            $points = $raw;
        }

        $polyline = RouteService::encodePolyline($points);
        $pdo->prepare(
            'INSERT INTO trip_tracks (ticket_id, leg, polyline, point_count, source)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               polyline = VALUES(polyline), point_count = VALUES(point_count),
               source = VALUES(source), created_at = NOW()'
        )->execute([$ticketId, $leg, $polyline, count($points), $source]);

        return ['leg' => $leg, 'source' => $source, 'point_count' => count($points)];
    }

    /**
     * Snap a list of [lat,lng] points, batching at MAX_POINTS with a one-point
     * overlap. Returns null if any batch fails (caller falls back to raw).
     */
    public static function snapPoints(array $latlng, ?callable $http = null): ?array
    {
        if (count($latlng) < 2) {
            return null;
        }
        $out = [];
        $i = 0;
        $n = count($latlng);
        while ($i < $n) {
            $chunk = array_slice($latlng, $i, self::MAX_POINTS);
            $res = $http ? $http($chunk) : self::request($chunk);
            if ($res === null) {
                return null;
            }
            // Skip the duplicated overlap point on every chunk after the first.
            if ($i > 0 && $out !== [] && $res !== []) {
                array_shift($res);
            }
            $out = array_merge($out, $res);
            $i += self::MAX_POINTS - 1;
        }
        return count($out) >= 2 ? $out : null;
    }

    /** Direct Roads API snapToRoads call. Returns [[lat,lng], …] or null. */
    private static function request(array $chunk): ?array
    {
        $key = GOOGLE_ROUTES_API_KEY;
        if (!function_exists('curl_init') || $key === '') {
            return null;
        }
        $path = implode('|', array_map(static fn(array $p): string => $p[0] . ',' . $p[1], $chunk));
        $url  = self::ENDPOINT . '?interpolate=false&path=' . rawurlencode($path) . '&key=' . rawurlencode($key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || $resp === false) {
            error_log('[RoadsService] snapToRoads ' . $code . ' ' . ($err ?: (string) $resp));
            return null;
        }
        $data = json_decode((string) $resp, true);
        $pts  = $data['snappedPoints'] ?? null;
        if (!is_array($pts) || count($pts) < 2) {
            return null;
        }
        $out = [];
        foreach ($pts as $p) {
            $loc = $p['location'] ?? null;
            if (is_array($loc) && isset($loc['latitude'], $loc['longitude'])) {
                $out[] = [(float) $loc['latitude'], (float) $loc['longitude']];
            }
        }
        return count($out) >= 2 ? $out : null;
    }
}
