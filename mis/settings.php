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
    'Origin Location' => ['NAAP_ORIGIN_NAME', 'NAAP_ORIGIN_LAT', 'NAAP_ORIGIN_LNG', 'NAAP_ORIGIN_PLACE_ID'],
    'Geofence' => ['NAAP_GEOFENCE_LAT', 'NAAP_GEOFENCE_LNG', 'NAAP_GEOFENCE_RADIUS_METERS'],
    'GPS Telemetry' => ['GPS_INGESTION_URL', 'GPS_FRESH_SEC', 'GPS_DELAYED_SEC', 'GPS_MIN_INTERVAL_SEC', 'GPS_ALLOW_INSECURE_HTTP', 'ROUTE_REFRESH_MIN', 'ROUTE_MIN_MOVE_M', 'ROUTE_DEVIATION_METERS', 'GPS_POSITION_RETENTION_DAYS', 'RETENTION_RAW_MONTHS'],
    'Trip Management' => ['MISSED_TRIP_GRACE_HOURS', 'OVERDUE_GRACE_MIN', 'STALE_REVIEW_DAYS'],
    'Authentication & Security' => ['LOGIN_MAX_ATTEMPTS', 'LOGIN_LOCKOUT_MINS', 'OTP_TTL_MIN', 'SESSION_REGEN_INTERVAL', 'IDLE_TIMEOUT_SEC', 'MFA_REQUIRED_ROLES', 'MFA_RECOMMENDED_ROLES', 'REQUIRE_EMAIL_VERIFICATION'],
    'Email (SMTP)' => ['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_FROM_NAME'],
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
$originLat = $byKey['NAAP_ORIGIN_LAT']['setting_value'] ?? '';
$originLng = $byKey['NAAP_ORIGIN_LNG']['setting_value'] ?? '';
$originName = $byKey['NAAP_ORIGIN_NAME']['setting_value'] ?? '';
$HEAD_EXTRAS =
    '<link rel="preconnect" href="https://maps.googleapis.com" crossorigin>' .
    '<link rel="preconnect" href="https://maps.gstatic.com" crossorigin>' .
    '<script>window.NAAP_MAPS_KEY=' . json_encode(GOOGLE_MAPS_API_KEY) . ';</script>';

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$searchCls = 'form-input';
$inputCls = 'form-input';
$btnPrimary = 'btn btn-primary';
?>
<form onsubmit="return false">
<div class="content-card p-3.5">
  <div class="flex flex-wrap items-center gap-2.5 mb-3.5">
    <button class="<?= $btnPrimary ?>" id="settingsSave">Save All</button>
    <input type="search" placeholder="Filter settings" data-filter="#tblSettings" class="<?= $searchCls ?> ml-auto input-search">
  </div>
  <p class="text-text-muted text-xs mb-3.5">Changes take effect immediately and are written to the audit trail. Sensitive values are masked — type a new value only when rotating.</p>
  <div class="overflow-x-auto">
  <table class="data-table" id="tblSettings">
    <thead><tr><th class="whitespace-nowrap">Key</th><th class="whitespace-nowrap">Value</th><th class="whitespace-nowrap">Type / Description</th></tr></thead>
    <tbody>
    <?php foreach ($categories as $cat => $keys): ?>
      <tr class="category-row"><td colspan="3"><?= htmlspecialchars($cat) ?></td></tr>
      <?php if ($cat === 'Origin Location'): ?>
      <tr><td colspan="3" style="padding:0">
        <div style="padding:12px">
          <input id="originSearch" type="search" placeholder="Search for origin location" class="<?= $searchCls ?>" style="margin-bottom:8px">
          <div id="originMap" class="map-mini"
               data-lat="<?= htmlspecialchars($originLat) ?>"
               data-lng="<?= htmlspecialchars($originLng) ?>"
               data-name="<?= htmlspecialchars($originName) ?>"></div>
        </div>
      </td></tr>
      <?php endif; ?>
      <?php if ($cat === 'Geofence'): ?>
      <tr><td colspan="3" style="padding:0">
        <div id="geofenceMap"
             data-lat="<?= htmlspecialchars($geoLat) ?>"
             data-lng="<?= htmlspecialchars($geoLng) ?>"
             data-radius="<?= htmlspecialchars($geoRad) ?>"></div>
      </td></tr>
      <?php endif; ?>
      <?php if ($cat === 'Email (SMTP)'): ?>
      <tr><td colspan="3" style="padding:0">
        <div style="padding:12px">
          <div class="flex flex-wrap items-center gap-2.5">
            <input id="smtpTestTo" type="email" placeholder="Recipient (blank = your account email)" class="<?= $searchCls ?> input-search">
            <button type="button" class="<?= $btnPrimary ?>" id="smtpTest">Send test email</button>
          </div>
          <p class="text-text-muted text-xs mt-2 mb-0"><?php if (MailService::envOverride()): ?>ENV_SMTP_* in config/secrets.php is active — the fields below are ignored while it is set.<?php else: ?>Sends using the saved values below. Save changes before testing.<?php endif; ?></p>
        </div>
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
        <td class="text-text-muted text-sm"><?= htmlspecialchars((string) $r['value_type']) ?> · <?= htmlspecialchars((string) $r['description']) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
</form>

<?php
$VENDOR_SCRIPTS = ['maps_loader.js'];
$PAGE_SCRIPTS = ['location-autocomplete.js', 'admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
