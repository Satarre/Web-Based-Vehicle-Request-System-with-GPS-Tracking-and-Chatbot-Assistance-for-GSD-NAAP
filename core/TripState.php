<?php
/**
 * core/TripState.php — THE single authority for trip-ticket status transitions.
 * Pure decision logic (no DB writes): callers validate with can(), then perform
 * the UPDATE + side effects + audit themselves. This is the choke point that
 * prevents illegal state jumps.
 */

class TripState
{
    public const DRAFT         = 1;
    public const PENDING       = 2;
    // NOTE: status_id 3 (VERIFIED) is retired — the request_status row is kept
    // inactive for audit/history only. No ticket may ever enter it again.
    public const APPROVED      = 4;
    public const DISPATCHED    = 5;
    public const ON_GOING      = 6;
    public const COMPLETED     = 7;
    public const CANCELLED     = 8;
    public const REJECTED      = 9;
    public const RESCHEDULED   = 10;

    /** id => seed name (keep in sync with request_status seed) */
    public const NAMES = [
        self::DRAFT       => 'DRAFT',
        self::PENDING     => 'PENDING',
        self::APPROVED    => 'APPROVED',
        self::DISPATCHED  => 'DISPATCHED',
        self::ON_GOING    => 'ON_GOING',
        self::COMPLETED   => 'COMPLETED',
        self::CANCELLED   => 'CANCELLED',
        self::REJECTED    => 'REJECTED',
        self::RESCHEDULED => 'RESCHEDULED',
    ];

    /** Statuses where the vehicle is considered on an active trip (GPS association window). */
    public const ACTIVE_TRIP = [self::ON_GOING];

    /**
     * Transition matrix: FROM => [TO => rule].
     * rule keys:
     *   roles            - role_id list allowed to perform it
     *   owner_only       - only the ticket creator
     *   owner_or_roles   - creator OR any listed role
     *   driver_match     - actor must be the assigned driver
     *   need_reason      - reason field mandatory
     *   need_assignment  - vehicle_id AND driver_id must be set on the ticket
     *   need_request     - core request fields must be filled (submit)
     */
    private const MATRIX = [
        self::DRAFT => [
            self::PENDING   => ['owner_only' => true, 'need_request' => true],
            self::CANCELLED => ['owner_only' => true],
        ],
        // Personnel submit → SAO (Supervising Admin Officer) approves straight
        // from PENDING; the former GSD "verify" step is gone.
        self::PENDING => [
            self::APPROVED  => ['roles' => [2]],
            self::REJECTED  => ['roles' => [2], 'need_reason' => true],
            self::CANCELLED => ['owner_only' => true],
        ],
        self::APPROVED => [
            self::DISPATCHED => ['roles' => [3], 'need_assignment' => true],
            self::REJECTED  => ['roles' => [3], 'need_reason' => true],
            self::CANCELLED => ['owner_only' => true],
            // GSD Dispatcher sends an approved ticket back to the requester
            // (reason mandatory) when the schedule can no longer be honored.
            self::RESCHEDULED => ['roles' => [3], 'need_reason' => true],
        ],
        self::DISPATCHED => [
            self::ON_GOING  => ['roles' => [4], 'driver_match' => true],
            self::CANCELLED => ['owner_only' => true],
        ],
        self::ON_GOING => [
            self::COMPLETED => ['roles' => [4], 'driver_match' => true],
        ],
        self::RESCHEDULED => [
            // Requester picks a new schedule; back into the normal pipeline
            // (SAO approval → GSD dispatch) from PENDING. Schedule validity
            // (future, ret>dep) is enforced by the API handler, not here —
            // the request already passed full completeness checks once.
            self::PENDING => ['owner_only' => true],
        ],
        self::COMPLETED  => [],
        self::REJECTED   => [],
        self::CANCELLED  => [],
    ];

