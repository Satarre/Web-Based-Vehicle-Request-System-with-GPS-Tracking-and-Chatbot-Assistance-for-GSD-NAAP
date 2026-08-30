<?php
declare(strict_types=1);
/**
 * personnel/requests.php — requester's ticket list (Phase 1).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [6]);
$ACTIVE = BASE_URL . 'personnel/requests.php';
$PAGE_TITLE = 'My Requests';

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';

$st = $pdo->prepare(
    'SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.place_to_visit,
            t.number_of_passengers, t.scheduled_departure, t.scheduled_return,
            t.status_id, r.status_name
     FROM trip_tickets t JOIN request_status r ON r.status_id = t.status_id
     WHERE t.created_by = ? AND t.is_active = 1
     ORDER BY t.ticket_id DESC'
);
$st->execute([$USER['id']]);
$tickets = $st->fetchAll();

$stats = ['DRAFT' => 0, 'PENDING' => 0, 'APPROVED' => 0, 'ACTIVE' => 0, 'COMPLETED' => 0];
foreach ($tickets as $t) {
    $s = (int) $t['status_id'];
    if ($s === TripState::DRAFT)               { $stats['DRAFT']++; }
    elseif ($s === TripState::PENDING)         { $stats['PENDING']++; }
    elseif (in_array($s, [TripState::VERIFIED, TripState::APPROVED, TripState::DISPATCHED], true)) { $stats['APPROVED']++; }
    elseif (TripState::isActiveTrip($s))       { $stats['ACTIVE']++; }
    elseif ($s === TripState::COMPLETED)       { $stats['COMPLETED']++; }
}

$PAGE_SCRIPTS = ['requests.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2.5 mb-3.5">
  <?php foreach ($stats as $label => $n): ?>
  <div class="stat-card"><div class="stat-card-label"><?= $label ?></div><div class="stat-card-value"><?= $n ?></div></div>
  <?php endforeach; ?>
</div>

<div class="flex items-center gap-2 flex-wrap mb-3.5">
  <a class="btn btn-primary" href="<?= BASE_URL ?>personnel/request_form.php">+ New Request</a>
</div>

<?php
$c = [
    'title' => 'My Trip Requests',
    'sub' => count($tickets),
    'id' => 'tblRequests',
    'filter' => 'Search requests…',
    'columns' => ['Ticket', 'Passenger', 'Destination', 'Schedule', 'Pax', 'Status', ''],
    'rows' => array_map(function ($t) use ($fmt, $USER) {
        $isDraft = (int) $t['status_id'] === TripState::DRAFT;
        $canCancel = TripState::can(array_merge($t, ['created_by' => $USER['id']]), TripState::CANCELLED,
            ['user_id' => $USER['id'], 'role_id' => $USER['role_id'], 'has_reason' => !$isDraft]) === true;
        return [
            '<strong>' . htmlspecialchars($t['dtt_number'] ?? ('#' . $t['ticket_id'])) . '</strong>',
            htmlspecialchars($t['passenger_name']),
            htmlspecialchars(mb_strimwidth($t['place_to_visit'], 0, 34, '…')),
            $fmt($t['scheduled_departure']) . ' → ' . $fmt($t['scheduled_return']),
            (string) (int) $t['number_of_passengers'],
            '<span class="badge badge-' . strtolower(str_replace('_', '-', $t['status_name'])) . '">' . htmlspecialchars($t['status_name']) . '</span>',
            '<span class="whitespace-nowrap">'
                . ($isDraft ? '<a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'personnel/request_form.php?id=' . $t['ticket_id'] . '">Edit</a>' : '')
                . ' <a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a>'
                . ($canCancel ? ' <button class="btn btn-sm btn-danger" data-cancel="' . $t['ticket_id'] . '" data-status="' . (int) $t['status_id'] . '">Cancel</button>' : '')
                . '</span>',
        ];
    }, $tickets),
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php
$RM_ID = 'cancelModal'; $RM_TITLE = 'Cancel Ticket'; $RM_OK = 'Cancel Ticket'; $RM_DANGER = true;
require_once dirname(__DIR__) . '/partials/reason_modal.php';
require_once dirname(__DIR__) . '/partials/layout_foot.php';
