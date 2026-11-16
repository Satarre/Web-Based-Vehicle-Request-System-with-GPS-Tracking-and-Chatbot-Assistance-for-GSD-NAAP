<?php
declare(strict_types=1);
/**
 * director/approvals.php — Supervising Administrative Officer approve queue
 * (PENDING tickets, approved straight to APPROVED).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [2]);
$ACTIVE = BASE_URL . 'director/approvals.php';
$PAGE_TITLE = 'Approve Requests';
$ENABLE_LIVE = true;

TripGuard::autoRejectPastDue($pdo);

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
$when = static fn(?string $d): string => $d ? (date('Y') === date('Y', strtotime($d)) ? date('M j, g:i A', strtotime($d)) : date('M j Y, g:i A', strtotime($d))) : '—';

$pending = $pdo->query(
    "SELECT t.ticket_id, t.passenger_name, t.surname, t.middle_initial, t.suffix, t.place_to_visit, t.number_of_passengers,
            t.scheduled_departure, t.scheduled_return, t.submitted_at, c.full_name AS requester
     FROM trip_tickets t LEFT JOIN account c ON c.account_id = t.created_by
     WHERE t.status_id = " . TripState::PENDING . " AND t.is_active = 1
     ORDER BY t.submitted_at ASC"
)->fetchAll();

$recent = $pdo->query(
    "SELECT t.ticket_id, t.dtt_number, r.status_name,
            COALESCE(t.approved_at, t.updated_at) AS decided_at,
            a.full_name AS decider
     FROM trip_tickets t
     JOIN request_status r ON r.status_id = t.status_id
     LEFT JOIN account a ON a.account_id = t.approver_id
     WHERE t.status_id IN (" . TripState::APPROVED . ", " . TripState::REJECTED . ") AND t.is_active = 1
     ORDER BY decided_at DESC LIMIT 10"
)->fetchAll();

$PAGE_SCRIPTS = ['approvals.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
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
$pendingRows = array_map(function ($t) use ($fmt, $when, $paxName) {
    return [
        $fmt($t['submitted_at']),
        htmlspecialchars((string) $t['requester']),
        htmlspecialchars($paxName($t)),
        htmlspecialchars((string) $t['place_to_visit']),
        '<div class="leading-normal"><div>' . $when($t['scheduled_departure']) . '</div><small class="text-xs font-bold tracking-wide text-text-muted">RET · ' . $when($t['scheduled_return']) . '</small></div>',
        (string) (int) $t['number_of_passengers'],
        '<span class="whitespace-nowrap"><a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a> <button class="btn btn-sm btn-primary" data-approve="' . $t['ticket_id'] . '">Approve</button> <button class="btn btn-sm btn-danger" data-reject="' . $t['ticket_id'] . '">Reject</button></span>',
    ];
}, $pending);
?>
<div style="margin-bottom:14px">
<?php
$c = [
    'title' => 'Pending Approval',
    'sub' => count($pending) . ' waiting',
    'id' => 'tblPending',
    'filter' => 'Search queue',
    'columns' => ['Submitted', 'Requester', 'Passenger', 'Destination', 'Schedule', 'Pax', ''],
    'rows' => $pendingRows,
    'empty' => 'Queue clear — nothing awaiting approval.',
];
include __DIR__ . '/../partials/ui/table.php';
?>
</div>

<?php
$c = [
    'title' => 'Recent Decisions',
    'sub' => count($recent),
    'id' => 'tblRecent',
    'columns' => ['Ticket', 'Decision', 'Decided By', 'Decided At', ''],
    'rows' => array_map(fn($t) => [
        '<strong>' . htmlspecialchars($t['dtt_number'] ?? ('#' . $t['ticket_id'])) . '</strong>',
        '<span class="badge badge-' . strtolower(str_replace('_', '-', $t['status_name'])) . '">' . htmlspecialchars($t['status_name']) . '</span>',
        htmlspecialchars((string) $t['decider']),
        $fmt($t['decided_at']),
        '<a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a>',
    ], $recent),
    'empty' => 'No decisions yet.',
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php
$RM_ID = 'rejectModal'; $RM_TITLE = 'Reject Request'; $RM_OK = 'Reject Request'; $RM_DANGER = true;
require_once dirname(__DIR__) . '/partials/reason_modal.php';
require_once dirname(__DIR__) . '/partials/layout_foot.php';