    /**
     * Validate a transition.
     *
     * @param array $ticket current trip_tickets row (needs status_id, created_by,
     *                      driver_id, vehicle_id, scheduled_departure/return,
     *                      place_to_visit, purpose, id_number, dtt_number)
     * @param int   $to     target status id
     * @param array $ctx    actor context: role_id, user_id, has_reason,
     *                      request_complete (bool), assignment_complete (bool)
     * @return true|string  true when legal, otherwise a human-readable error
     */
    public static function can(array $ticket, int $to, array $ctx): bool|string
    {
        $from = (int) ($ticket['status_id'] ?? 0);
        if (!isset(self::MATRIX[$from][$to])) {
            return sprintf('Illegal transition %s → %s.', self::name($from), self::name($to));
        }
        $rule = self::MATRIX[$from][$to];

        $uid     = (int) ($ctx['user_id'] ?? 0);
        $roleId  = (int) ($ctx['role_id'] ?? 0);
        $isOwner = $uid !== 0 && (int) ($ticket['created_by'] ?? 0) === $uid;

        if (!empty($rule['owner_only']) && !$isOwner) {
            return 'Only the requesting personnel may perform this action.';
        }
        if (isset($rule['roles'])) {
            if ($isOwner && in_array($to, [self::CANCELLED], true)) {
                // fall through: owner may always cancel own pre-start ticket
            } elseif (!in_array($roleId, $rule['roles'], true)) {
                return 'Your role is not authorized for this action.';
            } elseif (!empty($rule['driver_match'])
                && (int) ($ticket['driver_id'] ?? 0) !== $uid) {
                return 'You are not the assigned driver of this trip.';
            }
        }
        if (isset($rule['owner_or_roles']) && !$isOwner
            && !in_array($roleId, $rule['owner_or_roles'], true)) {
            return 'You are not allowed to perform this action.';
        }
        if (!empty($rule['need_reason']) && empty($ctx['has_reason'])) {
            return 'A reason is required for this action.';
        }
        if (!empty($rule['need_assignment'])) {
            // The dispatcher supplies the assignment in the same request that
            // triggers APPROVED → DISPATCHED, so the ticket row is still empty
            // at this point; `assignment_complete` lets that caller declare
            // the pending selection (mirrors need_request/request_complete).
            $assigned = !empty($ctx['assignment_complete'])
                || (!empty($ticket['vehicle_id']) && !empty($ticket['driver_id']));
            if (!$assigned) {
                return 'Both driver and vehicle must be assigned first.';
            }
        }
        if (!empty($rule['need_request']) && empty($ctx['request_complete'])) {
            return 'Complete all required fields before submitting.';
        }
        return true;
    }

    /**
     * Driver "Stop Trip" — terminate an ON_GOING trip early (breakdown, emergency).
     * Kept separate from the transition matrix so `can(..., CANCELLED, ...)` stays
     * owner-only and the generic Cancel button never renders for drivers.
     *
     * @param array $ticket current trip_tickets row (needs status_id, driver_id)
     * @param array $ctx    actor context: role_id, user_id, has_reason
     * @return true|string
     */
    public static function canStop(array $ticket, array $ctx): bool|string
    {
        if ((int) ($ticket['status_id'] ?? 0) !== self::ON_GOING) {
            return 'Only an ongoing trip can be stopped.';
        }
        if ((int) ($ctx['role_id'] ?? 0) !== 4) {
            return 'Only the assigned driver may stop the trip.';
        }
        if ((int) ($ticket['driver_id'] ?? 0) !== (int) ($ctx['user_id'] ?? 0)) {
            return 'You are not the assigned driver of this trip.';
        }
        if (empty($ctx['has_reason'])) {
            return 'A reason is required to stop the trip.';
        }
        return true;
    }

    /** Completeness gate for DRAFT → PENDING. */
    public static function requestComplete(array $t): bool
    {
        foreach (['passenger_name', 'id_number', 'place_to_visit', 'purpose', 'scheduled_departure', 'scheduled_return', 'attachment_path'] as $k) {
            if (!isset($t[$k]) || trim((string) $t[$k]) === '') {
                return false;
            }
        }
        $dep = strtotime((string) $t['scheduled_departure']);
        $ret = strtotime((string) $t['scheduled_return']);
        // Departure must be strictly in the future: a trip cannot be approved
        // or dispatched once its scheduled departure has already passed.
        return $dep !== false && $ret !== false
            && $ret > $dep
            && $dep > time()
            && (int) ($t['number_of_passengers'] ?? 0) >= 1;
    }

    public static function name(int $statusId): string
    {
        return self::NAMES[$statusId] ?? ('STATUS_' . $statusId);
    }

    public static function isActiveTrip(int $statusId): bool
    {
        return in_array($statusId, self::ACTIVE_TRIP, true);
    }
}
