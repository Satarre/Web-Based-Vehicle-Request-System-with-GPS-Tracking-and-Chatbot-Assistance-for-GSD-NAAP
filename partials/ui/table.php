<?php
declare(strict_types=1);
/**
 * partials/ui/table.php — the standard list card: card > card-head (+search) > table-wrap > table.
 * Set $c (array) before including. Keys:
 *   title*      card-head heading
 *   sub         muted count/hint next to title
 *   filter      search placeholder → renders search input wired via data-filter="#{id}"
 *   id*         table id (also the filter target)
 *   columns*    list of <th> labels (escaped)
 *   rows        list of rows; each row = list of raw-HTML cell strings (caller escapes)
 *   empty       message row when rows empty (default "No records."); colspan auto
 *   attrs       raw attrs for the <table>
 */
$c = $c ?? [];
$id = htmlspecialchars((string) ($c['id'] ?? ''));
$cols = (array) ($c['columns'] ?? []);
$rows = (array) ($c['rows'] ?? []);
$empty = (string) ($c['empty'] ?? 'No records.');
$attrs = ' ' . trim((string) ($c['attrs'] ?? ''));
?>
<div class="content-card">
  <div class="card-head">
    <span><?= htmlspecialchars((string) ($c['title'] ?? '')) ?><?php if (isset($c['sub'])): ?> <span class="text-text-muted font-normal text-sm"><?= htmlspecialchars((string) $c['sub']) ?></span><?php endif; ?></span>
<?php if (!empty($c['filter'])): ?>
    <div class="flex items-center gap-1.5 ml-auto flex-wrap"><input class="w-48" type="search" placeholder="<?= htmlspecialchars((string) $c['filter']) ?>" data-filter="#<?= $id ?>"></div>
<?php endif; ?>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table" id="<?= $id ?>" data-pager<?= $attrs ?>>
      <thead><tr><?php foreach ($cols as $th): ?><th class="whitespace-nowrap"><?= htmlspecialchars((string) $th) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
<?php if (!$rows): ?>
        <tr><td colspan="<?= count($cols) ?>" class="text-center text-text-muted p-5 whitespace-normal"><?= htmlspecialchars($empty) ?></td></tr>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
        <tr><?php foreach ((array) $row as $td): ?><td class="whitespace-nowrap"><?= $td ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php unset($c, $id, $cols, $rows, $empty, $attrs, $th, $row, $td); ?>
