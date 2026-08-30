<?php
declare(strict_types=1);
/**
 * mis/dashboard.php — system overview for MIS ADMIN.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1]);
$ACTIVE = BASE_URL . 'mis/dashboard.php';
$PAGE_TITLE = 'Dashboard';

$q = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$fleetVehicles  = $q('SELECT COUNT(*) FROM vehicle WHERE is_active = 1');
$fleetAvailable = $q('SELECT COUNT(*) FROM vehicle WHERE is_active = 1 AND is_available = 1');
$devicesTotal   = $q('SELECT COUNT(*) FROM gps_devices');
$devicesPaired  = $q('SELECT COUNT(*) FROM gps_devices WHERE vehicle_id IS NOT NULL AND is_active = 1');
$devicesOnline  = $q("SELECT COUNT(*) FROM gps_devices WHERE last_seen_at IS NOT NULL AND last_seen_at >= NOW() - INTERVAL " . Settings::getInt('GPS_DELAYED_SEC') . " SECOND");
$accountsActive = $q('SELECT COUNT(*) FROM account WHERE is_active = 1');
$ticketsMonth   = $q('SELECT COUNT(*) FROM trip_tickets WHERE created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")');
$openFlags      = $q('SELECT COUNT(*) FROM trip_flags WHERE resolved_at IS NULL');

$statusRows = $pdo->query(
    'SELECT r.status_name, COUNT(t.ticket_id) c FROM request_status r
     LEFT JOIN trip_tickets t ON t.status_id = r.status_id AND t.is_active = 1
     GROUP BY r.status_id ORDER BY r.status_id'
)->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="page-header">
  <h1 class="text-xl">System Dashboard</h1>
  <a class="btn btn-sm" href="<?= BASE_URL ?>mis/fleet.php">Manage Fleet</a>
</div>

<div class="stat-cards mb-4">
  <?php
  $cards = [
      ['Active Vehicles', "$fleetAvailable / $fleetVehicles", 'available / registered'],
      ['GPS Devices Paired', "$devicesPaired / $devicesTotal", "$devicesOnline currently online"],
      ['Active Accounts', (string) $accountsActive, 'across all roles'],
      ['Tickets This Month', (string) $ticketsMonth, 'created since the 1st'],
      ['Open Flags', (string) $openFlags, $openFlags > 0 ? 'need staff review' : 'all clear'],
  ];
  foreach ($cards as [$label, $big, $sub]):
    $lnk = $label === 'Open Flags' && $openFlags > 0 ? " onclick=\"location.href='" . BASE_URL . "flags.php'\"" : ''; ?>
    <div class="stat-card<?= $label === 'Open Flags' && $openFlags > 0 ? ' is-clickable' : '' ?>"<?= $lnk ?>>
      <div class="stat-card-label"><?= $label ?></div>
      <div class="stat-card-value"><?= $big ?></div>
      <div class="stat-card-sub"><?= $sub ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="content-card">
  <div class="p-3.5">
    <h3 class="text-sm uppercase tracking-widest text-text-muted mb-2.5">Tickets by Status</h3>
    <table class="data-table" data-pager>
      <tbody>
      <?php foreach ($statusRows as $s): ?>
        <tr>
          <td style="width:220px">
            <?php $c = ['status' => $s['status_name']]; include __DIR__ . '/../partials/ui/badge.php'; ?>
          </td>
          <td style="width:60%"><div class="progress-bar">
            <?php $max = max(1, max(array_column($statusRows, 'c'))); ?>
            <div class="progress-bar-fill bg-accent" style="width:<?= round(100 * (int) $s['c'] / $max) ?>%"></div>
          </div></td>
          <td class="text-right font-bold"><?= (int) $s['c'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
