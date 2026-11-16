<?php
declare(strict_types=1);
/**
 * mis/fleet.php — vehicle registry, GPS device registry, types.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 3]);
$ACTIVE = BASE_URL . 'mis/fleet.php';
$PAGE_TITLE = 'Vehicles & GPS Devices';

$vehicles = $pdo->query(
    'SELECT v.*, t.type_name,
            (SELECT COUNT(*) FROM gps_devices g WHERE g.vehicle_id = v.vehicle_id AND g.is_active = 1) AS paired_devices
     FROM vehicle v JOIN vehicle_types t ON t.type_id = v.type_id
     ORDER BY v.is_active DESC, v.plate_number'
)->fetchAll();

$devices = $pdo->query(
    'SELECT g.*, v.plate_number,
            (g.last_seen_at IS NOT NULL AND g.last_seen_at >= NOW() - INTERVAL 120 SECOND) AS online
     FROM gps_devices g LEFT JOIN vehicle v ON v.vehicle_id = g.vehicle_id
     ORDER BY g.device_uid'
)->fetchAll();

/* Next free UID in the GPS-NAAP-### series (placeholder for auto-numbering). */
$nextTrackerUid = sprintf('GPS-NAAP-%03d', 1 + (int) $pdo->query(
    "SELECT COALESCE(MAX(CAST(SUBSTRING(device_uid, 10) AS UNSIGNED)), 0)
     FROM gps_devices WHERE device_uid REGEXP '^GPS-NAAP-[0-9]+$'"
)->fetchColumn());

$types = $pdo->query('SELECT * FROM vehicle_types WHERE is_active = 1 ORDER BY type_name')->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$searchCls = 'form-input';
$btnPrimary = 'btn btn-primary';
$btnGhost = 'btn btn-sm btn-ghost';
$btnDanger = 'btn btn-sm btn-danger';
$labelCls = 'form-label';
$inputCls = 'form-input';
?>

