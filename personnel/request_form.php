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
    'ticket_id' => 0, 'passenger_name' => '', 'surname' => '', 'middle_initial' => '', 'suffix' => '',
    'id_number' => '', 'requester_type' => 'Personnel', 'email' => '', 'place_to_visit' => '', 'purpose' => '',
    'number_of_passengers' => 1, 'scheduled_departure' => null, 'scheduled_return' => null,
    'place_to_visit_name' => '', 'place_to_visit_lat' => null, 'place_to_visit_lon' => null,
    'place_to_visit_place_id' => '', 'attachment_path' => '',
];

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM trip_tickets WHERE ticket_id = ? AND created_by = ? AND is_active = 1');
    $st->execute([$id, $USER['id']]);
    $row = $st->fetch();
    if (!$row || (int) $row['status_id'] !== TripState::DRAFT) {
        header('Location: ' . BASE_URL . 'ticket.php?id=' . $id);
        exit;
    }
    foreach ($t as $k => $_) {
        if (array_key_exists($k, $row)) {
            $t[$k] = $row[$k];
        }
    }
}
$toLocal = static fn(?string $d): string => $d ? substr(str_replace(' ', 'T', $d), 0, 16) : '';

$HEAD_EXTRAS =
    '<link rel="stylesheet" href="' . BASE_URL . 'assets/js/vendor/flatpickr/flatpickr.min.css">' .
    '<script src="' . BASE_URL . 'assets/js/vendor/flatpickr/flatpickr.min.js"></script>' .
    '<link rel="stylesheet" href="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.css">' .
    '<script src="' . BASE_URL . 'assets/js/vendor/leaflet/leaflet.js"></script>';

$PAGE_SCRIPTS = ['location-autocomplete.js', 'request_form.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$inputCls = 'form-input';
$labelCls = 'form-label';
?>
<form id="reqForm" autocomplete="off" onsubmit="return false">
<input type="hidden" id="f_ticket_id" value="<?= (int) $t['ticket_id'] ?>">
<input type="hidden" id="f_attachment_path" value="<?= htmlspecialchars((string) $t['attachment_path']) ?>">
<div class="content-card p-3.5">
  <div class="flex items-center gap-2.5 mb-3.5">
    <h2 class="text-lg font-bold">Driver's Trip Ticket — Request Details</h2>
    <span class="badge badge-draft"><?= $t['ticket_id'] ? 'DRAFT #' . (int) $t['ticket_id'] : 'NEW' ?></span>
    <span id="saveState" class="text-text-muted ml-auto text-sm"></span>
  </div>

  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <div class="grid grid-cols-4 max-lg:grid-cols-2 gap-3 gap-x-3.5" style="grid-column:span 2">
      <div class="col-span-2"><label class="<?= $labelCls ?>">First Name *</label><input id="f_passenger_name" maxlength="150" value="<?= htmlspecialchars((string) $t['passenger_name']) ?>" class="<?= $inputCls ?>" placeholder="First Name"></div>
      <div><label class="<?= $labelCls ?>">Middle Initial</label><input id="f_middle_initial" maxlength="3" value="<?= htmlspecialchars((string) ($t['middle_initial'] ?? '')) ?>" class="<?= $inputCls ?> input-mi" placeholder="M."></div>
      <div class="col-span-2"><label class="<?= $labelCls ?>">Surname</label><input id="f_surname" maxlength="100" value="<?= htmlspecialchars((string) ($t['surname'] ?? '')) ?>" class="<?= $inputCls ?>" placeholder="Surname"></div>
      <div><label class="<?= $labelCls ?>">Suffix</label><input id="f_suffix" maxlength="10" value="<?= htmlspecialchars((string) ($t['suffix'] ?? '')) ?>" class="<?= $inputCls ?>" placeholder="Jr., Sr., III"></div>
    </div>
    <div><label class="<?= $labelCls ?>">ID Number *</label><input id="f_id_number" maxlength="50" value="<?= htmlspecialchars((string) $t['id_number']) ?>" class="<?= $inputCls ?>" placeholder="Employee ID"></div>
    <div>
      <label class="<?= $labelCls ?>">Requester Type</label>
      <select id="f_requester_type" class="<?= $inputCls ?>">
        <?php foreach (['Personnel', 'Student'] as $rt): ?>
        <option <?= $t['requester_type'] === $rt ? 'selected' : '' ?>><?= $rt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="<?= $labelCls ?>">Email *</label><input id="f_email" type="email" maxlength="150" value="<?= htmlspecialchars((string) ($t['email'] ?? '')) ?>" class="<?= $inputCls ?>" placeholder="name@email.com"></div>
    <div><label class="<?= $labelCls ?>">No. of Passengers</label><input id="f_number_of_passengers" type="number" min="1" max="999" value="<?= (int) $t['number_of_passengers'] ?>" class="<?= $inputCls ?>" placeholder="1"></div>
    <div style="grid-column:span 2"><label class="<?= $labelCls ?>">Destination *</label>
      <input id="f_place_to_visit" maxlength="255" value="<?= htmlspecialchars((string) $t['place_to_visit']) ?>" class="<?= $inputCls ?>" placeholder="Search for a place...">
      <input type="hidden" id="f_place_to_visit_name" value="<?= htmlspecialchars((string) ($t['place_to_visit_name'] ?? '')) ?>">
      <input type="hidden" id="f_place_to_visit_lat" value="<?= (float) ($t['place_to_visit_lat'] ?? 0) ?: '' ?>">
      <input type="hidden" id="f_place_to_visit_lon" value="<?= (float) ($t['place_to_visit_lon'] ?? 0) ?: '' ?>">
      <input type="hidden" id="f_place_to_visit_place_id" value="<?= htmlspecialchars((string) ($t['place_to_visit_place_id'] ?? '')) ?>">
      <div id="destMap" class="map-mini mt-2"></div>
    </div>
    <div style="grid-column:span 2"><label class="<?= $labelCls ?>">Purpose of Travel *</label><textarea id="f_purpose" rows="3" class="<?= $inputCls ?> no-resize" placeholder="State the official purpose of the trip"><?= htmlspecialchars((string) $t['purpose']) ?></textarea></div>
    <div><label class="<?= $labelCls ?>">Scheduled Departure *</label><input id="f_scheduled_departure" type="text" value="<?= $toLocal($t['scheduled_departure']) ?>" class="<?= $inputCls ?>" placeholder="Select date & time"></div>
    <div><label class="<?= $labelCls ?>">Scheduled Return *</label><input id="f_scheduled_return" type="text" value="<?= $toLocal($t['scheduled_return']) ?>" class="<?= $inputCls ?>" placeholder="Select date & time"></div>
    <div style="grid-column:span 2">
      <label class="<?= $labelCls ?>">Request Letter *</label>
      <label class="upload-drop" id="uploadDrop" for="f_request_letter">
        <input type="file" id="f_request_letter" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx">
        <span class="upload-icon"><?= icon('cloud') ?></span>
        <span id="uploadText">Upload File (Max 20mb)</span>
        <span class="upload-hint">PDF, PNG, JPG, DOC, DOCX</span>
      </label>
      <p class="text-text-muted text-sm mt-1.5">Note: Trip Departure will be set 3 days after Confirmation</p>
    </div>
  </div>

  <div class="flex gap-2 mt-4.5 flex-wrap">
    <button class="btn btn-ghost" type="button" id="btnSave">Save Draft</button>
    <button class="btn btn-primary" type="button" id="btnSubmit">Submit for Approval</button>
    <a class="btn btn-ghost" href="<?= BASE_URL ?>personnel/requests.php">Back</a>
  </div>
</div>
</form>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>