<?php
declare(strict_types=1);
/**
 * scripts/backfill_routes.php — one-time compute missing route legs for existing tickets.
 * Run: php scripts/backfill_routes.php
 */
require_once __DIR__ . '/../config/config.php';

$active = [TripState::APPROVED, TripState::DISPATCHED, TripState::ON_GOING, TripState::COMPLETED];
$placeholders = implode(',', array_fill(0, count($active), '?'));

$st = $pdo->prepare(
    "SELECT ticket_id, status_id, scheduled_departure, dest_arrival_at
     FROM trip_tickets
     WHERE is_active = 1 AND status_id IN ($placeholders)
     ORDER BY ticket_id"
);
$st->execute($active);
$tickets = $st->fetchAll();

$computed = 0;
$skipped  = 0;
$failed   = 0;

foreach ($tickets as $t) {
    $tid = (int) $t['ticket_id'];

    // Outbound leg
    $hasOut = $pdo->prepare('SELECT 1 FROM trip_routes WHERE ticket_id = ? AND leg = ?');
    $hasOut->execute([$tid, RouteService::LEG_OUTBOUND]);
    if (!$hasOut->fetch()) {
        try {
            $r = RouteService::computeLeg($pdo, $tid, RouteService::LEG_OUTBOUND);
            if ($r) { $computed++; echo "  #{$tid} outbound: {$r['distance_m']}m / {$r['duration_sec']}s\n"; }
            else    { $skipped++;  echo "  #{$tid} outbound: skipped (no coords)\n"; }
        } catch (Throwable $e) { $failed++; echo "  #{$tid} outbound: FAILED {$e->getMessage()}\n"; }
    } else { $skipped++; }

    // Return leg (only if trip reached destination or is completed)
    if ((int) $t['status_id'] === TripState::COMPLETED || !empty($t['dest_arrival_at'])) {
        $hasRet = $pdo->prepare('SELECT 1 FROM trip_routes WHERE ticket_id = ? AND leg = ?');
        $hasRet->execute([$tid, RouteService::LEG_RETURN]);
        if (!$hasRet->fetch()) {
            try {
                $r = RouteService::computeLeg($pdo, $tid, RouteService::LEG_RETURN);
                if ($r) { $computed++; echo "  #{$tid} return:   {$r['distance_m']}m / {$r['duration_sec']}s\n"; }
                else    { $skipped++;  echo "  #{$tid} return:   skipped\n"; }
            } catch (Throwable $e) { $failed++; echo "  #{$tid} return:   FAILED {$e->getMessage()}\n"; }
        } else { $skipped++; }
    }
}

echo "\nDone: $computed computed, $skipped skipped/already-exists, $failed failed\n";
