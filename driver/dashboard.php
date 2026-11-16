<?php
declare(strict_types=1);
/**
 * driver/dashboard.php — My Trips: everything currently assigned to this driver.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [4]);
$ACTIVE = BASE_URL . 'driver/dashboard.php';
$PAGE_TITLE = 'My Trips';
$ENABLE_LIVE = true;

$st = $pdo->prepare(
    'SELECT t.ticket_id, t.dtt_number, t.status_id, r.status_name, t.passenger_name,
            t.surname, t.middle_initial, t.suffix,
            t.place_to_visit, t.scheduled_departure, t.scheduled_return,
            t.odometer_start, t.odometer_end, t.distance_odometer,
            v.plate_number, v.brand, v.model_name
     FROM trip_tickets t
     JOIN request_status r ON r.status_id = t.status_id
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     WHERE t.driver_id = ? AND t.is_active = 1
       AND t.status_id BETWEEN ' . TripState::DISPATCHED . ' AND ' . TripState::COMPLETED . '
     ORDER BY FIELD(t.status_id, ' . TripState::ON_GOING . ', ' . TripState::DISPATCHED . ', ' . TripState::COMPLETED . '), t.scheduled_departure'
);
$st->execute([$USER['id']]);
$trips = $st->fetchAll();

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

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
$PAGE_SCRIPTS = ['table_filter.js', 'calendar.js'];
?>
<div class="content-card">
  <div class="card-head">
    <span>Trips Assigned to Me</span>
    <div class="flex items-center gap-1.5 ml-auto flex-wrap">
      <span class="text-text-muted text-xs"><?= count($trips) ?> trip(s)</span>
      <input class="input-search" type="search" placeholder="Search trips" data-filter="#tblMyTrips">
    </div>
  </div>
  <?php if (!$trips): ?>
    <div class="p-7 text-center text-text-muted">No trips assigned to you right now.</div>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table class="data-table" id="tblMyTrips" data-pager>
    <thead><tr><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Status</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Passenger</th><th class="whitespace-nowrap">Destination</th><th class="whitespace-nowrap">Departure</th><th class="whitespace-nowrap">Return</th><th class="whitespace-nowrap">Odometer (km)</th><th class="whitespace-nowrap"></th></tr></thead>
    <tbody>
    <?php foreach ($trips as $r): ?>
      <?php
      $isDispatched = (int) $r['status_id'] === TripState::DISPATCHED;
      $isLate = false;
      $lateText = '';
      if ($isDispatched && $r['scheduled_departure']) {
          $depTs = strtotime((string) $r['scheduled_departure']);
          if ($depTs && time() > $depTs) {
              $isLate = true;
              $diff = time() - $depTs;
              $hours = (int) floor($diff / 3600);
              $mins = (int) floor(($diff % 3600) / 60);
              $parts = [];
              if ($hours > 0) { $parts[] = $hours . 'h'; }
              if ($mins > 0) { $parts[] = $mins . 'm'; }
              $lateText = implode(' ', $parts) ?: '<1m';
          }
      }
      ?>
      <tr>
        <td class="whitespace-nowrap"><strong><?= $r['dtt_number'] ? htmlspecialchars($r['dtt_number']) : '#' . (int) $r['ticket_id'] ?></strong></td>
        <td class="whitespace-nowrap">
          <span class="badge badge-<?= strtolower(str_replace('_', '-', $r['status_name'])) ?>"><?= htmlspecialchars($r['status_name']) ?></span>
          <?php if ($isLate): ?>
            <span class="badge badge-rejected" title="Trip is late">LATE <?= htmlspecialchars($lateText) ?></span>
          <?php endif; ?>
        </td>
        <td class="whitespace-nowrap"><?= $r['plate_number'] ? htmlspecialchars($r['plate_number'] . ' · ' . $r['brand'] . ' ' . $r['model_name']) : '—' ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars($paxName($r)) ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars(mb_substr((string) $r['place_to_visit'], 0, 40)) ?></td>
        <td class="whitespace-nowrap"><?= $fmt($r['scheduled_departure']) ?></td>
        <td class="whitespace-nowrap"><?= $fmt($r['scheduled_return']) ?></td>
        <td class="whitespace-nowrap"><?= $r['odometer_start'] !== null
            ? number_format((float) $r['odometer_start'], 1) . ' → ' . ($r['odometer_end'] !== null ? number_format((float) $r['odometer_end'], 1) : '—') . ' km'
            : '—' ?></td>
        <td class="whitespace-nowrap"><a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>ticket.php?id=<?= (int) $r['ticket_id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/partials/calendar.php'; ?>
<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
