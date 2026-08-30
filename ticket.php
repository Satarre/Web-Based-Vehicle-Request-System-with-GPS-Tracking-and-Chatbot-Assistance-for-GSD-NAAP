<?php
declare(strict_types=1);
/**
 * ticket.php — shared read-only trip ticket view + role/status action bar.
 */
require_once __DIR__ . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);

$tid = (int) ($_GET['id'] ?? 0);
$st  = $pdo->prepare(
    'SELECT t.*, r.status_name,
            c.full_name AS creator_name,
            d.full_name AS driver_name,
            v.plate_number, v.model_name, v.brand,
            a.full_name AS approver_name,
            s.full_name AS staff_name,
            ct.full_name AS certifier_name
     FROM trip_tickets t
     JOIN request_status r ON r.status_id = t.status_id
     LEFT JOIN account c ON c.account_id = t.created_by
     LEFT JOIN account d ON d.account_id = t.driver_id
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
      LEFT JOIN account a ON a.account_id = t.approver_id
      LEFT JOIN account s ON s.account_id = t.assigned_by
      LEFT JOIN account ct ON ct.account_id = t.certified_by
     WHERE t.ticket_id = ? AND t.is_active = 1'
);
$st->execute([$tid]);
$t = $st->fetch();

$PAGE_TITLE = 'Trip Ticket';
if (!$t) {
    http_response_code(404);
    require_once __DIR__ . '/partials/layout_head.php';
    require_once __DIR__ . '/partials/shell_open.php';
    echo '<div class="content-card p-3.5">Ticket not found.</div>';
    require_once __DIR__ . '/partials/layout_foot.php';
    exit;
}
if (($USER['role_id'] === 6 && (int) $t['created_by'] !== $USER['id'])
    || ($USER['role_id'] === 4 && (int) $t['driver_id'] !== $USER['id'])) {
    http_response_code(403);
    echo '<div class="content-card p-3.5" style="margin:20px">You are not authorized to view this ticket.</div>';
    exit;
}

$flags = $pdo->prepare('SELECT flag_type, detail, raised_at FROM trip_flags WHERE ticket_id = ? AND resolved_at IS NULL ORDER BY raised_at');
$flags->execute([$tid]);
$flags = $flags->fetchAll();

$pax = $pdo->prepare('SELECT passenger_name FROM trip_passengers WHERE ticket_id = ? ORDER BY passenger_id');
$pax->execute([$tid]);
$pax = $pax->fetchAll(PDO::FETCH_COLUMN);

