<?php
declare(strict_types=1);
/**
 * account/mfa.php — self-service Two-Factor (MFA) setup, recovery codes, disable.
 * Uses TOTP (RFC 6238) compatible with Google Authenticator, Microsoft
 * Authenticator, Authy, 1Password and other authenticator apps.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);
$ACTIVE = BASE_URL . 'account/mfa.php';
$PAGE_TITLE = 'Two-Factor (MFA)';
$PAGE_SCRIPTS = ['mfa.js'];
$VENDOR_SCRIPTS = ['vendor/qrcode.js', 'qr-render.js'];

$st = $pdo->prepare('SELECT mfa_enabled, mfa_enabled_at, email_verified_at FROM account WHERE account_id = ?');
$st->execute([$USER['id']]);
$row = $st->fetch();
$mfaOn = (bool) ($row['mfa_enabled'] ?? false);
$mfaSince = $row['mfa_enabled_at'] ?? null;
$emailVerified = (bool) ($row['email_verified_at'] ?? false);

$roleId = (int) $USER['role_id'];
$required   = in_array($roleId, Auth::mfaRequiredRoles(), true);
$recommended = in_array($roleId, Auth::mfaRecommendedRoles(), true);

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="flex items-center gap-3 mb-3.5"><h1 class="text-lg">Two-Factor Authentication (MFA)</h1></div>

<?php if ($required): ?>
  <div class="alert alert-error mb-3.5">Your role requires two-factor authentication. Sign-in is blocked until it is enabled.</div>
<?php elseif ($recommended): ?>
  <div class="alert alert-info mb-3.5">Your role strongly recommends enabling two-factor authentication.</div>
<?php endif; ?>

<div class="content-card p-3.5 mb-3.5 card-md">
  <p class="text-base m-0 mb-2.5">Status:
    <span class="badge badge-<?= $mfaOn ? 'completed' : 'pending' ?>"><?= $mfaOn ? 'Enabled' : 'Not set up' ?></span>
    <?php if ($mfaOn && $mfaSince): ?>
      <span class="text-text-muted text-md ml-2">since <?= htmlspecialchars(date('M j, Y', strtotime((string) $mfaSince))) ?></span>
    <?php endif; ?>
  </p>
  <p class="text-text-muted text-md m-0">
    Two-factor authentication adds a one-time code on top of your password at login.
    Compatible with <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong>,
    <strong>Authy</strong>, <strong>1Password</strong>, and any RFC 6238-compatible authenticator app.
  </p>
</div>

<?php if (!$mfaOn): ?>
  <div class="content-card p-3.5 mb-3.5 card-md">
    <h2 class="text-lg font-bold mb-2.5">Enable Two-Factor Authentication</h2>
    <ol class="text-md text-text-muted m-0 mb-3 pl-4 space-y-1">
      <li>Install an authenticator app on your phone.</li>
      <li>Tap <em>Start Setup</em> and scan the QR code (or type the code shown).</li>
      <li>Enter the current 6-digit code from the app to confirm.</li>
      <li>Save the recovery codes shown afterward.</li>
    </ol>
    <div class="flex justify-end">
      <button class="btn btn-primary" id="btnMfaBegin">Start Setup</button>
    </div>
  </div>

  <div class="content-card p-3.5 card-md" id="mfaSetupCard" hidden>
    <h2 class="text-lg font-bold mb-2.5">Scan with your Authenticator App</h2>
    <div class="flex flex-wrap gap-4 items-start">
      <div id="mfaQr" class="rounded-lg" style="width:200px;height:200px"></div>
      <div class="min-w-0 flex-1">
        <label class="form-label">Manual entry code</label>
        <input type="text" id="mfaSecret" readonly class="form-input font-mono tracking-widest" style="max-width:220px">
        <label class="form-label mt-2.5">Current 6-Digit Code *</label>
        <input type="text" id="mfaCode" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
               placeholder="000000" autocomplete="one-time-code" class="form-input text-center tracking-widest text-xl font-bold" style="max-width:160px">
        <div class="mt-3 flex gap-2">
          <button class="btn btn-primary" id="btnMfaEnable">Verify &amp; Enable</button>
          <button class="btn btn-ghost" id="btnMfaCancel">Cancel</button>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="content-card p-3.5 mb-3.5 card-md">
    <h2 class="text-lg font-bold mb-2.5">Recovery Codes</h2>
    <p class="text-text-muted text-md m-0 mb-3">
      Use a recovery code to sign in if you lose your authenticator app.
      Each code works once. Regenerating invalidates the previous set.
    </p>
    <div class="flex justify-end">
      <button class="btn btn-primary" id="btnMfaRegen">Regenerate Recovery Codes</button>
    </div>
  </div>

  <div class="content-card p-3.5 card-md">
    <h2 class="text-lg font-bold mb-2.5">Disable Two-Factor Authentication</h2>
    <p class="text-text-muted text-md m-0 mb-3">
      Requires your current password and an authenticator or recovery code.
      <?= $required ? 'Your role requires MFA — it will be forced again at your next sign-in.' : '' ?>
    </p>
    <div class="flex justify-end">
      <button class="btn btn-danger" id="btnMfaDisable">Disable MFA</button>
    </div>
  </div>
<?php endif; ?>

<?php if (!$emailVerified): ?>
<div class="content-card p-3.5 mt-3.5 card-md">
  <h2 class="text-lg font-bold mb-2.5">Verify Email Address</h2>
  <p class="text-text-muted text-md m-0 mb-3">Your email address has not been verified yet. A code will be sent to <?= htmlspecialchars($USER['email']) ?>.</p>
  <div class="flex flex-wrap gap-2 items-center">
    <button class="btn btn-primary" id="btnEmailVerifySend">Send Code</button>
    <input type="text" id="emailVerifyCode" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
           placeholder="6-digit code" autocomplete="one-time-code" class="form-input text-center tracking-widest" style="max-width:150px">
    <button class="btn btn-ghost" id="btnEmailVerifyConfirm">Verify</button>
  </div>
</div>
<?php endif; ?>

<!-- Reconfirm identity (password + TOTP) before sensitive MFA actions -->
<div class="modal-backdrop" id="mfaConfirmModal">
  <div class="modal-body" style="max-width:380px">
    <div class="modal-head"><span id="mfaConfirmTitle">Confirm Identity</span>
      <button class="modal-close" type="button" onclick="UI.modal('mfaConfirmModal', false)"><?= function_exists('icon') ? icon('x') : '×' ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <p class="text-text-muted text-md m-0 mb-3">Confirm your password and current authenticator code to continue.</p>
      <label class="form-label">Current Password *</label>
      <input type="password" id="mfaConfirmPassword" autocomplete="current-password" class="form-input">
      <label class="form-label mt-2.5">Authenticator or Recovery Code *</label>
      <input type="text" id="mfaConfirmCode" inputmode="numeric" maxlength="9" autocomplete="one-time-code" class="form-input text-center tracking-widest">
      <div class="mt-3 flex justify-end gap-2">
        <button class="btn btn-ghost" type="button" onclick="UI.modal('mfaConfirmModal', false)">Cancel</button>
        <button class="btn btn-primary" id="btnMfaConfirmAction">Continue</button>
      </div>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>