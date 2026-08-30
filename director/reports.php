<?php
declare(strict_types=1);
/**
 * director/reports.php — operational overview + cost data + xlsx export.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [2, 3]);
$ACTIVE = BASE_URL . 'director/reports.php';
$PAGE_TITLE = 'Reports';

/* ── Stat cards ── */
$vehicleCount   = (int) $pdo->query('SELECT COUNT(*) FROM vehicle WHERE is_active = 1')->fetchColumn();
$tripCount      = (int) $pdo->query('SELECT COUNT(*) FROM trip_tickets WHERE is_active = 1')->fetchColumn();
$maintenanceCount = (int) $pdo->query('SELECT COUNT(*) FROM vehicle WHERE is_active = 0')->fetchColumn();
$fuelCostTotal  = (float) $pdo->query('SELECT COALESCE(SUM(fuel_cost), 0) FROM trip_tickets WHERE is_active = 1 AND fuel_cost IS NOT NULL')->fetchColumn();
$easytripTotal  = (float) $pdo->query('SELECT COALESCE(SUM(easytrip_cost), 0) FROM trip_tickets WHERE is_active = 1 AND easytrip_cost IS NOT NULL')->fetchColumn();
$autosweepTotal = (float) $pdo->query('SELECT COALESCE(SUM(autosweep_cost), 0) FROM trip_tickets WHERE is_active = 1 AND autosweep_cost IS NOT NULL')->fetchColumn();

/* ── Monthly bar chart ── */
$monthly = $pdo->query(
    "SELECT DATE_FORMAT(scheduled_departure, '%Y-%m') ym, COUNT(*) c
     FROM trip_tickets
     WHERE is_active = 1 AND status_id BETWEEN 2 AND 11 AND scheduled_departure IS NOT NULL
       AND scheduled_departure >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 11 MONTH)
     GROUP BY ym ORDER BY ym"
)->fetchAll();

/* ── Per-vehicle table (with costs) ── */
$perVehicle = $pdo->query(
    "SELECT v.plate_number,
            SUM(CASE WHEN t.status_id IN (6,7,8,9,10,11) THEN 1 ELSE 0 END) AS trips,
            COALESCE(SUM(t.distance_odometer), 0) AS km,
            COALESCE(SUM(t.fuel_used), 0) AS fuel,
            COALESCE(SUM(t.fuel_cost), 0) AS fuel_cost,
            COALESCE(SUM(t.easytrip_cost), 0) AS easytrip_cost,
            COALESCE(SUM(t.autosweep_cost), 0) AS autosweep_cost
     FROM vehicle v LEFT JOIN trip_tickets t ON t.vehicle_id = v.vehicle_id AND t.is_active = 1
     WHERE v.is_active = 1 GROUP BY v.vehicle_id ORDER BY trips DESC"
)->fetchAll();

/* ── Vehicle usage bar chart data ── */
$vehicleUsage = $pdo->query(
    "SELECT v.plate_number,
            SUM(CASE WHEN t.status_id IN (6,7,8,9,10,11) THEN 1 ELSE 0 END) AS trips
     FROM vehicle v LEFT JOIN trip_tickets t ON t.vehicle_id = v.vehicle_id AND t.is_active = 1
     WHERE v.is_active = 1 GROUP BY v.vehicle_id ORDER BY trips DESC"
)->fetchAll();

/* ── Trip log ── */
$BUCKETS = [
    'all'    => ['All', null],
    'open'   => ['Open', [2, 3, 4, 5]],
    'active' => ['Active', [6, 7, 8]],
    'done'   => ['Done', [9, 10, 11]],
];
$st = (string) ($_GET['status'] ?? 'all');
if (!isset($BUCKETS[$st])) { $st = 'all'; }
$q = trim((string) ($_GET['q'] ?? ''));

$where = 't.is_active = 1 AND t.status_id BETWEEN 2 AND 11';
$args = [];
if ($BUCKETS[$st][1] !== null) {
    $where .= ' AND t.status_id IN (' . implode(',', $BUCKETS[$st][1]) . ')';
}
if ($q !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $where .= ' AND (t.dtt_number LIKE :s1 OR t.passenger_name LIKE :s2 OR t.place_to_visit LIKE :s3 OR v.plate_number LIKE :s4 OR d.full_name LIKE :s5)';
    foreach (['s1', 's2', 's3', 's4', 's5'] as $k) { $args[$k] = $like; }
}
$logSt = $pdo->prepare(
    "SELECT t.ticket_id, t.dtt_number, r.status_name AS status,
            COALESCE(t.scheduled_departure, '') AS departure,
            COALESCE(t.scheduled_return, '') AS return_,
            t.passenger_name, t.place_to_visit,
            COALESCE(v.plate_number, '') AS plate,
            COALESCE(d.full_name, '') AS driver,
            COALESCE(t.distance_odometer, 0) AS distance_km,
            COALESCE(t.fuel_used, 0) AS fuel_l,
            t.fuel_cost, t.easytrip_cost, t.autosweep_cost
     FROM trip_tickets t
     JOIN request_status r ON r.status_id = t.status_id
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     LEFT JOIN account d ON d.account_id = t.driver_id
     WHERE $where
     ORDER BY t.scheduled_departure DESC"
);
$logSt->execute($args);
$log = $logSt->fetchAll();

