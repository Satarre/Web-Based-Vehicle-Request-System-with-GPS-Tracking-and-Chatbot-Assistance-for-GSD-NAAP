<?php
declare(strict_types=1);
/**
 * mis/reports.php — Chart.js operational reports.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1]);
$ACTIVE = BASE_URL . 'mis/reports.php';
$PAGE_TITLE = 'Reports';

$monthly = $pdo->query(
    "SELECT DATE_FORMAT(scheduled_departure, '%Y-%m') ym, COUNT(*) c
     FROM trip_tickets
     WHERE is_active = 1 AND status_id BETWEEN 2 AND 11 AND scheduled_departure IS NOT NULL
       AND scheduled_departure >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 5 MONTH)
     GROUP BY ym ORDER BY ym"
)->fetchAll();

$perVehicle = $pdo->query(
    'SELECT v.plate_number,
            SUM(CASE WHEN t.status_id IN (6,7,8,9,10,11) THEN 1 ELSE 0 END) AS trips,
            COALESCE(SUM(t.distance_odometer), 0) AS km,
            COALESCE(SUM(t.fuel_used), 0) AS fuel,
            ROUND(COALESCE(SUM(t.distance_odometer), 0) / NULLIF(SUM(t.fuel_used), 0), 1) AS kmpl
     FROM vehicle v LEFT JOIN trip_tickets t ON t.vehicle_id = v.vehicle_id AND t.is_active = 1
     WHERE v.is_active = 1 GROUP BY v.vehicle_id ORDER BY trips DESC'
)->fetchAll();

/* ── Time-based KPIs (computed at query time) ── */
$kpiApproval = (float) $pdo->query(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, submitted_at, approved_at)), 0) / 60
     FROM trip_tickets WHERE is_active = 1 AND submitted_at IS NOT NULL AND approved_at IS NOT NULL'
)->fetchColumn();
$kpiTurnaround = (float) $pdo->query(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, submitted_at, certified_at)), 0)
     FROM trip_tickets WHERE is_active = 1 AND submitted_at IS NOT NULL AND certified_at IS NOT NULL'
)->fetchColumn();
$kpiTripDur = (float) $pdo->query(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, actual_departure_at, return_at)), 0) / 60
     FROM trip_tickets WHERE is_active = 1 AND actual_departure_at IS NOT NULL AND return_at IS NOT NULL'
)->fetchColumn();
$kpiCompletion = (float) $pdo->query(
    'SELECT 100 * SUM(status_id = ' . TripState::COMPLETED . ')
            / NULLIF(SUM(status_id IN (' . implode(',', [TripState::APPROVED, TripState::DISPATCHED, TripState::ON_GOING, TripState::COMPLETED]) . ')), 0)
     FROM trip_tickets WHERE is_active = 1'
)->fetchColumn();
$kpiUtilHours = (float) $pdo->query(
    'SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, actual_departure_at, return_at)), 0) / 60
     FROM trip_tickets WHERE is_active = 1 AND actual_departure_at IS NOT NULL AND return_at IS NOT NULL'
)->fetchColumn();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$chartData = json_encode([
    'labels' => array_map(static fn($r) => $r['ym'], $monthly),
    'counts' => array_map(static fn($r) => (int) $r['c'], $monthly),
]);
?>
<div class="flex items-center gap-3 mb-3.5"><h1 class="text-lg">Operational Reports</h1></div>

<!-- Time-based KPIs -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-5 gap-2.5 mb-3.5 stagger-in">
  <div class="stat-card">
    <div class="stat-card-label">Avg Approval Time</div>
    <div class="stat-card-value"><?= $kpiApproval > 0 ? number_format($kpiApproval, 1) . ' h' : '—' ?></div>
    <div class="stat-card-sub">submit → approve</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Avg Turnaround Time</div>
    <div class="stat-card-value"><?= $kpiTurnaround > 0 ? number_format($kpiTurnaround, 1) . ' h' : '—' ?></div>
    <div class="stat-card-sub">submit → complete</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Avg Trip Duration</div>
    <div class="stat-card-value"><?= $kpiTripDur > 0 ? number_format($kpiTripDur, 1) . ' h' : '—' ?></div>
    <div class="stat-card-sub">depart → return</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Completion Rate</div>
    <div class="stat-card-value"><?= $kpiCompletion > 0 ? number_format($kpiCompletion, 1) . '%' : '—' ?></div>
    <div class="stat-card-sub">completed / pipeline</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Vehicle Usage</div>
    <div class="stat-card-value"><?= $kpiUtilHours > 0 ? number_format($kpiUtilHours, 1) . ' h' : '—' ?></div>
    <div class="stat-card-sub">active-trip hours</div>
  </div>
</div>

<div class="chart-grid mb-3.5">
  <div class="chart-card">
    <h3 class="text-sm uppercase tracking-wide text-text-muted mb-3">Tickets per Month (last 6)</h3>
    <canvas id="chMonthly" height="180" data-chart='<?= htmlspecialchars($chartData) ?>'></canvas>
  </div>
  <div class="content-card">
    <div class="card-head"><span>Trips &amp; Distance per Vehicle</span><span class="text-text-muted font-normal text-base"><?= count($perVehicle) ?> vehicles</span></div>
    <div class="max-h-66 overflow-y-auto overflow-x-auto">
    <table class="data-table" data-pager>
      <thead><tr><th class="text-left whitespace-nowrap">Vehicle</th><th class="text-right whitespace-nowrap">Trips</th><th class="text-right whitespace-nowrap">Distance (km)</th><th class="text-right whitespace-nowrap">Fuel (L)</th><th class="text-right whitespace-nowrap">Km/L</th></tr></thead>
      <tbody>
      <?php foreach ($perVehicle as $v): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) $v['plate_number']) ?></strong></td>
          <td class="text-right whitespace-nowrap"><?= (int) $v['trips'] ?></td>
          <td class="text-right whitespace-nowrap"><?= number_format((float) $v['km'], 1) ?></td>
          <td class="text-right whitespace-nowrap"><?= number_format((float) $v['fuel'], 2) ?></td>
          <td class="text-right whitespace-nowrap"><?= $v['kmpl'] !== null ? number_format((float) $v['kmpl'], 1) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<script src="<?= BASE_URL ?>assets/js/vendor/chart.umd.min.js"></script>
<?php
$PAGE_SCRIPTS = ['reports.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
