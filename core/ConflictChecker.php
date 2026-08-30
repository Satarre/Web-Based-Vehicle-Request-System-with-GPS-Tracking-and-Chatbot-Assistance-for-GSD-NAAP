<?php
declare(strict_types=1);
/**
 * core/ConflictChecker.php — vehicle/driver double-booking detection.
 * Overlap test: existing.dep < new.ret AND new.dep < existing.ret.
 */

class ConflictChecker
{
    /** Statuses that hold a vehicle/driver busy: DISPATCHED through ON_GOING. */
    public const BUSY = [
        TripState::DISPATCHED, TripState::ON_GOING,
    ];

    public static function vehicle(PDO $pdo, int $vehicleId, string $dep, string $ret, int $excludeTicket = 0): ?array
    {
        return self::find($pdo, 'vehicle_id', $vehicleId, $dep, $ret, $excludeTicket);
    }

    public static function driver(PDO $pdo, int $driverId, string $dep, string $ret, int $excludeTicket = 0): ?array
    {
        return self::find($pdo, 'driver_id', $driverId, $dep, $ret, $excludeTicket);
    }

    /** @return array{ticket_id:int,dtt_number:?string,status_name:string,scheduled_departure:string,scheduled_return:string}|null */
    private static function find(PDO $pdo, string $col, int $id, string $dep, string $ret, int $exclude): ?array
    {
        $busy = implode(',', self::BUSY);
        $stmt = $pdo->prepare(
            "SELECT t.ticket_id, t.dtt_number, r.status_name,
                    t.scheduled_departure, t.scheduled_return
             FROM trip_tickets t
             JOIN request_status r ON r.status_id = t.status_id
             WHERE t.$col = ? AND t.status_id IN ($busy)
               AND t.scheduled_departure < ? AND ? < t.scheduled_return
               AND t.ticket_id <> ?
             LIMIT 1"
        );
        $stmt->execute([$id, $ret, $dep, $exclude]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'ticket_id'           => (int) $row['ticket_id'],
            'dtt_number'          => $row['dtt_number'],
            'status_name'         => $row['status_name'],
            'scheduled_departure' => (string) $row['scheduled_departure'],
            'scheduled_return'    => (string) $row['scheduled_return'],
        ];
    }

    public static function describe(array $conflict, string $what): string
    {
        return sprintf(
            '%s is already booked for %s (%s) %s → %s.',
            $what,
            $conflict['dtt_number'] ?? ('ticket #' . $conflict['ticket_id']),
            $conflict['status_name'],
            $conflict['scheduled_departure'],
            $conflict['scheduled_return']
        );
    }
}
