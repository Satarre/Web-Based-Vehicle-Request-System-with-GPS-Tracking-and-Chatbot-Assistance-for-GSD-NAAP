<?php
declare(strict_types=1);
/**
 * core/TripGuard.php — pre-flight trip guards.
 * Currently: auto-reject of PENDING/APPROVED tickets whose scheduled
 * departure has already passed (they can no longer be approved/dispatched
 * in time). Idempotent — running it repeatedly only transitions tickets
 * that are still PENDING/APPROVED and still past due.
 */

class TripGuard
{
    /** System reason stamped on auto-rejected tickets. */
    public const AUTO_REJECT_REASON = 'System: Trip Ticket not approved before Departure Date and Time';

    /**
     * Reject every active PENDING/APPROVED ticket that is already past its
     * scheduled departure. Returns the number of tickets transitioned.
     * Runs outside a transaction (caller may be mid-page-render); each row
     * is an independent UPDATE + audit so partial failure is harmless.
     */
    public static function autoRejectPastDue(PDO $pdo): int
    {
        $st = $pdo->query(
            'SELECT ticket_id FROM trip_tickets
             WHERE is_active = 1
               AND status_id IN (' . TripState::PENDING . ', ' . TripState::APPROVED . ')
               AND scheduled_departure IS NOT NULL
               AND scheduled_departure < NOW()
             ORDER BY ticket_id'
        );
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            return 0;
        }
        $upd = $pdo->prepare(
            // updated_by = 0 (system actor): the AFTER UPDATE audit trigger maps
            // NEW.updated_by → audit_trail.user_id, which is NOT NULL.
            'UPDATE trip_tickets SET status_id = ?, reason = ?, approver_id = NULL, updated_by = 0
             WHERE ticket_id = ? AND is_active = 1
               AND status_id IN (' . TripState::PENDING . ', ' . TripState::APPROVED . ')'
        );
        $count = 0;
        foreach ($ids as $tid) {
            $upd->execute([TripState::REJECTED, self::AUTO_REJECT_REASON, $tid]);
            if ($upd->rowCount() > 0) {
                $count++;
                Audit::log($pdo, 'AUTO_REJECT', 'trip_tickets:' . $tid, 'PENDING/APPROVED', 'REJECTED — ' . self::AUTO_REJECT_REASON);
            }
        }
        return $count;
    }
}