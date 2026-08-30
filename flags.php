<?php
declare(strict_types=1);
/**
 * flags.php — shared flag desk (Phase 5). Roles 1 (MIS) & 3 (Staff).
 * Lists open trip_flags with manual resolution (mandatory reason);
 * NO_TELEMETRY/GEOFENCE_DEVIATION raised live, the rest by scripts/evaluate_flags.php.
 */
require_once __DIR__ . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 3]);
$ACTIVE = BASE_URL . 'flags.php';
$PAGE_TITLE = 'Flags';

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';

$open = $pdo->query(
    'SELECT f.flag_id, f.flag_type, f.detail, f.raised_at,
            t.ticket_id, t.dtt_number, v.plate_number, d.full_name AS driver_name
     FROM trip_flags f
     JOIN trip_tickets t ON t.ticket_id = f.ticket_id
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     LEFT JOIN account d ON d.account_id = t.driver_id
     WHERE f.resolved_at IS NULL
     ORDER BY f.raised_at ASC'
)->fetchAll();

$resolved = $pdo->query(
    'SELECT f.flag_type, f.resolution_reason, f.resolved_at,
            t.ticket_id, t.dtt_number, a.full_name AS resolver_name
     FROM trip_flags f
     JOIN trip_tickets t ON t.ticket_id = f.ticket_id
     LEFT JOIN account a ON a.account_id = f.resolved_by
     WHERE f.resolved_at IS NOT NULL
     ORDER BY f.resolved_at DESC LIMIT 15'
)->fetchAll();

$PAGE_SCRIPTS = ['flags.js', 'table_filter.js'];
require_once __DIR__ . '/partials/layout_head.php';
require_once __DIR__ . '/partials/shell_open.php';
?>
<div class="flex items-center gap-2 flex-wrap mb-3.5">
  <h1 class="text-xl">Trip Flags <span class="text-text-muted">(<?= count($open) ?> open)</span></h1>
</div>

<?php
$openRows = array_map(function ($f) use ($fmt) {
    return [
        '<span class="badge badge-rejected">' . htmlspecialchars($f['flag_type']) . '</span>',
        '<a href="' . BASE_URL . 'ticket.php?id=' . (int) $f['ticket_id'] . '"><strong>' . htmlspecialchars($f['dtt_number'] ?? ('#' . $f['ticket_id'])) . '</strong></a>',
        htmlspecialchars((string) ($f['plate_number'] ?? '—')),
        htmlspecialchars((string) ($f['driver_name'] ?? '—')),
        '<span class="text-text-muted">' . htmlspecialchars((string) ($f['detail'] ?? '')) . '</span>',
        $fmt($f['raised_at']),
        '<button class="btn btn-sm btn-primary" data-resolve="' . (int) $f['flag_id'] . '">Resolve…</button>',
    ];
}, $open);
?>
<div style="margin-bottom:14px">
  <div class="content-card">
    <div class="px-3.5 pt-3.5 pb-0">
      <h2 class="text-lg font-bold">Open Flags</h2>
      <p class="text-text-muted text-xs mt-1 mb-2.5">Raised automatically by the GPS pipeline and the scheduled evaluator (<code>scripts/evaluate_flags.php</code>). Resolving requires a reason and is audited.</p>
      <div class="flex items-center gap-1.5 flex-wrap mb-3" style="justify-content:flex-start"><input class="w-48" type="search" placeholder="Search open flags…" data-filter="#tblOpenFlags"></div>
    </div>
    <div class="overflow-x-auto">
      <table class="data-table" id="tblOpenFlags" data-pager>
        <thead><tr><th class="whitespace-nowrap">Type</th><th class="whitespace-nowrap">Ticket</th><th class="whitespace-nowrap">Vehicle</th><th class="whitespace-nowrap">Driver</th><th class="whitespace-nowrap">Detail</th><th class="whitespace-nowrap">Raised</th><th class="whitespace-nowrap"></th></tr></thead>
        <tbody>
        <?php if (!$open): ?>
          <tr><td colspan="7" class="text-center text-text-muted p-5 whitespace-normal">No open flags — all clear.</td></tr>
        <?php endif; ?>
        <?php foreach ($openRows as $row): ?>
          <tr><?php foreach ($row as $td): ?><td class="whitespace-nowrap"><?= $td ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$c = [
    'title' => 'Recently Resolved',
    'id' => 'tblResolved',
    'filter' => 'Search resolved…',
    'columns' => ['Type', 'Ticket', 'Reason', 'Resolved By', 'At'],
    'rows' => array_map(fn($f) => [
        '<span class="badge badge-completed">' . htmlspecialchars($f['flag_type']) . '</span>',
        '<a href="' . BASE_URL . 'ticket.php?id=' . (int) $f['ticket_id'] . '">' . htmlspecialchars($f['dtt_number'] ?? ('#' . $f['ticket_id'])) . '</a>',
        '<span class="text-text-muted">' . htmlspecialchars((string) ($f['resolution_reason'] ?? '')) . '</span>',
        htmlspecialchars((string) ($f['resolver_name'] ?? '—')),
        $fmt($f['resolved_at']),
    ], $resolved),
    'empty' => 'Nothing resolved yet.',
];
include __DIR__ . '/partials/ui/table.php';
?>

<?php
$RM_ID = 'resolveModal'; $RM_TITLE = 'Resolve Flag'; $RM_OK = 'Resolve';
require_once __DIR__ . '/partials/reason_modal.php';
require_once __DIR__ . '/partials/layout_foot.php';
