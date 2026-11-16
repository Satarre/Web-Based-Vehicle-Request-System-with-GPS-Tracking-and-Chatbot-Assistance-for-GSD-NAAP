<?php
declare(strict_types=1);
/**
 * mis/accounts.php — accounts & roles admin.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1]);
$ACTIVE = BASE_URL . 'mis/accounts.php';
$PAGE_TITLE = 'Accounts & Roles';

$roles = $pdo->query('SELECT * FROM roles ORDER BY role_id')->fetchAll();
$roleNames = [];
foreach ($roles as $r) {
    $roleNames[(int) $r['role_id']] = $r['role_name'];
}

$isSuper = (int) ($USER['is_super_admin'] ?? 0) === 1;

/* At most one ACTIVE SAO (2) / GSD Dispatcher (3) account at a time. */
$activeByRole = [];
foreach ($pdo->query('SELECT account_id, role_id, full_name FROM account WHERE role_id IN (2,3) AND is_active = 1 ORDER BY account_id') as $r) {
    $rid = (int) $r['role_id'];
    if (!isset($activeByRole[$rid])) {
        $activeByRole[$rid] = $r;
    }
}

/* Add-form roles: MIS ADMIN is reserved for the MIS Super Administrator, and a
 * role that already has an active singleton holder (SAO / GSD Dispatcher) is
 * not offered — the holder must be deactivated before creating a replacement.
 * GPS TRACKER (7) is a hidden system role owned by device registration, never
 * assignable to people. Server-side guards in api/admin.php remain the hard gate. */
$assignableRoles = array_values(array_filter(
    $roles,
    static fn(array $r): bool => ($isSuper || (int) $r['role_id'] !== 1)
        && (int) $r['role_id'] !== 7
        && !isset($activeByRole[(int) $r['role_id']])
));

/* Hidden system accounts (GPS trackers) are never listed here. */
$accounts = $pdo->query(
    'SELECT a.account_id, a.email, a.full_name, a.first_name, a.middle_initial, a.middle_name, a.last_name, a.suffix, a.role_id, a.is_super_admin, a.is_active, a.login_attempts, a.locked_until, a.created_at,
            (SELECT COUNT(*) FROM trip_tickets t WHERE t.created_by = a.account_id) AS tickets
     FROM account a WHERE a.is_system = 0 ORDER BY a.role_id, a.full_name'
)->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<?php
$accountRows = array_map(function ($a) use ($USER, $roleNames, $isSuper, $activeByRole) {
    $toggleCls = $a['is_active']
        ? 'btn btn-sm btn-danger'
        : 'btn btn-sm btn-primary';
    $isSelf   = (int) $a['account_id'] === (int) $USER['id'];
    $locked   = !empty($a['locked_until']) && strtotime((string) $a['locked_until']) > time();
    $attempts = (int) $a['login_attempts'];
    $isMisAdmin = (int) $a['role_id'] === 1;
    $isSuperRow = (int) ($a['is_super_admin'] ?? 0) === 1;
    /* MIS ADMIN accounts are managed only by the super administrator
     * (every account may still edit itself). */
    $canManage = $isSuper || !$isMisAdmin;
    /* Inactive SAO / GSD Dispatcher cannot be activated while a same-role account is active. */
    $roleHolder    = $activeByRole[(int) $a['role_id']] ?? null;
    $blockActivate = !$a['is_active'] && $roleHolder !== null
        && (int) $roleHolder['account_id'] !== (int) $a['account_id'];

    $actions = [];
    if ($canManage || $isSelf) {
        $actions[] = '<button class="btn btn-sm btn-ghost" data-edit=\'' . htmlspecialchars(json_encode(['id' => (int) $a['account_id'], 'email' => $a['email'], 'first_name' => $a['first_name'] ?? '', 'middle_initial' => $a['middle_initial'] ?? '', 'middle_name' => $a['middle_name'] ?? '', 'last_name' => $a['last_name'] ?? '', 'suffix' => $a['suffix'] ?? '', 'role_id' => (int) $a['role_id'], 'role_name' => $roleNames[(int) $a['role_id']] ?? ''])) . '\' data-modal="accountModal">Edit</button>';
    }
    if (!$isSelf && $canManage) {
        $actions[] = '<button class="btn btn-sm btn-ghost" data-toggle-action="account_unlock" data-pk="account_id" data-val="' . (int) $a['account_id'] . '" data-confirm="' . ($locked || $attempts > 0
                    ? 'Unlock account ' . htmlspecialchars($a['email']) . ' and clear its failed login attempts?'
                    : 'Clear failed login attempts for ' . htmlspecialchars($a['email']) . '? (account is not currently locked)') . '">Unlock</button>';
        $actions[] = '<button class="' . $toggleCls . '" data-toggle-action="account_toggle" data-pk="account_id" data-val="' . (int) $a['account_id'] . '" data-confirm="' . ($a['is_active'] ? 'Deactivate' : 'Activate') . ' account ' . htmlspecialchars($a['email']) . '?"'
            . ($blockActivate ? ' disabled title="Another account with this role is active. Deactivate it first."' : '')
            . '>' . ($a['is_active'] ? 'Deactivate' : 'Activate') . '</button>';
    }

    return [
        '<strong>' . htmlspecialchars($a['full_name']) . '</strong>' . ($isSelf ? ' <span class="text-text-muted">(you)</span>' : ''),
        htmlspecialchars($a['email']),
        '<span class="badge badge-draft">' . htmlspecialchars($roleNames[(int) $a['role_id']] ?? 'ROLE ' . $a['role_id']) . '</span>'
            . ($isSuperRow ? ' <span class="badge badge-approved" title="Only this account can create or manage MIS ADMIN accounts">Super Admin</span>' : ''),
        (string) (int) $a['tickets'],
        '<span class="badge badge-' . ($a['is_active'] ? 'completed' : 'rejected') . '">' . ($a['is_active'] ? 'Active' : 'Inactive') . '</span>'
            . ($locked ? ' <span class="badge badge-rejected">Locked</span>' : ''),
        '<span class="whitespace-nowrap flex gap-1.5">' . implode('', $actions) . '</span>',
    ];
}, $accounts);
?>
<?php
$c = [
    'id' => 'tblAccounts',
    'filter' => 'Search accounts',
    'add_button' => '<button class="btn btn-primary" data-edit=\'{"id":0}\' data-modal="accountModal">+ Add Account</button>',
    'columns' => ['Name', 'Email', 'Role', 'Tickets', 'Status', 'Actions'],
    'rows' => $accountRows,
];
include __DIR__ . '/../partials/ui/table.php';
?>

