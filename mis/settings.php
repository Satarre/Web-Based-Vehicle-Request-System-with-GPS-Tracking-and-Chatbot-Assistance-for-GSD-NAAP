<?php
declare(strict_types=1);
/**
 * mis/settings.php — system settings editor (app_settings), grouped by category.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1]);
$ACTIVE = BASE_URL . 'mis/settings.php';
$PAGE_TITLE = 'System Settings';

$rows = $pdo->query('SELECT * FROM app_settings ORDER BY setting_key')->fetchAll();
$sensitive = static fn(string $k): bool => (bool) preg_match('/PASS|SECRET|TOKEN/', $k);

/* ── Group settings by category ────────────────────────────────────── */
$categories = [
    'Geofence' => ['NAAP_GEOFENCE_LAT', 'NAAP_GEOFENCE_LNG', 'NAAP_GEOFENCE_RADIUS_METERS', 'GEOFENCE_GRACE_MIN'],
    'GPS Telemetry' => ['GPS_INGESTION_URL', 'GPS_FRESH_SEC', 'GPS_DELAYED_SEC', 'GPS_MIN_INTERVAL_SEC', 'GPS_ALLOW_INSECURE_HTTP', 'RETENTION_RAW_MONTHS'],
    'Trip Management' => ['MISSED_TRIP_GRACE_HOURS', 'OVERDUE_GRACE_MIN', 'STALE_REVIEW_DAYS'],
    'Authentication & Security' => ['LOGIN_MAX_ATTEMPTS', 'LOGIN_LOCKOUT_MINS', 'OTP_TTL_MIN', 'SESSION_REGEN_INTERVAL', 'IDLE_TIMEOUT_SEC'],
    'Email (SMTP)' => ['SMTP_USER', 'SMTP_PASS', 'SMTP_FROM_NAME'],
    'Miscellaneous' => ['UPLOAD_MAX_BYTES'],
];

$byKey = [];
foreach ($rows as $r) {
    $byKey[$r['setting_key']] = $r;
}

$assigned = [];
foreach ($categories as $keys) {
    foreach ($keys as $k) {
        $assigned[$k] = true;
    }
}
$others = array_diff(array_keys($byKey), array_keys($assigned));
if ($others) {
    $categories['Other'] = array_values($others);
}

$geoLat = $byKey['NAAP_GEOFENCE_LAT']['setting_value'] ?? '';
$geoLng = $byKey['NAAP_GEOFENCE_LNG']['setting_value'] ?? '';
$geoRad = $byKey['NAAP_GEOFENCE_RADIUS_METERS']['setting_value'] ?? '500';
$HEAD_EXTRAS =
    '<link rel="stylesheet" href="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.css">' .
    '<script src="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.js" defer></script>';

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$searchCls = 'form-input';
$inputCls = 'form-input';
$btnPrimary = 'btn btn-primary';
?>
<form onsubmit="return false">
<div class="content-card p-3.5">
  <div class="flex items-center gap-2.5 mb-3.5">
    <h2 class="text-lg font-bold">System Settings</h2>
    <button class="<?= $btnPrimary ?>" id="settingsSave">Save All</button>
    <input type="search" placeholder="Filter settings…" data-filter="#tblSettings" class="<?= $searchCls ?> ml-auto w-48">
  </div>
  <p class="text-text-muted text-xs mb-3.5">Changes take effect immediately and are written to the audit trail. Sensitive values are masked — type a new value only when rotating.</p>
  <div class="overflow-x-auto">
  <table class="data-table" id="tblSettings">
    <thead><tr><th class="whitespace-nowrap">Key</th><th class="whitespace-nowrap">Value</th><th class="whitespace-nowrap">Type / Description</th></tr></thead>
    <tbody>
    <?php foreach ($categories as $cat => $keys): ?>
      <tr class="category-row"><td colspan="3"><?= htmlspecialchars($cat) ?></td></tr>
      <?php if ($cat === 'Geofence'): ?>
      <tr><td colspan="3" style="padding:0">
        <div id="geofenceMap"
             data-lat="<?= htmlspecialchars($geoLat) ?>"
             data-lng="<?= htmlspecialchars($geoLng) ?>"
             data-radius="<?= htmlspecialchars($geoRad) ?>"></div>
      </td></tr>
      <?php endif; ?>
      <?php foreach ($keys as $k):
        if (!isset($byKey[$k])) continue;
        $r = $byKey[$k];
      ?>
      <tr>
        <td class="whitespace-nowrap"><code><?= htmlspecialchars($r['setting_key']) ?></code></td>
        <td style="min-width:260px">
          <?php if ($sensitive((string) $r['setting_key'])): ?>
            <input data-setting="<?= htmlspecialchars($r['setting_key']) ?>" type="password"
                   placeholder="<?= $r['setting_value'] !== '' ? str_repeat('•', 8) : '(not set)' ?>" autocomplete="new-password" class="<?= $inputCls ?>">
          <?php else: ?>
            <input data-setting="<?= htmlspecialchars($r['setting_key']) ?>" value="<?= htmlspecialchars((string) $r['setting_value']) ?>" class="<?= $inputCls ?>">
          <?php endif; ?>
        </td>
        <td class="text-text-muted text-sm whitespace-nowrap"><?= htmlspecialchars((string) $r['value_type']) ?> · <?= htmlspecialchars((string) $r['description']) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
</form>

<?php
$PAGE_SCRIPTS = ['admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
