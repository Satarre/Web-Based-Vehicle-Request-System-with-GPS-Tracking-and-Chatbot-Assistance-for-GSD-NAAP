<?php
declare(strict_types=1);
/**
 * director/vehicles.php — HR vehicle management (CRUD + deactivate).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [2]);
$ACTIVE = BASE_URL . 'director/vehicles.php';
$PAGE_TITLE = 'Vehicles';

$total      = (int) $pdo->query('SELECT COUNT(*) FROM vehicle WHERE is_active = 1')->fetchColumn();
$available  = (int) $pdo->query('SELECT COUNT(*) FROM vehicle WHERE is_active = 1 AND is_available = 1')->fetchColumn();
$inactive   = (int) $pdo->query('SELECT COUNT(*) FROM vehicle WHERE is_active = 0')->fetchColumn();

$vehicles = $pdo->query(
    'SELECT v.*, t.type_name
     FROM vehicle v JOIN vehicle_types t ON t.type_id = v.type_id
     ORDER BY v.is_active DESC, v.plate_number'
)->fetchAll();

$types = $pdo->query('SELECT * FROM vehicle_types WHERE is_active = 1 ORDER BY type_name')->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$labelCls = 'form-label';
$inputCls = 'form-input';
$btnPrimary = 'btn btn-primary';
$btnPrimarySm = 'btn btn-sm btn-primary';
$btnGhost = 'btn btn-sm btn-ghost';
$btnDanger = 'btn btn-sm btn-danger';
?>
<div class="flex items-center gap-2.5 mb-3.5 flex-wrap">
  <h1 class="text-xl">Vehicles</h1>
</div>

<!-- Sidebar stats -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 mb-3.5">
  <div class="stat-card">
    <div class="stat-card-label">Total Vehicles</div>
    <div class="stat-card-value"><?= $total ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Available</div>
    <div class="stat-card-value"><?= $available ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Inactive</div>
    <div class="stat-card-value"><?= $inactive ?></div>
  </div>
</div>

<!-- Vehicle table -->
<div class="content-card">
  <div class="flex items-center gap-2.5 px-4 py-3 border-b border-border">
    <h2 class="text-lg font-bold">Vehicle Registry</h2>
    <input type="search" placeholder="Search vehicles…" data-filter="#tblVehicles" class="<?= $inputCls ?> ml-auto w-48">
    <button class="<?= $btnPrimary ?>" data-edit='{"id":0}' data-modal="editModal">+ Add Vehicle</button>
  </div>
  <div class="overflow-x-auto">
  <table class="data-table" id="tblVehicles" data-pager>
    <thead><tr><th class="whitespace-nowrap">Plate</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Type</th><th class="whitespace-nowrap">Capacity</th><th class="whitespace-nowrap">Available</th><th class="whitespace-nowrap">Status</th><th class="whitespace-nowrap"></th></tr></thead>
    <tbody>
    <?php foreach ($vehicles as $v): ?>
      <tr style="<?= $v['is_active'] ? '' : 'opacity:.55' ?>">
        <td class="whitespace-nowrap"><strong><?= htmlspecialchars($v['plate_number']) ?></strong></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars(($v['brand'] ?? '') . ' ' . $v['model_name']) ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars($v['type_name']) ?></td>
        <td class="whitespace-nowrap"><?= (int) $v['capacity'] ?></td>
        <td class="whitespace-nowrap">
          <?php if ((int) $v['is_active']): ?>
            <span class="badge badge-<?= $v['is_available'] ? 'completed' : 'rejected' ?>"><?= $v['is_available'] ? 'Yes' : 'No' ?></span>
          <?php else: ?><span class="text-text-muted">—</span><?php endif; ?>
        </td>
        <td class="whitespace-nowrap">
          <?php if ((int) $v['is_active']): ?>
            <span class="badge badge-completed">Active</span>
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

<!-- Vehicle modal -->
<div class="modal-backdrop" id="editModal">
  <div class="modal-body" style="max-width:480px">
    <div class="modal-head">
      <span data-role="title" data-add-label="Register Vehicle" data-edit-label="Edit Vehicle">Register Vehicle</span>
      <button class="modal-close" type="button" onclick="UI.modal('editModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="px-[18px] py-4">
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
        <div class="flex items-center gap-1.5 mt-2.5"><input type="checkbox" name="is_available" checked> <span class="text-md">Available for dispatch</span></div>
      </form>
    </div>
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('editModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="vehicleFormSubmit">Save Vehicle</button>
    </div>
  </div>
</div>

<?php
$PAGE_SCRIPTS = ['admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
