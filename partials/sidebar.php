<?php
/**
 * partials/sidebar.php
 * Expects: $USER (Auth user), optional $ACTIVE (href).
 * Nav comes from nav_for() in nav.php — pages must not define $NAV.
 */
if (!function_exists('icon')) { require_once __DIR__ . '/icons.php'; }
require_once __DIR__ . '/nav.php';
$logo = BASE_URL . 'assets/img/VTS_white.png';
?>
<aside class="sidebar bg-shell text-shell-text" id="appSidebar">
  <div class="sb-brand border-b border-white-8">
    <img src="<?= $logo ?>" alt="VTS logo">
    <div class="min-w-0">
      <div class="sb-brand-title">National Aviation<br>Academy of the Philippines</div>
      <div class="sb-brand-sub">GSD Digital Trip Ticket System</div>
    </div>
  </div>
  <nav class="sidebar-nav py-[10px]">
    <?php foreach (nav_for((int) ($USER['role_id'] ?? 0)) as $section): ?>
      <?php foreach ($section['items'] as $item):
        $active = (isset($ACTIVE) && $ACTIVE === $item['href']) || ($_SERVER['SCRIPT_NAME'] ?? '') === parse_url($item['href'], PHP_URL_PATH); ?>
        <a class="nav-item<?= $active ? ' bg-accent text-on-accent' : ' text-shell-text' ?> hover:no-underline" href="<?= $item['href'] ?>">
          <?= icon($item['icon'] ?? 'dashboard') ?>
          <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <?php if (($USER['role_id'] ?? 0) === 6): ?>
    <a class="nav-item mt-auto border-t border-white-8 text-shell-text hover:no-underline" href="#" data-chat-open>
      <?= icon('bot') ?>
      <span>Ask GSD Assistant</span>
    </a>
  <?php endif; ?>
</aside>