<!-- Vehicles -->
<div class="content-card">
  <div class="list-head">
    <h2 class="text-lg font-bold">Vehicles</h2>
    <div class="list-head-actions">
      <input type="search" placeholder="Search vehicles" data-filter="#tblVehicles" class="<?= $searchCls ?> input-shrink">
      <button class="<?= $btnPrimary ?> btn-wide" data-edit='{"id":0}' data-modal="editModal">+ Add Vehicle</button>
    </div>
  </div>
  <div class="overflow-x-auto">
  <table class="data-table" id="tblVehicles" data-pager>
    <thead><tr><th class="cell-wrap">Plate</th><th class="cell-wrap">Vehicle</th><th class="whitespace-nowrap">Type</th><th class="whitespace-nowrap">Capacity</th><th class="whitespace-nowrap">Fuel</th><th class="whitespace-nowrap">GPS</th><th class="whitespace-nowrap">Status</th><th class="whitespace-nowrap"></th></tr></thead>
    <tbody>
    <?php foreach ($vehicles as $v): ?>
      <tr style="<?= $v['is_active'] ? '' : 'opacity:.55' ?>">
        <td class="cell-wrap"><strong><?= htmlspecialchars($v['plate_number']) ?></strong></td>
        <td class="cell-wrap"><?= htmlspecialchars(($v['brand'] ?? '') . ' ' . $v['model_name']) ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars($v['type_name']) ?></td>
        <td class="whitespace-nowrap"><?= (int) $v['capacity'] ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars((string) $v['default_fuel_type']) ?></td>
        <td class="whitespace-nowrap"><?= (int) $v['paired_devices'] > 0 ? '<span class="text-completed">✔</span>' : '<span class="text-text-muted">none</span>' ?></td>
        <td class="whitespace-nowrap">
          <?php if ((int) $v['is_active']): ?>
            <span class="badge badge-<?= $v['is_available'] ? 'completed' : 'rejected' ?>"><?= $v['is_available'] ? 'Available' : 'In Use' ?></span>
          <?php else: ?><span class="badge badge-rejected">Inactive</span><?php endif; ?>
        </td>
        <td class="whitespace-nowrap">
          <span class="inline-flex gap-1.5">
            <button class="<?= $btnGhost ?>" data-edit='<?= htmlspecialchars(json_encode([
                'id' => (int) $v['vehicle_id'], 'plate_number' => $v['plate_number'], 'model_name' => $v['model_name'],
                'brand' => $v['brand'], 'type_id' => (int) $v['type_id'], 'capacity' => (int) $v['capacity'],
                'default_fuel_type' => $v['default_fuel_type'], 'is_available' => (int) $v['is_available'],
            ])) ?>' data-modal="editModal">Edit</button>
            <button class="<?= $btnDanger ?>" data-toggle-action="vehicle_toggle" data-pk="vehicle_id" data-val="<?= (int) $v['vehicle_id'] ?>"
                    data-confirm="<?= $v['is_active'] ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars($v['plate_number']) ?>?"><?= $v['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- GPS Devices -->
<div class="content-card mt-3.5">
  <div class="list-head">
    <h2 class="text-lg font-bold">GPS Devices</h2>
    <span class="hidden md:inline text-text-muted text-sm">Bearer-token auth · raw token shown only at registration</span>
    <div class="list-head-actions">
      <input type="search" placeholder="Search devices" data-filter="#tblDevices" class="<?= $searchCls ?> input-shrink">
      <button class="btn btn-ghost" onclick="UI.modal('trackerModal', true)">+ Register Tracker Account</button>
      <button class="<?= $btnPrimary ?> btn-wide" onclick="UI.modal('deviceModal', true)">+ Register Device</button>
    </div>
  </div>
  <div class="overflow-x-auto">
  <table class="data-table" id="tblDevices" data-pager>
    <thead><tr><th class="cell-wrap">UID</th><th class="cell-wrap">Name</th><th class="whitespace-nowrap">Paired Vehicle</th><th class="whitespace-nowrap">Last Seen</th><th class="cell-wrap">Link</th><th class="whitespace-nowrap">Status</th><th class="whitespace-nowrap"></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d): ?>
      <tr style="<?= $d['is_active'] ? '' : 'opacity:.55' ?>">
        <td class="cell-wrap"><strong><?= htmlspecialchars($d['device_uid']) ?></strong></td>
        <td class="cell-wrap"><?= htmlspecialchars((string) ($d['device_name'] ?? '—')) ?></td>
        <td class="whitespace-nowrap" style="min-width:180px">
          <select data-pair-device="<?= (int) $d['device_id'] ?>" class="<?= $inputCls ?>">
            <option value="0">— unpaired —</option>
            <?php foreach ($vehicles as $v): if (!(int) $v['is_active']) continue; ?>
              <option value="<?= (int) $v['vehicle_id'] ?>" <?= (int) $d['vehicle_id'] === (int) $v['vehicle_id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate_number']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="whitespace-nowrap"><?= $d['last_seen_at'] ? date('M j g:i A', strtotime((string) $d['last_seen_at'])) : '<span class="text-text-muted">never</span>' ?>
            <?= $d['online'] ? '<span class="badge badge-completed ml-1">LIVE</span>' : '' ?></td>
        <td class="cell-wrap"><?= $d['plate_number'] ? htmlspecialchars((string) $d['plate_number']) : '—' ?></td>
        <td class="whitespace-nowrap"><span class="badge badge-<?= $d['is_active'] ? 'completed' : 'rejected' ?>"><?= $d['is_active'] ? 'Active' : 'Disabled' ?></span></td>
        <td class="whitespace-nowrap">
          <span class="inline-flex gap-1.5">
            <button class="<?= $btnGhost ?>" data-device-edit="<?= (int) $d['device_id'] ?>">Edit</button>
            <button class="<?= $btnGhost ?>" data-toggle-action="device_toggle" data-pk="device_id" data-val="<?= (int) $d['device_id'] ?>"
                    data-confirm="<?= $d['is_active'] ? 'Disable' : 'Enable' ?> device <?= htmlspecialchars($d['device_uid']) ?>?"><?= $d['is_active'] ? 'Disable' : 'Enable' ?></button>
            <button class="<?= $btnDanger ?>" data-delete-device="<?= (int) $d['device_id'] ?>" data-confirm="Delete device <?= htmlspecialchars($d['device_uid']) ?>? This permanently removes it and its history.">Delete</button>
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Vehicle Types -->
<div class="content-card p-3.5 mt-3.5 card-narrow">
  <div class="flex items-center gap-2.5 mb-3">
    <h2 class="text-lg font-bold">Vehicle Types</h2>
    <button class="<?= $btnPrimary ?> btn-wide ml-auto" data-edit='{"id":0}' data-modal="typeModal">+ Add Vehicle Type</button>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($types as $tp): ?>
      <span class="flex items-center gap-1">
        <span class="badge badge-draft"><?= htmlspecialchars($tp['type_name']) ?></span>
        <button class="icon-btn icon-btn-sm" title="Edit type" data-edit='<?= htmlspecialchars(json_encode(['id' => (int) $tp['type_id'], 'type_name' => $tp['type_name']])) ?>' data-modal="typeModal"><?= icon('edit') ?></button>
        <button class="icon-btn icon-btn-sm icon-btn-danger" title="Delete type" data-type-del="<?= (int) $tp['type_id'] ?>" data-confirm="Delete type <?= htmlspecialchars($tp['type_name']) ?>?"><?= icon('trash') ?></button>
      </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- Vehicle modal -->
