<?php
declare(strict_types=1);
/**
 * scripts/backfill_gps_tz.php — one-off migration for GPS timestamps that were
 * stored in UTC before config.php pinned the DB session to '+08:00'.
 *
 * Only run this on a host whose MySQL previously ran in UTC and whose
 * gps_devices.last_seen_at / gps_positions.recorded_at rows are therefore 8h
 * behind. Dry-run by default; pass --apply to commit.
 * Run: php scripts/backfill_gps_tz.php [--apply]
 */
require dirname(__DIR__) . '/config/config.php';

$apply = in_array('--apply', $argv, true);
$updates = [
    'gps_devices'   => 'last_seen_at',
    'gps_positions' => 'recorded_at',
];

foreach ($updates as $table => $col) {
    $rows = $pdo->query(
        "SELECT 1 FROM {$table} WHERE {$col} IS NOT NULL AND {$col} + INTERVAL 8 HOUR > NOW()"
    )->fetchAll();
    $n = count($rows);
    echo "{$table}.{$col}: {$n} row(s) within the future 8h window (candidate UTC rows)\n";
    if ($apply) {
        $pdo->exec("UPDATE {$table} SET {$col} = {$col} + INTERVAL 8 HOUR WHERE {$col} IS NOT NULL AND {$col} + INTERVAL 8 HOUR > NOW()");
        echo "  -> shifted +8h\n";
    }
}

if (!$apply) {
    echo "Dry-run. Re-run with --apply to shift.\n";
}