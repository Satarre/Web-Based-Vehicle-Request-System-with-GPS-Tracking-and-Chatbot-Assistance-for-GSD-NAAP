<?php
declare(strict_types=1);
/**
 * director/trips.php — SAO trip ticket listing with clickable stat card filters.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [2]);
$ACTIVE = BASE_URL . 'director/trips.php';
$PAGE_TITLE = 'Trip Tickets';

$fmt  = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
$when = static fn(?string $d): string => $d ? (date('Y') === date('Y', strtotime($d)) ? date('M j, g:i A', strtotime($d)) : date('M j Y, g:i A', strtotime($d))) : '—';
$orDash = static fn($v): string => $v === null || $v === '' ? '—' : htmlspecialchars((string) $v);

/* ── Status counts ── */
$counts = [];
$st = $pdo->query('SELECT status_id, status_name FROM request_status WHERE is_active = 1 ORDER BY status_id');
foreach ($st->fetchAll() as $r) {
    $counts[(int) $r['status_id']] = ['name' => $r['status_name'], 'count' => 0];
}
$q = $pdo->query('SELECT status_id, COUNT(*) AS c FROM trip_tickets WHERE is_active = 1 GROUP BY status_id');
foreach ($q->fetchAll() as $r) {
    $id = (int) $r['status_id'];
    if (isset($counts[$id])) { $counts[$id]['count'] = (int) $r['c']; }
}
$totalCount = array_sum(array_column($counts, 'count'));

/* ── Filter ── */
$filterStatus = isset($_GET['status']) ? (int) $_GET['status'] : 0;
$where = 't.is_active = 1';
$args  = [];
if ($filterStatus > 0 && isset($counts[$filterStatus])) {
    $where .= ' AND t.status_id = ?';
    $args[] = $filterStatus;
}

/* ── Search ── */
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
            t.submitted_at, t.status_id, r.status_name,
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
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-3 xl:grid-cols-4 gap-2.5 mb-3.5 stagger-in">
  <a class="stat-card<?= $filterStatus === 0 ? ' is-filtered' : '' ?>" data-stat="all" href="?<?= htmlspecialchars(http_build_query(['q' => $q])) ?>">
    <div class="stat-card-label">TOTAL</div>
    <div class="stat-card-value"><?= $totalCount ?></div>
  </a>
  <?php foreach ($counts as $sid => $c): ?>
    <a class="stat-card<?= $filterStatus === $sid ? ' is-filtered' : '' ?>" data-stat="<?= $sid ?>" href="?<?= htmlspecialchars(http_build_query(['status' => $sid, 'q' => $q])) ?>">
      <div class="stat-card-label"><?= htmlspecialchars($c['name']) ?></div>
      <div class="stat-card-value"><?= $c['count'] ?></div>
    </a>
  <?php endforeach; ?>
</div>

<!-- Search -->
  <form method="get" class="flex flex-wrap gap-1.5 mb-3">
    <?php if ($filterStatus > 0): ?><input type="hidden" name="status" value="<?= $filterStatus ?>"><?php endif; ?>
      <input class="input-search-lg min-w-0" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search ticket, passenger, destination, schedule, status">
  <button class="btn btn-sm btn-primary">Search</button>
  <?php if ($q !== '' || $filterStatus > 0): ?>
    <a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>director/trips.php">Clear</a>
  <?php endif; ?>
</form>

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
$rows = array_map(function ($t) use ($when, $paxName) {
    return [
        '<strong>' . htmlspecialchars($t['dtt_number'] ?? ('#' . $t['ticket_id'])) . '</strong>',
        htmlspecialchars((string) $t['requester']),
        htmlspecialchars($paxName($t)),
        htmlspecialchars((string) $t['place_to_visit']),
        '<div class="leading-normal"><div>' . $when($t['scheduled_departure']) . '</div><small class="text-xs font-bold tracking-wide text-text-muted">RET · ' . $when($t['scheduled_return']) . '</small></div>',
        (string) (int) $t['number_of_passengers'],
        htmlspecialchars((string) $t['plate']),
        '<span class="badge badge-' . strtolower(str_replace('_', '-', $t['status_name'])) . '">' . htmlspecialchars($t['status_name']) . '</span>',
        '<span class="whitespace-nowrap"><a class="btn btn-sm btn-ghost" href="' . BASE_URL . 'ticket.php?id=' . $t['ticket_id'] . '">View</a></span>',
    ];
}, $tickets);
$c = [
    'id' => 'tblTrips',
    'columns' => ['Ticket', 'Requester', 'Passenger', 'Destination', 'Schedule', 'Pax', 'Vehicle', 'Status', ''],
    'rows' => $rows,
    'empty' => 'No tickets found.',
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php
$PAGE_SCRIPTS = ['table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
