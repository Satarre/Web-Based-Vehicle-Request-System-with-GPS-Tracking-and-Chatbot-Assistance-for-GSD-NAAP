<?php
declare(strict_types=1);
/**
 * scripts/evaluate_flags.php — CLI flag sweep (Phase 5). Web access denied (.htaccess).
 *
 * Windows Task Scheduler (~5 min):
 *   schtasks /create /tn "NAAP VTS Flags" /sc minute /mo 5 /tr
 *     "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\NAAP_MN_VTS_Capstone\scripts\evaluate_flags.php\""
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once dirname(__DIR__) . '/config/config.php';

$raised = FlagEvaluator::run($pdo);
$total  = array_sum($raised);
echo '[' . date('Y-m-d H:i:s') . '] evaluate_flags: ' . $total . " raised\n";
foreach ($raised as $type => $n) {
    echo "  {$type}: {$n}\n";
}
exit(0);