<div class="modal-backdrop" id="accountModal">
  <div class="modal-body" style="max-width:440px">
    <div class="modal-head">
      <span data-role="title" data-add-label="Add Account" data-edit-label="Edit Account">Add Account</span>
      <button class="modal-close" type="button" onclick="UI.modal('accountModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <form id="accountForm" autocomplete="off" onsubmit="return false">
        <input type="hidden" name="account_id" value="0" data-role="pk">
        <div class="grid grid-cols-4 max-lg:grid-cols-2 gap-3 gap-x-3.5 mb-3">
          <div class="col-span-2"><label class="form-label">First Name *</label><input class="form-input input-cap" name="first_name" required maxlength="100"></div>
          <div class="col-span-2">
            <div class="grid grid-cols-4 gap-3 gap-x-3.5">
              <div><label class="form-label">M.I.</label><input class="form-input input-cap" name="middle_initial" maxlength="3" placeholder="M."></div>
              <div class="col-span-3"><label class="form-label">Middle Initial</label><input class="form-input input-cap" name="middle_name" maxlength="100" placeholder="REYES"></div>
            </div>
          </div>
          <div class="col-span-2"><label class="form-label">Last Name *</label><input class="form-input input-cap" name="last_name" required maxlength="100"></div>
          <div class="col-span-2"><label class="form-label">Suffix</label><input class="form-input input-cap" name="suffix" maxlength="10" placeholder="Jr., Sr., III"></div>
        </div>
        <div class="mb-3"><label class="form-label">Email *</label><input class="form-input" name="email" type="email" required maxlength="100"></div>
        <div class="mb-3" data-role-field>
          <label class="form-label" data-role-label>Role *</label>
          <select class="form-input" name="role_id" required>
            <option value="">— Select Role —</option>
            <?php foreach ($assignableRoles as $r): ?><option value="<?= (int) $r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option><?php endforeach; ?>
          </select>
          <?php if ($activeByRole): ?>
          <div class="text-text-muted text-sm mt-1.5" data-add-only>
            <?php foreach ([2 => 'SAO', 3 => 'GSD Dispatcher'] as $rid => $rlabel): ?>
            <?php if (isset($activeByRole[$rid])): ?>
            <?= $rlabel ?> is unavailable while <?= htmlspecialchars($activeByRole[$rid]['full_name']) ?> is active — deactivate that account to create a replacement.<br>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="form-input" data-role-display style="display:none; cursor:default;" aria-readonly="true"></div>
        </div>
        <div class="mb-3"><label class="form-label">Password</label>
          <div class="relative">
            <input class="form-input pr-10" id="acctPw" name="password" type="password" minlength="8" autocomplete="new-password">
            <button type="button" class="icon-btn icon-btn-bare pw-toggle absolute inset-y-0 right-1.5 my-auto" data-pw-toggle="acctPw" aria-label="Show password" tabindex="-1">
              <span class="pw-ico pw-ico-show"><?= icon('eye') ?></span>
              <span class="pw-ico pw-ico-hide"><?= icon('eye-off') ?></span>
            </button>
          </div>
        </div>
        <div class="mb-3"><label class="form-label">Confirm Password</label>
          <div class="relative">
            <input class="form-input pr-10" id="acctPwConfirm" name="password_confirm" type="password" autocomplete="new-password">
            <button type="button" class="icon-btn icon-btn-bare pw-toggle absolute inset-y-0 right-1.5 my-auto" data-pw-toggle="acctPwConfirm" aria-label="Show password" tabindex="-1">
              <span class="pw-ico pw-ico-show"><?= icon('eye') ?></span>
              <span class="pw-ico pw-ico-hide"><?= icon('eye-off') ?></span>
            </button>
          </div>
        </div>
        <div class="text-text-muted text-sm mt-1.5" data-role="pass-hint" style="display:none">Leave blank to keep the current password.</div>
        <div class="pw-req" data-pw-req="acctPw"></div>
      </form>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" type="button" onclick="UI.modal('accountModal', false)">Close</button>
      <button class="btn btn-primary" id="accountFormSubmit">Save Account</button>
    </div>
  </div>
</div>

<?php
$PAGE_SCRIPTS = ['password-policy.js', 'admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
