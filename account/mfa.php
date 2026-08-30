<?php
declare(strict_types=1);
/**
 * account/mfa.php — Two-Factor (MFA) placeholder.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);
$ACTIVE = BASE_URL . 'account/mfa.php';
$PAGE_TITLE = 'Two-Factor (MFA)';

$st = $pdo->prepare('SELECT mfa_enabled FROM account WHERE account_id = ?');
$st->execute([$USER['id']]);
$mfaOn = (bool) $st->fetchColumn();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="flex items-center gap-3 mb-3.5"><h1 class="text-lg">Two-Factor Authentication (MFA)</h1></div>

<div class="content-card p-3.5" style="max-width:560px">
  <p class="text-base m-0 mb-2.5">Status:
    <span class="badge badge-<?= $mfaOn ? 'completed' : 'pending' ?>"><?= $mfaOn ? 'Enabled' : 'Not set up' ?></span>
  </p>
  <p class="text-text-muted text-md m-0">
    Two-factor authentication adds a one-time code on top of your password at login.
    Self-service setup is coming in a later build phase — for now, contact the MIS Administrator
    if you need MFA configured or reset for your account.
  </p>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
