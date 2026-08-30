<?php
/**
 * partials/page_stub.php — Phase-0 module placeholder.
 * Expects: $STUB_TITLE, $USER, $NAV, $ACTIVE, $PAGE_TITLE.
 */
$PAGE_TITLE = $PAGE_TITLE ?? ($STUB_TITLE ?? 'Module');
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="content-card" style="max-width:640px">
  <div class="p-3.5">
    <h2 style="font-size:15px;margin-bottom:6px"><?= htmlspecialchars($STUB_TITLE) ?></h2>
    <p style="color:var(--text-muted);font-size:12.5px">
      This module arrives in a later build phase. The application shell, theme system,
      settings drawer and authentication are live.
    </p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
      <span class="badge badge-completed">Shell OK</span>
      <span class="badge badge-completed">Auth OK</span>
      <span class="badge badge-pending">Module pending</span>
    </div>
  </div>
</div>
<?php require_once dirname(__DIR__) . '/partials/layout_foot.php';
