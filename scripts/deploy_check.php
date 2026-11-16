<?php
declare(strict_types=1);
/**
 * scripts/deploy_check.php — post-upload smoke test. CLI only.
 *
 * Catches the classic partial-deploy failure: an FTP upload that skips
 * config/config.php (to keep secrets.php untouched) leaves the new API code
 * calling helpers that do not exist on the server. Writes then fail with a
 * generic error while the database quietly collects half-written rows.
 *
 *   php scripts/deploy_check.php
 *
 * Exit code 0 = every check passed, 1 = at least one failure.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once dirname(__DIR__) . '/config/config.php';

$failed = 0;
$passed = 0;
$check  = static function (string $label, bool $ok, string $detail = '') use (&$failed, &$passed): void {
    if ($ok) {
        $passed++;
        echo '  [ OK ] ' . $label . PHP_EOL;
        return;
    }
    $failed++;
    echo '  [FAIL] ' . $label . ($detail !== '' ? '  — ' . $detail : '') . PHP_EOL;
};

echo 'NAAP VTS deploy check' . PHP_EOL;
echo '  app root : ' . APP_ROOT . PHP_EOL;
echo '  base url : ' . BASE_URL . PHP_EOL;
echo '  app env  : ' . APP_ENV . PHP_EOL;

/* ── PHP runtime ─────────────────────────────────────────────────────── */
echo PHP_EOL . 'PHP' . PHP_EOL;
$check('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json'] as $ext) {
    $check('extension: ' . $ext, extension_loaded($ext));
}
foreach (['passenger_display_name', 'account_display_name', 'out'] as $fn) {
    $check('helper: ' . $fn . '()', function_exists($fn),
        'config/config.php on the server is stale — upload the current file');
}

/* ── Files (a partial upload is the usual culprit) ───────────────────── */
echo PHP_EOL . 'Files' . PHP_EOL;
foreach ([
    'config/config.php',
    'core/Auth.php',
    'core/TripState.php',
    'core/Audit.php',
    'api/trips.php',
    'personnel/request_form.php',
    'assets/js/pages/request_form.js',
] as $rel) {
    $check('file: ' . $rel, is_file(APP_ROOT . '/' . $rel));
}

$apiSrc = @file_get_contents(APP_ROOT . '/api/trips.php') ?: '';
$check('api/trips.php: transactional save_draft', str_contains($apiSrc, 'Could not save the draft'),
    'stale api/trips.php — re-upload the current file');
$check('api/trips.php: self-contained manifest writer', str_contains($apiSrc, 'Passenger-manifest writer'));
$check('api/trips.php: ticket-number retry', str_contains($apiSrc, 'errorInfo[1]'));
$jsSrc = @file_get_contents(APP_ROOT . '/assets/js/pages/request_form.js') ?: '';
$check('request_form.js: autosave guard', str_contains($jsSrc, 'autosavePaused'),
    'stale browser asset — re-upload assets/js/pages/request_form.js');
$check('request_form.js: upload progress', str_contains($jsSrc, 'formProgress'),
    'stale browser asset — re-upload assets/js/pages/request_form.js');
$apiJs = @file_get_contents(APP_ROOT . '/assets/js/api.js') ?: '';
$check('api.js: formProgress helper', str_contains($apiJs, 'formProgress'));
$userIni = @file_get_contents(APP_ROOT . '/.user.ini') ?: '';
$check('.user.ini: upload limits', str_contains($userIni, 'upload_max_filesize')
    && str_contains($userIni, 'post_max_size'));

/* ── Database ────────────────────────────────────────────────────────── */
echo PHP_EOL . 'Database ' . DB_NAME . PHP_EOL;
try {
    $check('connection', isset($pdo) && $pdo instanceof PDO);

    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);
        return (int) $st->fetchColumn() > 0;
    };
    foreach ([
        ['trip_drafts', 'middle_name'],
        ['trip_drafts', 'place_to_visit_name'],
        ['trip_drafts', 'place_to_visit_lat'],
        ['trip_drafts', 'place_to_visit_lon'],
        ['trip_drafts', 'scheduled_return'],
        ['trip_draft_passengers', 'surname'],
        ['trip_draft_passengers', 'first_name'],
        ['trip_draft_passengers', 'middle_initial'],
        ['trip_draft_passengers', 'middle_name'],
        ['trip_tickets', 'middle_name'],
        ['trip_passengers', 'surname'],
        ['trip_passengers', 'first_name'],
    ] as [$table, $column]) {
        $check('column: ' . $table . '.' . $column, $hasColumn($table, $column),
            'run the matching file in database/migrations/');
    }

    $statusCount = (int) $pdo->query('SELECT COUNT(*) FROM request_status WHERE is_active = 1')->fetchColumn();
    $check('request_status: active workflow statuses', $statusCount >= 9, (string) $statusCount . ' found');

    $triggers = $pdo->query(
        "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'trip_tickets'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $check('trip_tickets: audit triggers installed', count($triggers) >= 3, count($triggers) . ' found');
    $foreignDb = [];
    foreach ($triggers as $body) {
        if (preg_match_all('/`([A-Za-z0-9_]+)`\s*\.\s*`(audit_trail|request_status)`/', (string) $body, $m)) {
            foreach ($m[1] as $db) {
                if ($db !== DB_NAME) {
                    $foreignDb[] = $db;
                }
            }
        }
    }
    $check('trip_tickets: triggers target this database', $foreignDb === [],
        'hard-coded database ' . implode(', ', array_unique($foreignDb))
        . ' — run database/migrations/migration_single_statement_triggers.sql');
} catch (Throwable $e) {
    $check('database inspection', false, $e->getMessage());
}

/* ── Writable upload directories ─────────────────────────────────────── */
echo PHP_EOL . 'Uploads' . PHP_EOL;
$uploads = APP_ROOT . '/uploads';
$check('uploads/ exists and is writable', is_dir($uploads) && is_writable($uploads));
$letters = $uploads . '/request_letters';
$check('uploads/request_letters/ writable',
    is_dir($letters) ? is_writable($letters) : is_writable($uploads),
    'created automatically on first upload');

echo PHP_EOL . str_repeat('-', 62) . PHP_EOL;
if ($failed === 0) {
    echo 'PASS — ' . $passed . ' checks' . PHP_EOL;
    exit(0);
}
echo 'FAIL — ' . $failed . ' failed, ' . $passed . ' passed' . PHP_EOL;
exit(1);
