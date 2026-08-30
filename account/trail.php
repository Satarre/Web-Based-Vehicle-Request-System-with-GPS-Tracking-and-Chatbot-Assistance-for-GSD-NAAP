<?php
declare(strict_types=1);
/**
 * account/trail.php — Log Trail: own login/logout history (all roles).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);
$ACTIVE = BASE_URL . 'account/trail.php';
$PAGE_TITLE = 'Log Trail';

$from    = trim((string) ($_GET['from'] ?? ''));
$to      = trim((string) ($_GET['to'] ?? ''));
$pageNum = max(1, (int) ($_GET['page'] ?? 1));
$per     = 50;

$where = ['user_id = ?', "event_type IN ('LOGIN','LOGOUT')"];
$args  = [$USER['id']];
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

$qs = http_build_query(array_filter(['from' => $from, 'to' => $to]));

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card p-3.5 mb-3" style="max-width:900px">
  <form method="get" class="flex gap-2 flex-wrap items-center">
    <h2 class="text-lg font-bold mr-auto">Log Trail <span class="text-text-muted font-normal">(<?= $total ?> records)</span></h2>
    <label class="m-0 text-xs">From</label>
    <input class="form-input" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <label class="m-0 text-xs">To</label>
    <input class="form-input" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <button class="btn btn-sm btn-primary">Filter</button>
    <?php if ($from || $to): ?><a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>account/trail.php">Clear</a><?php endif; ?>
  </form>
</div>

<?php
$c = [
    'id' => 'tblTrail',
    'columns' => ['User Type', 'User ID', 'User Name', 'Event', 'Log Date', 'IP Address'],
    'rows' => array_map(fn($r) => [
        htmlspecialchars((string) $USER['role_name']),
        '<span class="text-text-muted">#' . (int) $r['user_id'] . '</span>',
        htmlspecialchars((string) $USER['full_name']),
        '<span class="badge badge-draft">' . htmlspecialchars($r['event_type']) . '</span>',
        '<span class="whitespace-nowrap">' . date('M j, Y g:i A', strtotime((string) $r['timestamp'])) . '</span>',
        '<span class="text-text-muted">' . htmlspecialchars((string) ($r['ip_address'] ?? '—')) . '</span>',
    ], $rows),
    'empty' => 'No login/logout records found.',
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php if ($pages > 1): ?>
<div class="flex items-center gap-2 flex-wrap mt-3" style="max-width:900px">
  <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
    <?php if ($i === $pageNum): ?><strong class="px-1.5"><?= $i ?></strong>
    <?php else: ?><a class="btn btn-sm btn-ghost" href="?<?= $qs ? $qs . '&' : '' ?>page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($pages > 20): ?><span class="text-text-muted">… <?= $pages ?> pages</span><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
