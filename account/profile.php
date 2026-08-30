<?php
declare(strict_types=1);
/**
 * account/profile.php — self-service profile & password (all roles).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);
$ACTIVE = BASE_URL . 'account/profile.php';
$PAGE_TITLE = 'Profile & Password';
$PAGE_SCRIPTS = ['account.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$inputCls = 'form-input';
$labelCls = 'form-label';
$btnPrimary = 'btn btn-primary';
?>
<div class="flex items-center gap-3 mb-3.5"><h1 class="text-lg">Profile &amp; Password</h1></div>

<div class="content-card p-3.5 mb-3.5" style="max-width:560px">
  <h2 class="text-lg font-bold mb-2.5">Profile</h2>
  <label class="<?= $labelCls ?>">Email</label>
  <input type="text" value="<?= htmlspecialchars($USER['email']) ?>" disabled class="<?= $inputCls ?>">
  <label class="<?= $labelCls ?> mt-2.5">Role</label>
  <input type="text" value="<?= htmlspecialchars($USER['role_name']) ?>" disabled class="<?= $inputCls ?>">
  <label class="<?= $labelCls ?> mt-2.5">Full Name *</label>
  <input type="text" id="pfName" maxlength="150" value="<?= htmlspecialchars($USER['full_name']) ?>" class="<?= $inputCls ?>">
  <div class="mt-3 flex justify-end">
    <button class="<?= $btnPrimary ?>" id="btnProfileSave">Save Profile</button>
  </div>
</div>

<div class="content-card p-3.5" style="max-width:560px">
  <h2 class="text-lg font-bold mb-2.5">Change Password</h2>
  <label class="<?= $labelCls ?>">Current Password *</label>
  <input type="password" id="pwCurrent" autocomplete="current-password" class="<?= $inputCls ?>">
  <label class="<?= $labelCls ?> mt-2.5">New Password *</label>
  <input type="password" id="pwNew" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" class="<?= $inputCls ?>">
  <label class="<?= $labelCls ?> mt-2.5">Confirm New Password *</label>
  <input type="password" id="pwConfirm" autocomplete="new-password" class="<?= $inputCls ?>">
  <div class="mt-3 flex justify-end">
    <button class="<?= $btnPrimary ?>" id="btnPasswordSave">Change Password</button>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
