<?php
declare(strict_types=1);
/**
 * core/FlagEvaluator.php — scheduled flag rules.
 * Raises OPEN flags once per ticket/type; resolution is always manual
 * (Staff/MIS via api/trips.php resolve_flag with mandatory reason).
 * Never mutates ticket statuses — stale trips get flagged, not completed.
 *
 * NO_TELEMETRY      → raised at dispatch override (api/trips.php assign)
 * GEOFENCE_DEVIATION→ raised at start/complete (api/trips.php)
 * OVERDUE / MISSED_TRIP / GPS_SIGNAL_LOST / NEEDS_REVIEW → here, cron ~5 min
 * DRAFT_PURGE       → deletes DRAFT tickets older than 3 days
 */
final class FlagEvaluator
{
    /** @return array<string|int,int> flags raised per type this run */
    public static function run(PDO $pdo): array
    {
        $graceOverdue  = max(0, Settings::getInt('OVERDUE_GRACE_MIN', 30));
        $graceMissed   = max(0, Settings::getInt('MISSED_TRIP_GRACE_HOURS', 2));
        $delayedSec    = max(0, Settings::getInt('GPS_DELAYED_SEC', 120));
        $staleDays     = max(0, Settings::getInt('STALE_REVIEW_DAYS', 3));
        $stillKph      = max(0, Settings::getInt('IDLE_STILL_KPH', 5));
        $idleMin       = max(1, Settings::getInt('IDLE_STILL_MINUTES', 15));

        $rules = [
            'OVERDUE' => [
                "t.status_id = " . TripState::ON_GOING . " AND t.is_active = 1
                 AND t.scheduled_return IS NOT NULL
                 AND t.scheduled_return < NOW() - INTERVAL {$graceOverdue} MINUTE",
                "CONCAT('Returned window exceeded by ', TIMESTAMPDIFF(MINUTE, t.scheduled_return, NOW()), ' min')",
            ],
            'MISSED_TRIP' => [
                "t.status_id = " . TripState::DISPATCHED . " AND t.is_active = 1
                 AND t.scheduled_departure IS NOT NULL
                 AND t.scheduled_departure < NOW() - INTERVAL {$graceMissed} HOUR",
                "CONCAT('Not started ', TIMESTAMPDIFF(HOUR, t.scheduled_departure, NOW()), ' h after scheduled departure')",
            ],
            'GPS_SIGNAL_LOST' => [
                "t.status_id = " . TripState::ON_GOING . " AND t.is_active = 1
                 AND EXISTS (SELECT 1 FROM gps_devices d
                             WHERE d.vehicle_id = t.vehicle_id AND d.is_active = 1
                               AND (d.last_seen_at IS NULL
                                    OR d.last_seen_at < NOW() - INTERVAL {$delayedSec} SECOND))",
                "'Paired device telemetry stale during active trip'",
            ],
            'IDLE_DETECTED' => [
                "t.status_id = " . TripState::ON_GOING . " AND t.is_active = 1
                 AND EXISTS (SELECT 1 FROM gps_positions p
                             WHERE p.ticket_id = t.ticket_id
                               AND p.recorded_at >= NOW() - INTERVAL {$idleMin} MINUTE
                             HAVING COUNT(*) >= 5
                                AND COALESCE(MAX(COALESCE(p.speed_kph, 0)), 0) < {$stillKph})",
                "CONCAT('Vehicle idle (speed < ', {$stillKph}, ' kph) for over ', {$idleMin}, ' min')",
            ],
            'NEEDS_REVIEW' => [
                "t.status_id BETWEEN 2 AND 6 AND t.is_active = 1
                 AND t.updated_at < NOW() - INTERVAL {$staleDays} DAY",
                "CONCAT('No progress for over ', {$staleDays}, ' days')",
            ],
        ];

        $raised = [];
        foreach ($rules as $type => [$where, $detail]) {
            $st = $pdo->prepare(
                "INSERT INTO trip_flags (ticket_id, flag_type, detail)
                 SELECT t.ticket_id, ?, {$detail}
                 FROM trip_tickets t
                 WHERE {$where}
                   AND NOT EXISTS (
                     SELECT 1 FROM trip_flags f
                     WHERE f.ticket_id = t.ticket_id AND f.flag_type = ? AND f.resolved_at IS NULL
                 )"
            );
            $st->execute([$type, $type]);
            $raised[$type] = $st->rowCount();
        }

        // DRAFT auto-purge: hard-delete trip_drafts (private scratchpads, not
        // real tickets) untouched for 3 days, including their request letters.
        // "Untouched" = updated_at when present, else created_at, so a draft
        // that is still being edited is never deleted mid-session.
        $drafts = $pdo->query(
            "SELECT draft_id, attachment_path FROM trip_drafts
             WHERE COALESCE(updated_at, created_at) < NOW() - INTERVAL 3 DAY"
        )->fetchAll();
        if ($drafts) {
            $ids = implode(',', array_map(static fn(array $d): int => (int) $d['draft_id'], $drafts));
            foreach ($drafts as $d) {
                $full = APP_ROOT . '/' . ltrim((string) $d['attachment_path'], '/');
                if (!empty($d['attachment_path']) && str_starts_with($full, APP_ROOT . '/uploads/') && is_file($full)) {
                    @unlink($full);
                }
            }
            $pdo->exec("DELETE FROM trip_draft_passengers WHERE draft_id IN ($ids)");
            $pdo->exec("DELETE FROM trip_drafts WHERE draft_id IN ($ids)");
            $raised['DRAFT_PURGE'] = count($drafts);
        }

        return $raised;
    }
}
