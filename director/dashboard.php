<?php
declare(strict_types=1);
/**
 * director/dashboard.php — Supervising Administrative Officer dashboard with
 * summary stats, pie chart, and the pending-approval queue.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [2]);
$ACTIVE = BASE_URL . 'director/dashboard.php';
$PAGE_TITLE = 'SAO Dashboard';
$ENABLE_LIVE = true;

/* ── Status counts (for pie chart) + KPI quick stats ── */
require_once dirname(__DIR__) . '/partials/dashboard_stats.php';

$vehicleCount  = (int) $pdo->query('SELECT COUNT(*) FROM vehicle WHERE is_active = 1')->fetchColumn();
$pendingCount = $counts[TripState::PENDING]['count'] ?? 0;

/* ── Tickets to approve (PENDING) ── */
$recentPending = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.surname, t.middle_initial, t.suffix, t.submitted_at, t.status_id, r.status_name
     FROM trip_tickets t JOIN request_status r ON r.status_id = t.status_id
     WHERE t.status_id = " . TripState::PENDING . " AND t.is_active = 1
     ORDER BY t.submitted_at DESC LIMIT 10"
)->fetchAll();

$needApproval = $counts[TripState::PENDING]['count'] ?? 0;

$fmt = static fn(?string $d): string => $d ? date('M j, g:i A', strtotime($d)) : '—';
$chartData   = json_encode(array_values(array_map(fn($c) => $c['count'], $counts)));
$chartLabels = json_encode(array_values(array_map(fn($c) => $c['name'], $counts)));
$chartColors = json_encode(['#9ca3af','#f59e0b','#3b82f6','#22c55e','#a855f7','#06b6d4','#10b981','#ef4444','#f97316','#6366f1']);
$PAGE_SCRIPTS = ['director_dashboard.js', 'calendar.js', 'table_sort.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card p-3.5" data-skeleton-page>
  <div class="flex items-center gap-2.5 mb-3.5">
    <h2 class="text-lg">SAO Dashboard</h2>
    <?php if ($needApproval > 0): ?>
      <a class="badge badge-pending" href="<?= BASE_URL ?>director/approvals.php"><?= $needApproval ?> awaiting approval</a>
    <?php endif; ?>
  </div>

  <!-- Summary stat cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-6 gap-2.5 mb-5 stagger-in">
    <div class="stat-card">
      <div class="stat-card-label">Total Drivers</div>
      <div class="stat-card-value"><?= $driverCount ?></div>
    </div>
    <a class="stat-card" href="<?= BASE_URL ?>director/vehicles.php">
      <div class="stat-card-label">Total Vehicles</div>
      <div class="stat-card-value"><?= $vehicleCount ?></div>
    </a>
    <a class="stat-card" href="<?= BASE_URL ?>director/trips.php">
      <div class="stat-card-label">Total Trips</div>
      <div class="stat-card-value"><?= $tripCount ?></div>
    </a>
    <a class="stat-card" href="<?= BASE_URL ?>director/approvals.php">
      <div class="stat-card-label">Awaiting Approval</div>
      <div class="stat-card-value"><?= $pendingCount ?></div>
    </a>
    <a class="stat-card" href="<?= BASE_URL ?>director/reports.php">
      <div class="stat-card-label">Avg Approval Time</div>
      <div class="stat-card-value"><?= $kpiApproval > 0 ? number_format($kpiApproval, 1) . ' h' : '—' ?></div>
    </a>
    <a class="stat-card" href="<?= BASE_URL ?>director/reports.php">
      <div class="stat-card-label">Completion Rate</div>
      <div class="stat-card-value"><?= $kpiCompletion > 0 ? number_format($kpiCompletion, 1) . '%' : '—' ?></div>
    </a>
  </div>

  <!-- Pie chart + Tickets to Approve -->
  <div class="flex flex-wrap gap-3.5">
    <!-- Pie chart -->
    <div class="content-card p-3.5 flex-1 min-w-80">
      <h3 class="text-sm uppercase tracking-wide text-text-muted mb-3">Trip Requests by Status</h3>
      <?php if ($tripCount > 0): ?>
        <div class="flex items-center justify-center">
          <canvas id="statusPieChart" data-chart='<?= htmlspecialchars($chartData, ENT_QUOTES) ?>' data-labels='<?= htmlspecialchars($chartLabels, ENT_QUOTES) ?>' data-colors='<?= htmlspecialchars($chartColors, ENT_QUOTES) ?>' style="max-width:360px;max-height:320px;"></canvas>
        </div>
      <?php else: ?>
        <p class="text-text-muted text-sm m-0">Nothing to show</p>
      <?php endif; ?>
    </div>

    <!-- Tickets to Approve -->
    <div class="content-card flex-1 min-w-80">
      <div class="card-head">
        <span>Tickets to Approve</span>
        <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>director/approvals.php">View All</a>
      </div>
      <div class="overflow-x-auto">
        <table class="data-table" data-sortable>
          <thead><tr><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Status</th><th class="whitespace-nowrap" data-sort="submitted">Submitted</th><th class="whitespace-nowrap"></th></tr></thead>
          <tbody>
          <?php if (!$recentPending): ?>
            <tr><td colspan="5" class="text-center p-3.5 text-text-muted">Nothing awaiting approval right now.</td></tr>
          <?php else: ?>
            <?php foreach ($recentPending as $r): ?>
            <tr>
              <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) ($r['dtt_number'] ?: '#' . $r['ticket_id'])) ?></strong></td>
              <?php $paxName = static function (array $t): string { $first = trim((string) ($t['passenger_name'] ?? '')); $mi = strtoupper(trim((string) ($t['middle_initial'] ?? ''))); $sur = trim((string) ($t['surname'] ?? '')); $suf = trim((string) ($t['suffix'] ?? '')); $name = $sur ? $sur . ', ' . $first : $first; if ($mi) $name .= ' ' . $mi . '.'; if ($suf) $name .= ' ' . $suf; return $name; }; ?>
              <td class="whitespace-nowrap"><?= htmlspecialchars($paxName($r)) ?></td>
              <td class="whitespace-nowrap"><span class="badge badge-<?= strtolower(str_replace('_', '-', $r['status_name'])) ?>"><?= htmlspecialchars($r['status_name']) ?></span></td>
              <td class="whitespace-nowrap" data-sort="<?= htmlspecialchars((string) $r['submitted_at']) ?>"><?= $fmt($r['submitted_at']) ?></td>
              <td class="whitespace-nowrap"><a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $r['ticket_id'] ?>">Open</a></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php require_once dirname(__DIR__) . '/partials/calendar.php'; ?>
</div>
<script src="<?= BASE_URL ?>assets/js/vendor/chart.umd.min.js"></script>
<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
