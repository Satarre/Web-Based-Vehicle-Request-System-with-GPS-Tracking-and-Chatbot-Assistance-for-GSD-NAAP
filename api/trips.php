<?php
declare(strict_types=1);
/**
 * api/trips.php — lifecycle mutations. JSON in / JSON out.
 * POST { action, csrf_token, ... }. Rendering stays on pages; no GET handlers.
 */
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'POST only.');
}
/* php.ini shorthand ("20M", "1G", "512K") → bytes; 0 when unlimited/unset. */
$iniBytes = static function (string $v): int {
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $unit = strtolower($v[strlen($v) - 1]);
    $n    = (float) $v;
    return (int) match ($unit) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
};
/* PHP silently drops $_POST/$_FILES when post_max_size is exceeded, which used
 * to surface as "Unknown action." on the request-letter upload. Say what really
 * happened instead. */
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxBytes  = $iniBytes((string) ini_get('post_max_size'));
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    out(false, 'The upload is larger than the server limit of '
        . rtrim(rtrim(number_format($postMaxBytes / 1048576, 1), '0'), '.') . ' MB.');
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
/* Column-safe text: trim + clip to the MySQL varchar width. A long pasted
 * address or ID used to abort the whole save with "Data too long" when the
 * server runs in strict mode. */
$clipText = static function (mixed $v, int $max): ?string {
    $s = trim((string) $v);
    if ($s === '') {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
};
$loadDraft = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM trip_drafts WHERE draft_id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) {
        out(false, 'Draft not found.');
    }
    return $d;
};
/* Passenger-manifest writer. Deliberately self-contained: it must not depend on
 * config helper functions or the mbstring extension, because a stale
 * config/config.php (the usual casualty of an FTP upload that skips config/ so
 * secrets.php is not overwritten) would otherwise abort every draft autosave
 * *after* the draft row was already written. */
