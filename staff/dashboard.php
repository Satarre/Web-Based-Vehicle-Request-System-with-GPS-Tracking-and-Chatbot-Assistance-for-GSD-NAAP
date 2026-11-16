<?php
declare(strict_types=1);
/**
 * staff/dashboard.php — operations overview for GSD Dispatcher with stats,
 * approved tickets awaiting dispatch, and dispatched trips.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [3]);
$ACTIVE = BASE_URL . 'staff/dashboard.php';
$PAGE_TITLE = 'Dashboard';
$ENABLE_LIVE = true;

/* ── Status counts + KPI quick stats ── */
require_once dirname(__DIR__) . '/partials/dashboard_stats.php';

$assignCount = $counts[TripState::APPROVED]['count'] ?? 0;
$openFlags   = (int) $pdo->query('SELECT COUNT(*) FROM trip_flags WHERE resolved_at IS NULL')->fetchColumn();

$fmt = static fn(?string $d): string => $d ? date('M j, g:i A', strtotime($d)) : '—';
$toLocal = static fn(?string $d): string => $d ? substr(str_replace(' ', ' ', $d), 0, 16) : '';

/* ── Approved tickets awaiting driver/vehicle assignment ── */
$assignTickets = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.surname, t.middle_initial, t.suffix, t.submitted_at,
            t.place_to_visit, t.place_to_visit_name,
            t.scheduled_departure, t.scheduled_return
     FROM trip_tickets t
     WHERE t.status_id = " . TripState::APPROVED . " AND t.is_active = 1
     ORDER BY t.submitted_at DESC LIMIT 10"
)->fetchAll();

/* ── Vehicles & drivers for assign modal ── */
$vehicles = $pdo->query(
    "SELECT v.vehicle_id, v.plate_number, v.model_name, v.brand, v.capacity, vt.type_name,
            (SELECT COUNT(*) FROM gps_devices g WHERE g.vehicle_id = v.vehicle_id AND g.is_active = 1) > 0 AS paired
     FROM vehicle v LEFT JOIN vehicle_types vt ON vt.type_id = v.type_id
     WHERE v.is_available = 1 AND v.is_active = 1
     ORDER BY v.plate_number"
)->fetchAll();

$drivers = $pdo->query(
    "SELECT account_id, full_name FROM account WHERE role_id = 4 AND is_active = 1 ORDER BY full_name"
)->fetchAll();

/* ── Busy windows (DISPATCHED/ON_GOING) for in-modal selection filtering ── */
$busyWindows = $pdo->query(
    "SELECT vehicle_id, driver_id, scheduled_departure, scheduled_return
     FROM trip_tickets
     WHERE is_active = 1
       AND status_id IN (" . TripState::DISPATCHED . ", " . TripState::ON_GOING . ")
       AND scheduled_departure IS NOT NULL AND scheduled_return IS NOT NULL"
)->fetchAll();
$busyVeh = [];
$busyDrv = [];
foreach ($busyWindows as $bw) {
    if (!empty($bw['vehicle_id'])) { $busyVeh[(int) $bw['vehicle_id']][] = [$bw['scheduled_departure'], $bw['scheduled_return']]; }
    if (!empty($bw['driver_id']))  { $busyDrv[(int) $bw['driver_id']][]  = [$bw['scheduled_departure'], $bw['scheduled_return']]; }
}
$busyJson = static fn(array $w): string => htmlspecialchars(json_encode($w), ENT_QUOTES);

/* ── Dispatched tickets awaiting trip start ── */
$dispatchedTickets = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.surname, t.middle_initial, t.suffix,
            t.scheduled_departure, t.scheduled_return,
            v.plate_number, d.full_name AS driver_name
     FROM trip_tickets t
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     LEFT JOIN account d ON d.account_id = t.driver_id
     WHERE t.status_id = " . TripState::DISPATCHED . " AND t.is_active = 1
     ORDER BY t.assigned_at ASC LIMIT 10"
)->fetchAll();

