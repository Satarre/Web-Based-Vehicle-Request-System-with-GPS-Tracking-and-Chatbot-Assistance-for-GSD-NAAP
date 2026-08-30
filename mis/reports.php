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
            COALESCE(SUM(t.distance_odometer), 0) AS km
     FROM vehicle v LEFT JOIN trip_tickets t ON t.vehicle_id = v.vehicle_id AND t.is_active = 1
     WHERE v.is_active = 1 GROUP BY v.vehicle_id ORDER BY trips DESC'
)->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$chartData = json_encode([
    'labels' => array_map(static fn($r) => $r['ym'], $monthly),
    'counts' => array_map(static fn($r) => (int) $r['c'], $monthly),
]);
?>
<div class="flex items-center gap-3 mb-3.5"><h1 class="text-lg">Operational Reports</h1></div>

<div class="grid gap-3.5" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
  <div class="chart-card">
    <h3 class="text-sm uppercase tracking-wide text-text-muted mb-3">Tickets per Month (last 6)</h3>
    <canvas id="chMonthly" height="180" data-chart='<?= htmlspecialchars($chartData) ?>'></canvas>
  </div>
  <div class="content-card">
    <div class="card-head"><span>Trips &amp; Distance per Vehicle</span><span class="text-text-muted font-normal text-base"><?= count($perVehicle) ?> vehicles</span></div>
    <div class="max-h-66 overflow-y-auto">
    <table class="data-table" data-pager>
      <thead><tr><th class="text-left whitespace-nowrap">Vehicle</th><th class="text-right whitespace-nowrap">Trips</th><th class="text-right whitespace-nowrap">Distance (odometer)</th></tr></thead>
      <tbody>
      <?php foreach ($perVehicle as $v): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) $v['plate_number']) ?></strong></td>
          <td class="text-right whitespace-nowrap"><?= (int) $v['trips'] ?></td>
          <td class="text-right whitespace-nowrap"><?= number_format((float) $v['km'], 1) ?> km</td>
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
