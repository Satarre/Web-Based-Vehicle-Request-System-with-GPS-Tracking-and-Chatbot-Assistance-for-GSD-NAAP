<?php
declare(strict_types=1);
/**
 * account/profile.php — self-service profile & password (all roles).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);
$ACTIVE = BASE_URL . 'account/profile.php';
$PAGE_TITLE = 'Profile & Password';
$PAGE_SCRIPTS = ['password-policy.js', 'account.js'];

$st = $pdo->prepare('SELECT first_name, middle_initial, middle_name, last_name, suffix FROM account WHERE account_id = ?');
$st->execute([$USER['id']]);
$row = $st->fetch();
$pf = [
    'first_name'     => (string) ($row['first_name'] ?? ''),
    'middle_initial' => (string) ($row['middle_initial'] ?? ''),
    'middle_name'    => (string) ($row['middle_name'] ?? ''),
    'last_name'      => (string) ($row['last_name'] ?? ''),
    'suffix'         => (string) ($row['suffix'] ?? ''),
];

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$inputCls = 'form-input';
$labelCls = 'form-label';
$btnPrimary = 'btn btn-primary';
?>
<div class="flex items-center gap-3 mb-3.5"><h1 class="text-lg">Profile &amp; Password</h1></div>

<div class="content-card p-3.5 mb-3.5 card-md">
  <h2 class="text-lg font-bold mb-2.5">Profile</h2>
  <label class="<?= $labelCls ?>">Email</label>
  <input type="text" value="<?= htmlspecialchars($USER['email']) ?>" disabled class="<?= $inputCls ?>">
  <label class="<?= $labelCls ?> mt-2.5">Role</label>
  <input type="text" value="<?= htmlspecialchars($USER['role_name']) ?>" disabled class="<?= $inputCls ?>">
  <label class="<?= $labelCls ?> mt-2.5">Name</label>
  <div class="grid grid-cols-4 max-lg:grid-cols-2 gap-3 gap-x-3.5">
    <div class="col-span-2"><label class="<?= $labelCls ?>">First Name *</label>
      <input type="text" id="pfFirstName" maxlength="100" value="<?= htmlspecialchars($pf['first_name']) ?>" class="<?= $inputCls ?> input-cap"></div>
    <div class="col-span-2">
      <div class="grid grid-cols-4 gap-3 gap-x-3.5">
        <div><label class="<?= $labelCls ?>">M.I.</label>
          <input type="text" id="pfMiddleInitial" maxlength="3" value="<?= htmlspecialchars($pf['middle_initial']) ?>" class="<?= $inputCls ?> input-cap" placeholder="M."></div>
        <div class="col-span-3"><label class="<?= $labelCls ?>">Middle Initial</label>
          <input type="text" id="pfMiddleName" maxlength="100" value="<?= htmlspecialchars($pf['middle_name']) ?>" class="<?= $inputCls ?> input-cap" placeholder="REYES"></div>
      </div>
    </div>
    <div class="col-span-2"><label class="<?= $labelCls ?>">Last Name *</label>
      <input type="text" id="pfLastName" maxlength="100" value="<?= htmlspecialchars($pf['last_name']) ?>" class="<?= $inputCls ?> input-cap"></div>
    <div class="col-span-2"><label class="<?= $labelCls ?>">Suffix</label>
      <input type="text" id="pfSuffix" maxlength="10" value="<?= htmlspecialchars($pf['suffix']) ?>" class="<?= $inputCls ?> input-cap" placeholder="Jr., Sr., III"></div>
  </div>
  <div class="mt-3 flex justify-end">
    <button class="<?= $btnPrimary ?>" id="btnProfileSave">Save Profile</button>
  </div>
</div>

<div class="content-card p-3.5 card-md">
  <h2 class="text-lg font-bold mb-2.5">Change Password</h2>
  <label class="<?= $labelCls ?>">Current Password *</label>
  <div class="relative">
    <input type="password" id="pwCurrent" autocomplete="current-password" class="<?= $inputCls ?> pr-10">
    <button type="button" class="icon-btn icon-btn-bare pw-toggle absolute inset-y-0 right-1.5 my-auto" data-pw-toggle="pwCurrent" aria-label="Show password" tabindex="-1">
      <span class="pw-ico pw-ico-show"><?= icon('eye') ?></span>
      <span class="pw-ico pw-ico-hide"><?= icon('eye-off') ?></span>
    </button>
  </div>
  <label class="<?= $labelCls ?> mt-2.5">New Password *</label>
  <div class="relative">
    <input type="password" id="pwNew" minlength="8" autocomplete="new-password" class="<?= $inputCls ?> pr-10">
    <button type="button" class="icon-btn icon-btn-bare pw-toggle absolute inset-y-0 right-1.5 my-auto" data-pw-toggle="pwNew" aria-label="Show password" tabindex="-1">
      <span class="pw-ico pw-ico-show"><?= icon('eye') ?></span>
      <span class="pw-ico pw-ico-hide"><?= icon('eye-off') ?></span>
    </button>
  </div>
  <label class="<?= $labelCls ?> mt-2.5">Confirm New Password *</label>
  <div class="relative">
    <input type="password" id="pwConfirm" autocomplete="new-password" class="<?= $inputCls ?> pr-10">
    <button type="button" class="icon-btn icon-btn-bare pw-toggle absolute inset-y-0 right-1.5 my-auto" data-pw-toggle="pwConfirm" aria-label="Show password" tabindex="-1">
      <span class="pw-ico pw-ico-show"><?= icon('eye') ?></span>
      <span class="pw-ico pw-ico-hide"><?= icon('eye-off') ?></span>
    </button>
  </div>
  <div class="pw-req" data-pw-req="pwNew"></div>
  <div class="mt-3 flex justify-end">
    <button class="<?= $btnPrimary ?>" id="btnPasswordSave">Change Password</button>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
