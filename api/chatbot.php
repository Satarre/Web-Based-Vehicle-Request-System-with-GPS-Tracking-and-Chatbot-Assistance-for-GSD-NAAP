<?php
declare(strict_types=1);
/**
 * api/chatbot.php — GSD Assistant endpoint. Rule-based only, no external AI.
 *
 * GET  action=list&page_url=…  → canonical keywords + page-aware chips/actions.
 * POST action=ask {question, page_url, form}
 *      → best FAQ match (unchanged stored text + synonyms), or a dynamic
 *        intent from core/ChatbotAssistant.php (status / trip details), or a
 *        fallback. Three consecutive unanswered asks escalate to the
 *        "contact the GSD" message.
 *
 * Every dynamic answer reads only rows the authenticated account owns
 * (created_by = session user id); no account or ticket id is ever taken from
 * the client. Read-only: nothing here writes business data.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_GET + $_POST;
}
$action = (string) ($in['action'] ?? '');

if ($action !== 'list' && $action !== 'ask') {
    out(false, 'Unknown action.');
}
if ($action === 'ask') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out(false, 'POST only.');
    }
    if (!Csrf::verifyRequest($in)) {
        out(false, 'Invalid or missing CSRF token.');
    }
}
$u = Auth::requireApi($pdo, ['*']);

$faqs = $pdo->query(
    'SELECT prompt_keyword, response_text, keywords FROM chatbot WHERE is_active = 1 AND is_displayed = 1 ORDER BY prompt_keyword'
)->fetchAll();

/* Client-supplied page path → allowlisted app-relative path ('' when unknown). */
$pagePath = ChatbotAssistant::normalizePath((string) ($in['page_url'] ?? ''));

if ($action === 'list') {
    // Dynamic intents ride along so the grouped browser lists them next to the
    // stored FAQs. The flat `keywords`/`page_*` keys stay for compatibility.
    $topicRows = array_merge($faqs, ChatbotAssistant::intentFaqs());
    out(true, '', [
        'keywords'         => array_column($faqs, 'prompt_keyword'),
        'page_suggestions' => ChatbotAssistant::pageTopics($pagePath),
        'page_actions'     => ChatbotAssistant::pageActions($pagePath),
        'groups'           => ChatbotAssistant::topicGroups($topicRows),
        'page_group'       => ChatbotAssistant::pageGroup($pagePath, $topicRows),
        'total'            => count($topicRows),
    ]);
}

$q = trim((string) ($in['question'] ?? ''));
if ($q === '') {
    out(false, 'Type a question first.');
}
if (mb_strlen($q) > 300) {
    $q = mb_substr($q, 0, 300);
}

// Form-assistance: the request-form state rides along with the ask so it can be
// logged, but missing/conflicting entries are only surfaced for the guides that
// are about the form (see ChatbotMatcher::formWarnings) — never for every
// keyword the requester clicks.
$form = (is_array($in['form'] ?? null)) ? $in['form'] : [];

// Pure matcher in core/ChatbotMatcher.php: canonical keyword + |-separated
// synonyms, whole-word boosting, short-token guard, threshold 0.45. Stored
// FAQs come first so legacy keywords keep their precedence; the synthetic
// dynamic intents only claim phrases the seeded FAQs never used.
$match = ChatbotMatcher::best(array_merge($faqs, ChatbotAssistant::intentFaqs()), $q);

$keyword     = null;
$answer      = null;
$score       = null;
$warnings    = [];
$actions     = [];
$suggestions = [];
$cards       = [];
$steps       = [];
$kind        = 'faq';
$kindLabel   = '';
$fallback    = null;

if ($match !== null) {
    $row     = $match['faq'];
    $keyword = (string) $row['prompt_keyword'];
    $score   = $match['score'];

    if (!empty($row['__intent'])) {
        $result      = ChatbotAssistant::run((string) $row['__intent'], $pdo, $u, $q);
        $answer      = trim((string) ($result['answer'] ?? ''));
        $actions     = $result['actions'] ?? [];
        $suggestions = $result['suggestions'] ?? [];
        $cards       = $result['cards'] ?? [];
        $steps       = $result['steps'] ?? [];
        $kind        = (string) ($result['kind'] ?? 'live');
        $kindLabel   = (string) ($result['label'] ?? '');
        /* A handler with no text (the wizard ships cards instead) is still an
           answer — only demote when it produced nothing at all. */
        if ($answer === '' && $cards === [] && $steps === []) {
            $answer = null;
        }
    } else {
        $answer      = (string) $row['response_text'];
        $warnings    = ChatbotMatcher::formWarningItems($form, $keyword);
        $actions     = ChatbotAssistant::actionsFor($keyword);
        $suggestions = ChatbotAssistant::suggestionsFor($keyword);
    }
}

// Unanswered (no match, or a handler that produced nothing): three consecutive
// misses inside the window escalate to the contact-GSD message. The streak is
// read from the append-only log, so it survives reloads/navigation, and a
// matched answer naturally resets it (the newest row is no longer a miss).
if ($answer === null) {
    $streak = ChatbotAssistant::consecutiveMisses($pdo, (int) $u['id']);

    if ($streak + 1 >= ChatbotAssistant::ESCALATE_AFTER) {
        $esc = ChatbotAssistant::escalation($faqs);
        $fallback = [
            'escalated' => true,
            'attempts'  => $streak + 1,
            'message'   => $esc['message'],
            'detail'    => $esc['detail'],
        ];
        $kind      = 'escalation';
        $kindLabel = 'Contact';
        $actions   = ChatbotAssistant::escalationActions();
    } else {
        $fallback = [
            'escalated' => false,
            'attempts'  => $streak + 1,
            'message'   => "I'm not sure about that one. Try one of these topics instead:",
            'detail'    => '',
        ];
        $kind      = 'fallback';
        $kindLabel = 'Not sure';
    }
}

// Append-only conversation log (never blocks the answer). Escalations are
// stored in the answer column so admins can count them with a simple query.
$logAnswer = $answer ?? ($fallback['escalated'] ?? false ? $fallback['message'] : null);
$formJson  = $form !== [] ? json_encode($form) : null;
if (is_string($formJson) && strlen($formJson) > 4000) {
    $formJson = null;
}
try {
    $pdo->prepare(
        'INSERT INTO chat_conversations (account_id, page_url, question, keyword, score, answer, form_state)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $u['id'],
        mb_substr((string) ($in['page_url'] ?? ''), 0, 255),
        mb_substr($q, 0, 300),
        $keyword,
        $score,
        $logAnswer,
        $formJson,
    ]);
} catch (Throwable $e) {
    error_log('[chatbot] conversation log failed: ' . $e->getMessage());
}

out(true, '', [
    'keyword'     => $keyword,
    'answer'      => $answer,
    'score'       => $score,
    'kind'        => $kind,
    'kind_label'  => $kindLabel,
    'cards'       => $cards,
    'steps'       => $steps,
    'warnings'    => $warnings,
    'actions'     => $actions,
    'suggestions' => $suggestions,
    'fallback'    => $fallback,
]);
