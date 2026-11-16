<?php
declare(strict_types=1);
/**
 * api/calendar.php — dispatch calendar data (GET, pure JSON).
 * Role-scoped like the dashboards that embed the calendar:
 *   roles 2/3/5 (SAO, Dispatcher, Guard) → all dispatched/ongoing/completed trips
 *   role 4 (Driver)                      → own trips
 *   role 6 (Personnel)                   → own requests
 * Params: cal_y, cal_m (required). Read-only GET, no CSRF needed.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');
$u = Auth::requireApi($pdo, [2, 3, 4, 5, 6]);

$year  = (int) ($_GET['cal_y'] ?? date('Y'));
$month = (int) ($_GET['cal_m'] ?? date('n'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$from = sprintf('%04d-%02d-01 00:00:00', $year, $month, 1);
$to   = date('Y-m-d 00:00:00', strtotime($from . ' +1 month'));

$scope = '';
$args  = [];
if ((int) $u['role_id'] === 4) {
    $scope = ' AND t.driver_id = ?';
    $args[] = $u['id'];
} elseif ((int) $u['role_id'] === 6) {
    $scope = ' AND t.created_by = ?';
    $args[] = $u['id'];
}

$st = $pdo->prepare(
    'SELECT t.ticket_id, t.dtt_number, t.scheduled_departure, t.scheduled_return, v.plate_number,
            c.full_name AS requester_name, t.place_to_visit, d.full_name AS driver_name
     FROM trip_tickets t
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
     LEFT JOIN account c ON c.account_id = t.created_by
     LEFT JOIN account d ON d.account_id = t.driver_id
     WHERE t.is_active = 1
       AND t.status_id IN (' . TripState::DISPATCHED . ', ' . TripState::ON_GOING . ', ' . TripState::COMPLETED . ')
       AND t.scheduled_departure >= ?
       AND t.scheduled_departure < ?'
    . $scope .
    ' ORDER BY t.scheduled_departure'
);
$st->execute([$from, $to, ...$args]);

$tickets = [];
foreach ($st->fetchAll() as $r) {
    $tickets[] = [
        'ticket_id'           => (int) $r['ticket_id'],
        'dtt_number'          => $r['dtt_number'],
        'scheduled_departure' => $r['scheduled_departure'],
        'scheduled_return'    => $r['scheduled_return'],
        'plate_number'        => $r['plate_number'],
        'requester_name'      => $r['requester_name'],
        'place_to_visit'      => $r['place_to_visit'],
        'driver_name'         => $r['driver_name'],
    ];
}

echo json_encode([
    'success' => true,
    'year'    => $year,
    'month'   => $month,
    'tickets' => $tickets,
]);