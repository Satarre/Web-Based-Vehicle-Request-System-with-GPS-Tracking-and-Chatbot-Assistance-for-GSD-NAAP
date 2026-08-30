<?php
declare(strict_types=1);
/**
 * mis/chatbot.php — chatbot FAQ management.
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [1]);
$ACTIVE = BASE_URL . 'mis/chatbot.php';
$PAGE_TITLE = 'Chatbot FAQs';

$faqs = $pdo->query(
    'SELECT c.*, a.full_name AS manager FROM chatbot c LEFT JOIN account a ON a.account_id = c.managed_by ORDER BY c.chatbot_id'
)->fetchAll();

require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';
?>
<?php
$faqRows = array_map(function ($f) {
    return [
        '<strong>' . htmlspecialchars($f['prompt_keyword']) . '</strong>',
        '<div style="max-width:420px;font-size:12.5px">' . htmlspecialchars(mb_substr((string) $f['response_text'], 0, 120)) . (mb_strlen((string) $f['response_text']) > 120 ? '…' : '') . '</div>',
        $f['is_displayed'] ? icon('check') : '<span class="text-text-muted">hidden</span>',
        htmlspecialchars((string) ($f['manager'] ?? '—')),
        date('M j, Y', strtotime((string) $f['last_updated'])),
        '<span class="whitespace-nowrap flex gap-1.5">'
            . '<button class="btn btn-sm btn-ghost" data-edit=\'' . htmlspecialchars(json_encode(['id' => (int) $f['chatbot_id'], 'prompt_keyword' => $f['prompt_keyword'], 'response_text' => $f['response_text']])) . '\' data-modal="faqModal">Edit</button>'
            . '<button class="btn btn-sm btn-danger" data-faq-del="' . (int) $f['chatbot_id'] . '">Delete</button>'
            . '</span>',
    ];
}, $faqs);
?>
<?php
$c = [
    'id' => 'tblFaqs',
    'filter' => 'Search FAQs…',
    'columns' => ['Keyword', 'Response', 'Shown', 'Managed By', 'Updated', ''],
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

<?php
$PAGE_SCRIPTS = ['admin.js', 'table_filter.js'];
require_once dirname(__DIR__) . '/partials/layout_foot.php';
