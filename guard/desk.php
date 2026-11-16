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

$inputCls = 'form-input';
$labelCls = 'form-label';

if (!$t):
    $cand = $pdo->query(
        'SELECT t.ticket_id, t.dtt_number, r.status_name, t.status_id, t.passenger_name, t.scheduled_departure,
                v.plate_number, d.full_name AS driver_name,
                (SELECT COUNT(*) FROM guard_verifications g WHERE g.ticket_id = t.ticket_id AND g.phase = "PRE_DEPARTURE") AS pre_done,
                (SELECT COUNT(*) FROM guard_verifications g WHERE g.ticket_id = t.ticket_id AND g.phase = "RETURN") AS ret_done
         FROM trip_tickets t
         JOIN request_status r ON r.status_id = t.status_id
         LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
         LEFT JOIN account d ON d.account_id = t.driver_id
         WHERE t.is_active = 1 AND t.status_id IN (' . TripState::DISPATCHED . ', ' . TripState::ON_GOING . ', ' . TripState::COMPLETED . ')
         ORDER BY t.scheduled_departure DESC LIMIT 50'
    )->fetchAll();
?>
<div class="content-card">
  <div class="card-head">
    <span>Trips at the Gate <span class="text-text-muted font-normal text-sm ml-2"><?= count($cand) ?> trip(s)</span></span>
  </div>
  <?php if (!$cand): ?>
    <div class="p-7 text-center text-text-muted">No trips currently at the gate.</div>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table class="data-table" id="tblGate" data-pager data-sortable>
    <thead><tr><th class="whitespace-nowrap" data-sort="ticket">Ticket</th><th class="whitespace-nowrap" data-sort="status">Status</th><th class="whitespace-nowrap" data-sort="vehicle">Vehicle</th><th class="whitespace-nowrap" data-sort="driver">Driver</th><th class="whitespace-nowrap" data-sort="departure">Departure</th><th class="whitespace-nowrap">Gate Checks</th><th class="whitespace-nowrap"></th></tr></thead>
    <tbody>
    <?php foreach ($cand as $c): ?>
      <tr>
        <td class="whitespace-nowrap" data-sort="<?= htmlspecialchars((string) $c['dtt_number']) ?>"><strong><?= $c['dtt_number'] ? htmlspecialchars($c['dtt_number']) : '#' . (int) $c['ticket_id'] ?></strong></td>
        <td class="whitespace-nowrap" data-sort="<?= (int) $c['status_id'] ?>"><span class="badge badge-<?= strtolower(str_replace('_', '-', $c['status_name'])) ?>"><?= htmlspecialchars($c['status_name']) ?></span></td>
        <td class="whitespace-nowrap" data-sort="<?= htmlspecialchars((string) ($c['plate_number'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['plate_number'] ?? '—')) ?></td>
        <td class="whitespace-nowrap" data-sort="<?= htmlspecialchars((string) ($c['driver_name'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['driver_name'] ?? '—')) ?></td>
        <td class="whitespace-nowrap" data-sort="<?= htmlspecialchars((string) ($c['scheduled_departure'] ?? '')) ?>"><?= $fmt($c['scheduled_departure']) ?></td>
        <td class="whitespace-nowrap">
          <span class="text-sm font-bold <?= $c['pre_done'] ? 'text-completed' : 'text-text-muted' ?>">PRE <?= $c['pre_done'] ? '✓' : '·' ?></span>
          <span class="text-sm font-bold ml-2 <?= $c['ret_done'] ? 'text-completed' : 'text-text-muted' ?>">RET <?= $c['ret_done'] ? '✓' : '·' ?></span>
        </td>
        <td class="whitespace-nowrap">
          <a class="btn btn-sm btn-ghost" href="?ticket_id=<?= (int) $c['ticket_id'] ?>">Verify</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/partials/calendar.php';
$PAGE_SCRIPTS = ['calendar.js', 'table_sort.js'];
?>

<?php else:
    $stId = (int) $t['status_id'];
    $suggestPhase = $stId === TripState::DISPATCHED ? 'PRE_DEPARTURE'
        : ($stId === TripState::ON_GOING ? 'RETURN' : 'PRE_DEPARTURE');
?>
<div class="content-card p-3.5 card-lg">
  <div class="flex items-center gap-2.5 mb-3.5 flex-wrap">
    <h2 class="text-lg font-bold"><?= htmlspecialchars((string) ($t['dtt_number'] ?: ('#' . (int) $t['ticket_id']))) ?></h2>
    <span class="badge badge-<?= strtolower(str_replace('_', '-', $t['status_name'])) ?>"><?= htmlspecialchars($t['status_name']) ?></span>
    <a class="btn btn-sm btn-ghost ml-auto" href="<?= BASE_URL ?>guard/desk.php">← Back to Desk</a>
  </div>

  <div class="detail-grid mb-4.5">
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Requester</div><strong><?= htmlspecialchars((string) ($t['requester_name'] ?? '—')) ?></strong></div>
    <div><div class="text-text-muted text-xs font-bold uppercase tracking-wide">Passenger</div><strong><?= htmlspecialchars($paxName($t)) ?></strong></div>
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
  <h3 class="text-sm uppercase tracking-widest text-text-muted mt-4.5 mb-2">Gate Checks</h3>
  <div class="detail-grid">
    <label class="field-cell checkbox-row"><input type="checkbox" id="g_checked_driver"> <span class="text-md">Driver identity checked</span></label>
    <label class="field-cell checkbox-row"><input type="checkbox" id="g_checked_vehicle"> <span class="text-md">Vehicle condition checked</span></label>
    <label class="field-cell checkbox-row"><input type="checkbox" id="g_checked_plate"> <span class="text-md">Plate / Ticket no. match</span></label>
    <label class="field-cell checkbox-row"><input type="checkbox" id="g_checked_passengers"> <span class="text-md">Passenger count checked</span></label>
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
