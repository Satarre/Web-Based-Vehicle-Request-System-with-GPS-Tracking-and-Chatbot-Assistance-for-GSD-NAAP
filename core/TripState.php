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
    public const VERIFIED      = 3;
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
        self::VERIFIED    => 'VERIFIED',
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

    /** Statuses from which the requester may still cancel. */
    public const CANCELLABLE = [self::DRAFT, self::PENDING, self::APPROVED, self::DISPATCHED];

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
        self::PENDING => [
            self::VERIFIED  => ['roles' => [2]],
            self::REJECTED  => ['roles' => [2], 'need_reason' => true],
            self::CANCELLED => ['owner_or_roles' => [2]],
        ],
        self::VERIFIED => [
            self::APPROVED  => ['roles' => [3]],
            self::REJECTED  => ['roles' => [3], 'need_reason' => true],
            self::CANCELLED => ['owner_or_roles' => [3]],
        ],
        self::APPROVED => [
            self::DISPATCHED => ['roles' => [3]],
            self::REJECTED  => ['roles' => [3], 'need_reason' => true],
            self::CANCELLED => ['owner_or_roles' => [3]],
        ],
        self::DISPATCHED => [
            self::ON_GOING  => ['roles' => [4, 5]],
            self::CANCELLED => ['owner_or_roles' => [3]],
        ],
        self::ON_GOING => [
            self::COMPLETED   => ['roles' => [4], 'driver_match' => true],
            self::RESCHEDULED => ['roles' => [3]],
        ],
        self::RESCHEDULED => [
            self::DISPATCHED => ['roles' => [3]],
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
        if (!empty($rule['need_assignment'])
            && (empty($ticket['vehicle_id']) || empty($ticket['driver_id']))) {
            return 'Both driver and vehicle must be assigned first.';
        }
        if (!empty($rule['need_request']) && empty($ctx['request_complete'])) {
            return 'Complete all required fields before submitting.';
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
        return $dep !== false && $ret !== false && $ret > $dep && (int) ($t['number_of_passengers'] ?? 0) >= 1;
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
