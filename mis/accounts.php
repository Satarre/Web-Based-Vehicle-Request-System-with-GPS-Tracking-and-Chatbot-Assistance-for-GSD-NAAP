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

$accounts = $pdo->query(
    'SELECT a.account_id, a.email, a.full_name, a.role_id, a.is_active, a.created_at,
            (SELECT COUNT(*) FROM trip_tickets t WHERE t.created_by = a.account_id) AS tickets
     FROM account a ORDER BY a.role_id, a.full_name'
)->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<?php
$accountRows = array_map(function ($a) use ($USER, $roleNames) {
    $toggleCls = $a['is_active']
        ? 'btn btn-sm btn-danger'
        : 'btn btn-sm btn-primary';
    return [
        '<strong>' . htmlspecialchars($a['full_name']) . '</strong>' . ((int) $a['account_id'] === (int) $USER['id'] ? ' <span class="text-text-muted">(you)</span>' : ''),
        htmlspecialchars($a['email']),
        '<span class="badge badge-draft">' . htmlspecialchars($roleNames[(int) $a['role_id']] ?? 'ROLE ' . $a['role_id']) . '</span>',
        (string) (int) $a['tickets'],
        '<span class="badge badge-' . ($a['is_active'] ? 'completed' : 'rejected') . '">' . ($a['is_active'] ? 'Active' : 'Inactive') . '</span>',
        '<span class="whitespace-nowrap flex gap-1.5">'
            . '<button class="btn btn-sm btn-ghost" data-edit=\'' . htmlspecialchars(json_encode(['id' => (int) $a['account_id'], 'email' => $a['email'], 'full_name' => $a['full_name'], 'role_id' => (int) $a['role_id']])) . '\' data-modal="accountModal">Edit</button>'
            . ((int) $a['account_id'] !== (int) $USER['id']
                ? '<button class="' . $toggleCls . '" data-toggle-action="account_toggle" data-pk="account_id" data-val="' . (int) $a['account_id'] . '" data-confirm="' . ($a['is_active'] ? 'Deactivate' : 'Activate') . ' account ' . htmlspecialchars($a['email']) . '?">' . ($a['is_active'] ? 'Deactivate' : 'Activate') . '</button>'
                : '')
            . '</span>',
    ];
}, $accounts);
?>
<?php
$c = [
    'id' => 'tblAccounts',
    'filter' => 'Search accounts…',
    'columns' => ['Name', 'Email', 'Role', 'Tickets', 'Status', ''],
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
    <div class="px-[18px] py-4">
      <form id="accountForm" autocomplete="off" onsubmit="return false">
        <input type="hidden" name="account_id" value="0" data-role="pk">
        <div class="mb-3"><label class="form-label">Full Name *</label><input class="form-input" name="full_name" required maxlength="100"></div>
        <div class="mb-3"><label class="form-label">Email *</label><input class="form-input" name="email" type="email" required maxlength="100"></div>
        <div class="mb-3"><label class="form-label">Role *</label>
          <select class="form-input" name="role_id" required>
            <?php foreach ($roles as $r): ?><option value="<?= (int) $r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Password <span class="text-text-muted">(min 8 chars; leave blank on edit to keep current)</span></label>
          <input class="form-input" name="password" type="password" minlength="8">
        </div>
      </form>
    </div>
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" type="button" onclick="UI.modal('accountModal', false)">Close</button>
      <button class="btn btn-primary" id="accountFormSubmit">Save Account</button>
    </div>
  </div>
</div>

<?php
$PAGE_SCRIPTS = ['admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
