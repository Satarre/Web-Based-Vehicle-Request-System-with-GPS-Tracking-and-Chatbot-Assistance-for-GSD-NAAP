<?php
declare(strict_types=1);
/**
 * director/chatbot.php — HR chatbot FAQ management (CRUD + toggle).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER   = Auth::requirePage($pdo, [2]);
$ACTIVE = BASE_URL . 'director/chatbot.php';
$PAGE_TITLE = 'Chatbot FAQs';

$totalFaqs   = (int) $pdo->query('SELECT COUNT(*) FROM chatbot')->fetchColumn();
$activeFaqs  = (int) $pdo->query('SELECT COUNT(*) FROM chatbot WHERE is_active = 1')->fetchColumn();
$inactiveFaqs= (int) $pdo->query('SELECT COUNT(*) FROM chatbot WHERE is_active = 0')->fetchColumn();

$faqs = $pdo->query(
    'SELECT c.*, a.full_name AS manager FROM chatbot c LEFT JOIN account a ON a.account_id = c.managed_by ORDER BY c.chatbot_id'
)->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<div class="flex items-center gap-2.5 mb-3.5 flex-wrap">
  <h1 class="text-xl">Chatbot FAQs</h1>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 mb-3.5">
  <div class="stat-card">
    <div class="stat-card-label">Total Keywords</div>
    <div class="stat-card-value"><?= $totalFaqs ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Active</div>
    <div class="stat-card-value"><?= $activeFaqs ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-label">Inactive</div>
    <div class="stat-card-value"><?= $inactiveFaqs ?></div>
  </div>
</div>

<?php
$faqRows = array_map(function ($f) {
    return [
        '<strong>' . htmlspecialchars($f['prompt_keyword']) . '</strong>',
        '<div style="max-width:420px;font-size:12.5px">' . htmlspecialchars(mb_substr((string) $f['response_text'], 0, 120)) . (mb_strlen((string) $f['response_text']) > 120 ? '…' : '') . '</div>',
        '<button class="btn btn-sm ' . ($f['is_active'] ? 'btn-primary' : 'btn-ghost') . '" data-faq-toggle="' . (int) $f['chatbot_id'] . '">'
            . ($f['is_active'] ? 'Active' : 'Inactive') . '</button>',
        htmlspecialchars((string) ($f['manager'] ?? '—')),
        date('M j, Y', strtotime((string) $f['last_updated'])),
        '<span class="whitespace-nowrap flex gap-1.5">'
            . '<button class="btn btn-sm btn-ghost" data-edit=\'' . htmlspecialchars(json_encode(['id' => (int) $f['chatbot_id'], 'prompt_keyword' => $f['prompt_keyword'], 'response_text' => $f['response_text']])) . '\' data-modal="faqModal">Edit</button>'
            . '<button class="btn btn-sm btn-danger" data-faq-del="' . (int) $f['chatbot_id'] . '">Delete</button>'
            . '</span>',
    ];
}, $faqs);
?>
<div class="flex items-center gap-2.5 px-0 py-3 mb-0">
  <button class="btn btn-primary" data-edit='{"id":0}' data-modal="faqModal">+ Add Keyword</button>
</div>

<?php
$c = [
    'id' => 'tblFaqs',
    'filter' => 'Search keywords…',
    'columns' => ['Keyword', 'Response', 'Status', 'Managed By', 'Updated', ''],
    'rows' => $faqRows,
];
include __DIR__ . '/../partials/ui/table.php';
?>

<div class="modal-backdrop" id="faqModal">
  <div class="modal-body" style="max-width:480px">
    <div class="modal-head">
      <span data-role="title" data-add-label="Add FAQ" data-edit-label="Edit FAQ">Add FAQ</span>
      <button class="modal-close" type="button" onclick="UI.modal('faqModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="px-[18px] py-4">
      <form id="faqForm" autocomplete="off" onsubmit="return false">
        <input type="hidden" name="chatbot_id" value="0" data-role="pk">
        <div class="mb-3"><label class="form-label">Prompt Keyword * <span class="text-text-muted">(what users might type)</span></label>
        <input name="prompt_keyword" required maxlength="255" class="form-input"></div>
        <div class="mb-3"><label class="form-label">Response *</label>
        <textarea name="response_text" rows="4" required maxlength="2000" class="form-input"></textarea></div>
      </form>
    </div>
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" type="button" onclick="UI.modal('faqModal', false)">Close</button>
      <button class="btn btn-primary" id="faqFormSubmit">Save FAQ</button>
    </div>
  </div>
</div>

<script>
/* faq_toggle handler for this page */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-faq-toggle]');
  if (!btn) return;
  var id = parseInt(btn.dataset.faqToggle, 10);
  API.post('api/admin.php', { action: 'faq_toggle', chatbot_id: id })
    .then(function (r) { UI.toast(r.message, 'ok'); setTimeout(function () { location.reload(); }, 400); })
    .catch(function (e) { UI.toast(e.message, 'err'); });
});
</script>

<?php
$PAGE_SCRIPTS = ['admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
