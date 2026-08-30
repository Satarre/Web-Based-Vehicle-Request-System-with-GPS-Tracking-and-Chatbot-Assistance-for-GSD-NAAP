<?php
declare(strict_types=1);
/**
 * guard/desk.php — PRE_DEPARTURE / RETURN gate verification desk.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [5]);
$ACTIVE = BASE_URL . 'guard/desk.php';
$PAGE_TITLE = 'Departure / Return Desk';
$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';

$t = null;
$id = (int) ($_GET['ticket_id'] ?? 0);
if ($id > 0) {
    $st = $pdo->prepare(
        'SELECT t.*, r.status_name, v.plate_number, v.brand, v.model_name,
                d.full_name AS driver_name, a.full_name AS requester_name
         FROM trip_tickets t
         JOIN request_status r ON r.status_id = t.status_id
         LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
         LEFT JOIN account d ON d.account_id = t.driver_id
         LEFT JOIN account a ON a.account_id = t.created_by
         WHERE t.ticket_id = ? AND t.is_active = 1
           AND t.status_id IN (' . TripState::DISPATCHED . ', ' . TripState::ON_GOING . ', ' . TripState::COMPLETED . ')'
    );
    $st->execute([$id]);
    $t = $st->fetch();
}

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$inputCls = 'form-input';
$labelCls = 'form-label';

if (!$t):
    $cand = $pdo->query(
        'SELECT t.ticket_id, t.dtt_number, r.status_name, t.passenger_name, t.scheduled_departure,
                v.plate_number, d.full_name AS driver_name,
                (SELECT COUNT(*) FROM guard_verifications g WHERE g.ticket_id = t.ticket_id AND g.phase = "PRE_DEPARTURE") AS pre_done,
                (SELECT COUNT(*) FROM guard_verifications g WHERE g.ticket_id = t.ticket_id AND g.phase = "RETURN") AS ret_done
         FROM trip_tickets t
         JOIN request_status r ON r.status_id = t.status_id
         LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
         LEFT JOIN account d ON d.account_id = t.driver_id
         WHERE t.is_active = 1 AND t.status_id IN (' . TripState::DISPATCHED . ', ' . TripState::ON_GOING . ', ' . TripState::COMPLETED . ')
         ORDER BY t.scheduled_departure LIMIT 50'
    )->fetchAll();
?>
<div class="content-card">
  <div class="flex items-center gap-3 px-4 py-3 border-b border-border">
    <h2 class="text-lg font-bold">Trips at the Gate</h2>
    <span class="text-text-muted text-xs ml-auto"><?= count($cand) ?> trip(s)</span>
  </div>
  <?php if (!$cand): ?>
    <div class="p-7 text-center text-text-muted">No trips currently at the gate.</div>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table class="data-table" data-pager>
    <thead><tr><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Status</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Driver</th><th class="whitespace-nowrap">Departure</th><th class="whitespace-nowrap">Gate Checks</th><th class="whitespace-nowrap"></th></tr></thead>
    <tbody>
    <?php foreach ($cand as $c): ?>
      <tr>
        <td class="whitespace-nowrap"><strong><?= $c['dtt_number'] ? htmlspecialchars($c['dtt_number']) : '#' . (int) $c['ticket_id'] ?></strong></td>
        <td class="whitespace-nowrap"><span class="badge badge-<?= strtolower(str_replace('_', '-', $c['status_name'])) ?>"><?= htmlspecialchars($c['status_name']) ?></span></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($c['plate_number'] ?? '—')) ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($c['driver_name'] ?? '—')) ?></td>
        <td class="whitespace-nowrap"><?= $fmt($c['scheduled_departure']) ?></td>
        <td class="whitespace-nowrap">
          <span class="text-sm font-bold" style="color:<?= $c['pre_done'] ? 'var(--st-completed,#16A34A)' : 'var(--text-muted)' ?>">PRE <?= $c['pre_done'] ? '✓' : '·' ?></span>
          <span class="text-sm font-bold ml-2" style="color:<?= $c['ret_done'] ? 'var(--st-completed,#16A34A)' : 'var(--text-muted)' ?>">RET <?= $c['ret_done'] ? '✓' : '·' ?></span>
        </td>
        <td class="whitespace-nowrap">
          <?php if ((int) $c['status_id'] === TripState::DISPATCHED): ?>
            <button class="btn btn-sm btn-primary" data-start-trip="<?= (int) $c['ticket_id'] ?>">Start Trip</button>
          <?php else: ?>
            <a class="btn btn-sm btn-ghost" href="?ticket_id=<?= (int) $c['ticket_id'] ?>">Verify</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php else:
    $stId = (int) $t['status_id'];
    $suggestPhase = $stId === TripState::DISPATCHED ? 'PRE_DEPARTURE'
        : ($stId === TripState::ON_GOING ? 'RETURN' : 'PRE_DEPARTURE');
?>
<div class="content-card p-3.5" style="max-width:860px">
  <div class="flex items-center gap-2.5 mb-3.5 flex-wrap">
    <h2 class="text-lg font-bold"><?= $t['dtt_number'] ? 'DTT ' . htmlspecialchars($t['dtt_number']) : 'Request #' . (int) $t['ticket_id'] ?></h2>
    <span class="badge badge-<?= strtolower(str_replace('_', '-', $t['status_name'])) ?>"><?= htmlspecialchars($t['status_name']) ?></span>
    <?php if ($stId === TripState::DISPATCHED): ?>
      <button class="btn btn-sm btn-primary ml-auto" id="btnGuardStart">Start Trip</button>
    <?php endif; ?>
    <a class="btn btn-sm btn-ghost ml-auto" href="<?= BASE_URL ?>guard/desk.php">← Back to Desk</a>
  </div>

  <div class="detail-grid mb-4.5">
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Requester</div><strong><?= htmlspecialchars((string) ($t['requester_name'] ?? '—')) ?></strong></div>
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Passenger</div><strong><?= htmlspecialchars((string) $t['passenger_name']) ?></strong></div>
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Driver</div><strong><?= htmlspecialchars((string) ($t['driver_name'] ?? '—')) ?></strong></div>
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Vehicle</div><strong><?= $t['plate_number'] ? htmlspecialchars($t['plate_number'] . ' · ' . $t['brand'] . ' ' . $t['model_name']) : '—' ?></strong></div>
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Scheduled Departure</div><strong><?= $fmt($t['scheduled_departure']) ?></strong></div>
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Scheduled Return</div><strong><?= $fmt($t['scheduled_return']) ?></strong></div>
  </div>

  <form id="gvForm" autocomplete="off" onsubmit="return false">
  <input type="hidden" id="g_ticket_id" value="<?= (int) $t['ticket_id'] ?>">
  <div class="detail-grid">
    <div>
      <label class="<?= $labelCls ?>">Phase *</label>
      <select id="g_phase" class="<?= $inputCls ?>">
        <option value="PRE_DEPARTURE" <?= $suggestPhase === 'PRE_DEPARTURE' ? 'selected' : '' ?>>PRE_DEPARTURE</option>
        <option value="RETURN" <?= $suggestPhase === 'RETURN' ? 'selected' : '' ?>>RETURN</option>
      </select>
    </div>
    <div>
      <label class="<?= $labelCls ?>">Result</label>
      <select id="g_result" class="<?= $inputCls ?>"><option>VALIDATED</option><option>FLAGGED</option></select>
    </div>
  </div>
  <div class="flex gap-4.5 flex-wrap mt-3 text-base">
    <label class="flex items-center gap-1.5"><input type="checkbox" id="g_checked_driver"> Driver identity checked</label>
    <label class="flex items-center gap-1.5"><input type="checkbox" id="g_checked_vehicle"> Vehicle condition checked</label>
    <label class="flex items-center gap-1.5"><input type="checkbox" id="g_checked_plate"> Plate / DTT match</label>
    <label class="flex items-center gap-1.5"><input type="checkbox" id="g_checked_passengers"> Passenger count checked</label>
  </div>
  <div class="mt-3"><label class="<?= $labelCls ?>">Notes</label><textarea id="g_notes" rows="2" maxlength="500" class="<?= $inputCls ?>"></textarea></div>
  <div class="flex gap-2 mt-4">
    <button class="btn btn-primary" type="button" id="btnGvSubmit">Record Verification</button>
    <a class="btn btn-ghost" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $t['ticket_id'] ?>">Open Ticket</a>
  </div>
  </form>
</div>
<?php endif; ?>

<?php
if ($t) {
    $PAGE_SCRIPTS = ['guard_desk.js'];
}
require_once dirname(__DIR__) . '/partials/layout_foot.php';