<div class="modal-backdrop" id="editModal">
  <div class="modal-body" style="max-width:480px">
    <div class="modal-head">
      <span data-role="title" data-add-label="Register Vehicle" data-edit-label="Edit Vehicle">Register Vehicle</span>
      <button class="modal-close" type="button" onclick="UI.modal('editModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <form id="vehicleForm" autocomplete="off" onsubmit="return false">
        <input type="hidden" name="vehicle_id" value="0" data-role="pk">
        <label class="<?= $labelCls ?>">Plate Number *</label><input name="plate_number" required maxlength="20" class="<?= $inputCls ?>" style="text-transform:uppercase">
        <label class="<?= $labelCls ?> mt-2.5">Brand</label><input name="brand" maxlength="50" class="<?= $inputCls ?>">
        <label class="<?= $labelCls ?> mt-2.5">Model *</label><input name="model_name" required maxlength="100" class="<?= $inputCls ?>">
        <label class="<?= $labelCls ?> mt-2.5">Type *</label>
        <select name="type_id" required class="<?= $inputCls ?>">
          <option value="">— select —</option>
          <?php foreach ($types as $tp): ?><option value="<?= (int) $tp['type_id'] ?>"><?= htmlspecialchars($tp['type_name']) ?></option><?php endforeach; ?>
        </select>
        <div class="grid grid-cols-2 gap-3 mt-2.5">
          <div><label class="<?= $labelCls ?>">Capacity *</label><input name="capacity" type="number" min="1" max="999" required value="1" class="<?= $inputCls ?>"></div>
          <div>
            <label class="<?= $labelCls ?>">Default Fuel</label>
            <select name="default_fuel_type" class="<?= $inputCls ?>"><option>Gasoline</option><option>Diesel</option></select>
          </div>
        </div>
        <div class="mt-2.5"><label class="checkbox-row"><input type="checkbox" name="is_available" checked> <span class="text-md">Available for dispatch</span></label></div>
      </form>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('editModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="vehicleFormSubmit">Save Vehicle</button>
    </div>
  </div>
</div>

<!-- Vehicle type modal -->
<div class="modal-backdrop" id="typeModal">
  <div class="modal-body" style="max-width:400px">
    <div class="modal-head">
      <span data-role="title" data-add-label="Add Vehicle Type" data-edit-label="Edit Vehicle Type">Add Vehicle Type</span>
      <button class="modal-close" type="button" onclick="UI.modal('typeModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <form id="typeForm" autocomplete="off" onsubmit="return false">
        <input type="hidden" name="type_id" value="0" data-role="pk">
        <label class="<?= $labelCls ?>">Type Name *</label>
        <input name="type_name" required maxlength="50" class="<?= $inputCls ?>" placeholder="e.g. Ambulance">
      </form>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('typeModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="typeFormSubmit">Save Type</button>
    </div>
  </div>
</div>

<!-- Device modal -->
<div class="modal-backdrop" id="deviceModal">
  <div class="modal-body" style="max-width:460px">
    <div class="modal-head">
      <span>Register GPS Device</span>
      <button class="modal-close" type="button" onclick="UI.modal('deviceModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <form id="deviceForm" autocomplete="off" onsubmit="return false">
        <label class="<?= $labelCls ?>">Device UID * <span class="text-text-muted">(e.g. GPS-NAAP-005)</span></label><input name="device_uid" required maxlength="64" class="<?= $inputCls ?>">
        <label class="<?= $labelCls ?> mt-2.5">Device Name</label><input name="device_name" maxlength="100" class="<?= $inputCls ?>">
        <div class="grid grid-cols-2 gap-3 mt-2.5">
          <div><label class="<?= $labelCls ?>">Manufacturer</label><input name="manufacturer" maxlength="50" class="<?= $inputCls ?>"></div>
          <div><label class="<?= $labelCls ?>">Model</label><input name="model" maxlength="50" class="<?= $inputCls ?>"></div>
        </div>
      </form>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnPrimary ?>" id="deviceFormSubmit">Generate Token</button>
    </div>
  </div>
</div>

<!-- Hidden tracker account modal -->
<div class="modal-backdrop" id="trackerModal">
  <div class="modal-body" style="max-width:460px">
    <div class="modal-head">
      <span>Register Tracker Account</span>
      <button class="modal-close" type="button" onclick="UI.modal('trackerModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <p class="text-sm text-text-muted mb-2">Creates a hidden account that owns a paired GPS device token. The tracker posts fixes like hardware — same endpoint, same bearer token — and can sign in with the login below to view its own status page.</p>
      <form id="trackerForm" autocomplete="off" onsubmit="return false">
        <label class="<?= $labelCls ?>">Vehicle *</label>
        <select name="vehicle_id" required class="<?= $inputCls ?>">
          <option value="">— select —</option>
          <?php foreach ($vehicles as $v): if (!(int) $v['is_active']) continue; ?>
            <option value="<?= (int) $v['vehicle_id'] ?>"><?= htmlspecialchars($v['plate_number']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="<?= $labelCls ?> mt-2.5">Device UID <span class="text-text-muted">(optional — auto if blank)</span></label>
        <input name="device_uid" maxlength="64" class="<?= $inputCls ?>" placeholder="<?= htmlspecialchars($nextTrackerUid) ?>">
        <label class="<?= $labelCls ?> mt-2.5">Tracker Name</label>
        <input name="device_name" maxlength="100" class="<?= $inputCls ?>" placeholder="e.g. Hiace Phone Tracker">
        <label class="<?= $labelCls ?> mt-2.5">Login Email <span class="text-text-muted">(optional — auto-generated if blank)</span></label>
        <input name="email" type="email" maxlength="100" class="<?= $inputCls ?>" placeholder="<?= htmlspecialchars(strtolower($nextTrackerUid) . '@naap.local') ?>">
        <label class="<?= $labelCls ?> mt-2.5">Login Password *</label>
        <div class="flex gap-2">
          <div class="relative flex-1">
            <input id="trackerPw" name="password" type="password" minlength="8" autocomplete="new-password" required class="<?= $inputCls ?> pr-10">
            <button type="button" class="icon-btn icon-btn-bare pw-toggle absolute inset-y-0 right-1.5 my-auto" data-pw-toggle="trackerPw" aria-label="Show password" tabindex="-1">
              <span class="pw-ico pw-ico-show"><?= icon('eye') ?></span>
              <span class="pw-ico pw-ico-hide"><?= icon('eye-off') ?></span>
            </button>
          </div>
          <button type="button" class="btn btn-ghost" id="trackerPwGenerate">Generate</button>
        </div>
        <div class="pw-req" data-pw-req="trackerPw"></div>
      </form>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('trackerModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="trackerFormSubmit">Create Tracker</button>
    </div>
  </div>
</div>

<!-- Generated API token modal -->
<div class="modal-backdrop" id="tokenModal">
  <div class="modal-body" style="max-width:460px">
    <div class="modal-head">
      <span id="tokenModalTitle">Device Registered</span>
      <button class="modal-close" type="button" onclick="UI.modal('tokenModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <p class="text-sm text-text-muted mb-2">Copy this API token now — it will not be shown again. The device sends it as a <code>Bearer</code> header when posting GPS fixes.</p>
      <p id="trackerInfo" class="text-sm text-text-muted mb-2" style="display:none">Hidden tracker account: <code id="trackerEmailOut" class="break-all text-xs"></code></p>
      <div id="tokenBox" style="background:rgba(56,189,248,.08);border:1px solid var(--accent,#38BDF8);border-radius:8px;padding:10px">
        <div class="text-xs font-bold uppercase mb-1">API Token</div>
        <code id="deviceTokenOut" class="break-all text-xs"></code>
      </div>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" id="btnTokenCopy">Copy</button>
      <button class="<?= $btnPrimary ?>" type="button" id="btnTokenDone">Done</button>
    </div>
  </div>
</div>

<!-- Device edit modal (hardware + tracker; login fields shown for trackers) -->
<div class="modal-backdrop" id="deviceEditModal">
  <div class="modal-body" style="max-width:460px">
    <div class="modal-head">
      <span>Edit GPS Device</span>
      <button class="modal-close" type="button" onclick="UI.modal('deviceEditModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <form id="deviceEditForm" autocomplete="off" onsubmit="return false">
        <input type="hidden" name="device_id" value="0">
        <label class="<?= $labelCls ?>">Device UID *</label>
        <input name="device_uid" required maxlength="64" class="<?= $inputCls ?>">
        <label class="<?= $labelCls ?> mt-2.5">Device Name</label>
        <input name="device_name" maxlength="100" class="<?= $inputCls ?>">
        <div data-hardware-only class="grid grid-cols-2 gap-3 mt-2.5">
          <div><label class="<?= $labelCls ?>">Manufacturer</label><input name="manufacturer" maxlength="50" class="<?= $inputCls ?>"></div>
          <div><label class="<?= $labelCls ?>">Model</label><input name="model" maxlength="50" class="<?= $inputCls ?>"></div>
        </div>
        <div data-tracker-only style="display:none">
          <label class="<?= $labelCls ?> mt-2.5">Login Email *</label>
          <input name="email" type="email" maxlength="100" class="<?= $inputCls ?>">
          <label class="<?= $labelCls ?> mt-2.5">New Password <span class="text-text-muted">(leave blank to keep current)</span></label>
          <div class="flex gap-2">
            <div class="relative flex-1">
              <input id="deviceEditPw" name="password" type="password" minlength="8" autocomplete="new-password" class="<?= $inputCls ?> pr-10">
              <button type="button" class="icon-btn icon-btn-bare pw-toggle absolute inset-y-0 right-1.5 my-auto" data-pw-toggle="deviceEditPw" aria-label="Show password" tabindex="-1">
                <span class="pw-ico pw-ico-show"><?= icon('eye') ?></span>
                <span class="pw-ico pw-ico-hide"><?= icon('eye-off') ?></span>
              </button>
            </div>
            <button type="button" class="btn btn-ghost" id="deviceEditPwGenerate">Generate</button>
          </div>
          <p class="text-text-muted text-sm mt-1.5">Changing the password signs out any active tracker sessions.</p>
        </div>
      </form>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('deviceEditModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="deviceEditSubmit">Save Device</button>
    </div>
  </div>
</div>

<?php
$PAGE_SCRIPTS = ['password-policy.js', 'admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
