<?php
declare(strict_types=1);
/**
 * api/trips.php — lifecycle mutations. JSON in / JSON out.
 * POST { action, csrf_token, ... }. Rendering stays on pages; no GET handlers.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

function out(bool $ok, string $msg = '', array $extra = []): never
{
    if (!$ok) {
        http_response_code(400);
    }
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'POST only.');
}
if (!Csrf::verifyRequest($in)) {
    out(false, 'Invalid or missing CSRF token.');
}

$action = (string) ($in['action'] ?? '');
$tid    = (int) ($in['ticket_id'] ?? 0);
$toDt   = static function (?string $raw): ?string {
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
};
$loadTicket = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM trip_tickets WHERE ticket_id = ? AND is_active = 1');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) {
        out(false, 'Ticket not found.');
    }
    return $t;
};
$latestFix = static function (int $vid) use ($pdo): array {
    if (!$vid) {
        return [null, null];
    }
    $st = $pdo->prepare('SELECT latitude, longitude FROM gps_positions WHERE vehicle_id = ? ORDER BY recorded_at DESC LIMIT 1');
    $st->execute([$vid]);
    $p = $st->fetch();
    return $p ? [$p['latitude'], $p['longitude']] : [null, null];
};
$num = static function (mixed $v): ?float {
    if ($v === null || trim((string) $v) === '') {
        return null;
    }
    return is_numeric((string) $v) ? (float) $v : null;
};
$savePassengers = static function (int $id, array $names) use ($pdo): void {
    $pdo->prepare('DELETE FROM trip_passengers WHERE ticket_id = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO trip_passengers (ticket_id, passenger_name) VALUES (?, ?)');
    foreach ($names as $n) {
        $n = trim((string) $n);
        if ($n !== '') {
            $ins->execute([$id, mb_substr($n, 0, 150)]);
        }
    }
};

try {
    switch ($action) {

        case 'save_draft': {
            $u = Auth::requireApi($pdo, [6]);
            $f = [
                'passenger_name'       => trim((string) ($in['passenger_name'] ?? '')),
                'surname'              => trim((string) ($in['surname'] ?? '')),
                'middle_initial'       => trim((string) ($in['middle_initial'] ?? '')) ?: null,
                'suffix'               => trim((string) ($in['suffix'] ?? '')) ?: null,
                'id_number'            => trim((string) ($in['id_number'] ?? '')),
                'requester_type'       => (($in['requester_type'] ?? '') === 'Student') ? 'Student' : 'Personnel',
                'place_to_visit'       => trim((string) ($in['place_to_visit'] ?? '')),
                'place_to_visit_name'  => trim((string) ($in['place_to_visit_name'] ?? '')) ?: null,
                'place_to_visit_lat'   => isset($in['place_to_visit_lat']) ? (float) $in['place_to_visit_lat'] : null,
                'place_to_visit_lon'   => isset($in['place_to_visit_lon']) ? (float) $in['place_to_visit_lon'] : null,
                'place_to_visit_place_id' => trim((string) ($in['place_to_visit_place_id'] ?? '')) ?: null,
                'purpose'              => trim((string) ($in['purpose'] ?? '')),
                'number_of_passengers' => max(1, min(999, (int) ($in['number_of_passengers'] ?? 1))),
                'email'                => trim((string) ($in['email'] ?? '')),
                'scheduled_departure'  => $toDt($in['scheduled_departure'] ?? null),
                'scheduled_return'     => $toDt($in['scheduled_return'] ?? null),
            ];
            $email = $f['email'];
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(false, 'Enter a valid email address.');
            }
            $tid = (int) ($in['ticket_id'] ?? 0);

            if ($tid > 0) {
                $t = $loadTicket($tid);
                if ((int) $t['status_id'] !== TripState::DRAFT || (int) $t['created_by'] !== $u['id']) {
                    out(false, 'Only your own draft tickets can be edited.');
                }
                $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($f)));
                $pdo->prepare("UPDATE trip_tickets SET $set, updated_by = ? WHERE ticket_id = ?")
                    ->execute([...array_values($f), $u['id'], $tid]);
                if (isset($in['passengers']) && is_array($in['passengers'])) {
                    $savePassengers($tid, $in['passengers']);
                }
                out(true, 'Draft saved.', ['ticket_id' => $tid]);
            }

            $cols = array_keys($f);
            $pdo->prepare(
                'INSERT INTO trip_tickets (' . implode(',', $cols) . ', status_id, created_by, updated_by)
                 VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ', ?, ?, ?)'
            )->execute([...array_values($f), TripState::DRAFT, $u['id'], $u['id']]);
            $tid = (int) $pdo->lastInsertId();
            if (isset($in['passengers']) && is_array($in['passengers'])) {
                $savePassengers($tid, $in['passengers']);
            }
            Audit::log($pdo, 'CREATE', 'trip_tickets:' . $tid, null, 'status=DRAFT');
            out(true, 'Draft created.', ['ticket_id' => $tid]);
        }

        case 'upload_request_letter': {
            $u   = Auth::requireApi($pdo, [6]);
            $tid = (int) ($in['ticket_id'] ?? 0);
            $t   = $tid > 0 ? $loadTicket($tid) : null;
            if ($t === null || (int) $t['created_by'] !== $u['id']) {
                out(false, 'Ticket not found among your requests.');
            }
            if ((int) $t['status_id'] !== TripState::DRAFT) {
                out(false, 'The request letter can only be changed while the request is a draft.');
            }
            $file = $_FILES['file'] ?? null;
            if (!$file || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                out(false, 'No file was uploaded.');
            }
            if ((int) $file['size'] > 20 * 1024 * 1024) {
                out(false, 'File exceeds the 20 MB limit.');
            }
            $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'], true)) {
                out(false, 'Only PDF, image, or Word document files are allowed.');
            }
            $dir = APP_ROOT . '/uploads/request_letters';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                out(false, 'Upload directory unavailable.');
            }
            $name = 'RL_' . $tid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
                out(false, 'Failed to save the uploaded file.');
            }
            $rel = 'uploads/request_letters/' . $name;
            if (!empty($t['attachment_path'])) {
                $old = APP_ROOT . '/' . ltrim((string) $t['attachment_path'], '/');
                if (str_starts_with($old, APP_ROOT . '/uploads/') && is_file($old)) {
                    @unlink($old);
                }
            }
            $pdo->prepare('UPDATE trip_tickets SET attachment_path = ?, updated_by = ? WHERE ticket_id = ?')
                ->execute([$rel, $u['id'], $tid]);
            Audit::log($pdo, 'REQUEST_LETTER_UPLOAD', 'trip_tickets:' . $tid, $t['attachment_path'] ?: null, $rel);
            out(true, 'Request letter uploaded.', ['attachment_path' => $rel]);
        }

        case 'remove_request_letter': {
            $u   = Auth::requireApi($pdo, [6]);
            $tid = (int) ($in['ticket_id'] ?? 0);
            $t   = $tid > 0 ? $loadTicket($tid) : null;
            if ($t === null || (int) $t['created_by'] !== $u['id']) {
                out(false, 'Ticket not found among your requests.');
            }
            if ((int) $t['status_id'] !== TripState::DRAFT) {
                out(false, 'The request letter can only be changed while the request is a draft.');
            }
            if (!empty($t['attachment_path'])) {
                $old = APP_ROOT . '/' . ltrim((string) $t['attachment_path'], '/');
                if (str_starts_with($old, APP_ROOT . '/uploads/') && is_file($old)) {
                    @unlink($old);
                }
            }
            $pdo->prepare('UPDATE trip_tickets SET attachment_path = NULL, updated_by = ? WHERE ticket_id = ?')
                ->execute([$u['id'], $tid]);
            Audit::log($pdo, 'REQUEST_LETTER_REMOVE', 'trip_tickets:' . $tid, $t['attachment_path'] ?: null, null);
            out(true, 'Request letter removed.');
        }

        case 'submit': {
            $u = Auth::requireApi($pdo, [6]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::PENDING, [
                'user_id' => $u['id'], 'role_id' => $u['role_id'],
                'request_complete' => TripState::requestComplete($t),
            ]);
            if ($err !== true) {
                out(false, $err);
            }
            $pdo->prepare('UPDATE trip_tickets SET status_id = ?, submitted_at = NOW(), updated_by = ? WHERE ticket_id = ?')
                ->execute([TripState::PENDING, $u['id'], $tid]);
            Audit::log($pdo, 'SUBMIT', 'trip_tickets:' . $tid, 'DRAFT', 'PENDING');
            out(true, 'Submitted for verification.');
        }

        case 'erase_draft': {
            $u = Auth::requireApi($pdo, [6]);
            $t = $loadTicket($tid);
            if ((int) $t['status_id'] !== TripState::DRAFT) {
                out(false, 'Only drafts can be erased.');
            }
            if ((int) $t['created_by'] !== $u['id']) {
                out(false, 'Not your draft.');
            }
            foreach (['trip_passengers','guard_verifications','trip_flags','gps_positions','geofence_events','feedback'] as $tbl) {
                $pdo->prepare("DELETE FROM $tbl WHERE ticket_id = ?")->execute([$tid]);
            }
            $pdo->prepare('DELETE FROM trip_tickets WHERE ticket_id = ?')->execute([$tid]);
            out(true, 'Draft erased.');
        }

        case 'cancel': {
            $u      = Auth::requireApi($pdo, [2, 3, 6]);
            $t      = $loadTicket($tid);
            $reason = trim((string) ($in['reason'] ?? ''));
            $err    = TripState::can($t, TripState::CANCELLED, [
                'user_id' => $u['id'], 'role_id' => $u['role_id'], 'has_reason' => $reason !== '',
            ]);
            if ($err !== true) {
                out(false, $err);
            }
            $pdo->prepare('UPDATE trip_tickets SET status_id = ?, reason = ?, cancelled_by = ?, cancelled_at = NOW(), updated_by = ? WHERE ticket_id = ?')
                ->execute([TripState::CANCELLED, $reason, $u['id'], $u['id'], $tid]);
            Audit::log($pdo, 'CANCEL', 'trip_tickets:' . $tid,
                TripState::name((int) $t['status_id']), 'CANCELLED — ' . $reason);
            out(true, 'Ticket cancelled.');
        }

        case 'hr_verify': {
            $u   = Auth::requireApi($pdo, [2]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::VERIFIED, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            $pdo->prepare('UPDATE trip_tickets SET status_id = ?, approver_id = ?, approved_at = NOW(), updated_by = ? WHERE ticket_id = ?')
                ->execute([TripState::VERIFIED, $u['id'], $u['id'], $tid]);
            Audit::log($pdo, 'HR_VERIFY', 'trip_tickets:' . $tid, 'PENDING', 'VERIFIED');
            out(true, 'Request verified.');
        }

        case 'approve': {
            $u   = Auth::requireApi($pdo, [3]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::APPROVED, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            $pdo->prepare('UPDATE trip_tickets SET status_id = ?, approver_id = ?, approved_at = NOW(), updated_by = ? WHERE ticket_id = ?')
                ->execute([TripState::APPROVED, $u['id'], $u['id'], $tid]);
            Audit::log($pdo, 'APPROVE', 'trip_tickets:' . $tid, 'VERIFIED', 'APPROVED');
            out(true, 'Request approved.');
        }

        case 'reject': {
            $u      = Auth::requireApi($pdo, [2, 3]);
            $t      = $loadTicket($tid);
            $reason = trim((string) ($in['reason'] ?? ''));
            $err    = TripState::can($t, TripState::REJECTED, [
                'user_id' => $u['id'], 'role_id' => $u['role_id'], 'has_reason' => $reason !== '',
            ]);
            if ($err !== true) {
                out(false, $err);
            }
            $pdo->prepare('UPDATE trip_tickets SET status_id = ?, reason = ?, approver_id = ?, updated_by = ? WHERE ticket_id = ?')
                ->execute([TripState::REJECTED, $reason, $u['id'], $u['id'], $tid]);
            Audit::log($pdo, 'REJECT', 'trip_tickets:' . $tid,
                TripState::name((int) $t['status_id']), 'REJECTED — ' . $reason);
            out(true, 'Request rejected.');
        }

        case 'assign': {
            $u   = Auth::requireApi($pdo, [3]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::DISPATCHED, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }

            $vid = (int) ($in['vehicle_id'] ?? 0);
            $did = (int) ($in['driver_id'] ?? 0);
            $dep = $toDt((string) ($in['scheduled_departure'] ?? '')) ?? $t['scheduled_departure'];
            $ret = $toDt((string) ($in['scheduled_return'] ?? '')) ?? $t['scheduled_return'];
            if (!$vid || !$did) {
                out(false, 'Select both a vehicle and a driver.');
            }
            if (!$dep || !$ret || strtotime($ret) <= strtotime((string) $dep)) {
                out(false, 'A valid departure/return schedule is required.');
            }

            $st = $pdo->prepare('SELECT plate_number FROM vehicle WHERE vehicle_id = ? AND is_active = 1 AND is_available = 1');
            $st->execute([$vid]);
            $vehicle = $st->fetch();
            if (!$vehicle) {
                out(false, 'Selected vehicle is not available.');
            }
            $st = $pdo->prepare('SELECT full_name FROM account WHERE account_id = ? AND role_id = 4 AND is_active = 1');
            $st->execute([$did]);
            $driver = $st->fetch();
            if (!$driver) {
                out(false, 'Selected driver is not available.');
            }

            $cv = ConflictChecker::vehicle($pdo, $vid, (string) $dep, (string) $ret, $tid);
            if ($cv) {
                out(false, ConflictChecker::describe($cv, 'Vehicle ' . $vehicle['plate_number']));
            }
            $cd = ConflictChecker::driver($pdo, $did, (string) $dep, (string) $ret, $tid);
            if ($cd) {
                out(false, ConflictChecker::describe($cd, 'Driver ' . $driver['full_name']));
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM gps_devices WHERE vehicle_id = ? AND is_active = 1');
            $stmt->execute([$vid]);
            $paired   = (int) $stmt->fetchColumn() > 0;
            $override = trim((string) ($in['gps_override_reason'] ?? ''));
            if (!$paired && $override === '') {
                out(false, 'Selected vehicle has no paired active GPS device. Enter an override reason to dispatch without telemetry.', ['needs_override' => true]);
            }

            $upd = 'UPDATE trip_tickets SET vehicle_id = ?, driver_id = ?, scheduled_departure = ?, scheduled_return = ?,
                    status_id = ?, assigned_by = ?, assigned_at = NOW(), updated_by = ?, dtt_number = ?,
                    dispatched_without_gps = ?
                    WHERE ticket_id = ?';
            $dtt = null;
            for ($i = 0; $i < 3 && $dtt === null; $i++) {
                try {
                    $pdo->beginTransaction();
                    $dtt = TicketNumber::next($pdo);
                    $pdo->prepare($upd)->execute([
                        $vid, $did, $dep, $ret, TripState::DISPATCHED, $u['id'], $u['id'], $dtt,
                        $paired ? 0 : 1, $tid,
                    ]);
                    $pdo->commit();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e->getCode() === '23000' && $i < 2) {
                        continue;
                    }
                    throw $e;
                }
            }

            if (!$paired) {
                $pdo->prepare('INSERT INTO trip_flags (ticket_id, flag_type, detail) VALUES (?, ?, ?)')
                    ->execute([$tid, 'NO_TELEMETRY', 'Dispatched without GPS: ' . $override]);
                Audit::log($pdo, 'DISPATCH_WITHOUT_GPS_OVERRIDE', 'trip_tickets:' . $tid, null, $override, 'OVERRIDE');
            }
            $after = json_encode([
                'dtt' => $dtt, 'vehicle' => $vehicle['plate_number'], 'driver' => $driver['full_name'],
                'departure' => $dep, 'return' => $ret, 'gps_paired' => (bool) $paired,
            ]);
            Audit::log($pdo, 'ASSIGN', 'trip_tickets:' . $tid, 'APPROVED', $after);
            out(true, "Assigned. Ticket number {$dtt}.", ['dtt_number' => $dtt]);
        }

        case 'start_trip': {
            $u   = Auth::requireApi($pdo, [4, 5]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::ON_GOING, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $pdo->prepare('UPDATE trip_tickets SET status_id = ?, actual_departure_at = NOW(), odometer_start = ?, departure_lat = ?, departure_lon = ?, updated_by = ? WHERE ticket_id = ?')
                ->execute([TripState::ON_GOING, $in['odometer_start'] ?? null, $lat, $lon, $u['id'], $tid]);
            Audit::log($pdo, 'TRIP_START', 'trip_tickets:' . $tid, 'DISPATCHED', 'ON_GOING');

            $lateInfo = null;
            if (!empty($t['scheduled_departure'])) {
                $depTs  = strtotime((string) $t['scheduled_departure']);
                $nowTs  = time();
                if ($depTs && $nowTs > $depTs) {
                    $diff   = $nowTs - $depTs;
                    $hours  = (int) floor($diff / 3600);
                    $mins   = (int) floor(($diff % 3600) / 60);
                    $parts  = [];
                    if ($hours > 0) { $parts[] = $hours . 'h'; }
                    if ($mins > 0)  { $parts[] = $mins . 'm'; }
                    $lateInfo = implode(' ', $parts) ?: '<1m';
                }
            }
            $extra = ['late' => $lateInfo];
            out(true, $lateInfo ? "Trip started. You are {$lateInfo} late." : 'Trip started. Safe travels!', $extra);
        }

        case 'record_arrival': {
            $u = Auth::requireApi($pdo, [4]);
            $t = $loadTicket($tid);
            if ((int) $t['status_id'] !== TripState::ON_GOING) {
                out(false, 'Trip is not currently in progress.');
            }
            if ((int) $t['driver_id'] !== $u['id']) {
                out(false, 'You are not the assigned driver of this trip.');
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $pdo->prepare('UPDATE trip_tickets SET dest_arrival_at = NOW(), dest_arrival_lat = ?, dest_arrival_lon = ?, updated_by = ? WHERE ticket_id = ?')
                ->execute([$lat, $lon, $u['id'], $tid]);
            Audit::log($pdo, 'TRIP_ARRIVE', 'trip_tickets:' . $tid, null, 'ON_GOING');
            out(true, 'Arrival recorded.');
        }

        case 'record_leave_dest': {
            $u = Auth::requireApi($pdo, [4]);
            $t = $loadTicket($tid);
            if ((int) $t['status_id'] !== TripState::ON_GOING) {
                out(false, 'Trip is not currently in progress.');
            }
            if ((int) $t['driver_id'] !== $u['id']) {
                out(false, 'You are not the assigned driver of this trip.');
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $pdo->prepare('UPDATE trip_tickets SET dest_departure_at = NOW(), dest_departure_lat = ?, dest_departure_lon = ?, updated_by = ? WHERE ticket_id = ?')
                ->execute([$lat, $lon, $u['id'], $tid]);
            Audit::log($pdo, 'TRIP_LEAVE_DEST', 'trip_tickets:' . $tid, null, 'ON_GOING');
            out(true, 'Departure from destination recorded.');
        }

        case 'record_return': {
            $u = Auth::requireApi($pdo, [4]);
            $t = $loadTicket($tid);
            if ((int) $t['status_id'] !== TripState::ON_GOING) {
                out(false, 'Trip is not currently in progress.');
            }
            if ((int) $t['driver_id'] !== $u['id']) {
                out(false, 'You are not the assigned driver of this trip.');
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $pdo->prepare('UPDATE trip_tickets SET return_at = NOW(), return_lat = ?, return_lon = ?, updated_by = ? WHERE ticket_id = ?')
                ->execute([$lat, $lon, $u['id'], $tid]);
            Audit::log($pdo, 'TRIP_RETURN', 'trip_tickets:' . $tid, null, 'ON_GOING');
            out(true, 'Return recorded.');
        }

        case 'submit_posttrip': {
            $u   = Auth::requireApi($pdo, [4]);
            $t   = $loadTicket($tid);
            if ((int) $t['status_id'] !== TripState::ON_GOING) {
                out(false, 'Trip is not currently in progress.');
            }
            if ((int) $t['driver_id'] !== $u['id']) {
                out(false, 'You are not the assigned driver of this trip.');
            }
            if ($t['odometer_start'] === null) {
                $reOdo = $num($in['odometer_start'] ?? null);
                if ($reOdo === null || $reOdo < 0) {
                    out(false, 'Odometer start reading is required.');
                }
                $pdo->prepare('UPDATE trip_tickets SET odometer_start = ? WHERE ticket_id = ?')->execute([$reOdo, $tid]);
                $t['odometer_start'] = $reOdo;
            }
            $odoEnd = $num($in['odometer_end'] ?? null);
            if ($odoEnd === null || $odoEnd < (float) $t['odometer_start']) {
                out(false, 'Odometer end must be greater than or equal to the start reading (' . $t['odometer_start'] . ').');
            }
            $fStart = $num($in['fuel_tank_balance_start'] ?? null) ?? 0.0;
            $issued = $num($in['fuel_issued_stock'] ?? null) ?? 0.0;
            $purch  = $num($in['fuel_purchased_trip'] ?? null) ?? 0.0;
            $used   = $num($in['fuel_used'] ?? null) ?? 0.0;
            $total  = round($fStart + $issued + $purch, 2);
            $balEnd = round($total - $used, 2);
            if ($balEnd < -0.001) {
                out(false, 'Fuel used cannot exceed total available fuel.');
            }
            $fuelType = in_array(($in['fuel_type'] ?? ''), ['Gasoline', 'Diesel'], true) ? $in['fuel_type'] : null;
            $clip = static fn(?string $s, int $n): ?string => $s === null ? null : (trim($s) === '' ? null : mb_substr(trim($s), 0, $n));
            $fuelCost     = $num($in['fuel_cost'] ?? null);
            $easytripCost = $num($in['easytrip_cost'] ?? null);
            $autosweepCost= $num($in['autosweep_cost'] ?? null);
            $pdo->prepare(
                'UPDATE trip_tickets SET odometer_end = ?, distance_odometer = ?, fuel_type = ?,
                 fuel_tank_balance_start = ?, fuel_issued_stock = ?, fuel_purchased_trip = ?, fuel_total_available = ?,
                 fuel_used = ?, fuel_balance_end = ?, gear_oil_used = ?, lubricant_oil_issued = ?, grease_issued = ?,
                 remarks = ?, fuel_cost = ?, easytrip_cost = ?, autosweep_cost = ?, updated_by = ?
                 WHERE ticket_id = ?'
            )->execute([
                $odoEnd, round($odoEnd - (float) $t['odometer_start'], 1),
                $fuelType, $fStart ?: null, $issued ?: null, $purch ?: null, $total ?: null,
                $used ?: null, $balEnd,
                $clip($in['gear_oil_used'] ?? null, 100), $clip($in['lubricant_oil_issued'] ?? null, 100),
                $clip($in['grease_issued'] ?? null, 100), $clip($in['remarks'] ?? null, 500),
                $fuelCost, $easytripCost, $autosweepCost,
                $u['id'], $tid,
            ]);
            Audit::log($pdo, 'SECTION_B_SUBMIT', 'trip_tickets:' . $tid, null, 'ON_GOING', 'SUCCESS');
            out(true, 'Post-trip report submitted.');
        }

        case 'complete_trip': {
            $u   = Auth::requireApi($pdo, [4]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::COMPLETED, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $pdo->prepare('UPDATE trip_tickets SET status_id = ?, certified_by = ?, certified_at = NOW(), updated_by = ? WHERE ticket_id = ?')
                ->execute([TripState::COMPLETED, $u['id'], $u['id'], $tid]);
            Audit::log($pdo, 'TRIP_COMPLETE', 'trip_tickets:' . $tid, 'ON_GOING', 'COMPLETED');
            out(true, 'Trip completed.');
        }

        case 'reschedule': {
            $u       = Auth::requireApi($pdo, [3, 6]);
            $t       = $loadTicket($tid);
            $isOwner = (int) $t['created_by'] === $u['id'];
            if (!(($u['role_id'] === 3) || ($u['role_id'] === 6 && $isOwner))) {
                out(false, 'Only the requester or GSD Staff may reschedule.');
            }
            $fromStatus = (int) $t['status_id'];
            $allowed = in_array($fromStatus, [TripState::PENDING, TripState::APPROVED, TripState::DISPATCHED, TripState::ON_GOING], true);
            if (!$allowed) {
                out(false, 'Rescheduling is not available for this ticket status.');
            }
            $dep = $toDt((string) ($in['scheduled_departure'] ?? ''));
            $ret = $toDt((string) ($in['scheduled_return'] ?? ''));
            if (!$dep || !$ret || strtotime($ret) <= strtotime($dep)) {
                out(false, 'A valid new departure/return window is required.');
            }
            $old = $t['scheduled_departure'] . ' → ' . $t['scheduled_return'];

            $cleared = false;
            if (!empty($t['vehicle_id']) || !empty($t['driver_id'])) {
                $cv = !empty($t['vehicle_id']) ? ConflictChecker::vehicle($pdo, (int) $t['vehicle_id'], $dep, $ret, $tid) : null;
                $cd = !empty($t['driver_id']) ? ConflictChecker::driver($pdo, (int) $t['driver_id'], $dep, $ret, $tid) : null;
                if ($cv || $cd) {
                    $pdo->prepare('UPDATE trip_tickets SET scheduled_departure = ?, scheduled_return = ?,
                                   vehicle_id = NULL, driver_id = NULL, dtt_number = NULL, dispatched_without_gps = 0,
                                   status_id = ?, updated_by = ? WHERE ticket_id = ?')
                        ->execute([$dep, $ret, TripState::RESCHEDULED, $u['id'], $tid]);
                    $cleared = true;
                }
            }
            if (!$cleared) {
                $targetStatus = $fromStatus === TripState::ON_GOING ? TripState::RESCHEDULED : $fromStatus;
                $pdo->prepare('UPDATE trip_tickets SET scheduled_departure = ?, scheduled_return = ?, status_id = ?, updated_by = ? WHERE ticket_id = ?')
                    ->execute([$dep, $ret, $targetStatus, $u['id'], $tid]);
            }
            Audit::log($pdo, 'RESCHEDULE', 'trip_tickets:' . $tid, $old, "$dep → $ret"
                . ($cleared ? ' — assignment cleared (conflict)' : ''));
            out(true, $cleared
                ? 'Rescheduled. The previous vehicle/driver now conflicts and has been unassigned — staff must assign again.'
                : 'Schedule updated.');
        }

        case 'verify': {
            $u      = Auth::requireApi($pdo, [5]);
            $t      = $loadTicket($tid);
            $phase  = (($in['phase'] ?? '') === 'RETURN') ? 'RETURN' : 'PRE_DEPARTURE';
            $result = (($in['result'] ?? '') === 'FLAGGED') ? 'FLAGGED' : 'VALIDATED';
            $stId   = (int) $t['status_id'];
            $rangeOk = $phase === 'PRE_DEPARTURE'
                ? in_array($stId, [TripState::DISPATCHED, TripState::ON_GOING], true)
                : in_array($stId, [TripState::ON_GOING, TripState::COMPLETED], true);
            if (!$rangeOk) {
                out(false, $phase === 'PRE_DEPARTURE'
                    ? 'Pre-departure verification applies to dispatched or ongoing trips.'
                    : 'Return verification applies to ongoing or completed trips.');
            }
            $cb = static fn(mixed $v): int => !empty($v) ? 1 : 0;
            $pdo->prepare(
                'INSERT INTO guard_verifications
                 (ticket_id, guard_id, phase, result, checked_driver, checked_vehicle, checked_plate, checked_passengers, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $tid, $u['id'], $phase, $result,
                $cb($in['checked_driver'] ?? 0), $cb($in['checked_vehicle'] ?? 0),
                $cb($in['checked_plate'] ?? 0), $cb($in['checked_passengers'] ?? 0),
                mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null,
            ]);
            Audit::log($pdo, 'GUARD_VERIFY', 'trip_tickets:' . $tid, null, "$phase $result");
            out(true, 'Verification recorded.');
        }

        case 'resolve_flag': {
            $u      = Auth::requireApi($pdo, [3, 1]);
            $fid    = (int) ($in['flag_id'] ?? 0);
            $reason = trim((string) ($in['reason'] ?? ''));
            if ($reason === '') {
                out(false, 'A resolution reason is required.');
            }
            $st = $pdo->prepare(
                'SELECT f.flag_id, f.flag_type, f.ticket_id FROM trip_flags f
                 WHERE f.flag_id = ? AND f.resolved_at IS NULL'
            );
            $st->execute([$fid]);
            $f = $st->fetch();
            if (!$f) {
                out(false, 'Flag not found or already resolved.');
            }
            $pdo->prepare('UPDATE trip_flags SET resolved_by = ?, resolved_at = NOW(), resolution_reason = ? WHERE flag_id = ?')
                ->execute([$u['id'], mb_substr($reason, 0, 500), $fid]);
            Audit::log($pdo, 'FLAG_RESOLVE', 'trip_flags:' . $fid,
                $f['flag_type'] . ' open', $f['flag_type'] . ' resolved — ' . $reason);
            out(true, 'Flag resolved.');
        }

        case 'feedback_save': {
            $u   = Auth::requireApi($pdo, [6]);
            $tid = (int) ($in['ticket_id'] ?? 0);
            $details = trim((string) ($in['details'] ?? ''));
            if ($details === '') {
                out(false, 'Feedback details are required.');
            }
            if (!$tid) {
                out(false, 'Select the trip this feedback refers to.');
            }
            $st = $pdo->prepare('SELECT ticket_id FROM trip_tickets WHERE ticket_id = ? AND created_by = ? AND is_active = 1');
            $st->execute([$tid, $u['id']]);
            if (!$st->fetch()) {
                out(false, 'Ticket not found among your requests.');
            }
            $pdo->prepare('INSERT INTO feedback (ticket_id, submitter_name, details) VALUES (?, ?, ?)')
                ->execute([$tid, $u['full_name'], mb_substr($details, 0, 2000)]);
            Audit::log($pdo, 'FEEDBACK_SUBMIT', 'feedback:' . $pdo->lastInsertId(), null, 'ticket:' . $tid);
            out(true, 'Feedback submitted. Thank you!');
        }

        default:
            out(false, 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[api/trips] ' . $e->getMessage());
    out(false, 'An internal error occurred. Please try again.');
}
