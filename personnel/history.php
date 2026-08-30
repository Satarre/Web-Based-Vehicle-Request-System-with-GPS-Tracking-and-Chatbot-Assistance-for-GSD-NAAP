<?php
declare(strict_types=1);
/**
 * personnel/history.php — requester's full trip history with status stat cards.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [6]);
$ACTIVE = BASE_URL . 'personnel/history.php';
$PAGE_TITLE = 'Request History';

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';

$st = $pdo->prepare(
    'SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.surname, t.middle_initial, t.suffix,
            t.place_to_visit, t.number_of_passengers, t.scheduled_departure, t.scheduled_return,
            t.status_id, r.status_name,
            EXISTS(SELECT 1 FROM audit_trail a
                   WHERE a.event_type = \'RESCHEDULE\'
                     AND a.object_id = CONCAT(\'trip_tickets:\', t.ticket_id)) AS was_rescheduled
     FROM trip_tickets t JOIN request_status r ON r.status_id = t.status_id
     WHERE t.created_by = ? AND t.is_active = 1
     ORDER BY t.ticket_id DESC'
);
$st->execute([$USER['id']]);
$tickets = $st->fetchAll();

$stats = ['total' => count($tickets), 'draft' => 0, 'pending' => 0, 'verified' => 0,
          'approved' => 0, 'dispatched' => 0, 'ongoing' => 0, 'completed' => 0,
          'rescheduled' => 0, 'cancelled' => 0, 'rejected' => 0];
foreach ($tickets as $t) {
    $s = (int) $t['status_id'];
    if ($s === TripState::DRAFT)              { $stats['draft']++; }
    elseif ($s === TripState::PENDING)        { $stats['pending']++; }
    elseif ($s === TripState::VERIFIED)       { $stats['verified']++; }
    elseif ($s === TripState::APPROVED)       { $stats['approved']++; }
    elseif ($s === TripState::DISPATCHED)     { $stats['dispatched']++; }
    elseif ($s === TripState::ON_GOING)       { $stats['ongoing']++; }
    elseif ($s === TripState::COMPLETED)      { $stats['completed']++; }
    elseif ($s === TripState::CANCELLED)      { $stats['cancelled']++; }
    elseif ($s === TripState::REJECTED)       { $stats['rejected']++; }
    if ((int) $t['was_rescheduled'] === 1) {
        $stats['rescheduled']++;
    }
}

$PAGE_SCRIPTS = ['history.js', 'table_filter.js', 'requests.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="page-header">
  <div>
    <h1>Request History</h1>
    <p class="text-text-muted mt-1">All your trip requests, with one-click filters by status</p>
  </div>
  <a class="btn btn-primary" href="<?= BASE_URL ?>personnel/request_form.php">+ New Request</a>
</div>

<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 mb-3.5">
  <?php
  $labels = [
      'total' => 'Total', 'draft' => 'Draft', 'pending' => 'Pending',
      'verified' => 'Verified', 'approved' => 'Approved', 'dispatched' => 'Dispatched',
      'ongoing' => 'On-going', 'completed' => 'Completed', 'rescheduled' => 'Rescheduled',
      'cancelled' => 'Cancelled', 'rejected' => 'Rejected',
  ];
  foreach ($labels as $key => $label): ?>
  <div class="stat-card is-clickable" data-stat="<?= $key ?>">
    <div class="stat-card-label"><?= $label ?></div>
    <div class="stat-card-value"><?= $stats[$key] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$rowStats = static function (array $t): string {
    $s  = (int) $t['status_id'];
    $k  = ['total'];
    if ($s === TripState::DRAFT)                              { $k[] = 'draft'; }
    elseif ($s === TripState::PENDING)                        { $k[] = 'pending'; }
    elseif ($s === TripState::VERIFIED)                       { $k[] = 'verified'; }
    elseif ($s === TripState::APPROVED)                       { $k[] = 'approved'; }
    elseif ($s === TripState::DISPATCHED)                     { $k[] = 'dispatched'; }
    elseif ($s === TripState::ON_GOING)                       { $k[] = 'ongoing'; }
    elseif ($s === TripState::COMPLETED)                      { $k[] = 'completed'; }
    elseif ($s === TripState::CANCELLED)                      { $k[] = 'cancelled'; }
    elseif ($s === TripState::REJECTED)                       { $k[] = 'rejected'; }
    if ((int) $t['was_rescheduled'] === 1)                        { $k[] = 'rescheduled'; }
    return implode(' ', $k);
};
$fullName = static function (array $t): string {
    return trim(($t['passenger_name'] ?? '') . ' ' . ($t['middle_initial'] ?? '')
        . ' ' . ($t['surname'] ?? '') . ' ' . ($t['suffix'] ?? ''));
};
$c = [
    'title' => 'Trip Requests',
    'sub' => count($tickets),
    'id' => 'tblHistory',
    'filter' => 'Search requests…',
    'columns' => ['Ticket', 'Passenger', 'Destination', 'Schedule', 'Pax', 'Status', ''],
    'rows' => array_map(function ($t) use ($fmt, $USER, $rowStats, $fullName) {
        $isDraft = (int) $t['status_id'] === TripState::DRAFT;
        $canCancel = TripState::can(array_merge($t, ['created_by' => $USER['id']]), TripState::CANCELLED,
            ['user_id' => $USER['id'], 'role_id' => $USER['role_id'], 'has_reason' => !$isDraft]) === true;
        return [
            '<strong>' . htmlspecialchars($t['dtt_number'] ?? ('#' . $t['ticket_id'])) . '</strong>',
            htmlspecialchars($fullName($t) ?: $t['passenger_name']),
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
?>
<div data-row-stats>
<?php foreach ($tickets as $t): ?><span hidden data-stat-row="<?= (int) $t['ticket_id'] ?>" data-stat="<?= htmlspecialchars($rowStats($t)) ?>"></span><?php endforeach; ?>
</div>
<?php
include __DIR__ . '/../partials/ui/table.php';
?>

<?php
$RM_ID = 'cancelModal'; $RM_TITLE = 'Cancel Ticket'; $RM_OK = 'Cancel Ticket'; $RM_DANGER = true;
require_once dirname(__DIR__) . '/partials/reason_modal.php';
require_once dirname(__DIR__) . '/partials/layout_foot.php';