$when = static function (?string $d): string {
    if ($d === null || $d === '') { return '—'; }
    return date('Y') === date('Y', strtotime($d))
        ? date('M j, g:i A', strtotime($d))
        : date('M j Y, g:i A', strtotime($d));
};
$peso = static fn($v): string => $v !== null && (float) $v > 0 ? '₱' . number_format((float) $v, 2) : '—';

$chartData = json_encode([
    'labels' => array_map(static fn($r) => $r['ym'], $monthly),
    'counts' => array_map(static fn($r) => (int) $r['c'], $monthly),
]);

$vehicleChartData = json_encode([
    'labels' => array_map(static fn($r) => $r['plate_number'], $vehicleUsage),
    'counts' => array_map(static fn($r) => (int) $r['trips'], $vehicleUsage),
]);

$export = json_encode([
    'tripLog' => [
        ['DTT', 'Status', 'Departure', 'Return', 'Passenger', 'Destination', 'Vehicle', 'Driver', 'Distance (km)', 'Fuel Used (L)', 'Fuel Cost (₱)', 'Easytrip (₱)', 'Autosweep (₱)'],
        ...array_map(static fn($r) => [
            $r['dtt_number'], $r['status'], $r['departure'], $r['return_'],
            $r['passenger_name'], $r['place_to_visit'], $r['plate'], $r['driver'],
            (float) $r['distance_km'], (float) $r['fuel_l'],
            (float) ($r['fuel_cost'] ?? 0), (float) ($r['easytrip_cost'] ?? 0), (float) ($r['autosweep_cost'] ?? 0),
        ], $log),
    ],
    'perVehicle' => [
        ['Vehicle', 'Trips', 'Distance (km)', 'Fuel Used (L)', 'Fuel Cost (₱)', 'Easytrip (₱)', 'Autosweep (₱)'],
        ...array_map(static fn($v) => [
            $v['plate_number'], (int) $v['trips'],
            round((float) $v['km'], 1), round((float) $v['fuel'], 2),
            round((float) $v['fuel_cost'], 2), round((float) $v['easytrip_cost'], 2), round((float) $v['autosweep_cost'], 2),
        ], $perVehicle),
    ],
], JSON_UNESCAPED_UNICODE);

$chipUrl = static fn(string $s): string => htmlspecialchars(http_build_query(['status' => $s, 'q' => $q]), ENT_QUOTES);

