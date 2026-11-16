<?php
declare(strict_types=1);
/**
 * tracker.php — GPS tracker account home (role: GPS TRACKER).
 * Status-only view of the caller's own device/vehicle; polls api/gps/me.php.
 */
require_once __DIR__ . '/config/config.php';
$USER = Auth::requirePage($pdo, [7]);
$ACTIVE = BASE_URL . 'tracker.php';
$PAGE_TITLE = 'My Tracker';
$KEEP_SESSION = true; // live status display; api/gps/me.php pings keep the session warm

$st = $pdo->prepare(
    'SELECT g.device_uid, g.device_name, g.is_active,
            v.plate_number, v.brand, v.model_name
     FROM gps_devices g LEFT JOIN vehicle v ON v.vehicle_id = g.vehicle_id
     WHERE g.account_id = ?'
);
$st->execute([$USER['id']]);
$device = $st->fetch() ?: null;
$shareInterval = max(5, Settings::getInt('GPS_MIN_INTERVAL_SEC', 20));

require_once __DIR__ . '/partials/layout_head.php';
require_once __DIR__ . '/partials/shell_open.php';
?>
<div class="page-header">
  <div>
    <h1>My Tracker</h1>
    <p class="text-text-muted mt-1">Live status of your GPS tracker · refreshes every 10 seconds</p>
  </div>
</div>

<?php if (!$device): ?>
<div class="content-card p-7 text-center text-text-muted">
  This account has no tracker device. Contact GSD/MIS to register one.
</div>
<?php else: ?>
<div class="content-card mb-3.5" id="trkShareCard"
     data-interval-sec="<?= (int) $shareInterval ?>"
     data-device-uid="<?= htmlspecialchars((string) $device['device_uid']) ?>">
  <div class="card-head">
    <span>Share Device Location</span>
    <span id="trkShareBadge" class="badge badge-draft">STOPPED</span>
  </div>
  <div class="p-3.5">
    <p class="text-text-muted text-sm mb-2.5">Sends this device's GPS to the system exactly like a hardware tracker — same pipeline, same live map. Keep this page open while sharing; fixes queued offline are replayed automatically.</p>
    <div class="flex items-center gap-2 flex-wrap">
      <button class="btn btn-primary" id="trkShareStart" type="button">Start Sharing</button>
      <button class="btn btn-danger" id="trkShareStop" type="button" style="display:none">Stop Sharing</button>
    </div>
    <div class="grid grid-cols-4 max-lg:grid-cols-2 gap-2.5 mt-3">
      <div><div class="text-text-muted text-xs">Sent</div><div class="text-lg font-bold" id="trkSent">0</div></div>
      <div><div class="text-text-muted text-xs">Stored</div><div class="text-lg font-bold" id="trkStored">0</div></div>
      <div><div class="text-text-muted text-xs">Throttled</div><div class="text-lg font-bold" id="trkThrottled">0</div></div>
      <div><div class="text-text-muted text-xs">Queued</div><div class="text-lg font-bold" id="trkQueued">0</div></div>
    </div>
    <p id="trkShareMsg" class="text-sm mt-2.5 text-text-muted"></p>
  </div>
</div>
<div class="grid grid-cols-2 gap-3.5 max-lg:grid-cols-1">
  <div class="content-card">
    <div class="card-head">
      <span>Tracker Device</span>
      <span class="badge badge-<?= (int) $device['is_active'] === 1 ? 'completed' : 'rejected' ?>"><?= (int) $device['is_active'] === 1 ? 'Active' : 'Disabled' ?></span>
    </div>
    <div class="p-3.5 flex flex-col gap-2.5">
      <div class="flex items-start justify-between gap-3">
        <span class="text-text-muted text-sm">Device UID</span>
        <strong><?= htmlspecialchars((string) $device['device_uid']) ?></strong>
      </div>
      <div class="flex items-start justify-between gap-3">
        <span class="text-text-muted text-sm">Name</span>
        <span class="text-right"><?= htmlspecialchars((string) ($device['device_name'] ?? '—')) ?></span>
      </div>
      <div class="flex items-start justify-between gap-3">
        <span class="text-text-muted text-sm">Paired Vehicle</span>
        <span class="text-right"><?= $device['plate_number']
            ? htmlspecialchars(trim(($device['brand'] ?? '') . ' ' . ($device['model_name'] ?? '')) . ' (' . $device['plate_number'] . ')')
            : '—' ?></span>
      </div>
    </div>
  </div>
  <div class="content-card">
    <div class="card-head">
      <span>Live Status</span>
      <span id="trkFresh" class="inline-flex px-2 py-0.5 rounded-full text-9-5px font-bold text-white b-offline">OFFLINE</span>
    </div>
    <div class="p-3.5 flex flex-col gap-2.5">
      <div class="flex items-start justify-between gap-3">
        <span class="text-text-muted text-sm">Last Seen</span>
        <span id="trkSeen" class="text-right">—</span>
      </div>
      <div class="flex items-start justify-between gap-3">
        <span class="text-text-muted text-sm">Position</span>
        <span id="trkPos" class="text-right">—</span>
      </div>
      <div class="flex items-start justify-between gap-3">
        <span class="text-text-muted text-sm">Speed</span>
        <span id="trkSpeed" class="text-right">—</span>
      </div>
      <div class="flex items-start justify-between gap-3">
        <span class="text-text-muted text-sm">Heading</span>
        <span id="trkHeading" class="text-right">—</span>
      </div>
    </div>
    <p id="trkMsg" class="text-text-muted text-sm px-3.5 pb-3.5"></p>
  </div>
</div>
<?php endif; ?>
<?php
$PAGE_SCRIPTS = $device ? ['tracker.js'] : [];
require_once __DIR__ . '/partials/layout_foot.php';