$PAGE_SCRIPTS = ['assignments.js', 'calendar.js', 'table_sort.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
$paxName = static function (array $t): string {
    $first = trim((string) ($t['passenger_name'] ?? ''));
    $mi    = strtoupper(trim((string) ($t['middle_initial'] ?? '')));
    $sur   = trim((string) ($t['surname'] ?? ''));
    $suf   = trim((string) ($t['suffix'] ?? ''));
    $name  = $sur ? $sur . ', ' . $first : $first;
    if ($mi) $name .= ' ' . $mi . '.';
    if ($suf) $name .= ' ' . $suf;
    return $name;
};
?>
<div class="content-card p-3.5" data-skeleton-page>
<div class="page-header">
  <h1 class="text-xl">Dispatcher Dashboard</h1>
  <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>staff/assignments.php">Open Assignments</a>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-6 gap-2.5 mb-5 stagger-in">
  <div class="stat-card">
    <div class="stat-card-label">Total Drivers</div>
    <div class="stat-card-value"><?= $driverCount ?></div>
  </div>
  <a class="stat-card" href="<?= BASE_URL ?>staff/trips.php">
    <div class="stat-card-label">Total Trips</div>
    <div class="stat-card-value"><?= $tripCount ?></div>
  </a>
  <a class="stat-card" href="<?= BASE_URL ?>staff/assignments.php">
    <div class="stat-card-label">Awaiting Assignment</div>
    <div class="stat-card-value"><?= $assignCount ?></div>
  </a>
  <a class="stat-card" href="<?= BASE_URL ?>flags.php">
    <div class="stat-card-label">Open Flags</div>
    <div class="stat-card-value"><?= $openFlags ?></div>
    <div class="stat-card-sub"><?= $openFlags > 0 ? 'need resolution' : 'all clear' ?></div>
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

<!-- Assign driver -->
<div class="content-card mb-3.5">
  <div class="card-head">
    <span>Assign Driver &amp; Vehicle</span>
    <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>staff/trips.php?status=<?= TripState::APPROVED ?>">View All</a>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table" data-sortable>
      <thead><tr><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Destination</th><th class="whitespace-nowrap" data-sort="submitted">Submitted</th><th class="whitespace-nowrap">Schedule</th><th class="whitespace-nowrap"></th></tr></thead>
      <tbody>
      <?php if (!$assignTickets): ?>
        <tr><td colspan="6" class="text-center p-3.5 text-text-muted">No tickets awaiting assignment.</td></tr>
      <?php else: ?>
        <?php foreach ($assignTickets as $r): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) ($r['dtt_number'] ?: '#' . $r['ticket_id'])) ?></strong></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars($paxName($r)) ?></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($r['place_to_visit_name'] ?: $r['place_to_visit'])) ?></td>
          <td class="whitespace-nowrap" data-sort="<?= htmlspecialchars((string) $r['submitted_at']) ?>"><?= $fmt($r['submitted_at']) ?></td>
          <td class="whitespace-nowrap"><?= $fmt($r['scheduled_departure']) ?> → <?= $fmt($r['scheduled_return']) ?></td>
          <td class="whitespace-nowrap">
            <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $r['ticket_id'] ?>">View</a>
            <button class="btn btn-sm btn-primary" data-assign="<?= (int) $r['ticket_id'] ?>" data-dep="<?= htmlspecialchars($toLocal($r['scheduled_departure'])) ?>" data-ret="<?= htmlspecialchars($toLocal($r['scheduled_return'])) ?>">Assign</button>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Dispatched -->
<div class="content-card">
  <div class="card-head">
    <span>Dispatched — Awaiting Trip Start</span>
    <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>staff/trips.php?status=<?= TripState::DISPATCHED ?>">View All</a>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead><tr><th class="whitespace-nowrap">Ticket No.</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Driver</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Schedule</th><th class="whitespace-nowrap"></th></tr></thead>
      <tbody>
      <?php if (!$dispatchedTickets): ?>
        <tr><td colspan="6" class="text-center p-3.5 text-text-muted">No tickets dispatched yet.</td></tr>
      <?php else: ?>
        <?php foreach ($dispatchedTickets as $r): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) $r['dtt_number']) ?></strong></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars($paxName($r)) ?></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($r['driver_name'] ?? '—')) ?></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($r['plate_number'] ?? '—')) ?></td>
          <td class="whitespace-nowrap"><?= $fmt($r['scheduled_departure']) ?> → <?= $fmt($r['scheduled_return']) ?></td>
          <td class="whitespace-nowrap"><a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $r['ticket_id'] ?>">View</a></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
require_once dirname(__DIR__) . '/partials/calendar.php';
?>
</div>

<?php
$cancelCls = 'btn btn-ghost';
$okCls = 'btn btn-primary';
$inputCls = 'form-input';
$labelCls = 'form-label';
?>
<div class="modal-backdrop" id="assignModal">
  <div class="modal-body">
    <div class="modal-head">
      <span>Assign Driver &amp; Vehicle</span>
      <button class="modal-close" type="button" onclick="UI.modal('assignModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <input type="hidden" id="asTicketId">
      <div class="grid gap-3" style="grid-template-columns:1fr">
        <div>
          <label class="<?= $labelCls ?>">Vehicle *</label>
          <select id="asVehicle" class="<?= $inputCls ?>">
            <option value="">— select —</option>
            <?php foreach ($vehicles as $v): ?>
              <option value="<?= $v['vehicle_id'] ?>" data-paired="<?= (int) $v['paired'] ?>" data-busy="<?= $busyJson($busyVeh[(int) $v['vehicle_id']] ?? []) ?>">
                <?= htmlspecialchars("{$v['plate_number']} · {$v['brand']} {$v['model_name']} ({$v['capacity']} pax)") ?>
                <?= $v['paired'] ? '' : ' · NO GPS' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="<?= $labelCls ?>">Driver *</label>
          <select id="asDriver" class="<?= $inputCls ?>">
            <option value="">— select —</option>
            <?php foreach ($drivers as $d): ?>
              <option value="<?= $d['account_id'] ?>" data-busy="<?= $busyJson($busyDrv[(int) $d['account_id']] ?? []) ?>"><?= htmlspecialchars($d['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="<?= $labelCls ?>">Scheduled Departure *</label>
          <input id="asDep" type="datetime-local" class="<?= $inputCls ?>">
        </div>
        <div>
          <label class="<?= $labelCls ?>">Scheduled Return *</label>
          <input id="asRet" type="datetime-local" class="<?= $inputCls ?>">
        </div>
        <div id="asOverrideWrap" style="display:none">
          <div class="alert alert-warning text-sm font-semibold mb-2">
            Selected vehicle has no paired active GPS device. Dispatching without telemetry requires a reason and will raise a NO_TELEMETRY flag.
          </div>
          <label class="<?= $labelCls ?>">GPS Override Reason *</label>
          <textarea id="asOverride" rows="2" maxlength="500" class="<?= $inputCls ?>"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $cancelCls ?>" type="button" onclick="UI.modal('assignModal', false)">Cancel</button>
      <button class="<?= $okCls ?>" type="button" id="asOk">Assign &amp; Dispatch</button>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
