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
 *   rows        list of rows; each row = list of raw-HTML cell strings (caller escapes),
 *               or ['cells' => [...], 'pin' => 'draft'] to keep the row above sorted rows
 *   empty       message row when rows empty (default "No records."); colspan auto
 *   attrs       raw attrs for the <table>
 */
$c = $c ?? [];
$id = htmlspecialchars((string) ($c['id'] ?? ''));
$cols = (array) ($c['columns'] ?? []);
$rows = (array) ($c['rows'] ?? []);
$empty = (string) ($c['empty'] ?? 'No records.');
$attrs = ' ' . trim((string) ($c['attrs'] ?? ''));
/* Optional column sorting: $c['sort'] = [colIndex => sortKey]. When set, the
 * table becomes data-sortable and those <th> get data-sort (table_sort.js).
 * A cell may be ['html' => '…', 'sort' => 'raw'] to expose a raw sort value. */
$sort = (array) ($c['sort'] ?? []);
/* Optional default sort, e.g. 'schedule:desc'. Applied by table_sort.js on
 * load so the header shows the active direction and the first click inverts. */
$sortDefault = (string) ($c['sort_default'] ?? '');
?>
<div class="content-card">
  <div class="card-head">
    <span><?= htmlspecialchars((string) ($c['title'] ?? '')) ?><?php if (isset($c['sub'])): ?> <span class="text-text-muted font-normal text-sm ml-2"><?= htmlspecialchars((string) $c['sub']) ?></span><?php endif; ?></span>
    <div class="card-head-actions">
<?php if (!empty($c['add_button'])): ?><?= $c['add_button'] ?><?php endif; ?>
<?php if (!empty($c['filter'])): ?>
      <input class="form-input input-shrink" type="search" placeholder="<?= htmlspecialchars((string) $c['filter']) ?>" data-filter="#<?= $id ?>">
<?php endif; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table" id="<?= $id ?>" data-pager<?= $sort ? ' data-sortable' : '' ?><?= $sortDefault !== '' ? ' data-sort-default="' . htmlspecialchars($sortDefault) . '"' : '' ?><?= $attrs ?>>
      <thead><tr><?php foreach ($cols as $i => $th): ?><th class="whitespace-nowrap"<?= isset($sort[$i]) ? ' data-sort="' . htmlspecialchars((string) $sort[$i]) . '"' : '' ?>><?= htmlspecialchars((string) $th) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
<?php if (!$rows): ?>
        <tr><td colspan="<?= count($cols) ?>" class="text-center text-text-muted p-5 whitespace-normal"><?= htmlspecialchars($empty) ?></td></tr>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
<?php
        /* A row may be a plain cell list, or ['cells' => [...], 'pin' => 'draft'].
         * The pin keeps it above the sorted rows (see table_sort.js). */
        $rowCells = (array) $row;
        $rowAttr  = '';
        if (isset($row['cells']) && is_array($row['cells'])) {
            $rowCells = $row['cells'];
            if (!empty($row['pin'])) {
                $rowAttr = ' data-pin="' . htmlspecialchars((string) $row['pin']) . '"';
            }
        }
?>
        <tr<?= $rowAttr ?>><?php $i = 0; foreach ($rowCells as $cell): $html = is_array($cell) ? (string) ($cell['html'] ?? '') : (string) $cell; $sortVal = is_array($cell) ? ($cell['sort'] ?? null) : null; $lbl = trim((string) ($cols[$i] ?? '')); $attr = $lbl !== '' ? ' data-label="' . htmlspecialchars($lbl) . '"' : ''; if ($sortVal !== null) { $attr .= ' data-sort="' . htmlspecialchars((string) $sortVal) . '"'; } ?><td<?= $attr ?>><?= $html ?></td><?php $i++; endforeach; ?></tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php unset($c, $id, $cols, $rows, $empty, $attrs, $sort, $sortDefault, $th, $row, $rowCells, $rowAttr, $cell, $html, $sortVal, $lbl, $attr); ?>
