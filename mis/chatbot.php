<?php
declare(strict_types=1);
/**
 * mis/chatbot.php — shared chatbot FAQ management (MIS ADMIN + SAO/Director).
 * Roles 1 & 2 have identical full CRUD: Add, Edit, Delete, Active toggle,
 * Shown/Hidden (is_displayed) toggle.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2]);
$ACTIVE = BASE_URL . 'mis/chatbot.php';
$PAGE_TITLE = 'Chatbot FAQs';

$totalFaqs    = (int) $pdo->query('SELECT COUNT(*) FROM chatbot')->fetchColumn();
$activeFaqs   = (int) $pdo->query('SELECT COUNT(*) FROM chatbot WHERE is_active = 1')->fetchColumn();
$inactiveFaqs = (int) $pdo->query('SELECT COUNT(*) FROM chatbot WHERE is_active = 0')->fetchColumn();

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
<div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 mb-3.5 stagger-in">
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
        '<div style="max-width:220px;font-size:12px">' . ($f['keywords']
            ? htmlspecialchars(mb_substr((string) $f['keywords'], 0, 60)) . (mb_strlen((string) $f['keywords']) > 60 ? '…' : '')
            : '<span class="text-text-muted">—</span>') . '</div>',
        '<div style="max-width:420px;font-size:12.5px">' . htmlspecialchars(mb_substr((string) $f['response_text'], 0, 120)) . (mb_strlen((string) $f['response_text']) > 120 ? '…' : '') . '</div>',
        '<button class="btn btn-sm ' . ($f['is_active'] ? 'btn-primary' : 'btn-ghost') . '" data-faq-toggle="' . (int) $f['chatbot_id'] . '">'
            . ($f['is_active'] ? 'Active' : 'Inactive') . '</button>',
        '<button class="btn btn-sm ' . ($f['is_displayed'] ? 'btn-primary' : 'btn-ghost') . '" data-faq-display-toggle="' . (int) $f['chatbot_id'] . '">'
            . ($f['is_displayed'] ? 'Shown' : 'Hidden') . '</button>',
        htmlspecialchars((string) ($f['manager'] ?? '—')),
        date('M j, Y', strtotime((string) $f['last_updated'])),
        '<span class="whitespace-nowrap flex gap-1.5">'
            . '<button class="btn btn-sm btn-ghost" data-edit=\'' . htmlspecialchars(json_encode(['id' => (int) $f['chatbot_id'], 'prompt_keyword' => $f['prompt_keyword'], 'response_text' => $f['response_text'], 'keywords' => $f['keywords'] ?? ''])) . '\' data-modal="faqModal">Edit</button>'
            . '<button class="btn btn-sm btn-danger" data-faq-del="' . (int) $f['chatbot_id'] . '">Delete</button>'
            . '</span>',
    ];
}, $faqs);
?>
<?php
$c = [
    'id' => 'tblFaqs',
    'filter' => 'Search keywords',
    'add_button' => '<button class="btn btn-primary" data-edit=\'{"id":0}\' data-modal="faqModal">+ Add Keyword</button>',
    'columns' => ['Keyword', 'Synonyms', 'Response', 'Active', 'Shown', 'Managed By', 'Updated', ''],
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
    <div class="modal-pad-x py-4">
      <form id="faqForm" autocomplete="off" onsubmit="return false">
        <input type="hidden" name="chatbot_id" value="0" data-role="pk">
        <div class="mb-3"><label class="form-label">Prompt Keyword * <span class="text-text-muted">(what users might type)</span></label>
        <input name="prompt_keyword" required maxlength="255" class="form-input"></div>
        <div class="mb-3"><label class="form-label">Also matches (synonyms) <span class="text-text-muted">(separate with |)</span></label>
        <input name="keywords" maxlength="1000" class="form-input" placeholder="book a vehicle | new request"></div>
        <div class="mb-3"><label class="form-label">Response *</label>
        <textarea name="response_text" rows="4" required maxlength="2000" class="form-input"></textarea></div>
      </form>
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" type="button" onclick="UI.modal('faqModal', false)">Close</button>
      <button class="btn btn-primary" id="faqFormSubmit">Save FAQ</button>
    </div>
  </div>
</div>

<?php
$PAGE_SCRIPTS = ['admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';