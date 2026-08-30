<?php
declare(strict_types=1);
/**
 * account/audit.php — Audit Trail: own activity/events, all types (all roles).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);
$ACTIVE = BASE_URL . 'account/audit.php';
$PAGE_TITLE = 'Audit Trail';

$event   = trim((string) ($_GET['event'] ?? ''));
$from    = trim((string) ($_GET['from'] ?? ''));
$to      = trim((string) ($_GET['to'] ?? ''));
$pageNum = max(1, (int) ($_GET['page'] ?? 1));
$per     = 50;

$where = ['user_id = ?'];
$args  = [$USER['id']];
if ($event !== '') {
    $where[] = 'event_type = ?';
    $args[]  = $event;
}
if ($from !== '' && ($ts = strtotime($from))) {
    $where[] = 'timestamp >= ?';
    $args[]  = date('Y-m-d 00:00:00', $ts);
}
if ($to !== '' && ($ts = strtotime($to))) {
    $where[] = 'timestamp <= ?';
    $args[]  = date('Y-m-d 23:59:59', $ts);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM audit_trail $whereSql");
$total->execute($args);
$total = (int) $total->fetchColumn();
$pages = max(1, (int) ceil($total / $per));

$st = $pdo->prepare(
    "SELECT * FROM audit_trail $whereSql ORDER BY trail_id DESC LIMIT $per OFFSET " . (($pageNum - 1) * $per)
);
$st->execute($args);
$rows = $st->fetchAll();

$events = $pdo->prepare('SELECT DISTINCT event_type FROM audit_trail WHERE user_id = ? ORDER BY event_type');
$events->execute([$USER['id']]);
$events = $events->fetchAll(PDO::FETCH_COLUMN);

$qs = http_build_query(array_filter(['event' => $event, 'from' => $from, 'to' => $to]));

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card p-3.5 mb-3">
  <form method="get" class="flex gap-2 flex-wrap items-center">
    <h2 class="text-lg font-bold mr-auto">Audit Trail <span class="text-text-muted font-normal">(<?= $total ?> records)</span></h2>
    <select class="form-input" name="event">
      <option value="">All events</option>
      <?php foreach ($events as $ev): ?>
        <option value="<?= htmlspecialchars($ev) ?>" <?= $event === $ev ? 'selected' : '' ?>><?= htmlspecialchars($ev) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="form-input" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <input class="form-input" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <button class="btn btn-sm btn-primary">Filter</button>
    <?php if ($event || $from || $to): ?><a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>account/audit.php">Clear</a><?php endif; ?>
  </form>
</div>

<?php
$c = [
    'id' => 'tblAudit',
    'columns' => ['#', 'When', 'Event', 'Object', 'Before → After', 'Outcome', 'IP Address'],
    'rows' => array_map(fn($r) => [
        '<span class="text-text-muted">' . (int) $r['trail_id'] . '</span>',
        '<span class="whitespace-nowrap">' . date('M j, Y g:i A', strtotime((string) $r['timestamp'])) . '</span>',
        '<span class="badge badge-draft">' . htmlspecialchars($r['event_type']) . '</span>',
        htmlspecialchars($r['object_id']),
        '<span class="text-text-muted" style="max-width:300px;font-size:11.5px;word-break:break-word">'
            . htmlspecialchars((string) ($r['before_value'] ?? ''))
            . (($r['before_value'] ?? '') !== '' ? ' → ' : '')
            . (htmlspecialchars((string) ($r['after_value'] ?? '')) ?: '—') . '</span>',
        '<span class="badge badge-' . (strtoupper((string) $r['outcome']) === 'SUCCESS' || $r['outcome'] === 'OVERRIDE' ? 'completed' : 'rejected') . '">' . htmlspecialchars((string) $r['outcome']) . '</span>',
        '<span class="text-text-muted">' . htmlspecialchars((string) ($r['ip_address'] ?? '—')) . '</span>',
    ], $rows),
    'empty' => 'No activity records found.',
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php if ($pages > 1): ?>
<div class="flex items-center gap-2 flex-wrap mt-3">
  <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
    <?php if ($i === $pageNum): ?><strong class="px-1.5"><?= $i ?></strong>
    <?php else: ?><a class="btn btn-sm btn-ghost" href="?<?= $qs ? $qs . '&' : '' ?>page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($pages > 20): ?><span class="text-text-muted">… <?= $pages ?> pages</span><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