$saveDraftPassengers = static function (int $id, array $rows) use ($pdo): void {
    $clip = static function (string $s, int $n): string {
        if ($s === '') {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
    };
    $upper = static function (string $s): string {
        return function_exists('mb_strtoupper') ? mb_strtoupper($s) : strtoupper($s);
    };
    $norm = static function (mixed $v, int $n) use ($clip, $upper): string {
        return $clip($upper(trim((string) $v)), $n);
    };
    /* "SURNAME, FIRSTNAME M." — same shape as passenger_display_name(). */
    $compose = static function (string $first, string $mi, string $last) use ($upper): string {
        $first = $upper(trim($first));
        $last  = $upper(trim($last));
        $mi    = $upper(trim($mi));
        $name  = $last !== '' ? $last . ', ' . $first : $first;
        if ($mi !== '') {
            $name .= ' ' . (strlen($mi) === 1 ? $mi . '.' : $mi);
        }
        return trim($name);
    };

    $pdo->prepare('DELETE FROM trip_draft_passengers WHERE draft_id = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO trip_draft_passengers (draft_id, passenger_name, surname, first_name, middle_initial, middle_name)
                          VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) {
        if (is_array($r)) {
            $first = $norm($r['first_name'] ?? '', 150);
            $mi    = $norm($r['middle_initial'] ?? '', 3);
            $mn    = $norm($r['middle_name'] ?? '', 100);
            $last  = $norm($r['surname'] ?? '', 100);
            $name  = $clip($compose($first, $mi, $last), 150);
        } else {
            /* Legacy plain-string payload: kept verbatim in passenger_name. */
            $name  = $clip(trim((string) $r), 150);
            $first = $mi = $mn = $last = '';
        }
        if ($name === '') {
            continue;
        }
        $ins->execute([
            $id,
            $name,
            $last !== '' ? $last : null,
            $first !== '' ? $first : null,
            $mi !== '' ? $mi : null,
            $mn !== '' ? $mn : null,
        ]);
    }
};
$unlinkAttachment = static function (?string $path): void {
    if (empty($path)) {
        return;
    }
    $full = APP_ROOT . '/' . ltrim((string) $path, '/');
    if (str_starts_with($full, APP_ROOT . '/uploads/') && is_file($full)) {
        @unlink($full);
    }
};

try {
    switch ($action) {

        case 'save_draft': {
            $u = Auth::requireApi($pdo, [6]);
            $f = [
                'passenger_name'       => $clipText($in['passenger_name'] ?? '', 150) ?? '',
                'surname'              => $clipText($in['surname'] ?? '', 100),
                'middle_initial'       => $clipText($in['middle_initial'] ?? '', 3),
                'middle_name'          => $clipText($in['middle_name'] ?? '', 100),
                'suffix'               => $clipText($in['suffix'] ?? '', 10),
                'id_number'            => $clipText($in['id_number'] ?? '', 50) ?? '',
                'requester_type'       => (($in['requester_type'] ?? '') === 'Student') ? 'Student' : 'Personnel',
                'place_to_visit'       => trim((string) ($in['place_to_visit'] ?? '')),
                'place_to_visit_name'  => $clipText($in['place_to_visit_name'] ?? '', 255),
                'place_to_visit_lat'   => isset($in['place_to_visit_lat']) ? (float) $in['place_to_visit_lat'] : null,
                'place_to_visit_lon'   => isset($in['place_to_visit_lon']) ? (float) $in['place_to_visit_lon'] : null,
                'place_to_visit_place_id' => $clipText($in['place_to_visit_place_id'] ?? '', 255),
                'purpose'              => trim((string) ($in['purpose'] ?? '')),
                'number_of_passengers' => max(1, min(999, (int) ($in['number_of_passengers'] ?? 1))),
                'email'                => $clipText($in['email'] ?? '', 150) ?? '',
                'scheduled_departure'  => $toDt($in['scheduled_departure'] ?? null),
                'scheduled_return'     => $toDt($in['scheduled_return'] ?? null),
            ];
            $email = $f['email'];
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(false, 'Enter a valid email address.');
            }
            $did = (int) ($in['draft_id'] ?? 0);
            $hasPax = isset($in['passengers']) && is_array($in['passengers']);
            $created = $did <= 0;

            /* One transaction for the draft row + its passenger manifest: a
             * failure must never leave a half-written draft behind. Without
             * this, a failing manifest write still committed the draft, the
             * browser never received draft_id, and every 10 s autosave
             * inserted yet another row. */
            try {
                $pdo->beginTransaction();

                if ($did > 0) {
                    $own = $pdo->prepare('SELECT created_by FROM trip_drafts WHERE draft_id = ? FOR UPDATE');
                    $own->execute([$did]);
                    $owner = $own->fetchColumn();
                    if ($owner === false || (int) $owner !== (int) $u['id']) {
                        $pdo->rollBack();
                        out(false, 'Only your own drafts can be edited.');
                    }
                    $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($f)));
                    $pdo->prepare("UPDATE trip_drafts SET $set, updated_by = ? WHERE draft_id = ?")
                        ->execute([...array_values($f), $u['id'], $did]);
                    if ($hasPax) {
                        $saveDraftPassengers($did, $in['passengers']);
                    }
                } else {
                    $cols = array_keys($f);
                    $pdo->prepare(
                        'INSERT INTO trip_drafts (' . implode(',', $cols) . ', created_by, updated_by)
                         VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ', ?, ?)'
                    )->execute([...array_values($f), $u['id'], $u['id']]);
                    $did = (int) $pdo->lastInsertId();
                    if ($hasPax) {
                        $saveDraftPassengers($did, $in['passengers']);
                    }
                    Audit::log($pdo, 'CREATE', 'trip_drafts:' . $did, null, 'status=DRAFT');
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[api/trips] save_draft: ' . get_class($e) . ': ' . $e->getMessage());
                out(false, APP_ENV === 'dev'
                    ? 'Could not save the draft: ' . $e->getMessage()
                    : 'Could not save the draft. Please try again.');
            }

            out(true, $created ? 'Draft created.' : 'Draft saved.', ['draft_id' => $did]);
        }

        case 'upload_request_letter': {
            $u   = Auth::requireApi($pdo, [6]);
            $did = (int) ($in['draft_id'] ?? 0);
            $d   = $did > 0 ? $loadDraft($did) : null;
            if ($d === null || (int) $d['created_by'] !== $u['id']) {
                out(false, 'Draft not found among your requests.');
            }
            $file = $_FILES['file'] ?? null;
            if (!$file || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                out(false, 'No file was uploaded.');
            }
            if ((int) $file['size'] > 20 * 1024 * 1024) {
                out(false, 'File exceeds the 20 MB limit.');
            }
            if ((int) $file['size'] < 1) {
                out(false, 'The selected file is empty.');
            }
            $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'], true)) {
                out(false, 'Only PDF, image, or Word document files are allowed.');
            }
            $dir = APP_ROOT . '/uploads/request_letters';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                out(false, 'Upload directory unavailable.');
            }
            $name = 'RL_' . $did . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
                out(false, 'Failed to save the uploaded file.');
            }
            $rel = 'uploads/request_letters/' . $name;
            $old = $d['attachment_path'] ?? null;
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE trip_drafts SET attachment_path = ?, updated_by = ? WHERE draft_id = ?')
                    ->execute([$rel, $u['id'], $did]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                /* Keep the previous letter: drop the file we just moved in. */
                $unlinkAttachment($rel);
                throw $e;
            }
            $unlinkAttachment($old);
            Audit::log($pdo, 'REQUEST_LETTER_UPLOAD', 'trip_drafts:' . $did, $old, $rel);
            out(true, 'Request letter uploaded.', ['attachment_path' => $rel]);
        }

        case 'remove_request_letter': {
            $u   = Auth::requireApi($pdo, [6]);
            $did = (int) ($in['draft_id'] ?? 0);
            $d   = $did > 0 ? $loadDraft($did) : null;
            if ($d === null || (int) $d['created_by'] !== $u['id']) {
                out(false, 'Draft not found among your requests.');
            }
            $old = $d['attachment_path'] ?? null;
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE trip_drafts SET attachment_path = NULL, updated_by = ? WHERE draft_id = ?')
                    ->execute([$u['id'], $did]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $unlinkAttachment($old);
            Audit::log($pdo, 'REQUEST_LETTER_REMOVE', 'trip_drafts:' . $did, $old, null);
            out(true, 'Request letter removed.');
        }

        case 'submit': {
            $u   = Auth::requireApi($pdo, [6]);
            $did = (int) ($in['draft_id'] ?? 0);
            $d   = $did > 0 ? $loadDraft($did) : null;
            if ($d === null || (int) $d['created_by'] !== $u['id']) {
                out(false, 'Draft not found among your requests.');
            }
            if (TripState::requestComplete($d) !== true) {
                out(false, 'Complete all required fields before submitting.');
            }
            /* The passenger manifest must name every declared passenger. */
            $paxSt = $pdo->prepare('SELECT COUNT(*) FROM trip_draft_passengers WHERE draft_id = ?');
            $paxSt->execute([$did]);
            $paxCount  = (int) $paxSt->fetchColumn();
            $paxNeeded = (int) $d['number_of_passengers'];
            if ($paxCount !== $paxNeeded) {
                out(false, 'Add the names of all ' . $paxNeeded . ' passenger' . ($paxNeeded === 1 ? '' : 's') . ' before submitting.');
            }
            $cols = [
                'passenger_name', 'surname', 'middle_initial', 'middle_name', 'suffix', 'id_number',
                'requester_type', 'place_to_visit', 'place_to_visit_name',
                'place_to_visit_lat', 'place_to_visit_lon', 'place_to_visit_place_id',
                'purpose', 'number_of_passengers', 'email',
                'scheduled_departure', 'scheduled_return', 'attachment_path',
            ];
            $lateDays = max(1, (int) Settings::getInt('LATE_ADVANCE_DAYS', 3));
            $isLate = (!empty($d['scheduled_departure'])
                && (strtotime((string) $d['scheduled_departure']) - time()) < $lateDays * 86400) ? 1 : 0;
            $pdo->beginTransaction();
            try {
                /* Snapshot the manifest BEFORE claiming the draft: deleting the
                 * draft row cascades to trip_draft_passengers (fk_draft_pass). */
                $paxRowsStmt = $pdo->prepare('SELECT passenger_name, surname, first_name, middle_initial, middle_name
                                              FROM trip_draft_passengers WHERE draft_id = ? ORDER BY draft_passenger_id');
                $paxRowsStmt->execute([$did]);
                $manifest = $paxRowsStmt->fetchAll();

                // Claim the draft atomically — only one concurrent submit can win.
                $claim = $pdo->prepare('DELETE FROM trip_drafts WHERE draft_id = ? AND created_by = ?');
                $claim->execute([$did, $u['id']]);
                if ($claim->rowCount() === 0) {
                    $pdo->rollBack();
                    out(false, 'This request has already been submitted.');
                }
                $rtt = '';
                $newId = 0;
                /* dtt_number is UNIQUE and TicketNumber::next() is MAX+1, so two
                 * drafts submitted concurrently can compute the same number.
                 * Retry with a fresh number on a duplicate-key error (1062); the
                 * failed statement does not abort the surrounding transaction. */
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $rtt = TicketNumber::next($pdo);
                    try {
                        $pdo->prepare(
                            'INSERT INTO trip_tickets (' . implode(',', $cols) . ", dtt_number, status_id, is_late, submitted_at, created_by, updated_by)
                             VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ', ?, ?, ?, NOW(), ?, ?)'
                        )->execute([...array_map(static fn(string $k) => $d[$k], $cols), $rtt, TripState::PENDING, $isLate, $u['id'], $u['id']]);
                        $newId = (int) $pdo->lastInsertId();
                        break;
                    } catch (PDOException $e) {
                        if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || $attempt === 2) {
                            throw $e;
                        }
                    }
                }
                if ($manifest) {
                    $insPax = $pdo->prepare('INSERT INTO trip_passengers (ticket_id, passenger_name, surname, first_name, middle_initial, middle_name)
                                             VALUES (?, ?, ?, ?, ?, ?)');
                    foreach ($manifest as $p) {
                        $insPax->execute([$newId, $p['passenger_name'], $p['surname'], $p['first_name'], $p['middle_initial'], $p['middle_name']]);
                    }
                }
                $pdo->prepare('DELETE FROM trip_draft_passengers WHERE draft_id = ?')->execute([$did]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            Audit::log($pdo, 'SUBMIT', 'trip_tickets:' . $newId, 'DRAFT', 'PENDING' . ($isLate ? ' (LATE)' : ''));
            NotificationService::notifyRole($pdo, 2, 'status_change', $newId,
                'New trip request ' . $rtt . ' submitted by ' . htmlspecialchars((string) $u['full_name']));

            // Pre-compute both route legs at submission so the requester sees the
            // origin → destination route immediately. Google failure falls back
            // to a straight-line estimate (route_source='offline').
            foreach ([RouteService::LEG_OUTBOUND, RouteService::LEG_RETURN] as $leg) {
                try {
                    RouteService::computeLeg($pdo, $newId, $leg, null, null, true);
                } catch (Throwable $e) {
                    error_log('[api/trips] submit route ' . $leg . ': ' . $e->getMessage());
                }
            }
            out(true, 'Submitted for approval.', ['ticket_id' => $newId, 'dtt_number' => $rtt, 'is_late' => (bool) $isLate]);
        }

        case 'erase_draft': {
            $u   = Auth::requireApi($pdo, [6]);
            $did = (int) ($in['draft_id'] ?? 0);
            $d   = $did > 0 ? $loadDraft($did) : null;
            if ($d === null || (int) $d['created_by'] !== $u['id']) {
                out(false, 'Not your draft.');
            }
            $unlinkAttachment($d['attachment_path'] ?? null);
            $pdo->prepare('DELETE FROM trip_draft_passengers WHERE draft_id = ?')->execute([$did]);
            $pdo->prepare('DELETE FROM trip_drafts WHERE draft_id = ?')->execute([$did]);
            out(true, 'Draft erased.');
        }

        case 'cancel': {
            $u      = Auth::requireApi($pdo, [6]);
            $t      = $loadTicket($tid);
            $reason = trim((string) ($in['reason'] ?? ''));
            $err    = TripState::can($t, TripState::CANCELLED, [
                'user_id' => $u['id'], 'role_id' => $u['role_id'], 'has_reason' => $reason !== '',
            ]);
            if ($err !== true) {
                out(false, $err);
            }
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare('UPDATE trip_tickets SET status_id = ?, reason = ?, cancelled_by = ?, cancelled_at = NOW(), updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $upd->execute([TripState::CANCELLED, $reason, $u['id'], $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Ticket was already updated.');
            }
            Audit::log($pdo, 'CANCEL', 'trip_tickets:' . $tid,
                TripState::name((int) $t['status_id']), 'CANCELLED — ' . $reason);
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'status_change', $tid,
                'Your trip ' . htmlspecialchars((string) $t['dtt_number']) . ' has been cancelled.');
            out(true, 'Ticket cancelled.');
        }

        case 'approve': {
            $u   = Auth::requireApi($pdo, [2]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::APPROVED, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare('UPDATE trip_tickets SET status_id = ?, approver_id = ?, approved_at = NOW(), updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $upd->execute([TripState::APPROVED, $u['id'], $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Request was already updated.');
            }
            Audit::log($pdo, 'APPROVE', 'trip_tickets:' . $tid,
                TripState::name((int) $t['status_id']), 'APPROVED');
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'status_change', $tid,
                'Your trip request ' . htmlspecialchars((string) $t['dtt_number']) . ' has been approved.');
            NotificationService::notifyRole($pdo, 3, 'status_change', $tid,
                'Trip ' . htmlspecialchars((string) $t['dtt_number']) . ' approved — ready for dispatch.');

            // Traffic-optimized route display is unlocked once the SAO approves.
            // Best-effort recompute both legs now so ETA/delay are available.
            foreach ([RouteService::LEG_OUTBOUND, RouteService::LEG_RETURN] as $leg) {
                try {
                    RouteService::computeLeg($pdo, $tid, $leg, null, null, true);
                } catch (Throwable $e) {
                    error_log('[api/trips] approve route ' . $leg . ': ' . $e->getMessage());
                }
            }
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
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare('UPDATE trip_tickets SET status_id = ?, reason = ?, approver_id = ?, updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $upd->execute([TripState::REJECTED, $reason, $u['id'], $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Request was already updated.');
            }
            Audit::log($pdo, 'REJECT', 'trip_tickets:' . $tid,
                TripState::name((int) $t['status_id']), 'REJECTED — ' . $reason);
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'status_change', $tid,
                'Your trip request ' . htmlspecialchars((string) $t['dtt_number']) . ' has been rejected. Reason: ' . htmlspecialchars($reason));
            out(true, 'Request rejected.');
        }

        case 'assign': {
            $u   = Auth::requireApi($pdo, [3]);
            $t   = $loadTicket($tid);

            $vid = (int) ($in['vehicle_id'] ?? 0);
            $did = (int) ($in['driver_id'] ?? 0);
            if (!$vid || !$did) {
                out(false, 'Select both a vehicle and a driver.');
            }
            // The assignment is applied later in this same request, so declare
            // it to TripState::can() — the ticket row is still unassigned here.
            $err = TripState::can($t, TripState::DISPATCHED, [
                'user_id' => $u['id'], 'role_id' => $u['role_id'],
                'assignment_complete' => true,
            ]);
            if ($err !== true) {
                out(false, $err);
            }

            $dep = $toDt((string) ($in['scheduled_departure'] ?? '')) ?? $t['scheduled_departure'];
            $ret = $toDt((string) ($in['scheduled_return'] ?? '')) ?? $t['scheduled_return'];
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

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM gps_devices WHERE vehicle_id = ? AND is_active = 1');
            $stmt->execute([$vid]);
            $paired   = (int) $stmt->fetchColumn() > 0;
            $override = trim((string) ($in['gps_override_reason'] ?? ''));
            if (!$paired && $override === '') {
                out(false, 'Selected vehicle has no paired active GPS device. Enter an override reason to dispatch without telemetry.', ['needs_override' => true]);
            }

            // Serialize concurrent assignments of the same vehicle/driver, then
            // re-check conflicts and apply an atomic conditional dispatch.
            $from = (int) $t['status_id'];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('SELECT vehicle_id FROM vehicle WHERE vehicle_id = ? FOR UPDATE')->execute([$vid]);
                $pdo->prepare('SELECT account_id FROM account WHERE account_id = ? FOR UPDATE')->execute([$did]);

                $cv = ConflictChecker::vehicle($pdo, $vid, (string) $dep, (string) $ret, $tid);
                if ($cv) {
                    $pdo->rollBack();
                    out(false, ConflictChecker::describe($cv, 'Vehicle ' . $vehicle['plate_number']));
                }
                $cd = ConflictChecker::driver($pdo, $did, (string) $dep, (string) $ret, $tid);
                if ($cd) {
                    $pdo->rollBack();
                    out(false, ConflictChecker::describe($cd, 'Driver ' . $driver['full_name']));
                }

                $upd = $pdo->prepare('UPDATE trip_tickets SET vehicle_id = ?, driver_id = ?, scheduled_departure = ?, scheduled_return = ?,
                        status_id = ?, assigned_by = ?, assigned_at = NOW(), updated_by = ?,
                        dispatched_without_gps = ?
                        WHERE ticket_id = ? AND status_id = ?');
                $upd->execute([
                    $vid, $did, $dep, $ret, TripState::DISPATCHED, $u['id'], $u['id'],
                    $paired ? 0 : 1, $tid, $from,
                ]);
                if ($upd->rowCount() === 0) {
                    $pdo->rollBack();
                    out(true, 'This request was already dispatched.');
                }

                if (!$paired) {
                    $pdo->prepare('INSERT INTO trip_flags (ticket_id, flag_type, detail) VALUES (?, ?, ?)')
                        ->execute([$tid, 'NO_TELEMETRY', 'Dispatched without GPS: ' . $override]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if (!$paired) {
                Audit::log($pdo, 'DISPATCH_WITHOUT_GPS_OVERRIDE', 'trip_tickets:' . $tid, null, $override, 'OVERRIDE');
            }
            $after = json_encode([
                'vehicle' => $vehicle['plate_number'], 'driver' => $driver['full_name'],
                'departure' => $dep, 'return' => $ret, 'gps_paired' => (bool) $paired,
            ]);
            Audit::log($pdo, 'ASSIGN', 'trip_tickets:' . $tid, 'APPROVED', $after);
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'assignment', $tid,
                'Your trip ' . htmlspecialchars((string) $t['dtt_number']) . ' has been dispatched. Vehicle: ' . htmlspecialchars($vehicle['plate_number']) . ', Driver: ' . htmlspecialchars($driver['full_name']));
            NotificationService::notifyUser($pdo, $did, 'assignment', $tid,
                'You have been assigned to trip ' . htmlspecialchars((string) $t['dtt_number']) . '. Departure: ' . htmlspecialchars($dep));

            // Pre-compute the traffic-aware outbound route at dispatch so the
            // dispatcher/driver see it before departure (best-effort).
            try {
                RouteService::computeLeg($pdo, $tid, RouteService::LEG_OUTBOUND);
            } catch (Throwable $e) {
                error_log('[api/trips] outbound route on assign: ' . $e->getMessage());
            }
            out(true, 'Assigned.');
        }

        case 'start_trip': {
            $u   = Auth::requireApi($pdo, [4]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::ON_GOING, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            $odoStart = $num($in['odometer_start'] ?? null);
            if ($odoStart === null || $odoStart < 0) {
                out(false, 'A valid odometer start reading is required.');
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare('UPDATE trip_tickets SET status_id = ?, actual_departure_at = NOW(), odometer_start = ?, departure_lat = ?, departure_lon = ?, updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $upd->execute([TripState::ON_GOING, $odoStart, $lat, $lon, $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Trip was already started.');
            }
            GpsIngest::raiseGeofenceFlag($pdo, $tid, (int) $t['vehicle_id'], 'start');
            Audit::log($pdo, 'TRIP_START', 'trip_tickets:' . $tid, 'DISPATCHED', 'ON_GOING');
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'status_change', $tid,
                'Your trip ' . htmlspecialchars((string) $t['dtt_number']) . ' has started. Safe travels!');

            // Best-effort traffic-aware outbound route (origin → destination).
            try {
                $routeSummary = RouteService::computeLeg($pdo, $tid, RouteService::LEG_OUTBOUND);
            } catch (Throwable $e) {
                error_log('[api/trips] outbound route: ' . $e->getMessage());
                $routeSummary = null;
            }

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
            $extra = ['late' => $lateInfo, 'route' => $routeSummary ?? null];
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
            $upd = $pdo->prepare('UPDATE trip_tickets SET dest_arrival_at = NOW(), dest_arrival_lat = ?, dest_arrival_lon = ?, updated_by = ? WHERE ticket_id = ? AND dest_arrival_at IS NULL');
            $upd->execute([$lat, $lon, $u['id'], $tid]);
            if ($upd->rowCount() === 0) {
                out(true, 'Arrival was already recorded.');
            }
            Audit::log($pdo, 'TRIP_ARRIVE', 'trip_tickets:' . $tid, null, 'ON_GOING');

            // Return leg begins: fresh traffic-aware route destination → origin.
            try {
                RouteService::computeLeg($pdo, $tid, RouteService::LEG_RETURN);
            } catch (Throwable $e) {
                error_log('[api/trips] return route: ' . $e->getMessage());
            }
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
            if (empty($t['dest_arrival_at'])) {
                out(false, 'Record arrival at the destination first.');
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $upd = $pdo->prepare('UPDATE trip_tickets SET dest_departure_at = NOW(), dest_departure_lat = ?, dest_departure_lon = ?, updated_by = ? WHERE ticket_id = ? AND dest_departure_at IS NULL');
            $upd->execute([$lat, $lon, $u['id'], $tid]);
            if ($upd->rowCount() === 0) {
                out(true, 'Departure from destination was already recorded.');
            }
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
            if (empty($t['dest_departure_at'])) {
                out(false, 'Record departure from the destination (Start Return) first.');
            }
            if (!empty($t['return_at'])) {
                out(true, 'Return to base was already recorded.');
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $upd = $pdo->prepare('UPDATE trip_tickets SET return_at = NOW(), return_lat = ?, return_lon = ?, updated_by = ? WHERE ticket_id = ? AND return_at IS NULL');
            $upd->execute([$lat, $lon, $u['id'], $tid]);
            if ($upd->rowCount() === 0) {
                out(true, 'Return to base was already recorded.');
            }
            GpsIngest::raiseGeofenceFlag($pdo, $tid, (int) $t['vehicle_id'], 'complete');
            Audit::log($pdo, 'TRIP_RETURN', 'trip_tickets:' . $tid, null, 'ON_GOING');
            out(true, 'Trip complete. Please fill in the post-trip report.', ['ticket_id' => $tid]);
        }

        case 'stop_trip': {
            $u      = Auth::requireApi($pdo, [4]);
            $t      = $loadTicket($tid);
            $reason = trim((string) ($in['reason'] ?? ''));
            $err    = TripState::canStop($t, [
                'user_id' => $u['id'], 'role_id' => $u['role_id'], 'has_reason' => $reason !== '',
            ]);
            if ($err !== true) {
                out(false, $err);
            }
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare('UPDATE trip_tickets SET status_id = ?, reason = ?, cancelled_by = ?, cancelled_at = NOW(), updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $upd->execute([TripState::CANCELLED, $reason, $u['id'], $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Trip was already stopped.');
            }
            Audit::log($pdo, 'TRIP_STOP', 'trip_tickets:' . $tid, TripState::name($from), 'CANCELLED — ' . $reason);
            $pdo->prepare(
                'INSERT INTO trip_flags (ticket_id, flag_type, detail)
                 SELECT ?, ?, ? FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM trip_flags WHERE ticket_id = ? AND flag_type = ? AND resolved_at IS NULL)'
            )->execute([$tid, 'NEEDS_REVIEW', 'Driver stopped the trip: ' . $reason, $tid, 'NEEDS_REVIEW']);
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'status_change', $tid,
                'Your trip ' . htmlspecialchars((string) $t['dtt_number']) . ' was stopped by the driver. Reason: ' . htmlspecialchars($reason));
            out(true, 'Trip stopped.');
        }

        case 'correct_sub_event': {
            $u = Auth::requireApi($pdo, [4]);
            $t = $loadTicket($tid);
            if ((int) $t['status_id'] !== TripState::ON_GOING) {
                out(false, 'Trip is not currently in progress.');
            }
            if ((int) $t['driver_id'] !== $u['id']) {
                out(false, 'You are not the assigned driver of this trip.');
            }
            if ($t['odometer_end'] !== null) {
                out(false, 'The post-trip report is already submitted; correction is no longer available.');
            }
            $reason = trim((string) ($in['reason'] ?? ''));
            if ($reason === '') {
                out(false, 'A reason is required.');
            }
            if (!empty($t['dest_departure_at'])) {
                $pdo->prepare('UPDATE trip_tickets SET dest_departure_at = NULL, dest_departure_lat = NULL, dest_departure_lon = NULL, updated_by = ? WHERE ticket_id = ?')
                    ->execute([$u['id'], $tid]);
                $cleared = 'Start Return';
            } elseif (!empty($t['dest_arrival_at'])) {
                $pdo->prepare('UPDATE trip_tickets SET dest_arrival_at = NULL, dest_arrival_lat = NULL, dest_arrival_lon = NULL, updated_by = ? WHERE ticket_id = ?')
                    ->execute([$u['id'], $tid]);
                $cleared = 'Destination Reached';
            } else {
                out(false, 'There is no recorded step to correct.');
            }
            Audit::log($pdo, 'SUBEVENT_CORRECT', 'trip_tickets:' . $tid, $cleared, 'cleared — ' . $reason);
            $pdo->prepare(
                'INSERT INTO trip_flags (ticket_id, flag_type, detail)
                 SELECT ?, ?, ? FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM trip_flags WHERE ticket_id = ? AND flag_type = ? AND resolved_at IS NULL)'
            )->execute([$tid, 'NEEDS_REVIEW', 'Driver corrected a recorded step (' . $cleared . '): ' . $reason, $tid, 'NEEDS_REVIEW']);
            out(true, 'Last recorded step corrected.');
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
            if (empty($t['dest_departure_at'])) {
                out(false, 'Record departure from the destination (Start Return) first.');
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

            // The report completes the trip: stamp the return, set COMPLETED with
            // the completion side effects, and persist the recorded track.
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $gpsKm = null;
            $crumbs = $pdo->prepare('SELECT latitude, longitude FROM gps_positions WHERE ticket_id = ? ORDER BY recorded_at, position_id');
            $crumbs->execute([$tid]);
            $pts = $crumbs->fetchAll();
            if (count($pts) >= 2) {
                $gpsKm = round(Geo::pathKm($pts), 1);
            }
            $updDone = $pdo->prepare('UPDATE trip_tickets SET status_id = ?, certified_by = ?, certified_at = NOW(), gps_distance_km = ?,
                    return_at = COALESCE(return_at, NOW()), return_lat = COALESCE(return_lat, ?), return_lon = COALESCE(return_lon, ?),
                    updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $updDone->execute([TripState::COMPLETED, $u['id'], $gpsKm, $lat, $lon, $u['id'], $tid, TripState::ON_GOING]);
            if ($updDone->rowCount() === 0) {
                out(true, 'Post-trip report submitted.');
            }
            GpsIngest::raiseGeofenceFlag($pdo, $tid, (int) $t['vehicle_id'], 'complete');
            Audit::log($pdo, 'TRIP_COMPLETE', 'trip_tickets:' . $tid, 'ON_GOING', 'COMPLETED');
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'status_change', $tid,
                'Your trip ' . htmlspecialchars((string) $t['dtt_number']) . ' has been completed.');
            try {
                RoadsService::storeRecordedTrack($pdo, $tid);
            } catch (Throwable $e) {
                error_log('[api/trips] track snap: ' . $e->getMessage());
            }
            out(true, 'Trip completed. Post-trip report submitted.');
        }

        case 'complete_trip': {
            $u   = Auth::requireApi($pdo, [4]);
            $t   = $loadTicket($tid);
            if (empty($t['dest_arrival_at'])) {
                out(false, 'Record arrival at the destination first.');
            }
            if (empty($t['dest_departure_at'])) {
                out(false, 'Record departure from the destination (Start Return) first.');
            }
            if ($t['odometer_end'] === null) {
                out(false, 'Submit the post-trip report first.');
            }
            $err = TripState::can($t, TripState::COMPLETED, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            [$lat, $lon] = $latestFix((int) $t['vehicle_id']);
            $gpsKm = null;
            $crumbs = $pdo->prepare('SELECT latitude, longitude FROM gps_positions WHERE ticket_id = ? ORDER BY recorded_at, position_id');
            $crumbs->execute([$tid]);
            $pts = $crumbs->fetchAll();
            if (count($pts) >= 2) {
                $gpsKm = round(Geo::pathKm($pts), 1);
            }
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare('UPDATE trip_tickets SET status_id = ?, certified_by = ?, certified_at = NOW(), gps_distance_km = ?,
                    return_at = COALESCE(return_at, NOW()), return_lat = COALESCE(return_lat, ?), return_lon = COALESCE(return_lon, ?),
                    updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $upd->execute([TripState::COMPLETED, $u['id'], $gpsKm, $lat, $lon, $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Trip was already completed.');
            }
            Audit::log($pdo, 'TRIP_COMPLETE', 'trip_tickets:' . $tid, 'ON_GOING', 'COMPLETED');
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'status_change', $tid,
                'Your trip ' . htmlspecialchars((string) $t['dtt_number']) . ' has been completed.');

            // Persist the recorded route (road-snapped) per leg; never block completion.
            try {
                RoadsService::storeRecordedTrack($pdo, $tid);
            } catch (Throwable $e) {
                error_log('[api/trips] track snap: ' . $e->getMessage());
            }
            out(true, 'Trip completed.');
        }

        case 'reschedule': {
            $u      = Auth::requireApi($pdo, [3]);
            $t      = $loadTicket($tid);
            $reason = trim((string) ($in['reason'] ?? ''));
            $err    = TripState::can($t, TripState::RESCHEDULED, [
                'user_id' => $u['id'], 'role_id' => $u['role_id'], 'has_reason' => $reason !== '',
            ]);
            if ($err !== true) {
                out(false, $err);
            }
            $old = $t['scheduled_departure'] . ' → ' . $t['scheduled_return'];
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare(
                'UPDATE trip_tickets SET status_id = ?, reason = ?,
                 vehicle_id = NULL, driver_id = NULL, dispatched_without_gps = 0,
                 updated_by = ? WHERE ticket_id = ? AND status_id = ?'
            );
            $upd->execute([TripState::RESCHEDULED, $reason, $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Trip was already updated.');
            }
            Audit::log($pdo, 'RESCHEDULE', 'trip_tickets:' . $tid, $old, 'RESCHEDULED — ' . $reason);
            NotificationService::notifyUser($pdo, (int) $t['created_by'], 'reschedule', $tid,
                'Your trip ' . htmlspecialchars((string) $t['dtt_number']) . ' has been rescheduled. Please update your schedule.');
            out(true, 'Trip rescheduled. The requester will pick a new schedule and it will be re-approved.');
        }

        case 'resubmit_rescheduled': {
            $u   = Auth::requireApi($pdo, [6]);
            $t   = $loadTicket($tid);
            $err = TripState::can($t, TripState::PENDING, ['user_id' => $u['id'], 'role_id' => $u['role_id']]);
            if ($err !== true) {
                out(false, $err);
            }
            $dep = $toDt((string) ($in['scheduled_departure'] ?? ''));
            $ret = $toDt((string) ($in['scheduled_return'] ?? ''));
            if (!$dep || !$ret || strtotime($ret) <= strtotime($dep)) {
                out(false, 'A valid new departure/return window is required.');
            }
            if (strtotime($dep) <= time()) {
                out(false, 'Scheduled departure must be in the future.');
            }
            $lateDays = max(1, (int) Settings::getInt('LATE_ADVANCE_DAYS', 3));
            $isLate = (strtotime($dep) - time()) < $lateDays * 86400 ? 1 : 0;
            $from = (int) $t['status_id'];
            $upd = $pdo->prepare('UPDATE trip_tickets SET scheduled_departure = ?, scheduled_return = ?, status_id = ?, is_late = ?, updated_by = ? WHERE ticket_id = ? AND status_id = ?');
            $upd->execute([$dep, $ret, TripState::PENDING, $isLate, $u['id'], $tid, $from]);
            if ($upd->rowCount() === 0) {
                out(true, 'Trip was already resubmitted.');
            }
            Audit::log($pdo, 'RESUBMIT_RESCHEDULED', 'trip_tickets:' . $tid, 'RESCHEDULED', 'PENDING — ' . $dep . ' → ' . $ret);
            NotificationService::notifyRole($pdo, 2, 'status_change', $tid,
                'Trip ' . htmlspecialchars((string) $t['dtt_number']) . ' resubmitted with new schedule: ' . htmlspecialchars($dep) . ' → ' . htmlspecialchars($ret));
            out(true, 'New schedule submitted for approval.');
        }

        case 'refresh_route': {
            $u = Auth::requireApi($pdo, [1, 3, 4]);
            $t = $loadTicket($tid);
            if ((int) $t['status_id'] !== TripState::ON_GOING) {
                out(false, 'Route refresh is only available while the trip is ongoing.');
            }
            if ($u['role_id'] === 4 && (int) $t['driver_id'] !== $u['id']) {
                out(false, 'You are not the assigned driver of this trip.');
            }
            $leg     = RouteService::activeLeg($t);
            $fix     = RouteService::latestFix($pdo, (int) $t['vehicle_id']);
            $summary = RouteService::computeLeg($pdo, $tid, $leg, $fix);
            if (!$summary) {
                out(false, 'Could not refresh the route right now. Check that routing is configured and the vehicle has a GPS fix.');
            }
            if (!empty($summary['cached'])) {
                out(true, 'Route is up to date — no refresh needed.', $summary);
            }
            Audit::log($pdo, 'ROUTE_REFRESH', 'trip_tickets:' . $tid, null,
                $leg . ' duration=' . $summary['duration_sec'] . 's delay=' . $summary['traffic_delay_sec'] . 's');
            out(true, 'Route refreshed.', $summary);
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
            $ins = $pdo->prepare(
                'INSERT INTO guard_verifications
                 (ticket_id, guard_id, phase, result, checked_driver, checked_vehicle, checked_plate, checked_passengers, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE verification_id = verification_id'
            );
            $ins->execute([
                $tid, $u['id'], $phase, $result,
                $cb($in['checked_driver'] ?? 0), $cb($in['checked_vehicle'] ?? 0),
                $cb($in['checked_plate'] ?? 0), $cb($in['checked_passengers'] ?? 0),
                mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null,
            ]);
            if ($ins->rowCount() === 0) {
                out(true, 'Verification already recorded for this phase.');
            }
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
            $st = $pdo->prepare('SELECT ticket_id FROM trip_tickets WHERE ticket_id = ? AND created_by = ? AND is_active = 1 AND status_id = ?');
            $st->execute([$tid, $u['id'], TripState::COMPLETED]);
            if (!$st->fetch()) {
                out(false, 'Feedback is only available on your completed trip tickets.');
            }
            $has = $pdo->prepare('SELECT 1 FROM feedback WHERE ticket_id = ? AND is_active = 1 LIMIT 1');
            $has->execute([$tid]);
            if ($has->fetchColumn()) {
                out(true, 'Feedback was already submitted for this trip.');
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
    error_log('[api/trips] ' . ($action !== '' ? $action . ': ' : '') . get_class($e) . ': ' . $e->getMessage());
    out(false, APP_ENV === 'dev'
        ? 'An internal error occurred: ' . $e->getMessage()
        : 'An internal error occurred. Please try again.');
}
