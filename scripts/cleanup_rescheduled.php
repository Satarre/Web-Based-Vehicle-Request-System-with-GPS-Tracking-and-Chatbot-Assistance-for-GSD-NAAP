<?php
declare(strict_types=1);
/**
 * scripts/cleanup_rescheduled.php — one-time data cleanup (CLI only).
 *
 * !!! DANGER: this DELETES RESCHEDULED tickets and RENUMBERS ticket_id and
 * dtt_number for every remaining ticket. Ticket numbers are permanent once a
 * request is submitted (NAAP-MN-YYYY-####). Do NOT run this against live data
 * or as part of the normal reschedule flow — it exists only to restore a demo
 * database. Reschedules keep the original number automatically.
 *
 * 1. Deletes all RESCHEDULED trip tickets (and their child rows + audit rows).
 * 2. Re-aligns remaining ticket_id values to be consecutive (1..N, by current
 *    ticket_id order), cascading to child tables and audit_trail.object_id.
 * 3. Re-numbers dtt_number to NAAP-MN-YYYY-0001..NNNN.
 * 4. Resets the trip_tickets AUTO_INCREMENT.
 *
 * Run: C:\xampp\php\php.exe scripts\cleanup_rescheduled.php
 * Web access denied via scripts/.htaccess.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once dirname(__DIR__) . '/config/config.php';

$CHILD_TABLES = ['trip_passengers', 'guard_verifications', 'trip_flags', 'gps_positions', 'geofence_events', 'feedback'];

$pdo->beginTransaction();
try {
    /* ── 1. Locate + delete RESCHEDULED tickets ─────────────────────── */
    $st = $pdo->query('SELECT ticket_id FROM trip_tickets WHERE status_id = ' . TripState::RESCHEDULED);
    $doomed = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$doomed) {
        echo "No RESCHEDULED tickets found.\n";
        $pdo->rollBack();
        exit(0);
    }
    $inList = implode(',', $doomed);
    foreach ($CHILD_TABLES as $table) {
        $n = $pdo->exec("DELETE FROM `$table` WHERE ticket_id IN ($inList)");
        echo "  children $table: {$n} deleted\n";
    }
    $objList = implode(',', array_map(static fn(int $id): string => "'trip_tickets:$id'", $doomed));
    $pdo->exec("DELETE FROM audit_trail WHERE object_id IN ($objList)");
    $pdo->exec("DELETE FROM trip_tickets WHERE ticket_id IN ($inList)");
    echo "RESCHEDULED tickets removed: " . implode(', ', $doomed) . "\n";

    /* ── 2. Renumber remaining tickets (ticket_id + dtt_number) ─────── */
    $rows = $pdo->query('SELECT ticket_id, dtt_number FROM trip_tickets ORDER BY ticket_id')->fetchAll();
    $year  = date('Y');
    $seq   = 0;
    $map   = [];
    $updT  = $pdo->prepare('UPDATE trip_tickets SET ticket_id = ?, dtt_number = ? WHERE ticket_id = ?');
    foreach ($rows as $r) {
        $seq++;
        $new = $seq;
        $old = (int) $r['ticket_id'];
        if ($old === $new) { continue; }
        $map[$old] = $new;
        $updT->execute([$new, sprintf('NAAP-MN-%s-%04d', $year, $seq), $old]);
    }

    /* ── 3. Cascade ticket_id into child tables + audit object strings ── */
    foreach ($map as $old => $new) {
        foreach ($CHILD_TABLES as $table) {
            $pdo->prepare("UPDATE `$table` SET ticket_id = ? WHERE ticket_id = ?")->execute([$new, $old]);
        }
        $pdo->prepare("UPDATE audit_trail SET object_id = CONCAT('trip_tickets:', ?) WHERE object_id = CONCAT('trip_tickets:', ?)")
            ->execute([$new, $old]);
    }
    echo "Re-aligned ticket_id: " . count($map) . " row(s) renumbered\n";
    $pdo->commit();
    echo "Cleanup committed.\n";

    /* ── 4. Reset AUTO_INCREMENT (DDL auto-commits; run after commit) ── */
    $max = (int) $pdo->query('SELECT COALESCE(MAX(ticket_id), 0) FROM trip_tickets')->fetchColumn();
    $pdo->exec('ALTER TABLE trip_tickets AUTO_INCREMENT = ' . ($max + 1));
    echo "AUTO_INCREMENT reset to " . ($max + 1) . "\n";

    echo "Done.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}