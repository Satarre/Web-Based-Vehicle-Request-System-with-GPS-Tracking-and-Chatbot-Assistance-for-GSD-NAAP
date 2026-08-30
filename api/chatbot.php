<?php
declare(strict_types=1);
/**
 * api/chatbot.php — GSD Assistant FAQ endpoint.
 * GET/POST action=list           → keywords of displayed FAQs.
 * POST action=ask {question}     → best local match (similar_text + word overlap).
 * Read-only, any authenticated role. No external AI service involved.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

function out(bool $ok, string $msg = '', array $extra = []): never
{
    if (!$ok) {
        http_response_code(400);
    }
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

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
Auth::requireApi($pdo, ['*']);

$faqs = $pdo->query(
    'SELECT prompt_keyword, response_text FROM chatbot WHERE is_active = 1 AND is_displayed = 1 ORDER BY prompt_keyword'
)->fetchAll();

if ($action === 'list') {
    out(true, '', ['keywords' => array_column($faqs, 'prompt_keyword')]);
}

$q = trim((string) ($in['question'] ?? ''));
if ($q === '') {
    out(false, 'Type a question first.');
}
if (mb_strlen($q) > 300) {
    $q = mb_substr($q, 0, 300);
}

$norm  = static fn(string $s): string => trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', strtolower($s)));
$words = static fn(string $s): array => array_values(array_unique(array_filter(explode(' ', $s))));

$qn = $norm($q);
$qw = $words($qn);

$best = null;
$bestScore = 0.0;
foreach ($faqs as $f) {
    $kn = $norm($f['prompt_keyword']);
    similar_text($qn, $kn, $pct);
    $kw = $words($kn);
    $overlap = 0.0;
    if ($kw !== []) {
        $overlap = count(array_intersect($qw, $kw)) / count($kw);
    }
    if ($qn !== '' && str_contains($qn, $kn)) {
        $overlap = 1.0;
    }
    $score = max($pct / 100.0, $overlap);
    if ($score > $bestScore) {
        $bestScore = $score;
        $best = $f;
    }
}

if ($best !== null && $bestScore >= 0.45) {
    out(true, '', [
        'keyword' => $best['prompt_keyword'],
        'answer'  => $best['response_text'],
        'score'   => round($bestScore, 2),
    ]);
}
out(true, '', ['keyword' => null, 'answer' => null]);
