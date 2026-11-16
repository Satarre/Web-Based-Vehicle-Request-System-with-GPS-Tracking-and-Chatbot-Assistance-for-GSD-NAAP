<?php
declare(strict_types=1);
/**
 * account/trail.php — Log Trail: own login/logout history (all roles).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);
$ACTIVE = BASE_URL . 'account/trail.php';
$PAGE_TITLE = 'Log Trail';

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
$per  = 1000;

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

$st = $pdo->prepare(
    "SELECT * FROM audit_trail $whereSql ORDER BY trail_id DESC LIMIT $per"
);
$st->execute($args);
$rows = $st->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card p-3.5 mb-3">
  <form method="get" class="flex gap-2 flex-wrap items-center">
    <h2 class="text-lg font-bold mr-auto">Log Trail <span class="text-text-muted font-normal ml-2">(<?= $total ?> records)</span></h2>
    <input class="form-input input-date" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <input class="form-input input-date" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <button class="btn btn-primary btn-form">Filter</button>
    <?php if ($from || $to): ?><a class="btn btn-ghost btn-form" href="<?= BASE_URL ?>account/trail.php">Clear</a><?php endif; ?>
  </form>
</div>

<?php
$c = [
    'id' => 'tblTrail',
    'attrs' => 'data-page-size="25"',
    'columns' => ['User Type', 'User ID', 'User Name', 'Event', 'Detail', 'Log Date', 'IP Address'],
    'rows' => array_map(fn($r) => [
        htmlspecialchars((string) $USER['role_name']),
        '<span class="text-text-muted">#' . (int) $r['user_id'] . '</span>',
        htmlspecialchars((string) $USER['full_name']),
        '<span class="badge badge-draft">' . htmlspecialchars($r['event_type']) . '</span>',
        htmlspecialchars((string) ($r['after_value'] ?? ($r['event_type'] === 'LOGIN' ? 'account logged in' : 'account logged out'))),
        '<span class="whitespace-nowrap">' . date('M j, Y g:i A', strtotime((string) $r['timestamp'])) . '</span>',
        '<span class="text-text-muted">' . htmlspecialchars((string) ($r['ip_address'] ?? '—')) . '</span>',
    ], $rows),
    'empty' => 'No login/logout records found.',
];
include __DIR__ . '/../partials/ui/table.php';
?>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
