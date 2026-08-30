<?php
declare(strict_types=1);
/**
 * core/TicketNumber.php — DTT-YYYY-#### generator (per-year sequence).
 * Call inside the caller's transaction; the UNIQUE KEY on dtt_number plus a
 * retry loop at the call site resolves races.
 */

class TicketNumber
{
    // ponytail: MAX+1 under unique-key retry, not a counter table — fine for capstone concurrency
    public static function next(PDO $pdo, ?int $year = null): string
    {
        $y    = $year ?? (int) date('Y');
        $stmt = $pdo->prepare(
            'SELECT MAX(CAST(RIGHT(dtt_number, 4) AS UNSIGNED))
             FROM trip_tickets WHERE dtt_number LIKE ?'
        );
        $stmt->execute([sprintf('DTT-%d-%%', $y)]);
        return sprintf('DTT-%d-%04d', $y, ((int) $stmt->fetchColumn()) + 1);
    }
}
