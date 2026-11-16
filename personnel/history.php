<?php
declare(strict_types=1);
/**
 * personnel/history.php — requester's full trip history.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [6]);
$ACTIVE = BASE_URL . 'personnel/history.php';
$PAGE_TITLE = 'Request History';

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';

$st = $pdo->prepare(
    "SELECT t.ticket_id, 0 AS draft_id, t.dtt_number, t.passenger_name, t.surname, t.middle_initial, t.suffix,
            t.place_to_visit, t.number_of_passengers, t.scheduled_departure, t.scheduled_return,
            t.status_id, r.status_name, t.created_at,
            EXISTS(SELECT 1 FROM audit_trail a
                   WHERE a.event_type = 'RESCHEDULE'
                     AND a.object_id = CONCAT('trip_tickets:', t.ticket_id)) AS was_rescheduled
     FROM trip_tickets t JOIN request_status r ON r.status_id = t.status_id
     WHERE t.created_by = ? AND t.is_active = 1"
);
$st->execute([$USER['id']]);
$tickets = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT 0 AS ticket_id, draft_id, NULL AS dtt_number, passenger_name, surname, middle_initial, suffix,
            place_to_visit, number_of_passengers, scheduled_departure, scheduled_return,
            " . TripState::DRAFT . " AS status_id, 'DRAFT' AS status_name, created_at, updated_at,
            0 AS was_rescheduled
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

$PAGE_SCRIPTS = ['table_filter.js', 'requests.js', 'table_sort.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>

<?php
$fullName = static function (array $t): string {
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
    'title' => 'Trip Requests',
    'sub' => count($tickets),
    'id' => 'tblHistory',
    'filter' => 'Search requests',
    'columns' => ['Ticket', 'Passenger', 'Destination', 'Schedule', 'Pax', 'Status', ''],
    'sort' => [0 => 'ticket', 1 => 'passenger', 2 => 'destination', 3 => 'schedule', 4 => 'pax', 5 => 'status'],
    'sort_default' => 'ticket:desc',
    'rows' => array_map(function ($t) use ($fmt, $USER, $fullName) {
        $isDraft = (int) $t['status_id'] === TripState::DRAFT;
        $canCancel = !$isDraft && TripState::can(array_merge($t, ['created_by' => $USER['id']]), TripState::CANCELLED,
            ['user_id' => $USER['id'], 'role_id' => $USER['role_id'], 'has_reason' => true]) === true;
        $name = $fullName($t) ?: (string) $t['passenger_name'];
        $cells = [
            ['html' => '<strong>' . htmlspecialchars($t['dtt_number'] ?? ($isDraft ? '—' : '#' . $t['ticket_id'])) . '</strong>',
             'sort' => (string) ($t['dtt_number'] ?? '')],
            ['html' => htmlspecialchars($name), 'sort' => $name],
            ['html' => htmlspecialchars(mb_strimwidth($t['place_to_visit'], 0, 34, '…')), 'sort' => (string) $t['place_to_visit']],
            ['html' => $fmt($t['scheduled_departure']) . ' → ' . $fmt($t['scheduled_return']),
             'sort' => (string) ($t['scheduled_departure'] ?? '')],
            ['html' => (string) (int) $t['number_of_passengers'], 'sort' => (string) (int) $t['number_of_passengers']],
            ['html' => '<span class="badge badge-' . strtolower(str_replace('_', '-', $t['status_name'])) . '">' . htmlspecialchars($t['status_name']) . '</span>',
             'sort' => (string) (int) $t['status_id']],
            '<span class="whitespace-nowrap">'
                . ($isDraft ? '<a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'personnel/request_form.php?id=' . $t['draft_id'] . '">Edit</a>' : '')
                . ($isDraft ? ' <button class="btn btn-sm btn-danger" data-cancel="' . (int) $t['draft_id'] . '" data-status="1">Delete</button>' : '')
                . (!$isDraft ? ' <a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a>' : '')
                . ($canCancel ? ' <button class="btn btn-sm btn-danger" data-cancel="' . $t['ticket_id'] . '" data-status="' . (int) $t['status_id'] . '">Cancel</button>' : '')
                . '</span>',
        ];
        /* Drafts stay pinned above the sorted tickets (table_sort.js). */
        return $isDraft ? ['pin' => 'draft', 'cells' => $cells] : $cells;
    }, $tickets),
];

include __DIR__ . '/../partials/ui/table.php';
?>

<?php
$RM_ID = 'cancelModal'; $RM_TITLE = 'Cancel Ticket'; $RM_OK = 'Cancel Ticket'; $RM_DANGER = true;
require_once dirname(__DIR__) . '/partials/reason_modal.php';
require_once dirname(__DIR__) . '/partials/layout_foot.php';