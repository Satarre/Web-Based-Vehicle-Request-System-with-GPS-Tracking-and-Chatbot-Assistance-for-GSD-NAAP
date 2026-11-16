<?php
declare(strict_types=1);
/**
 * personnel/requests.php — requester's ticket list (Phase 1).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [6]);
$ACTIVE = BASE_URL . 'personnel/requests.php';
$PAGE_TITLE = 'My Requests';
$ENABLE_LIVE = true;

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';

$st = $pdo->prepare(
    "SELECT t.ticket_id, 0 AS draft_id, t.dtt_number, t.passenger_name, t.surname, t.middle_initial, t.suffix, t.place_to_visit,
            t.number_of_passengers, t.scheduled_departure, t.scheduled_return,
            t.status_id, r.status_name, t.created_at, t.reason, t.is_late
     FROM trip_tickets t JOIN request_status r ON r.status_id = t.status_id
     WHERE t.created_by = ? AND t.is_active = 1"
);
$st->execute([$USER['id']]);
$tickets = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT 0 AS ticket_id, draft_id, NULL AS dtt_number, passenger_name, surname, middle_initial, suffix, place_to_visit,
            number_of_passengers, scheduled_departure, scheduled_return,
            " . TripState::DRAFT . " AS status_id, 'DRAFT' AS status_name, created_at, updated_at
     FROM trip_drafts WHERE created_by = ?"
);
$st->execute([$USER['id']]);
$tickets = array_merge($tickets, $st->fetchAll());
/* Default order: drafts first (most recently saved on top) so work in progress
 * is never buried under finished tickets, then tickets by number descending.
 * table_sort.js keeps the pinned draft rows on top when a column is sorted. */
$order = static fn(array $t): array => [
    (int) ($t['status_id'] ?? 0) === TripState::DRAFT ? 1 : 0,
    (string) ($t['dtt_number'] ?? ''),
    (string) (($t['updated_at'] ?? null) ?: ($t['created_at'] ?? '')),
    (int) ($t['draft_id'] ?? 0),
    (int) ($t['ticket_id'] ?? 0),
];
usort($tickets, static fn(array $a, array $b): int => $order($b) <=> $order($a));

$stats = ['DRAFT' => 0, 'PENDING' => 0, 'APPROVED' => 0, 'ACTIVE' => 0, 'COMPLETED' => 0];
foreach ($tickets as $t) {
    $s = (int) $t['status_id'];
    if ($s === TripState::DRAFT)               { $stats['DRAFT']++; }
    elseif ($s === TripState::PENDING)         { $stats['PENDING']++; }
    elseif (in_array($s, [TripState::APPROVED, TripState::DISPATCHED], true)) { $stats['APPROVED']++; }
    elseif (TripState::isActiveTrip($s))       { $stats['ACTIVE']++; }
    elseif ($s === TripState::COMPLETED)       { $stats['COMPLETED']++; }
}

$HEAD_EXTRAS =
    '<link rel="stylesheet" href="' . BASE_URL . 'assets/js/vendor/flatpickr/flatpickr.min.css">' .
    '<script src="' . BASE_URL . 'assets/js/vendor/flatpickr/flatpickr.min.js"></script>';
