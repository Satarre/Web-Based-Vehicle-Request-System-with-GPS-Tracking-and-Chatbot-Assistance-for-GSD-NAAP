<?php
declare(strict_types=1);
/**
 * partials/ui/badge.php — status pill.
 * Set $c (array) before including. Keys:
 *   status   status name, e.g. 'DRAFT', 'PENDING' (case-insensitive)
 *   label    visible text (defaults to status)
 */
$c = $c ?? [];
$status = strtoupper((string) ($c['status'] ?? ''));
$statusMap = [
    'DRAFT'               => 'badge-draft',
    'PENDING'             => 'badge-pending',
    'VERIFIED'            => 'badge-verified',
    'APPROVED'            => 'badge-approved',
    'DISPATCHED'          => 'badge-dispatched',
    'ON_GOING'            => 'badge-on-going',
    'COMPLETED'           => 'badge-completed',
    'CANCELLED'           => 'badge-cancelled',
    'REJECTED'            => 'badge-rejected',
    'RESCHEDULED'         => 'badge-rescheduled',
];
$cls = 'badge ' . ($statusMap[$status] ?? 'badge-draft');
?>
<span class="<?= $cls ?>"><?= htmlspecialchars((string) ($c['label'] ?? $status)) ?></span>
<?php unset($c, $status, $statusMap, $cls); ?>
