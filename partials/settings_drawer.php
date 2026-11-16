<?php
/** partials/settings_drawer.php — global settings drawer (theme, account links). */
if (!function_exists('icon')) { require_once __DIR__ . '/icons.php'; }
$u = $USER ?? null;
?>
<div class="drawer-backdrop" id="drawerBackdrop"></div>
<aside class="drawer" id="settingsDrawer" aria-label="Settings">
  <div class="flex items-center justify-between p-3.5 px-4 border-b border-border font-extrabold">
    <span class="flex items-center gap-2"><?= icon('settings') ?> Settings</span>
      <button class="icon-btn" id="drawerClose"><?= icon('x') ?></button>
  </div>
  <div class="p-3.5 px-4 overflow-y-auto flex-1">
    <div class="mb-4">
      <label class="form-label">Appearance</label>
      <div class="seg">
        <button type="button" data-theme-opt="light" class="btn btn-sm btn-ghost flex-1">Light</button>
        <button type="button" data-theme-opt="dark" class="btn btn-sm btn-ghost flex-1">Dark</button>
      </div>
    </div>
    <?php if ($u && (int) $u['role_id'] !== 7): /* GPS TRACKER accounts have their own single page */ ?>
    <div>
      <label class="form-label">My Account</label>
      <a class="flex items-center gap-2 p-2 px-2.5 rounded-lg text-app-text font-semibold text-md hover:bg-bg-page hover:no-underline no-underline" href="<?= BASE_URL ?>account/profile.php"><?= icon('users') ?> Profile &amp; Password</a>
      <a class="flex items-center gap-2 p-2 px-2.5 rounded-lg text-app-text font-semibold text-md hover:bg-bg-page hover:no-underline no-underline" href="<?= BASE_URL ?>account/mfa.php"><?= icon('shield') ?> Two-Factor (MFA)</a>
      <a class="flex items-center gap-2 p-2 px-2.5 rounded-lg text-app-text font-semibold text-md hover:bg-bg-page hover:no-underline no-underline" href="<?= BASE_URL ?>account/trail.php"><?= icon('history') ?> Log Trail</a>
      <a class="flex items-center gap-2 p-2 px-2.5 rounded-lg text-app-text font-semibold text-md hover:bg-bg-page hover:no-underline no-underline" href="<?= BASE_URL ?>account/audit.php"><?= icon('report') ?> Audit Trail</a>
      <?php if ((int) $u['role_id'] === 1): ?>
      <a class="flex items-center gap-2 p-2 px-2.5 rounded-lg text-app-text font-semibold text-md hover:bg-bg-page hover:no-underline no-underline" href="<?= BASE_URL ?>mis/audit.php"><?= icon('requests') ?> System Audit Trail</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($u): ?>
  <div class="p-3 px-4 border-t border-border">
    <a class="btn btn-danger w-full no-underline" href="<?= BASE_URL ?>auth/logout.php">
      <?= icon('logout') ?> Logout
    </a>
  </div>
  <?php endif; ?>
</aside>

<div id="idleOverlay"><div class="bg-bg-surface text-app-text rounded-xl p-7 text-center max-w-xs">
  <h3 class="text-lg">Session Idle</h3>
  <p class="text-text-muted text-sm mt-1.5 mb-1.5">You will be logged out in</p>
  <div class="text-5xl font-extrabold font-mono my-2" id="idleCountdown">30</div>
  <button class="btn btn-primary" onclick="stayLoggedIn()">Stay Logged In</button>
</div></div>