$PAGE_SCRIPTS = ['director_reports.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$searchCls = 'form-input';
$btnPrimarySm = 'btn btn-sm btn-primary';
$btnGhostSm = 'btn btn-sm btn-ghost';
?>
<div class="flex items-center gap-3 mb-3.5">
  <h1 class="text-lg">Reports</h1>
  <button class="<?= $btnPrimarySm ?>" id="btnExportXlsx" style="margin-left:auto">Export to Excel (.xlsx)</button>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2.5 mb-3.5">
  <div class="stat-card">
    <div class="stat-card-label">Total Vehicles</div>
    <div class="stat-card-value"><?= $vehicleCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Total Trips</div>
    <div class="stat-card-value"><?= $tripCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Maintenance</div>
    <div class="stat-card-value"><?= $maintenanceCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Fuel Cost</div>
    <div class="stat-card-value"><?= $peso($fuelCostTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Easytrip Cost</div>
    <div class="stat-card-value"><?= $peso($easytripTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Autosweep Cost</div>
    <div class="stat-card-value"><?= $peso($autosweepTotal) ?></div>
  </div>
</div>

<!-- Charts -->
<div class="grid gap-3.5 mb-3.5" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
  <div class="content-card p-3.5">
    <h3 class="text-sm uppercase tracking-wide text-text-muted mb-3">Tickets per Month (last 12)</h3>
    <?php if ($monthly): ?>
      <canvas id="chMonthly" height="180" data-chart='<?= htmlspecialchars($chartData, ENT_QUOTES) ?>'></canvas>
    <?php else: ?>
      <p class="text-text-muted text-sm m-0">Nothing to show</p>
    <?php endif; ?>
  </div>
  <div class="content-card p-3.5">
    <h3 class="text-sm uppercase tracking-wide text-text-muted mb-3">Vehicle Usage</h3>
    <?php if ($vehicleUsage): ?>
      <canvas id="chVehicleUsage" height="180" data-chart='<?= htmlspecialchars($vehicleChartData, ENT_QUOTES) ?>'></canvas>
    <?php else: ?>
      <p class="text-text-muted text-sm m-0">Nothing to show</p>
    <?php endif; ?>
  </div>
</div>

<!-- Vehicle trip reports -->
<div class="content-card mb-3.5">
  <div class="card-head"><span>Vehicle Trip Reports</span><span class="text-text-muted font-normal text-base"><?= count($perVehicle) ?> vehicles</span></div>
  <div class="max-h-66 overflow-y-auto">
    <table class="data-table" data-pager>
      <thead><tr><th class="whitespace-nowrap">Vehicle</th><th class="text-right whitespace-nowrap">Trips</th><th class="text-right whitespace-nowrap">Distance</th><th class="text-right whitespace-nowrap">Fuel (L)</th><th class="text-right whitespace-nowrap">Fuel (₱)</th><th class="text-right whitespace-nowrap">Easytrip (₱)</th><th class="text-right whitespace-nowrap">Autosweep (₱)</th></tr></thead>
      <tbody>
      <?php if (!$perVehicle): ?><tr><td colspan="7" class="text-center text-text-muted">No active vehicles.</td></tr><?php endif; ?>
      <?php foreach ($perVehicle as $v): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars($v['plate_number']) ?></strong></td>
          <td class="text-right whitespace-nowrap"><?= (int) $v['trips'] ?></td>
          <td class="text-right whitespace-nowrap"><?= number_format((float) $v['km'], 1) ?> km</td>
          <td class="text-right whitespace-nowrap"><?= number_format((float) $v['fuel'], 2) ?></td>
          <td class="text-right whitespace-nowrap"><?= $peso($v['fuel_cost']) ?></td>
          <td class="text-right whitespace-nowrap"><?= $peso($v['easytrip_cost']) ?></td>
          <td class="text-right whitespace-nowrap"><?= $peso($v['autosweep_cost']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Trip log -->
<div class="content-card">
  <div class="card-head">
    <span>Trip Log <span class="text-text-muted font-normal text-base"><?= count($log) ?></span></span>
    <div class="flex gap-1.5 items-center">
      <form method="get" class="flex gap-1.5 items-center">
        <input type="hidden" name="status" value="<?= htmlspecialchars($st) ?>">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="DTT, passenger, destination, vehicle…" class="<?= $searchCls ?>" style="width:250px">
        <button class="<?= $btnPrimarySm ?>">Search</button>
        <?php if ($q !== ''): ?><a class="<?= $btnGhostSm ?>" href="?status=<?= htmlspecialchars($st) ?>">Clear</a><?php endif; ?>
      </form>
      <?php foreach ($BUCKETS as $key => [$label]): ?>
        <a class="<?= $key === $st ? $btnPrimarySm : $btnGhostSm ?>" href="<?= $chipUrl($key) ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table" data-pager>
      <thead><tr><th class="whitespace-nowrap">DTT</th><th class="whitespace-nowrap">Status</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Destination</th><th class="whitespace-nowrap">Schedule</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Driver</th><th class="text-right whitespace-nowrap">Distance</th><th class="text-right whitespace-nowrap">Fuel (L)</th><th class="text-right whitespace-nowrap">Fuel (₱)</th><th class="text-right whitespace-nowrap">Easytrip (₱)</th><th class="text-right whitespace-nowrap">Autosweep (₱)</th></tr></thead>
      <tbody>
      <?php if (!$log): ?><tr><td colspan="12" class="text-center text-text-muted">No trips match this view.</td></tr><?php endif; ?>
      <?php foreach ($log as $t): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars($t['dtt_number'] ?? '—') ?></strong></td>
          <td class="whitespace-nowrap"><span class="badge badge-<?= strtolower(str_replace('_', '-', $t['status'])) ?>"><?= htmlspecialchars($t['status']) ?></span></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars((string) $t['passenger_name']) ?></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars(mb_strimwidth((string) $t['place_to_visit'], 0, 48, '…')) ?></td>
          <td class="whitespace-nowrap"><div><?= $when($t['departure'] ?: null) ?></div><div class="text-sm text-text-muted">RET · <?= $when($t['return_'] ?: null) ?></div></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars($t['plate'] ?: '—') ?></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars($t['driver'] ?: '—') ?></td>
          <td class="text-right whitespace-nowrap"><?= (float) $t['distance_km'] > 0 ? number_format((float) $t['distance_km'], 1) . ' km' : '—' ?></td>
          <td class="text-right whitespace-nowrap"><?= (float) $t['fuel_l'] > 0 ? number_format((float) $t['fuel_l'], 2) : '—' ?></td>
          <td class="text-right whitespace-nowrap"><?= $peso($t['fuel_cost']) ?></td>
          <td class="text-right whitespace-nowrap"><?= $peso($t['easytrip_cost']) ?></td>
          <td class="text-right whitespace-nowrap"><?= $peso($t['autosweep_cost']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<input type="hidden" id="exportData" value="<?= htmlspecialchars($export, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/vendor/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/vendor/xlsx.full.min.js"></script>
<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
