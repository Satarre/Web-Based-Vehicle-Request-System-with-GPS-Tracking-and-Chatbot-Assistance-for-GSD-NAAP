<?php
declare(strict_types=1);
/**
 * staff/assignments.php — assign driver + vehicle to APPROVED requests (Phase 1).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [3]);
$ACTIVE = BASE_URL . 'staff/assignments.php';
$PAGE_TITLE = 'Assignments';

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
$toLocal = static fn(?string $d): string => $d ? substr(str_replace(' ', ' ', $d), 0, 16) : '';

$queue = $pdo->query(
    "SELECT t.ticket_id, t.passenger_name, t.place_to_visit, t.number_of_passengers,
            t.scheduled_departure, t.scheduled_return, c.full_name AS requester
     FROM trip_tickets t LEFT JOIN account c ON c.account_id = t.created_by
     WHERE t.status_id = " . TripState::APPROVED . " AND t.is_active = 1
     ORDER BY t.approved_at ASC"
)->fetchAll();

$assigned = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name,
            t.scheduled_departure, t.scheduled_return,
            v.plate_number, d.full_name AS driver_name
     FROM trip_tickets t
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     LEFT JOIN account d ON d.account_id = t.driver_id
     WHERE t.status_id = " . TripState::DISPATCHED . " AND t.is_active = 1
     ORDER BY t.assigned_at ASC"
)->fetchAll();

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

$openId = (int) ($_GET['ticket_id'] ?? 0);

$PAGE_SCRIPTS = ['assignments.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<?php
$c = [
    'title' => 'Approved — Awaiting Assignment',
    'sub' => count($queue),
    'id' => 'tblQueue',
    'columns' => ['Approved', 'Requester', 'Passenger', 'Destination', 'Requested Schedule', 'Pax', ''],
    'rows' => array_map(function ($t) use ($fmt, $toLocal) {
        return [
            $fmt($t['scheduled_departure']),
            htmlspecialchars((string) $t['requester']),
            htmlspecialchars($t['passenger_name']),
            htmlspecialchars(mb_strimwidth($t['place_to_visit'], 0, 30, '…')),
            $fmt($t['scheduled_departure']) . ' → ' . $fmt($t['scheduled_return']),
            (string) (int) $t['number_of_passengers'],
            '<span class="whitespace-nowrap"><a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a> <button class="btn btn-sm btn-primary" data-assign="' . $t['ticket_id'] . '" data-dep="' . $toLocal($t['scheduled_departure']) . '" data-ret="' . $toLocal($t['scheduled_return']) . '">Assign…</button></span>',
        ];
    }, $queue),
    'empty' => 'Nothing awaiting assignment.',
];
?>
<div style="margin-bottom:14px">
<?php include __DIR__ . '/../partials/ui/table.php'; ?>
</div>

<?php
$c = [
    'title' => 'Dispatched — Awaiting Trip Start',
    'id' => 'tblAssigned',
    'columns' => ['DTT', 'Passenger', 'Driver', 'Vehicle', 'Schedule', ''],
    'rows' => array_map(function ($t) use ($fmt) {
        return [
            '<strong>' . htmlspecialchars((string) $t['dtt_number']) . '</strong>',
            htmlspecialchars($t['passenger_name']),
            htmlspecialchars((string) $t['driver_name']),
            htmlspecialchars((string) $t['plate_number']),
            $fmt($t['scheduled_departure']) . ' → ' . $fmt($t['scheduled_return']),
            '<a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a>',
        ];
    }, $assigned),
    'empty' => 'None dispatched yet.',
];
include __DIR__ . '/../partials/ui/table.php';
?>

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

<?php if ($openId): ?><input type="hidden" id="autoOpenTicket" value="<?= $openId ?>"><?php endif; ?>

<?php
$PAGE_SCRIPTS = array_unique(array_merge($PAGE_SCRIPTS ?? [], ['assignments.js']));
require_once dirname(__DIR__) . '/partials/layout_foot.php';
