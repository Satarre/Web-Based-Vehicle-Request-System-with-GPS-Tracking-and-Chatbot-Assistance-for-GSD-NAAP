<?php
declare(strict_types=1);
/**
 * guard/history.php — my gate verifications, newest first.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [5]);
$ACTIVE = BASE_URL . 'guard/history.php';
$PAGE_TITLE = 'Verification History';

$st = $pdo->prepare(
    'SELECT g.*, t.dtt_number, t.passenger_name, v.plate_number
     FROM guard_verifications g
     JOIN trip_tickets t ON t.ticket_id = g.ticket_id
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     WHERE g.guard_id = ?
     ORDER BY g.verified_at DESC LIMIT 200'
);
$st->execute([$USER['id']]);
$rows = $st->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
$PAGE_SCRIPTS = ['table_filter.js'];
?>
<?php
$c = [
    'id' => 'tblGuardHist',
    'filter' => 'Search verifications…',
    'columns' => ['Ticket', 'Vehicle', 'Phase', 'Result', 'Notes', 'At'],
    'rows' => array_map(fn($g) => [
        '<strong>' . ($g['dtt_number'] ? htmlspecialchars($g['dtt_number']) : '#' . (int) $g['ticket_id']) . '</strong>',
        htmlspecialchars((string) ($g['plate_number'] ?? '—')),
        htmlspecialchars($g['phase']),
        '<span class="badge badge-' . ($g['result'] === 'VALIDATED' ? 'completed' : 'rejected') . '">' . htmlspecialchars($g['result']) . '</span>',
        htmlspecialchars((string) ($g['notes'] ?? '—')),
        date('M j, Y g:i A', strtotime((string) $g['verified_at'])),
    ], $rows),
    'empty' => 'No verifications recorded yet.',
];
include __DIR__ . '/../partials/ui/table.php';
?>
<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
