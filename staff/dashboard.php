<?php
declare(strict_types=1);
/**
 * staff/dashboard.php — operations overview for Staff with stats, awaiting approval, and assign driver tables.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [3]);
$ACTIVE = BASE_URL . 'staff/dashboard.php';
$PAGE_TITLE = 'Dashboard';

/* ── Status counts ── */
$counts = [];
$st = $pdo->query('SELECT status_id, status_name FROM request_status ORDER BY status_id');
foreach ($st->fetchAll() as $r) {
    $counts[(int) $r['status_id']] = ['name' => $r['status_name'], 'count' => 0];
}
$q = $pdo->query('SELECT status_id, COUNT(*) AS c FROM trip_tickets WHERE is_active = 1 GROUP BY status_id');
foreach ($q->fetchAll() as $r) {
    $id = (int) $r['status_id'];
    if (isset($counts[$id])) { $counts[$id]['count'] = (int) $r['c']; }
}
$tripCount = array_sum(array_column($counts, 'count'));

/* ── Summary stat cards ── */
$driverCount    = (int) $pdo->query('SELECT COUNT(*) FROM account WHERE role_id = 4 AND is_active = 1')->fetchColumn();
$awaitingCount  = $counts[TripState::VERIFIED]['count'] ?? 0;
$openFlags      = (int) $pdo->query('SELECT COUNT(*) FROM trip_flags WHERE resolved_at IS NULL')->fetchColumn();

$fmt = static fn(?string $d): string => $d ? date('M j, g:i A', strtotime($d)) : '—';
$toLocal = static fn(?string $d): string => $d ? substr(str_replace(' ', ' ', $d), 0, 16) : '';

/* ── Verified tickets awaiting GSD Staff approval ── */
$awaitingTickets = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.submitted_at,
            r.full_name AS requester_name
     FROM trip_tickets t
     LEFT JOIN account r ON r.account_id = t.created_by
     WHERE t.status_id = " . TripState::VERIFIED . " AND t.is_active = 1
     ORDER BY t.submitted_at DESC LIMIT 10"
)->fetchAll();

/* ── Approved tickets awaiting driver/vehicle assignment ── */
$assignTickets = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.submitted_at,
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

/* ── Dispatched tickets awaiting trip start ── */
$dispatchedTickets = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name,
            t.scheduled_departure, t.scheduled_return,
            v.plate_number, d.full_name AS driver_name
     FROM trip_tickets t
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     LEFT JOIN account d ON d.account_id = t.driver_id
     WHERE t.status_id = " . TripState::DISPATCHED . " AND t.is_active = 1
     ORDER BY t.assigned_at ASC LIMIT 10"
)->fetchAll();

