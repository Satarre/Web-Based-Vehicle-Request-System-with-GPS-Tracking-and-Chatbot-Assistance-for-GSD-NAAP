<?php
declare(strict_types=1);
/**
 * driver/posttrip.php — Section B post-trip report for RETURNED trips.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [4]);
$ACTIVE = BASE_URL . 'driver/posttrip.php';
$PAGE_TITLE = 'Post-Trip Report';
$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
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

$pending = $pdo->prepare(
    "SELECT t.ticket_id, t.dtt_number, t.odometer_start, t.passenger_name, t.surname, t.middle_initial, t.suffix, t.place_to_visit,
            v.plate_number, t.scheduled_departure
     FROM trip_tickets t LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     WHERE t.driver_id = ? AND t.status_id = ? AND t.is_active = 1
       AND t.dest_departure_at IS NOT NULL AND t.odometer_end IS NULL
     ORDER BY t.dest_departure_at DESC"
);
$pending->execute([$USER['id'], TripState::ON_GOING]);
$pending = $pending->fetchAll();

$submitted = $pdo->prepare(
    "SELECT t.ticket_id, t.dtt_number, r.status_name, t.distance_odometer, t.odometer_start, t.odometer_end, v.plate_number
     FROM trip_tickets t JOIN request_status r ON r.status_id = t.status_id
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     WHERE t.driver_id = ? AND t.status_id IN (" . TripState::COMPLETED . ")
       AND t.is_active = 1 ORDER BY t.certified_at DESC, t.return_at DESC LIMIT 50"
);
$submitted->execute([$USER['id']]);
$submitted = $submitted->fetchAll();

$t = null;
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $st = $pdo->prepare(
        'SELECT tt.*, v.plate_number FROM trip_tickets tt
         LEFT JOIN vehicle v ON v.vehicle_id = tt.vehicle_id
         WHERE tt.ticket_id = ? AND tt.driver_id = ? AND tt.status_id = ? AND tt.is_active = 1
           AND tt.dest_departure_at IS NOT NULL AND tt.odometer_end IS NULL'
    );
    $st->execute([$id, $USER['id'], TripState::ON_GOING]);
    $t = $st->fetch();
    if (!$t) {
        header('Location: ' . BASE_URL . 'driver/posttrip.php');
        exit;
    }
}

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$inputCls = 'form-input';
$labelCls = 'form-label';

if ($t):
?>
<form id="ptForm" autocomplete="off" onsubmit="return false">
<input type="hidden" id="f_ticket_id" value="<?= (int) $t['ticket_id'] ?>">
<div class="content-card p-3.5" style="max-width:860px">
  <div class="flex items-center gap-2.5 mb-3.5 flex-wrap">
    <h2 class="text-lg font-bold">Section B — Post-Trip Report</h2>
    <span class="badge badge-on-going">ON GOING</span>
    <span class="text-text-muted ml-auto text-xs">
      <?= $t['dtt_number'] ? htmlspecialchars($t['dtt_number']) : '#' . (int) $t['ticket_id'] ?>
      · <?= htmlspecialchars((string) ($t['plate_number'] ?? '')) ?><?= $t['place_to_visit'] ? ' · ' . htmlspecialchars(mb_substr((string) $t['place_to_visit'], 0, 40)) : '' ?>
    </span>
  </div>

  <h3 class="text-sm uppercase tracking-widest text-text-muted mb-2">Odometer</h3>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <div><label class="<?= $labelCls ?>">Odometer Start (km)</label><input name="odometer_start" id="f_odometer_start" value="<?= $t['odometer_start'] !== null ? number_format((float) $t['odometer_start'], 1) : '' ?>" <?= $t['odometer_start'] !== null ? 'disabled' : '' ?> type="number" min="0" step="0.1" placeholder="required" class="<?= $inputCls ?>"></div>
    <div><label class="<?= $labelCls ?>">Odometer End (km) *</label><input id="f_odometer_end" type="number" min="<?= (float) $t['odometer_start'] ?>" step="0.1" placeholder="official distance basis" class="<?= $inputCls ?>"></div>
  </div>

  <h3 class="text-sm uppercase tracking-widest text-text-muted mt-4.5 mb-2">Fuel &amp; Lubricants</h3>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <div>
      <label class="<?= $labelCls ?>">Fuel Type</label>
      <select id="f_fuel_type" class="<?= $inputCls ?>">
        <option value="">—</option>
        <?php foreach (['Gasoline', 'Diesel'] as $ft): ?>
        <option <?= $t['fuel_type'] === $ft ? 'selected' : '' ?>><?= $ft ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="<?= $labelCls ?>">Tank Balance at Start</label><input id="f_fuel_tank_balance_start" type="number" min="0" step="0.01" class="<?= $inputCls ?>"></div>
    <div><label class="<?= $labelCls ?>">Issued from Stock</label><input id="f_fuel_issued_stock" type="number" min="0" step="0.01" class="<?= $inputCls ?>"></div>
    <div><label class="<?= $labelCls ?>">Purchased During Trip</label><input id="f_fuel_purchased_trip" type="number" min="0" step="0.01" class="<?= $inputCls ?>"></div>
    <div><label class="<?= $labelCls ?>">Fuel Used</label><input id="f_fuel_used" type="number" min="0" step="0.01" class="<?= $inputCls ?>"></div>
    <div><label class="<?= $labelCls ?>">Gear Oil Used</label><input id="f_gear_oil_used" maxlength="100" class="<?= $inputCls ?>"></div>
    <div><label class="<?= $labelCls ?>">Lubricant Oil Issued</label><input id="f_lubricant_oil_issued" maxlength="100" class="<?= $inputCls ?>"></div>
    <div><label class="<?= $labelCls ?>">Grease Issued</label><input id="f_grease_issued" maxlength="100" class="<?= $inputCls ?>"></div>
    <div class="span-full"><label class="<?= $labelCls ?>">Remarks</label><textarea id="f_remarks" rows="2" maxlength="500" class="<?= $inputCls ?>"></textarea></div>
  </div>
  <p class="text-text-muted text-sm mt-1.5">Total available is computed as tank balance at start + issued stock + purchased during trip; balance at end = total − used. Odometer end is the official distance.</p>

  <h3 class="text-sm uppercase tracking-widest text-text-muted mt-4.5 mb-2">Trip Costs</h3>
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <div><label class="<?= $labelCls ?>">Fuel Cost (₱)</label><input id="f_fuel_cost" type="number" min="0" step="0.01" class="<?= $inputCls ?>" placeholder="0.00"></div>
    <div><label class="<?= $labelCls ?>">Easytrip Cost (₱)</label><input id="f_easytrip_cost" type="number" min="0" step="0.01" class="<?= $inputCls ?>" placeholder="0.00"></div>
    <div><label class="<?= $labelCls ?>">Autosweep Cost (₱)</label><input id="f_autosweep_cost" type="number" min="0" step="0.01" class="<?= $inputCls ?>" placeholder="0.00"></div>
  </div>

  <div class="flex gap-2 mt-4.5 flex-wrap">
    <button class="btn btn-primary" type="button" id="btnPtSubmit">Submit Post-Trip Report</button>
    <a class="btn btn-ghost" href="<?= BASE_URL ?>driver/posttrip.php">Back</a>
  </div>
</div>
</form>

<?php else: ?>
<div class="content-card p-3.5" style="margin-bottom:14px">
  <h2 class="text-lg font-bold mb-3">Trips Waiting for My Post-Trip Report</h2>
  <?php if (!$pending): ?>
    <p class="text-text-muted text-base m-0">Nothing to report — no trips have started their return leg yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table class="data-table" data-pager>
    <thead><tr><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Odo Start</th><th class="whitespace-nowrap"></th></tr></thead>
    <tbody>
    <?php foreach ($pending as $p): ?>
      <tr class="table-row">
        <td class="table-cell"><strong><?= $p['dtt_number'] ? htmlspecialchars($p['dtt_number']) : '#' . (int) $p['ticket_id'] ?></strong></td>
        <td class="table-cell"><?= htmlspecialchars((string) ($p['plate_number'] ?? '—')) ?></td>
        <td class="table-cell"><?= htmlspecialchars($paxName($p)) ?></td>
        <td class="table-cell"><?= number_format((float) $p['odometer_start'], 1) ?> km</td>
        <td class="table-cell"><a class="btn btn-sm btn-primary" href="?id=<?= (int) $p['ticket_id'] ?>">Fill Report</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($submitted): ?>
<?php
$c = [
    'title' => 'Submitted Reports',
    'id' => 'tblPosttrip',
    'filter' => 'Search reports',
    'columns' => ['Ticket', 'Status', 'Vehicle', 'Odo Start (km)', 'Odo End (km)', 'Distance (odometer)', ''],
    'rows' => array_map(fn($s) => [
        '<strong>' . ($s['dtt_number'] ? htmlspecialchars($s['dtt_number']) : '#' . (int) $s['ticket_id']) . '</strong>',
        '<span class="badge badge-' . strtolower(str_replace('_', '-', $s['status_name'])) . '">' . htmlspecialchars($s['status_name']) . '</span>',
        htmlspecialchars((string) ($s['plate_number'] ?? '—')),
        $s['odometer_start'] !== null ? number_format((float) $s['odometer_start'], 1) : '—',
        $s['odometer_end'] !== null ? number_format((float) $s['odometer_end'], 1) : '—',
        $s['distance_odometer'] !== null ? number_format((float) $s['distance_odometer'], 1) . ' km' : '—',
        '<a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . (int) $s['ticket_id'] . '">View</a>',
    ], $submitted),
];
include __DIR__ . '/../partials/ui/table.php';
?>
<?php endif; ?>
<?php endif; ?>

<?php
if ($t) {
    $PAGE_SCRIPTS = ['posttrip.js'];
} else {
    $PAGE_SCRIPTS = ['table_filter.js'];
}
require_once dirname(__DIR__) . '/partials/layout_foot.php';
