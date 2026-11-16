<?php
declare(strict_types=1);
/**
 * staff/trips.php — all trip tickets across statuses (Staff oversight list).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [3]);
$ACTIVE = BASE_URL . 'staff/trips.php';
$PAGE_TITLE = 'Trip Tickets';

TripGuard::autoRejectPastDue($pdo);

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
$when = static fn(?string $d): string => $d ? (date('Y') === date('Y', strtotime($d)) ? date('M j, g:i A', strtotime($d)) : date('M j Y, g:i A', strtotime($d))) : '—';

$FILTERS = [
    'all'     => ['All', null],
    'open'    => ['Open', [TripState::PENDING, TripState::APPROVED, TripState::DISPATCHED]],
    'active'  => ['Active', [TripState::ON_GOING]],
    'done'    => ['Done', [TripState::COMPLETED, TripState::CANCELLED, TripState::REJECTED, TripState::RESCHEDULED]],
];
$flt = (string) ($_GET['status'] ?? 'all');
if (!isset($FILTERS[$flt])) { $flt = 'all'; }

$where = 't.is_active = 1';
$args  = [];
if ($FILTERS[$flt][1] !== null) {
    $where .= ' AND t.status_id IN (' . implode(',', $FILTERS[$flt][1]) . ')';
}
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $where .= ' AND (t.dtt_number LIKE :qs1 OR t.passenger_name LIKE :qs2 OR t.surname LIKE :qs6
                 OR t.place_to_visit LIKE :qs3 OR c.full_name LIKE :qs4 OR v.plate_number LIKE :qs5
                 OR r.status_name LIKE :qs7 OR t.scheduled_departure LIKE :qs8 OR t.scheduled_return LIKE :qs9)';
    foreach (['qs1','qs2','qs3','qs4','qs5','qs6','qs7','qs8','qs9'] as $k) { $args[$k] = $like; }
}

$st = $pdo->prepare(
    "SELECT t.ticket_id, t.dtt_number, t.passenger_name, t.surname, t.middle_initial, t.suffix, t.place_to_visit,
            t.number_of_passengers, t.scheduled_departure, t.scheduled_return,
            t.status_id, r.status_name, t.is_late,
            c.full_name AS requester, COALESCE(v.plate_number, '—') AS plate
     FROM trip_tickets t
     JOIN request_status r ON r.status_id = t.status_id
     LEFT JOIN account c ON c.account_id = t.created_by
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     WHERE $where
     ORDER BY t.ticket_id DESC"
);
$st->execute($args);
$tickets = $st->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="flex items-center gap-2 flex-wrap mb-3.5">
  <h1 class="text-xl">Trip Tickets</h1>
  <div class="flex gap-1.5">
    <?php foreach ($FILTERS as $key => [$label]): ?>
      <a class="btn btn-sm <?= $key === $flt ? 'btn-primary' : 'btn-ghost' ?>" href="?<?= htmlspecialchars(http_build_query(['status' => $key, 'q' => $q])) ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="flex gap-1.5 ml-auto">
    <input type="hidden" name="status" value="<?= htmlspecialchars($flt) ?>">
    <input class="input-search-lg" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search ticket, passenger, destination, schedule, status">
    <button class="btn btn-sm btn-primary">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-sm btn-ghost" href="?status=<?= htmlspecialchars($flt) ?>">Clear</a><?php endif; ?>
  </form>
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
    'id' => 'tblTrips',
    'columns' => ['Ticket', 'Requester', 'Passenger', 'Destination', 'Schedule', 'Vehicle', 'Status', ''],
    'rows' => array_map(function ($t) use ($when, $paxName) {
        return [
            '<strong>' . htmlspecialchars($t['dtt_number'] ?? ('#' . $t['ticket_id'])) . '</strong>',
            htmlspecialchars((string) $t['requester']),
            htmlspecialchars($paxName($t)),
            htmlspecialchars(mb_strimwidth($t['place_to_visit'], 0, 48, '…')),
            '<div class="leading-normal"><div>' . $when($t['scheduled_departure']) . '</div><small class="text-xs font-bold tracking-wide text-text-muted">RET · ' . $when($t['scheduled_return']) . '</small></div>',
            htmlspecialchars((string) $t['plate']),
            '<span class="badge badge-' . strtolower(str_replace('_', '-', $t['status_name'])) . '">' . htmlspecialchars($t['status_name']) . '</span>'
                . ((int) ($t['is_late'] ?? 0) === 1
                    ? ' <span class="badge badge-late" title="Scheduled within 3 days of submission">LATE</span>' : ''),
            '<a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a>',
        ];
    }, $tickets),
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