$PAGE_SCRIPTS = ['assignments.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="page-header">
  <h1 class="text-xl">Staff Dashboard</h1>
  <a class="btn btn-sm" href="<?= BASE_URL ?>staff/assignments.php">Open Assignments</a>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mb-5">
  <div class="stat-card">
    <div class="stat-card-label">Total Drivers</div>
    <div class="stat-card-value"><?= $driverCount ?></div>
  </div>
  <a class="stat-card" href="<?= BASE_URL ?>staff/trips.php">
    <div class="stat-card-label">Total Trips</div>
    <div class="stat-card-value"><?= $tripCount ?></div>
  </a>
  <a class="stat-card" href="<?= BASE_URL ?>staff/trips.php?status=<?= TripState::VERIFIED ?>">
    <div class="stat-card-label">Awaiting Approval</div>
    <div class="stat-card-value"><?= $awaitingCount ?></div>
  </a>
  <a class="stat-card" href="<?= BASE_URL ?>flags.php">
    <div class="stat-card-label">Open Flags</div>
    <div class="stat-card-value"><?= $openFlags ?></div>
    <div class="stat-card-sub"><?= $openFlags > 0 ? 'need resolution' : 'all clear' ?></div>
  </a>
</div>

<!-- Awaiting Approval + Assign Driver -->
<div class="flex gap-3.5" style="flex-wrap:wrap;">
  <!-- Awaiting approval -->
  <div class="content-card" style="flex:1 1 340px;">
    <div class="card-head">
      <span>Awaiting Approval</span>
      <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>staff/trips.php?status=<?= TripState::VERIFIED ?>">View All</a>
    </div>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Requester</th><th class="whitespace-nowrap">Submitted</th><th class="whitespace-nowrap"></th></tr></thead>
        <tbody>
        <?php if (!$awaitingTickets): ?>
          <tr><td colspan="5" class="text-center p-3.5 text-text-muted">No tickets awaiting approval.</td></tr>
        <?php else: ?>
          <?php foreach ($awaitingTickets as $r): ?>
          <tr>
            <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) ($r['dtt_number'] ?: '#' . $r['ticket_id'])) ?></strong></td>
            <td class="whitespace-nowrap"><?= htmlspecialchars((string) $r['passenger_name']) ?></td>
            <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($r['requester_name'] ?? '—')) ?></td>
            <td class="whitespace-nowrap"><?= $fmt($r['submitted_at']) ?></td>
            <td class="whitespace-nowrap"><a class="btn btn-sm" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $r['ticket_id'] ?>">Open</a></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Assign driver -->
  <div class="content-card" style="flex:1 1 340px;">
    <div class="card-head">
      <span>Assign Driver</span>
      <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>staff/trips.php?status=<?= TripState::APPROVED ?>">View All</a>
    </div>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Destination</th><th class="whitespace-nowrap">Schedule</th><th class="whitespace-nowrap"></th></tr></thead>
        <tbody>
        <?php if (!$assignTickets): ?>
          <tr><td colspan="5" class="text-center p-3.5 text-text-muted">No tickets awaiting assignment.</td></tr>
        <?php else: ?>
          <?php foreach ($assignTickets as $r): ?>
          <tr>
            <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) ($r['dtt_number'] ?: '#' . $r['ticket_id'])) ?></strong></td>
            <td class="whitespace-nowrap"><?= htmlspecialchars((string) $r['passenger_name']) ?></td>
            <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($r['place_to_visit_name'] ?: $r['place_to_visit'])) ?></td>
            <td class="whitespace-nowrap"><?= $fmt($r['scheduled_departure']) ?> → <?= $fmt($r['scheduled_return']) ?></td>
            <td class="whitespace-nowrap">
              <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $r['ticket_id'] ?>">View</a>
              <button class="btn btn-sm btn-primary" data-assign="<?= (int) $r['ticket_id'] ?>" data-dep="<?= $toLocal($r['scheduled_departure']) ?>" data-ret="<?= $toLocal($r['scheduled_return']) ?>">Assign…</button>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Dispatched -->
<div class="content-card mt-3.5">
  <div class="card-head">
    <span>Dispatched — Awaiting Trip Start</span>
    <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>staff/trips.php?status=<?= TripState::DISPATCHED ?>">View All</a>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead><tr><th class="whitespace-nowrap">DTT</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Driver</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Schedule</th><th class="whitespace-nowrap"></th></tr></thead>
      <tbody>
      <?php if (!$dispatchedTickets): ?>
        <tr><td colspan="6" class="text-center p-3.5 text-text-muted">No tickets dispatched yet.</td></tr>
      <?php else: ?>
        <?php foreach ($dispatchedTickets as $r): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars((string) $r['dtt_number']) ?></strong></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars((string) $r['passenger_name']) ?></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($r['driver_name'] ?? '—')) ?></td>
          <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($r['plate_number'] ?? '—')) ?></td>
          <td class="whitespace-nowrap"><?= $fmt($r['scheduled_departure']) ?> → <?= $fmt($r['scheduled_return']) ?></td>
          <td class="whitespace-nowrap"><a class="btn btn-sm" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $r['ticket_id'] ?>">View</a></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
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
    <div class="px-[18px] py-4">
      <input type="hidden" id="asTicketId">
      <div class="grid gap-3" style="grid-template-columns:1fr">
        <div>
          <label class="<?= $labelCls ?>">Vehicle *</label>
          <select id="asVehicle" class="<?= $inputCls ?>">
            <option value="">— select —</option>
            <?php foreach ($vehicles as $v): ?>
              <option value="<?= $v['vehicle_id'] ?>" data-paired="<?= (int) $v['paired'] ?>">
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
              <option value="<?= $d['account_id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
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
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $cancelCls ?>" type="button" onclick="UI.modal('assignModal', false)">Cancel</button>
      <button class="<?= $okCls ?>" type="button" id="asOk">Assign &amp; Issue DTT</button>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
