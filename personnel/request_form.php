<?php
declare(strict_types=1);
/**
 * personnel/request_form.php — create / edit a DRAFT trip request.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [6]);
$ACTIVE = BASE_URL . 'personnel/requests.php';
$PAGE_TITLE = 'Trip Request Form';

$t = [
    'draft_id' => 0, 'passenger_name' => '', 'surname' => '', 'middle_initial' => '', 'middle_name' => '', 'suffix' => '',
    'id_number' => '', 'requester_type' => 'Personnel', 'email' => '', 'place_to_visit' => '', 'purpose' => '',
    'number_of_passengers' => 1, 'scheduled_departure' => null, 'scheduled_return' => null,
    'place_to_visit_name' => '', 'place_to_visit_lat' => null, 'place_to_visit_lon' => null,
    'place_to_visit_place_id' => '', 'attachment_path' => '',
];

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM trip_drafts WHERE draft_id = ? AND created_by = ?');
    $st->execute([$id, $USER['id']]);
    $row = $st->fetch();
    if (!$row) {
        header('Location: ' . BASE_URL . 'personnel/requests.php');
        exit;
    }
    foreach ($t as $k => $_) {
        if (array_key_exists($k, $row)) {
            $t[$k] = $row[$k];
        }
    }
}

/* Saved passenger manifest (draft only) — rehydrates the modal when editing. */
$paxRows = [];
if ($id > 0) {
    $paxSt = $pdo->prepare('SELECT surname, first_name, middle_initial, middle_name FROM trip_draft_passengers WHERE draft_id = ? ORDER BY draft_passenger_id');
    $paxSt->execute([$id]);
    $paxRows = $paxSt->fetchAll();
}
$paxJson = json_encode(array_map(static fn(array $p): array => [
    'surname'        => (string) ($p['surname'] ?? ''),
    'first_name'     => (string) ($p['first_name'] ?? ''),
    'middle_initial' => (string) ($p['middle_initial'] ?? ''),
    'middle_name'    => (string) ($p['middle_name'] ?? ''),
], $paxRows), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$toLocal = static fn(?string $d): string => $d ? substr(str_replace(' ', 'T', $d), 0, 16) : '';

/* Upload cap: never promise more than PHP will accept. The 20 MB product limit
 * is reduced to upload_max_filesize / post_max_size when the server is lower. */
$iniBytes = static function (string $v): int {
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $unit = strtolower($v[strlen($v) - 1]);
    $n    = (float) $v;
    return (int) match ($unit) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
};
$maxUpload = 20 * 1024 * 1024;
foreach ([ini_get('upload_max_filesize'), ini_get('post_max_size')] as $iniLimit) {
    $bytes = $iniBytes((string) $iniLimit);
    if ($bytes > 0 && $bytes < $maxUpload) {
        $maxUpload = $bytes;
    }
}
$maxUploadMb = max(1, (int) floor($maxUpload / 1048576));

$HEAD_EXTRAS =
    '<link rel="stylesheet" href="' . BASE_URL . 'assets/js/vendor/flatpickr/flatpickr.min.css">' .
    '<script src="' . BASE_URL . 'assets/js/vendor/flatpickr/flatpickr.min.js"></script>' .
    '<link rel="preconnect" href="https://maps.googleapis.com" crossorigin>' .
    '<link rel="preconnect" href="https://maps.gstatic.com" crossorigin>' .
    '<script>window.NAAP_MAPS_KEY=' . json_encode(GOOGLE_MAPS_API_KEY) . ';</script>';

$VENDOR_SCRIPTS = ['maps_loader.js', 'schedule_picker.js'];
$PAGE_SCRIPTS = ['location-autocomplete.js', 'geo.js', 'request_form.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$inputCls = 'form-input';
$labelCls = 'form-label';
?>
<form id="reqForm" autocomplete="off" onsubmit="return false">
<input type="hidden" id="f_draft_id" value="<?= (int) $t['draft_id'] ?>">
<input type="hidden" id="f_attachment_path" value="<?= htmlspecialchars((string) $t['attachment_path']) ?>">
<input type="hidden" id="f_late_days" value="<?= max(1, (int) Settings::getInt('LATE_ADVANCE_DAYS', 3)) ?>">
<script type="application/json" id="f_passengers_json"><?= $paxJson ?></script>
<div class="content-card p-3.5">
  <div class="flex items-center gap-2.5 mb-3.5">
    <h2 class="text-lg font-bold">Driver's Trip Ticket — Request Details</h2>
    <span class="badge badge-draft"><?= $t['draft_id'] ? 'DRAFT' : 'NEW' ?></span>
    <span id="saveState" class="text-text-muted ml-auto text-sm"></span>
  </div>

  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <div class="grid grid-cols-4 max-lg:grid-cols-2 gap-3 gap-x-3.5 span-full">
      <div class="col-span-2"><label class="<?= $labelCls ?>">First Name *</label><input id="f_passenger_name" maxlength="150" value="<?= htmlspecialchars((string) $t['passenger_name']) ?>" class="<?= $inputCls ?> input-cap" placeholder="First Name"></div>
      <div class="col-span-2">
        <div class="grid grid-cols-4 gap-3 gap-x-3.5">
          <div><label class="<?= $labelCls ?>">M.I.</label><input id="f_middle_initial" maxlength="3" value="<?= htmlspecialchars((string) ($t['middle_initial'] ?? '')) ?>" class="<?= $inputCls ?> input-cap" placeholder="M."></div>
          <div class="col-span-3"><label class="<?= $labelCls ?>">Middle Initial</label><input id="f_middle_name" maxlength="100" value="<?= htmlspecialchars((string) ($t['middle_name'] ?? '')) ?>" class="<?= $inputCls ?> input-cap" placeholder="REYES"></div>
        </div>
      </div>
      <div class="col-span-2"><label class="<?= $labelCls ?>">Surname</label><input id="f_surname" maxlength="100" value="<?= htmlspecialchars((string) ($t['surname'] ?? '')) ?>" class="<?= $inputCls ?> input-cap" placeholder="Surname"></div>
      <div class="col-span-2"><label class="<?= $labelCls ?>">Suffix</label><input id="f_suffix" maxlength="10" value="<?= htmlspecialchars((string) ($t['suffix'] ?? '')) ?>" class="<?= $inputCls ?> input-cap" placeholder="Jr., Sr., III"></div>
    </div>
    <div><label class="<?= $labelCls ?>">ID Number *</label><input id="f_id_number" maxlength="50" value="<?= htmlspecialchars((string) $t['id_number']) ?>" class="<?= $inputCls ?> input-cap" placeholder="Employee ID"></div>
    <div>
      <label class="<?= $labelCls ?>">Requester Type</label>
      <select id="f_requester_type" class="<?= $inputCls ?>">
        <?php foreach (['Personnel', 'Student'] as $rt): ?>
        <option <?= $t['requester_type'] === $rt ? 'selected' : '' ?>><?= $rt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="<?= $labelCls ?>">Email *</label><input id="f_email" type="email" maxlength="150" value="<?= htmlspecialchars((string) ($t['email'] ?? '')) ?>" class="<?= $inputCls ?>" placeholder="name@email.com"></div>
    <div><label class="<?= $labelCls ?>">No. of Passengers</label>
      <input id="f_number_of_passengers" type="number" min="1" max="999" value="<?= (int) $t['number_of_passengers'] ?>" class="<?= $inputCls ?>" placeholder="1">
      <div class="flex items-center gap-2 mt-1.5 flex-wrap">
        <button class="btn btn-sm btn-ghost" type="button" id="btnPaxNames">Manage Names</button>
        <span class="text-text-muted text-sm" id="paxSummary"></span>
      </div>
    </div>
    <div class="span-full"><label class="<?= $labelCls ?>">Destination *</label>
      <input id="f_place_to_visit" maxlength="255" value="<?= htmlspecialchars((string) $t['place_to_visit']) ?>" class="<?= $inputCls ?>" placeholder="Search for a place">
      <input type="hidden" id="f_place_to_visit_name" value="<?= htmlspecialchars((string) ($t['place_to_visit_name'] ?? '')) ?>">
      <input type="hidden" id="f_place_to_visit_lat" value="<?= (float) ($t['place_to_visit_lat'] ?? 0) ?: '' ?>">
      <input type="hidden" id="f_place_to_visit_lon" value="<?= (float) ($t['place_to_visit_lon'] ?? 0) ?: '' ?>">
      <input type="hidden" id="f_place_to_visit_place_id" value="<?= htmlspecialchars((string) ($t['place_to_visit_place_id'] ?? '')) ?>">
      <div id="destMap" class="map-mini mt-2"
           data-origin-lat="<?= htmlspecialchars((string) Settings::getFloat('NAAP_ORIGIN_LAT')) ?>"
           data-origin-lng="<?= htmlspecialchars((string) Settings::getFloat('NAAP_ORIGIN_LNG')) ?>"
           data-origin-name="<?= htmlspecialchars((string) Settings::get('NAAP_ORIGIN_NAME', '')) ?>"
           data-est-kph="<?= (int) Settings::getInt('ROUTE_ESTIMATE_KPH', 40) ?>"></div>
    </div>
    <div class="span-full"><label class="<?= $labelCls ?>">Purpose of Travel *</label><textarea id="f_purpose" rows="3" class="<?= $inputCls ?> no-resize" placeholder="State the official purpose of the trip"><?= htmlspecialchars((string) $t['purpose']) ?></textarea></div>
    <div><label class="<?= $labelCls ?>">Scheduled Departure *</label><input id="f_scheduled_departure" type="text" value="<?= htmlspecialchars($toLocal($t['scheduled_departure'])) ?>" class="<?= $inputCls ?>" placeholder="Select date & time"></div>
    <div><label class="<?= $labelCls ?>">Scheduled Return *</label><input id="f_scheduled_return" type="text" value="<?= htmlspecialchars($toLocal($t['scheduled_return'])) ?>" class="<?= $inputCls ?>" placeholder="Select date & time"></div>
    <div class="span-full">
      <label class="<?= $labelCls ?>">Request Letter *</label>
      <label class="upload-drop" id="uploadDrop" for="f_request_letter" data-max-bytes="<?= (int) $maxUpload ?>">
        <input type="file" id="f_request_letter" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx">
        <span class="upload-icon"><?= icon('cloud') ?></span>
        <span id="uploadText">Upload File (Max <?= (int) $maxUploadMb ?>mb)</span>
        <span class="upload-hint">PDF, PNG, JPG, DOC, DOCX</span>
        <div class="upload-progress" id="uploadProgress" role="progressbar"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden>
          <div class="upload-bar" id="uploadBar"></div>
        </div>
        <span class="upload-percent" id="uploadPercent" aria-live="polite"></span>
      </label>
      <p class="text-text-muted text-sm mt-1.5">Note: Trip Departure will be set 3 days after Confirmation</p>
    </div>
  </div>

  <div class="flex gap-2 mt-4.5 flex-wrap">
    <a class="btn btn-ghost" href="<?= BASE_URL ?>personnel/requests.php">Back</a>
    <button class="btn btn-ghost" type="button" id="btnSave">Save Draft</button>
    <button class="btn btn-primary" type="button" id="btnSubmit">Submit for Approval</button>
  </div>
</div>
</form>

<div class="modal-backdrop" id="paxModal">
  <div class="modal-body" style="max-width:640px">
    <div class="modal-head">
      <span id="paxModalTitle">Passenger Names</span>
      <button class="modal-close" type="button" id="paxClose"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <label class="checkbox-row mb-3">
        <input type="checkbox" id="paxAddSelf" checked>
        <span class="font-semibold">Add yourself — use my travel-order name as the first passenger</span>
      </label>
      <div id="paxRows" class="flex flex-col gap-3"></div>
      <p class="text-text-muted text-sm mt-2.5">Enter the surname, first name, and middle initial for each passenger. The number of rows matches the No. of Passengers field.</p>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" type="button" id="paxCancel">Cancel</button>
      <button class="btn btn-primary" type="button" id="paxSave">Save Names</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="exitDraftModal">
  <div class="modal-body" style="max-width:420px">
    <div class="modal-head">
      <span>Unsaved Changes</span>
      <button class="modal-close" type="button" onclick="UI.modal('exitDraftModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <p class="m-0 font-semibold">Do you want to save this Trip Ticket as a draft?</p>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2 flex-wrap">
      <button class="btn btn-ghost" type="button" id="exitStay">Stay</button>
      <button class="btn btn-ghost" type="button" id="exitLeaveNoSave">Leave without Saving</button>
      <button class="btn btn-primary" type="button" id="exitSaveLeave">Save &amp; Leave</button>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>