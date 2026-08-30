<?php
declare(strict_types=1);
/**
 * mis/audit.php — audit trail viewer (read-only, filterable).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1]);
$ACTIVE = BASE_URL . 'mis/audit.php';
$PAGE_TITLE = 'Audit Trail';

$event   = trim((string) ($_GET['event'] ?? ''));
$q       = trim((string) ($_GET['q'] ?? ''));

$where = [];
$args = [];
if ($event !== '') {
    $where[] = 'a.event_type = ?';
    $args[] = $event;
}
if ($q !== '') {
    $where[] = "(a.object_id LIKE ? OR a.after_value LIKE ? OR u.full_name LIKE ?)";
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->prepare("SELECT COUNT(*) FROM audit_trail a LEFT JOIN account u ON u.account_id = a.user_id $whereSql");
$total->execute($args);
$total = (int) $total->fetchColumn();

$st = $pdo->prepare(
    "SELECT a.*, u.full_name, u.email
     FROM audit_trail a LEFT JOIN account u ON u.account_id = a.user_id
     $whereSql ORDER BY a.trail_id DESC"
);
$st->execute($args);
$rows = $st->fetchAll();

$events = $pdo->query('SELECT DISTINCT event_type FROM audit_trail ORDER BY event_type')->fetchAll(PDO::FETCH_COLUMN);

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card p-3.5 mb-3">
  <form method="get" class="flex gap-2 flex-wrap items-center">
    <h2 class="text-lg font-bold mr-auto">Audit Trail <span class="text-text-muted font-normal">(<?= $total ?> records)</span></h2>
    <select class="form-input" name="event" onchange="this.form.submit()">
      <option value="">All events</option>
      <?php foreach ($events as $ev): ?>
        <option value="<?= htmlspecialchars($ev) ?>" <?= $event === $ev ? 'selected' : '' ?>><?= htmlspecialchars($ev) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="form-input w-60" name="q" placeholder="Search object / detail / user…" value="<?= htmlspecialchars($q) ?>">
    <button class="btn btn-sm btn-primary">Filter</button>
    <?php if ($event || $q): ?><a class="btn btn-sm btn-ghost" href="<?= BASE_URL ?>mis/audit.php">Clear</a><?php endif; ?>
  </form>
</div>

<?php
$c = [
    'id' => 'tblAudit',
    'columns' => ['#', 'When', 'User', 'Event', 'Object', 'Before → After', 'Outcome'],
    'rows' => array_map(fn($r) => [
        '<span class="text-text-muted">' . (int) $r['trail_id'] . '</span>',
        '<span class="whitespace-nowrap">' . date('M j, Y g:i A', strtotime((string) $r['timestamp'])) . '</span>',
        htmlspecialchars((string) ($r['full_name'] ?? 'system/' . $r['user_id'])),
        '<span class="badge badge-draft">' . htmlspecialchars($r['event_type']) . '</span>',
        htmlspecialchars($r['object_id']),
        '<span class="text-text-muted text-sm" style="max-width:340px;word-break:break-word">'
            . htmlspecialchars((string) ($r['before_value'] ?? ''))
            . (($r['before_value'] ?? '') !== '' ? ' → ' : '')
            . (htmlspecialchars((string) ($r['after_value'] ?? '')) ?: '—') . '</span>',
        '<span class="badge badge-' . (strtoupper((string) $r['outcome']) === 'SUCCESS' || $r['outcome'] === 'OVERRIDE' ? 'completed' : 'rejected') . '">' . htmlspecialchars((string) $r['outcome']) . '</span>',
    ], $rows),
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
