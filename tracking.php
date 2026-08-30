<?php
declare(strict_types=1);
/**
 * tracking.php — shared live vehicle map (MIS, Director, Staff).
 * Polls api/gps/latest.php every 10 s via TrackingClient.
 */
require_once __DIR__ . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3]);
$ACTIVE = BASE_URL . 'tracking.php';
$PAGE_TITLE = 'Live Tracking';

$HEAD_EXTRAS =
    '<link rel="stylesheet" href="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.css">' .
    '<script src="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.js" defer></script>';

$fenceLat    = Settings::getFloat('NAAP_GEOFENCE_LAT');
$fenceLng    = Settings::getFloat('NAAP_GEOFENCE_LNG');
$fenceRadius = Settings::getFloat('NAAP_GEOFENCE_RADIUS_METERS', 500);

require_once __DIR__ . '/partials/layout_head.php';
require_once __DIR__ . '/partials/shell_open.php';
?>
<div class="page-header">
  <div>
    <h1>Live Tracking</h1>
    <p class="text-text-muted mt-1">Vehicle positions refresh every 10 seconds · campus geofence shown as dashed circle</p>
  </div>
</div>
  <div class="track-grid">
    <div id="map"
         data-lat="<?= htmlspecialchars((string) $fenceLat) ?>"
         data-lng="<?= htmlspecialchars((string) $fenceLng) ?>"
         data-radius="<?= htmlspecialchars((string) $fenceRadius) ?>"></div>
    <aside class="bg-bg-surface border border-border rounded-[10px] track-panel">
      <div class="card-head"><span>Fleet Status</span><span id="trackCount" class="text-text-muted"></span></div>
      <ul class="track-list" id="trackList"></ul>
      <div class="legend">
        <span class="badge b-current">CURRENT</span>
        <span class="badge b-delayed">DELAYED</span>
        <span class="badge b-offline">OFFLINE</span>
      </div>
    </aside>
  </div>
</div>
<?php
$PAGE_SCRIPTS = ['tracking.js'];
require_once __DIR__ . '/partials/layout_foot.php';
