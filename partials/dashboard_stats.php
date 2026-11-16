<?php
/**
 * partials/dashboard_stats.php — shared dashboard queries.
 * Expects $pdo. Sets $counts, $tripCount, $driverCount, $kpiApproval, $kpiCompletion.
 */
TripGuard::autoRejectPastDue($pdo);
$counts = [];
$st = $pdo->query('SELECT status_id, status_name FROM request_status WHERE is_active = 1 ORDER BY status_id');
foreach ($st->fetchAll() as $r) {
    $counts[(int) $r['status_id']] = ['name' => $r['status_name'], 'count' => 0];
}
$q = $pdo->query('SELECT status_id, COUNT(*) AS c FROM trip_tickets WHERE is_active = 1 GROUP BY status_id');
foreach ($q->fetchAll() as $r) {
    $id = (int) $r['status_id'];
    if (isset($counts[$id])) { $counts[$id]['count'] = (int) $r['c']; }
}
$tripCount = array_sum(array_column($counts, 'count'));

$driverCount = (int) $pdo->query('SELECT COUNT(*) FROM account WHERE role_id = 4 AND is_active = 1')->fetchColumn();

$kpiApproval = (float) $pdo->query(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, submitted_at, approved_at)), 0) / 60
     FROM trip_tickets WHERE is_active = 1 AND submitted_at IS NOT NULL AND approved_at IS NOT NULL'
)->fetchColumn();
$kpiCompletion = (float) $pdo->query(
    'SELECT 100 * SUM(status_id = ' . TripState::COMPLETED . ')
            / NULLIF(SUM(status_id IN (' . implode(',', [TripState::APPROVED, TripState::DISPATCHED, TripState::ON_GOING, TripState::COMPLETED]) . ')), 0)
     FROM trip_tickets WHERE is_active = 1'
)->fetchColumn();