$VENDOR_SCRIPTS = ['schedule_picker.js'];
$PAGE_SCRIPTS = ['requests.js', 'table_filter.js', 'calendar.js', 'table_sort.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card p-3.5">
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-5 gap-2.5 mb-3.5 stagger-in">
  <?php foreach ($stats as $label => $n): ?>
  <div class="stat-card"><div class="stat-card-label"><?= $label ?></div><div class="stat-card-value"><?= $n ?></div></div>
  <?php endforeach; ?>
</div>

<div class="flex items-center gap-2 flex-wrap mb-3.5">
  <a class="btn btn-primary" href="<?= BASE_URL ?>personnel/request_form.php">+ New Request</a>
</div>

<?php
$paxName = static function (array $t): string {
    $first = trim((string) ($t['passenger_name'] ?? ''));
    $mi    = strtoupper(trim((string) ($t['middle_initial'] ?? '')));
    $sur   = trim((string) ($t['surname'] ?? ''));
    $suf   = trim((string) ($t['suffix'] ?? ''));
    $name  = $sur ? $sur . ', ' . $first : $first;
    if ($mi) $name .= ' ' . $mi . '.';
    if ($suf) $name .= ' ' . $suf;
    return $name;
};
$c = [
    'title' => 'My Trip Requests',
    'sub' => count($tickets),
    'id' => 'tblRequests',
    'filter' => 'Search requests',
    'columns' => ['Ticket', 'Passenger', 'Destination', 'Schedule', 'Pax', 'Status', ''],
    'sort' => [0 => 'ticket', 1 => 'passenger', 2 => 'destination', 3 => 'schedule', 4 => 'pax', 5 => 'status'],
    'sort_default' => 'ticket:desc',
    'rows' => array_map(function ($t) use ($fmt, $USER, $paxName) {
        $isDraft  = (int) $t['status_id'] === TripState::DRAFT;
        $isResched = (int) $t['status_id'] === TripState::RESCHEDULED;
        $canCancel = !$isDraft && TripState::can(array_merge($t, ['created_by' => $USER['id']]), TripState::CANCELLED,
            ['user_id' => $USER['id'], 'role_id' => $USER['role_id'], 'has_reason' => true]) === true;
        $name = $paxName($t);
        $cells = [
            ['html' => '<strong>' . htmlspecialchars($t['dtt_number'] ?? ($isDraft ? '—' : '#' . $t['ticket_id'])) . '</strong>',
             'sort' => (string) ($t['dtt_number'] ?? '')],
            ['html' => htmlspecialchars($name), 'sort' => $name],
            ['html' => htmlspecialchars(mb_strimwidth($t['place_to_visit'], 0, 34, '…')), 'sort' => (string) $t['place_to_visit']],
            ['html' => $fmt($t['scheduled_departure']) . ' → ' . $fmt($t['scheduled_return']),
             'sort' => (string) ($t['scheduled_departure'] ?? '')],
            ['html' => (string) (int) $t['number_of_passengers'], 'sort' => (string) (int) $t['number_of_passengers']],
            ['html' => '<span class="badge badge-' . strtolower(str_replace('_', '-', $t['status_name'])) . '">' . htmlspecialchars($t['status_name']) . '</span>'
                . ((int) ($t['is_late'] ?? 0) === 1
                    ? ' <span class="badge badge-late" title="Scheduled within 3 days of submission">LATE</span>' : ''),
             'sort' => (string) (int) $t['status_id']],
            '<span class="whitespace-nowrap">'
                . ($isDraft ? '<a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'personnel/request_form.php?id=' . $t['draft_id'] . '">Edit</a>' : '')
                . ($isDraft ? ' <button class="btn btn-sm btn-danger" data-cancel="' . (int) $t['draft_id'] . '" data-status="1">Delete</button>' : '')
                . (!$isDraft ? ' <a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a>' : '')
                . ($isResched ? ' <button class="btn btn-sm btn-primary" data-resubmit="' . $t['ticket_id'] . '" data-reason="' . htmlspecialchars((string) ($t['reason'] ?? ''), ENT_QUOTES) . '" data-dep="' . htmlspecialchars((string) ($t['scheduled_departure'] ?? ''), ENT_QUOTES) . '" data-ret="' . htmlspecialchars((string) ($t['scheduled_return'] ?? ''), ENT_QUOTES) . '">Edit Schedule</button>' : '')
                . ($canCancel ? ' <button class="btn btn-sm btn-danger" data-cancel="' . $t['ticket_id'] . '" data-status="' . (int) $t['status_id'] . '">Cancel</button>' : '')
                . '</span>',
        ];
        /* Drafts stay pinned above the sorted tickets (table_sort.js). */
        return $isDraft ? ['pin' => 'draft', 'cells' => $cells] : $cells;
    }, $tickets),
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php require_once dirname(__DIR__) . '/partials/calendar.php'; ?>
</div>

<?php
$RM_ID = 'cancelModal'; $RM_TITLE = 'Cancel Ticket'; $RM_OK = 'Cancel Ticket'; $RM_DANGER = true;
require_once dirname(__DIR__) . '/partials/reason_modal.php';
?>
<div class="modal-backdrop" id="resubmitModal">
  <div class="modal-body" style="max-width:420px">
    <div class="modal-head">
      <span>Pick a New Schedule</span>
      <button class="modal-close" type="button" onclick="UI.modal('resubmitModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <input type="hidden" id="rs_ticket_id">
      <input type="hidden" id="rs_late_days" value="<?= max(1, (int) Settings::getInt('LATE_ADVANCE_DAYS', 3)) ?>">
      <div class="alert alert-warning text-sm font-semibold mb-3">
        <strong>GSD Dispatcher reason:</strong> <span id="rs_reason_display"></span>
      </div>
      <label class="form-label">New Departure *</label>
      <input id="rs_dep" type="datetime-local" class="form-input">
      <label class="form-label mt-2.5">New Return *</label>
      <input id="rs_ret" type="datetime-local" class="form-input">
      <p class="text-text-muted text-11-5px mt-2">After submitting, this trip will go back for SAO approval, then GSD dispatch.</p>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" type="button" onclick="UI.modal('resubmitModal', false)">Close</button>
      <button class="btn btn-primary" id="rs_submit">Submit New Schedule</button>
    </div>
  </div>
</div>
<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