$gv = $pdo->prepare('SELECT g.phase, g.result, g.notes, g.verified_at, a.full_name AS guard_name
                     FROM guard_verifications g LEFT JOIN account a ON a.account_id = g.guard_id
                     WHERE g.ticket_id = ? ORDER BY g.verified_at DESC');
$gv->execute([$tid]);
$gv = $gv->fetchAll();

$fmt   = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
$orDash = static fn($v): string => ($v !== null && $v !== '') ? htmlspecialchars((string) $v) : '—';

$ctx        = ['user_id' => $USER['id'], 'role_id' => $USER['role_id']];
$canVerify  = TripState::can($t, TripState::VERIFIED, $ctx) === true;
$canApprove = TripState::can($t, TripState::APPROVED, $ctx) === true;
$canReject  = TripState::can($t, TripState::REJECTED, $ctx + ['has_reason' => true]) === true;
$canAssign  = TripState::can($t, TripState::DISPATCHED, $ctx) === true;
$canStart   = TripState::can($t, TripState::ON_GOING, $ctx) === true;
$canComplete = TripState::can($t, TripState::COMPLETED, $ctx) === true;
$isOnGoing  = (int) $t['status_id'] === TripState::ON_GOING;
$canResched = TripState::can($t, TripState::RESCHEDULED, $ctx) === true
           || ((int) $t['status_id'] === TripState::ON_GOING
               && ($USER['role_id'] === 3 || ($USER['role_id'] === 6 && (int) $t['created_by'] === $USER['id'])));
$canCancel  = TripState::can($t, TripState::CANCELLED, $ctx + ['has_reason' => (int) $t['status_id'] !== TripState::DRAFT]) === true;
$toLocal = static fn(?string $d): string => $d ? substr(str_replace(' ', 'T', (string) $d), 0, 16) : '';
$coord   = static fn(?string $lat, ?string $lon): string => ($lat !== null && $lon !== null)
    ? number_format((float) $lat, 5) . ', ' . number_format((float) $lon, 5) : '';

$labelCls = 'form-label';
$row = static function (string $label, string $value): string {
    return '<div><div class="text-xs font-bold uppercase tracking-wide text-text-muted mb-0.5">' . $label . '</div>'
        . '<div class="font-semibold">' . $value . '</div></div>';
};

$btnPrimary = 'btn btn-primary';
$btnGhost = 'btn btn-ghost';
$btnDanger = 'btn btn-danger';
$inputCls = 'form-input';

require_once __DIR__ . '/partials/layout_head.php';
require_once __DIR__ . '/partials/shell_open.php';
?>
<div class="flex items-center gap-3 mb-3.5">
  <a class="<?= $btnGhost ?>" href="javascript:history.back()">← Back</a>
</div>

<div class="content-card p-3.5 mb-3.5">
  <div class="flex items-center gap-3 flex-wrap mb-4">
    <h2 class="text-lg"><?= $t['dtt_number'] ? 'DTT ' . htmlspecialchars($t['dtt_number']) : 'Request #' . (int) $t['ticket_id'] ?></h2>
    <span class="badge badge-<?= strtolower(str_replace('_', '-', $t['status_name'])) ?>"><?= htmlspecialchars($t['status_name']) ?></span>
    <?php if ((int) $t['dispatched_without_gps'] === 1): ?>
      <span class="badge badge-rejected" title="Dispatched without GPS telemetry">NO GPS</span>
    <?php endif; ?>
    <div class="ml-auto flex gap-2 flex-wrap" id="actionBar">
      <?php if ($canVerify): ?><button class="<?= $btnPrimary ?>" id="btnVerify">Verify</button><?php endif; ?>
      <?php if ($canApprove): ?><button class="<?= $btnPrimary ?>" id="btnApprove">Approve</button><?php endif; ?>
      <?php if ($canReject): ?><button class="<?= $btnDanger ?>" id="btnRejectOpen">Reject…</button><?php endif; ?>
      <?php if ($canAssign): ?><a class="<?= $btnPrimary ?>" href="<?= BASE_URL ?>staff/assignments.php?ticket_id=<?= (int) $t['ticket_id'] ?>">Assign Driver &amp; Vehicle</a><?php endif; ?>
      <?php if ($canStart): ?><button class="<?= $btnPrimary ?>" id="btnStart">Start Trip…</button><?php endif; ?>
      <?php if ($isOnGoing): ?>
        <?php if (empty($t['dest_arrival_at'])): ?>
          <button class="<?= $btnGhost ?>" id="btnArrive">Arrived at Destination</button>
        <?php elseif (empty($t['dest_departure_at'])): ?>
          <button class="<?= $btnGhost ?>" id="btnLeave">Leave Destination</button>
        <?php elseif (empty($t['return_at'])): ?>
          <button class="<?= $btnGhost ?>" id="btnReturn">Return to Base</button>
        <?php else: ?>
          <a class="<?= $btnGhost ?>" href="<?= BASE_URL ?>driver/posttrip.php?id=<?= (int) $t['ticket_id'] ?>">Post-Trip Report</a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($canComplete): ?><button class="<?= $btnPrimary ?>" id="btnComplete">Complete Trip</button><?php endif; ?>
      <?php if ($canResched): ?><button class="<?= $btnGhost ?>" id="btnReschedOpen">Reschedule…</button><?php endif; ?>
      <?php if ($canCancel): ?><button class="<?= $btnDanger ?>" id="btnCancelOpen" data-status="<?= (int) $t['status_id'] ?>">Cancel Ticket</button><?php endif; ?>
    </div>
  </div>

  <h3 class="text-sm uppercase tracking-widest text-text-muted mb-2">Section A — Travel Order</h3>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5 mb-4">
    <?= $row('First Name', $orDash($t['passenger_name'])) ?>
    <?= $row('Middle Initial', $orDash($t['middle_initial'])) ?>
    <?= $row('Surname', $orDash($t['surname'])) ?>
    <?= $row('Suffix', $orDash($t['suffix'])) ?>
    <?= $row('ID Number', $orDash($t['id_number'])) ?>
    <?= $row('Type', $orDash($t['requester_type'])) ?>
    <?= $row('Email', $orDash($t['email'])) ?>
    <?= $row('Passengers', (string) (int) $t['number_of_passengers']) ?>
    <?= $row('Request Letter', $t['attachment_path']
        ? '<a class="text-accent-strong font-semibold underline" target="_blank" rel="noopener" href="' . BASE_URL . htmlspecialchars($t['attachment_path']) . '">View file</a>'
        : '—') ?>
    <div style="grid-column:span 2"><?= $row('Place to Visit', nl2br($orDash($t['place_to_visit']))) ?></div>
    <div style="grid-column:span 2"><?= $row('Purpose of Travel', nl2br($orDash($t['purpose']))) ?></div>
    <?= $row('Scheduled Departure', $fmt($t['scheduled_departure'])) ?>
    <?= $row('Scheduled Return', $fmt($t['scheduled_return'])) ?>
    <?= $row('Requested By', $orDash($t['creator_name'])) ?>
    <?= $row('Submitted At', $fmt($t['submitted_at'])) ?>
  </div>

  <?php if ($pax): ?>
  <div class="mb-4">
    <div class="text-sm font-bold uppercase tracking-wide text-text-muted mb-0.5">
      Passenger Manifest (<?= count($pax) ?>)
    </div>
    <div class="text-sm"><?= htmlspecialchars(implode(', ', $pax)) ?></div>
  </div>
  <?php endif; ?>

  <h3 class="text-sm uppercase tracking-widest text-text-muted mb-2">Approval &amp; Assignment</h3>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <?= $row('Approved By', $orDash($t['approver_name'])) ?>
    <?= $row('Approved At', $fmt($t['approved_at'])) ?>
    <?= $row('Assigned By', $orDash($t['staff_name'])) ?>
    <?= $row('Driver', $orDash($t['driver_name'])) ?>
    <?= $row('Vehicle', $t['vehicle_id'] ? htmlspecialchars(($t['plate_number'] ?? '') . ' · ' . ($t['brand'] ?? '') . ' ' . ($t['model_name'] ?? '')) : '—') ?>
    <?= $row('Assigned At', $fmt($t['assigned_at'])) ?>
  </div>

  <?php $hasExec = $t['actual_departure_at'] || $t['dest_arrival_at'] || $t['dest_departure_at'] || $t['return_at']; ?>
  <?php if ($hasExec): ?>
  <h3 class="text-sm uppercase tracking-widest text-text-muted mt-4 mb-2">Execution</h3>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <?php
    $exRow = static function (string $label, ?string $ts, ?string $lat, ?string $lon) use ($row, $fmt, $coord): string {
        $c = $coord($lat, $lon);
        return $row($label, $fmt($ts) . ($c !== '' ? ' <span class="text-text-muted">· GPS ' . $c . '</span>' : ''));
    };
    echo $exRow('Actual Departure', $t['actual_departure_at'], $t['departure_lat'], $t['departure_lon']);
    echo $exRow('Arrived at Destination', $t['dest_arrival_at'], $t['dest_arrival_lat'], $t['dest_arrival_lon']);
    echo $exRow('Left Destination', $t['dest_departure_at'], $t['dest_departure_lat'], $t['dest_departure_lon']);
    echo $exRow('Returned', $t['return_at'], $t['return_lat'], $t['return_lon']);
    ?>
  </div>
  <?php endif; ?>

  <?php if ($t['odometer_start'] !== null): ?>
  <h3 class="text-sm uppercase tracking-widest text-text-muted mt-4 mb-2">Section B — Driver's Post-Trip Report</h3>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <?= $row('Odometer Start', number_format((float) $t['odometer_start'], 1) . ' km') ?>
    <?= $row('Odometer End', $t['odometer_end'] !== null ? number_format((float) $t['odometer_end'], 1) . ' km' : '—') ?>
    <?= $row('Distance (official, odometer)', $t['distance_odometer'] !== null ? number_format((float) $t['distance_odometer'], 1) . ' km' : '—') ?>
    <?= $row('GPS Distance (supporting)', $t['gps_distance_km'] !== null ? number_format((float) $t['gps_distance_km'], 1) . ' km' : '—') ?>
    <?= $row('Fuel Type', $orDash($t['fuel_type'])) ?>
    <?= $row('Tank Balance at Start', $orDash($t['fuel_tank_balance_start'])) ?>
    <?= $row('Issued from Stock', $orDash($t['fuel_issued_stock'])) ?>
    <?= $row('Purchased During Trip', $orDash($t['fuel_purchased_trip'])) ?>
    <?= $row('Total Available', $orDash($t['fuel_total_available'])) ?>
    <?= $row('Fuel Used', $orDash($t['fuel_used'])) ?>
    <?= $row('Balance at End', $orDash($t['fuel_balance_end'])) ?>
    <?= $row('Gear Oil Used', $orDash($t['gear_oil_used'])) ?>
    <?= $row('Lubricant Oil Issued', $orDash($t['lubricant_oil_issued'])) ?>
    <?= $row('Grease Issued', $orDash($t['grease_issued'])) ?>
    <div style="grid-column:span 2"><?= $row('Remarks', nl2br($orDash($t['remarks']))) ?></div>
    <?= $row('Fuel Cost', $t['fuel_cost'] !== null ? '₱' . number_format((float) $t['fuel_cost'], 2) : '—') ?>
    <?= $row('Easytrip Cost', $t['easytrip_cost'] !== null ? '₱' . number_format((float) $t['easytrip_cost'], 2) : '—') ?>
    <?= $row('Autosweep Cost', $t['autosweep_cost'] !== null ? '₱' . number_format((float) $t['autosweep_cost'], 2) : '—') ?>
    <?php if ((int) $t['status_id'] >= TripState::COMPLETED): ?>
      <?= $row('Certified By', $orDash($t['certifier_name'])) ?>
      <?= $row('Certified At', $fmt($t['certified_at'])) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($gv): ?>
  <h3 class="text-sm uppercase tracking-widest text-text-muted mt-4 mb-2">Gate Verifications</h3>
  <div class="overflow-x-auto">
  <table class="data-table" data-pager>
    <thead><tr><th class="whitespace-nowrap">Phase</th><th class="whitespace-nowrap">Result</th><th class="whitespace-nowrap">Notes</th><th class="whitespace-nowrap">By</th><th class="whitespace-nowrap">At</th></tr></thead>
    <tbody>
    <?php foreach ($gv as $g): ?>
      <tr>
        <td class="whitespace-nowrap"><?= htmlspecialchars($g['phase']) ?></td>
        <td class="whitespace-nowrap"><span class="badge badge-<?= $g['result'] === 'VALIDATED' ? 'completed' : 'rejected' ?>"><?= htmlspecialchars($g['result']) ?></span></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($g['notes'] ?? '—')) ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($g['guard_name'] ?? '—')) ?></td>
        <td class="whitespace-nowrap"><?= $fmt($g['verified_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <?php
  $crumbs = [];
  if ($t['vehicle_id']) {
      $all = $pdo->prepare(
          'SELECT latitude, longitude, speed_kph, recorded_at FROM gps_positions
           WHERE ticket_id = ? ORDER BY recorded_at, position_id'
      );
      $all->execute([$t['ticket_id']]);
      $rows  = $all->fetchAll(PDO::FETCH_ASSOC);
      $stride = max(1, (int) ceil(count($rows) / 500));
      foreach ($rows as $i => $r) {
          if ($i % $stride === 0 || $i === count($rows) - 1) {
              $crumbs[] = [(float) $r['latitude'], (float) $r['longitude'], $r['speed_kph'] !== null ? round((float) $r['speed_kph'], 1) : null, $r['recorded_at']];
          }
      }
  }
  ?>
  <?php if (count($crumbs) >= 2): ?>
  <?php
  $HEAD_EXTRAS =
      '<link rel="stylesheet" href="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.css">' .
      '<script src="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.js" defer></script>';
  ?>
  <h3 class="text-sm uppercase tracking-widest text-text-muted mt-4 mb-2">GPS Route</h3>
  <div id="crumbMap"
       data-crumbs="<?= htmlspecialchars(json_encode($crumbs), ENT_QUOTES) ?>"
       data-lat="<?= htmlspecialchars((string) Settings::getFloat('NAAP_GEOFENCE_LAT')) ?>"
       data-lng="<?= htmlspecialchars((string) Settings::getFloat('NAAP_GEOFENCE_LNG')) ?>"
       data-radius="<?= htmlspecialchars((string) Settings::getFloat('NAAP_GEOFENCE_RADIUS_METERS', '500')) ?>"></div>
  <?php elseif ($t['vehicle_id'] && (int) $t['status_id'] === TripState::ON_GOING): ?>
  <p class="text-text-muted text-xs mt-3.5">No GPS breadcrumbs received yet for this trip.</p>
  <?php endif; ?>

  <?php if (!empty($t['reason'])): ?>
  <div class="mt-3.5 bg-[rgba(220,38,38,.07)] border border-rejected rounded-lg p-2.5 text-[12.5px]">
    <strong>Reason on record:</strong> <?= nl2br(htmlspecialchars($t['reason'])) ?>
  </div>
  <?php endif; ?>

  <?php if ($flags): ?>
  <div class="mt-3.5">
    <h3 class="text-sm uppercase tracking-widest text-text-muted mb-2">Open Flags</h3>
    <?php foreach ($flags as $f): ?>
      <div class="inline-flex items-center gap-1.5 bg-[rgba(217,119,6,.1)] border border-pending rounded-full px-2.5 py-0.5 text-[11px] font-bold mr-1.5 mb-1.5">
        <?= icon('alert') ?> <?= htmlspecialchars($f['flag_type']) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php
$RM_ID = 'reasonModal'; $RM_TITLE = 'Provide Reason'; $RM_OK = 'Confirm';
require_once __DIR__ . '/partials/reason_modal.php';
?>
<?php if ($canStart): ?>
<div class="modal-backdrop" id="startModal">
  <div class="modal-body" style="max-width:380px">
    <div class="modal-head">
      <span>Start Trip — Odometer</span>
      <button class="modal-close" type="button" onclick="UI.modal('startModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="px-[18px] py-4">
      <label class="<?= $labelCls ?>">Current odometer reading (km) *</label>
      <input id="odoInput" type="number" min="0" step="0.1" placeholder="e.g. 45230.5" class="<?= $inputCls ?>">
    </div>
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('startModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="startOk">Start Trip</button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($canResched): ?>
<div class="modal-backdrop" id="reschedModal">
  <div class="modal-body" style="max-width:420px">
    <div class="modal-head">
      <span>Reschedule Trip</span>
      <button class="modal-close" type="button" onclick="UI.modal('reschedModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="px-[18px] py-4">
      <label class="<?= $labelCls ?>">New Departure *</label>
      <input id="rsDep" type="datetime-local" value="<?= $toLocal($t['scheduled_departure']) ?>" class="<?= $inputCls ?>">
      <label class="<?= $labelCls ?> mt-2.5">New Return *</label>
      <input id="rsRet" type="datetime-local" value="<?= $toLocal($t['scheduled_return']) ?>" class="<?= $inputCls ?>">
      <p class="text-text-muted text-[11.5px] mt-2">If the new window conflicts with the assigned vehicle/driver, the assignment is cleared and staff must assign again.</p>
    </div>
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('reschedModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="reschedOk">Save Schedule</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$PAGE_SCRIPTS = ['ticket.js'];
require_once __DIR__ . '/partials/layout_foot.php';
