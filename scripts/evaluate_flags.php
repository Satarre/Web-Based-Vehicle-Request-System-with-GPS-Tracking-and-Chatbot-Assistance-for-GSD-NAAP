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

/** Prune telemetry/log tables by configured retention; runs at most once per day. */
function retentionRun(PDO $pdo): array
{
    $last = strtotime((string) Settings::get('RETENTION_LAST_RUN', ''));
    if ($last > time() - 86400) {
        return [];
    }
    $posDays = Settings::getInt('GPS_POSITION_RETENTION_DAYS', 90);
    $logDays = Settings::getInt('AUDIT_RETENTION_DAYS', 365);
    $otpDays = Settings::getInt('OTP_RETENTION_DAYS', 30);
    $out = [
        'gps_positions'     => $pdo->exec("DELETE FROM gps_positions     WHERE recorded_at < NOW() - INTERVAL {$posDays} DAY"),
        'audit_trail'       => $pdo->exec("DELETE FROM audit_trail       WHERE timestamp    < NOW() - INTERVAL {$logDays} DAY"),
        'security_events'   => $pdo->exec("DELETE FROM security_events   WHERE created_at   < NOW() - INTERVAL {$logDays} DAY"),
        'email_otps'        => $pdo->exec("DELETE FROM email_otps        WHERE created_at   < NOW() - INTERVAL {$otpDays} DAY"),
        'chat_conversations'=> $pdo->exec("DELETE FROM chat_conversations WHERE created_at   < NOW() - INTERVAL {$otpDays} DAY"),
    ];
    $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, value_type, description)
         VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute(['RETENTION_LAST_RUN', date('Y-m-d H:i:s'), 'string', 'Last retention prune (daily cron)']);
    return $out;
}

$retained = retentionRun($pdo);
foreach ($retained as $table => $n) {
    echo '[' . date('Y-m-d H:i:s') . "] retention {$table}: {$n} row(s) deleted\n";
}

$autoRejected = TripGuard::autoRejectPastDue($pdo);
if ($autoRejected > 0) {
    echo '[' . date('Y-m-d H:i:s') . "] auto-reject past-due: {$autoRejected} ticket(s)\n";
}

$rerouted = RouteService::rerouteActiveTrips(
    $pdo,
    Settings::getInt('ROUTE_REFRESH_MIN', 4),
    Settings::getInt('ROUTE_DEVIATION_METERS', 750)
);
if ($rerouted > 0) {
    echo '[' . date('Y-m-d H:i:s') . "] traffic-aware reroutes: {$rerouted}\n";
}

$raised = FlagEvaluator::run($pdo);
$total  = array_sum($raised);
echo '[' . date('Y-m-d H:i:s') . '] evaluate_flags: ' . $total . " raised\n";
foreach ($raised as $type => $n) {
    echo "  {$type}: {$n}\n";
}
exit(0);
