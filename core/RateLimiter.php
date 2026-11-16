<?php
declare(strict_types=1);
/**
 * core/RateLimiter.php — simple server-side bucket limiter on security_events.
 * Guards OTP sending / verification spam per IP.
 */

class RateLimiter
{
    /**
     * Record one attempt if under the cap (per IP AND per account, when known).
     * Returns true when allowed.
     * When over the cap the attempt is rejected and NOT recorded.
     */
    public static function hit(PDO $pdo, string $eventType, ?int $accountId, int $max, int $windowSec): bool
    {
        if (SecurityEvent::recentByIp($pdo, $eventType, $windowSec) >= $max) {
            return false;
        }
        if ($accountId !== null && SecurityEvent::recentByAccount($pdo, $eventType, $accountId, $windowSec) >= $max) {
            return false;
        }
        SecurityEvent::log($pdo, $eventType, $accountId);
        return true;
    }
}