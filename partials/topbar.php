<?php
/**
 * partials/topbar.php
 * Expects: $PAGE_TITLE, $USER.
 */
if (!function_exists('icon')) { require_once __DIR__ . '/icons.php'; }
?>
<header class="topbar p-2.5 px-5 bg-bg-surface border-b border-border">
  <button class="nav-toggle icon-btn" data-toggle-nav title="Menu"><?= icon('requests') ?></button>
  <span class="text-lg font-extrabold"><?= htmlspecialchars($PAGE_TITLE) ?></span>
  <div class="topbar-right ml-auto flex items-center gap-2">
    <span class="text-sm font-bold text-text-muted">
      <?= htmlspecialchars($USER['full_name']) ?> · <?= htmlspecialchars($USER['role_name']) ?>
    </span>
    <button class="icon-btn" data-open-settings title="Settings"><?= icon('settings') ?></button>
  </div>
</header>
