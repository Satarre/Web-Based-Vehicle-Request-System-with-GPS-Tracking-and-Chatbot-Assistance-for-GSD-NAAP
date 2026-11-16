<?php
declare(strict_types=1);
/**
 * core/TicketNumber.php — NAAP-MN-YYYY-#### ticket-number generator.
 * One permanent number per ticket, issued at submission (no RTT/DTT prefix
 * promotion anymore). Call inside the caller's transaction; the UNIQUE KEY on
 * dtt_number plus a retry loop at the call site resolves races.
 */

class TicketNumber
{
    // ponytail: MAX+1 under unique-key retry, not a counter table — fine for capstone concurrency
    public static function next(PDO $pdo, ?int $year = null): string
    {
        $y    = $year ?? (int) date('Y');
        $like = sprintf('NAAP-MN-%d-', $y);
        $stmt = $pdo->prepare(
            'SELECT MAX(CAST(SUBSTRING(dtt_number, LENGTH(?) + 1) AS UNSIGNED))
             FROM trip_tickets
             WHERE dtt_number LIKE ?'
        );
        $stmt->execute([$like, $like . '%']);
        return $like . sprintf('%04d', ((int) $stmt->fetchColumn()) + 1);
    }
}
