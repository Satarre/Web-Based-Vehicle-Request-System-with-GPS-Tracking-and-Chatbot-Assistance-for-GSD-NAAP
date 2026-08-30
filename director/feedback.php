<?php
declare(strict_types=1);
/**
 * director/feedback.php — HR feedback management (view + soft delete).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [2]);
$ACTIVE = BASE_URL . 'director/feedback.php';
$PAGE_TITLE = 'Feedback';

$rows = $pdo->query(
    'SELECT f.*, t.dtt_number FROM feedback f LEFT JOIN trip_tickets t ON t.ticket_id = f.ticket_id
     WHERE f.is_active = 1 ORDER BY f.submitted_at DESC LIMIT 200'
)->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card p-3.5 mb-3.5">
  <h2 class="text-lg font-bold">Feedback <span class="text-text-muted text-xs font-normal">(<?= count($rows) ?>)</span></h2>
  <p class="text-text-muted text-xs mt-1">Feedback is decoupled from the trip lifecycle — removing an entry does not affect any ticket.</p>
</div>

<?php if (!$rows): ?>
<div class="content-card p-3.5"><p class="text-text-muted text-base m-0">No feedback submitted yet.</p></div>
<?php else: ?>
<?php
$c = [
    'title' => 'Submissions',
    'id' => 'tblFeedback',
    'filter' => 'Search feedback…',
    'columns' => ['Ticket', 'Submitter', 'Details', 'Submitted', ''],
    'rows' => array_map(fn($r) => [
        $r['dtt_number'] ? '<strong>' . htmlspecialchars((string) $r['dtt_number']) . '</strong>' : '#' . (int) $r['ticket_id'],
        htmlspecialchars((string) ($r['submitter_name'] ?? '—')),
        '<div style="max-width:420px;font-size:12.5px">' . nl2br(htmlspecialchars((string) $r['details'])) . '</div>',
        '<span class="whitespace-nowrap">' . date('M j, Y g:i A', strtotime((string) $r['submitted_at'])) . '</span>',
        '<button class="btn btn-sm btn-danger" data-fb-del="' . (int) $r['feedback_id'] . '">Remove</button>',
    ], $rows),
];
include __DIR__ . '/../partials/ui/table.php';
?>
<?php endif; ?>

<?php
$PAGE_SCRIPTS = ['admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
