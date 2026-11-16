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
    '<link rel="preconnect" href="https://maps.googleapis.com" crossorigin>' .
    '<link rel="preconnect" href="https://maps.gstatic.com" crossorigin>' .
    '<script>window.NAAP_MAPS_KEY=' . json_encode(GOOGLE_MAPS_API_KEY) . ';</script>';

$fenceLat    = Settings::getFloat('NAAP_GEOFENCE_LAT');
$fenceLng    = Settings::getFloat('NAAP_GEOFENCE_LNG');
$fenceRadius = Settings::getFloat('NAAP_GEOFENCE_RADIUS_METERS', 500);
$originLat   = Settings::getFloat('NAAP_ORIGIN_LAT');
$originLng   = Settings::getFloat('NAAP_ORIGIN_LNG');
$originName  = Settings::get('NAAP_ORIGIN_NAME', '');

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
         data-radius="<?= htmlspecialchars((string) $fenceRadius) ?>"
         data-origin-lat="<?= htmlspecialchars((string) $originLat) ?>"
         data-origin-lng="<?= htmlspecialchars((string) $originLng) ?>"
         data-origin-name="<?= htmlspecialchars($originName) ?>"></div>
    <aside class="bg-bg-surface border border-border rounded-lg track-panel">
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
$VENDOR_SCRIPTS = ['maps_loader.js'];
$PAGE_SCRIPTS = ['tracking.js'];
require_once __DIR__ . '/partials/layout_foot.php';
