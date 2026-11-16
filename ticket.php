<?php
declare(strict_types=1);
/**
 * ticket.php — shared read-only trip ticket view + role/status action bar.
 */
require_once __DIR__ . '/config/config.php';
$USER = Auth::requirePage($pdo, [1, 2, 3, 4, 5, 6]);

$tid = (int) ($_GET['id'] ?? 0);
$st  = $pdo->prepare(
    'SELECT t.*, r.status_name,
            c.full_name AS creator_name,
            d.full_name AS driver_name,
            v.plate_number, v.model_name, v.brand,
            a.full_name AS approver_name,
            s.full_name AS staff_name,
            ct.full_name AS certifier_name
     FROM trip_tickets t
     JOIN request_status r ON r.status_id = t.status_id
     LEFT JOIN account c ON c.account_id = t.created_by
     LEFT JOIN account d ON d.account_id = t.driver_id
     LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
      LEFT JOIN account a ON a.account_id = t.approver_id
      LEFT JOIN account s ON s.account_id = t.assigned_by
      LEFT JOIN account ct ON ct.account_id = t.certified_by
     WHERE t.ticket_id = ? AND t.is_active = 1'
);
$st->execute([$tid]);
$t = $st->fetch();

$PAGE_TITLE = 'Trip Ticket';
if (!$t) {
    http_response_code(404);
    require_once __DIR__ . '/partials/layout_head.php';
    require_once __DIR__ . '/partials/shell_open.php';
    echo '<div class="content-card p-3.5">Ticket not found.</div>';
    require_once __DIR__ . '/partials/layout_foot.php';
    exit;
}
if (($USER['role_id'] === 6 && (int) $t['created_by'] !== $USER['id'])
    || ($USER['role_id'] === 4 && (int) $t['driver_id'] !== $USER['id'])) {
    http_response_code(403);
    echo '<div class="content-card p-3.5" style="margin:20px">You are not authorized to view this ticket.</div>';
    exit;
}

$flags = $pdo->prepare('SELECT flag_type, detail, raised_at FROM trip_flags WHERE ticket_id = ? AND resolved_at IS NULL ORDER BY raised_at');
$flags->execute([$tid]);
$flags = $flags->fetchAll();

/* ── Traffic-aware route legs (Google Routes API snapshots) ── */
$routeOutbound = RouteService::snapshot($pdo, $tid, RouteService::LEG_OUTBOUND);
$routeReturn   = RouteService::snapshot($pdo, $tid, RouteService::LEG_RETURN);

$canRefreshRoute = (int) $t['status_id'] === TripState::ON_GOING
    && in_array($USER['role_id'], [1, 3, 4], true)
    && ($USER['role_id'] !== 4 || (int) $t['driver_id'] === $USER['id']);

$pax = $pdo->prepare('SELECT passenger_name FROM trip_passengers WHERE ticket_id = ? ORDER BY passenger_id');
$pax->execute([$tid]);
$pax = $pax->fetchAll(PDO::FETCH_COLUMN);

$gv = $pdo->prepare('SELECT g.phase, g.result, g.notes, g.verified_at, a.full_name AS guard_name
                     FROM guard_verifications g LEFT JOIN account a ON a.account_id = g.guard_id
                     WHERE g.ticket_id = ? ORDER BY g.verified_at DESC');
$gv->execute([$tid]);
$gv = $gv->fetchAll();

$fmt   = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
$orDash = static fn($v): string => ($v !== null && $v !== '') ? htmlspecialchars((string) $v) : '—';

$ctx        = ['user_id' => $USER['id'], 'role_id' => $USER['role_id']];
$canApprove = TripState::can($t, TripState::APPROVED, $ctx) === true;
$canReject  = TripState::can($t, TripState::REJECTED, $ctx + ['has_reason' => true]) === true;
$canAssign  = TripState::can($t, TripState::DISPATCHED, $ctx) === true;
    $canStart   = TripState::can($t, TripState::ON_GOING, $ctx) === true;
    $isOnGoing  = (int) $t['status_id'] === TripState::ON_GOING;
    $reportDone = $t['odometer_end'] !== null;
    $canStop    = TripState::canStop($t, $ctx + ['has_reason' => true]) === true;
    $canCorrect = $isOnGoing && !$reportDone && !empty($t['dest_arrival_at']);
    $canResched = TripState::can($t, TripState::RESCHEDULED, $ctx + ['has_reason' => true]) === true;
$canCancel  = TripState::can($t, TripState::CANCELLED, $ctx + ['has_reason' => (int) $t['status_id'] !== TripState::DRAFT]) === true;
$toLocal = static fn(?string $d): string => $d ? substr(str_replace(' ', 'T', (string) $d), 0, 16) : '';
$coord   = static fn(?string $lat, ?string $lon): string => ($lat !== null && $lon !== null)
    ? number_format((float) $lat, 5) . ', ' . number_format((float) $lon, 5) : '';

$labelCls = 'form-label';
$row = static function (string $label, string $value): string {
    return '<div class="field-cell"><div class="text-xs font-bold uppercase tracking-wide text-text-muted mb-0.5">' . $label . '</div>'
        . '<div class="font-semibold">' . $value . '</div></div>';
};

$isLateSubmission = (int) ($t['is_late'] ?? 0) === 1;
$lateDays = max(1, (int) Settings::getInt('LATE_ADVANCE_DAYS', 3));
$lateTitle = 'Submitted with departure within ' . $lateDays . ' days of submission';

$btnPrimary = 'btn btn-primary';
$btnGhost = 'btn btn-ghost';
$btnDanger = 'btn btn-danger';
$inputCls = 'form-input';

$hasOrigin = (float) Settings::getFloat('NAAP_ORIGIN_LAT') !== 0.0 || (float) Settings::getFloat('NAAP_ORIGIN_LNG') !== 0.0;
$hasDest   = (float) ($t['place_to_visit_lat'] ?: 0) !== 0.0 || (float) ($t['place_to_visit_lon'] ?: 0) !== 0.0;
if ($hasOrigin || $hasDest) {
    $VENDOR_SCRIPTS = ['maps_loader.js'];
    $HEAD_EXTRAS =
        '<link rel="preconnect" href="https://maps.googleapis.com" crossorigin>' .
        '<link rel="preconnect" href="https://maps.gstatic.com" crossorigin>' .
        '<script>window.NAAP_MAPS_KEY=' . json_encode(GOOGLE_MAPS_API_KEY) . ';</script>';
}

require_once __DIR__ . '/partials/layout_head.php';
require_once __DIR__ . '/partials/shell_open.php';
?>
<div class="flex items-center gap-3 mb-3.5">
  <a class="<?= $btnGhost ?>" href="javascript:history.back()">← Back</a>
</div>

<div class="ticket-grid grid grid-cols-1 xl:grid-cols-5 gap-3.5 items-start">
<div class="content-card xl:col-span-5">
  <div class="card-head flex-wrap gap-2">
    <div class="flex items-center gap-3 flex-wrap">
      <h2 class="text-lg"><?= htmlspecialchars((string) ($t['dtt_number'] ?: ('#' . (int) $t['ticket_id']))) ?></h2>
      <span class="badge badge-<?= strtolower(str_replace('_', '-', $t['status_name'])) ?>"><?= htmlspecialchars($t['status_name']) ?></span>
      <?php if ((int) $t['dispatched_without_gps'] === 1): ?>
        <span class="badge badge-rejected" title="Dispatched without GPS telemetry">NO GPS</span>
      <?php endif; ?>
      <?php if ($isLateSubmission): ?>
        <span class="badge badge-late" title="<?= htmlspecialchars($lateTitle) ?>">LATE SUBMISSION</span>
      <?php endif; ?>
    </div>
    <div class="flex gap-2 flex-wrap" id="actionBar">
      <?php if ($canApprove): ?><button class="<?= $btnPrimary ?>" id="btnApprove">Approve</button><?php endif; ?>
      <?php if ($canReject): ?><button class="<?= $btnDanger ?>" id="btnRejectOpen">Reject</button><?php endif; ?>
      <?php if ($canAssign): ?><a class="<?= $btnPrimary ?>" href="<?= BASE_URL ?>staff/assignments.php?ticket_id=<?= (int) $t['ticket_id'] ?>">Assign Driver &amp; Vehicle</a><?php endif; ?>
      <?php if ($canStart): ?><button class="<?= $btnPrimary ?>" id="btnStart">Start Trip</button><?php endif; ?>
      <?php if ($isOnGoing): ?>
        <?php if (empty($t['dest_arrival_at'])): ?>
          <button class="<?= $btnPrimary ?>" id="btnArrive">Destination Reached</button>
        <?php elseif (empty($t['dest_departure_at'])): ?>
          <button class="<?= $btnPrimary ?>" id="btnLeave">Start Return</button>
        <?php elseif (empty($t['return_at'])): ?>
          <button class="<?= $btnPrimary ?>" id="btnTripComplete">Trip Complete</button>
        <?php elseif (!$reportDone): ?>
          <a class="<?= $btnPrimary ?>" href="<?= BASE_URL ?>driver/posttrip.php?id=<?= (int) $t['ticket_id'] ?>">Post-Trip Report</a>
        <?php else: ?>
          <button class="<?= $btnPrimary ?>" id="btnComplete">Complete Trip Ticket</button>
        <?php endif; ?>
        <?php if ($canCorrect): ?><button class="<?= $btnGhost ?>" id="btnCorrectOpen">Correct Last Step</button><?php endif; ?>
        <?php if ($canStop): ?><button class="<?= $btnDanger ?>" id="btnStopOpen">Stop Trip</button><?php endif; ?>
      <?php endif; ?>
      <?php if ($canResched): ?><button class="<?= $btnGhost ?>" id="btnReschedOpen">Reschedule</button><?php endif; ?>
      <?php if ($canCancel): ?><button class="<?= $btnDanger ?>" id="btnCancelOpen" data-status="<?= (int) $t['status_id'] ?>">Cancel Ticket</button><?php endif; ?>
    </div>
  </div>
</div>

<div class="xl:col-span-3">
<div class="content-card mb-3.5">
  <div class="card-head"><span>Section A — Travel Order</span></div>
  <div class="p-3.5">
  <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5 mb-4">
    <?= $row('First Name', $orDash($t['passenger_name'])) ?>
    <?= $row('M.I.', $orDash($t['middle_initial'])) ?>
    <?= $row('Middle Initial', $orDash($t['middle_name'] ?? null)) ?>
    <?= $row('Surname', $orDash($t['surname'])) ?>
    <?= $row('Suffix', $orDash($t['suffix'])) ?>
    <?= $row('ID Number', $orDash($t['id_number'])) ?>
    <?= $row('Type', $orDash($t['requester_type'])) ?>
    <?= $row('Email', $orDash($t['email'])) ?>
    <?= $row('Passengers', (string) (int) $t['number_of_passengers']) ?>
    <?= $row('Request Letter', $t['attachment_path']
        ? '<button class="text-accent-strong font-semibold underline cursor-pointer" type="button" onclick="UI.modal(\'fileModal\', true)">View file</button>'
        : '—') ?>
    <div class="span-full"><?= $row('Place to Visit', nl2br(htmlspecialchars((string) ($t['place_to_visit'] ?? ''))) ?: '—') ?></div>
    <div class="span-full"><?= $row('Purpose of Travel', nl2br(htmlspecialchars((string) ($t['purpose'] ?? ''))) ?: '—') ?></div>
    <?= $row('Scheduled Departure', $fmt($t['scheduled_departure'])) ?>
    <?= $row('Scheduled Return', $fmt($t['scheduled_return'])) ?>
    <?= $row('Requested By', $orDash($t['creator_name'])) ?>
    <?= $row('Submitted At', $fmt($t['submitted_at'])) ?>
  </div>

  <?php if ($pax): ?>
  <div class="mt-3.5">
    <div class="text-sm font-bold uppercase tracking-wide text-text-muted mb-0.5">
      Passenger Manifest (<?= count($pax) ?>)
    </div>
    <div class="text-sm"><?= htmlspecialchars(implode(', ', $pax)) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($flags): ?>
  <div class="mt-3.5">
    <h3 class="text-sm uppercase tracking-widest text-text-muted mb-2">Open Flags</h3>
    <?php foreach ($flags as $f): ?>
      <div class="flag-chip">
        <?= icon('alert') ?> <?= htmlspecialchars($f['flag_type']) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  </div>
</div>

<?php
/* Completed trip = show the recorded GPS track instead of the planned route. */
$trackedMode = $t['return_at'] !== null || (int) $t['status_id'] === TripState::COMPLETED;

$crumbs = [];
if ($t['vehicle_id']) {
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM gps_positions WHERE ticket_id = ' . (int) $t['ticket_id'])->fetchColumn();
    $stride = max(1, (int) ceil($cnt / 500));
    $sql  = 'SELECT latitude, longitude, speed_kph, recorded_at FROM gps_positions
             WHERE ticket_id = ? AND (position_id % ? = 0
                OR position_id = (SELECT MAX(position_id) FROM gps_positions WHERE ticket_id = ?))';
    $args = [(int) $t['ticket_id'], $stride, (int) $t['ticket_id']];
    if ($trackedMode && !empty($t['return_at'])) {
        $sql .= ' AND recorded_at <= ?';
        $args[] = $t['return_at'];
    }
    $sql .= ' ORDER BY recorded_at, position_id';
    $all = $pdo->prepare($sql);
    $all->execute($args);
    $destArrivalTs = !empty($t['dest_arrival_at']) ? strtotime((string) $t['dest_arrival_at']) : null;
    foreach ($all->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $leg = ($destArrivalTs !== null && strtotime((string) $r['recorded_at']) > $destArrivalTs) ? 'ret' : 'out';
        $crumbs[] = [
            (float) $r['latitude'],
            (float) $r['longitude'],
            $r['speed_kph'] !== null ? round((float) $r['speed_kph'], 1) : null,
            $r['recorded_at'],
            $leg,
        ];
    }
}

/* Recorded (road-snapped) route per leg, written at completion. */
$tracks = [];
$trSt = $pdo->prepare('SELECT leg, polyline FROM trip_tracks WHERE ticket_id = ?');
$trSt->execute([(int) $t['ticket_id']]);
foreach ($trSt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
    $tracks[$tr['leg']] = (string) $tr['polyline'];
}
?>
<?php
$trafficVisible = (int) $t['status_id'] >= TripState::APPROVED;
$fmtDur = static function (int $s): string {
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    return ($m > 0 ? $m . 'm' : '&lt;1m');
};
$routeEtaBlock = static function (array $r, string $label) use ($fmtDur, $fmt, $trafficVisible, $lateDays): string {
    $dist    = number_format((float) $r['distance_m'] / 1000, 1) . ' km';
    $offline = RouteService::isOffline($r);
    $title   = '<div class="route-leg-title">' . htmlspecialchars($label) . ($offline ? ' <span class="badge badge-late">Estimated (offline)</span>' : '') . '</div>';

    $cells = '<div class="field-cell"><div class="route-eta-label">Distance</div><div>' . $dist . '</div></div>';
    $cells .= '<div class="field-cell"><div class="route-eta-label">' . ($offline ? 'Est. time' : 'Normal time') . '</div><div>' . $fmtDur((int) $r['static_duration_sec']) . '</div></div>';

    if (!$offline && $trafficVisible) {
        $dur   = (int) $r['duration_sec'];
        $stat  = (int) $r['static_duration_sec'];
        $delay = max(0, $dur - $stat);
        $delayHtml = $delay > 0
            ? '<span class="route-delay">+' . $fmtDur($delay) . '</span>'
            : '<span class="route-clear">No traffic delay</span>';
        $cells .= '<div class="field-cell"><div class="route-eta-label">ETA (traffic)</div><div>' . $fmtDur($dur) . '</div></div>';
        $cells .= '<div class="field-cell"><div class="route-eta-label">Traffic delay</div><div>' . $delayHtml . '</div></div>';
    }

    $note = '';
    if ($offline) {
        $note = 'Straight-line estimate — used because the route service was unreachable. It will refresh to a live route once available.';
    } elseif (!$trafficVisible) {
        $note = 'Traffic-optimized ETA is shown once this ticket is approved by the SAO.';
    }
    return '<div class="route-leg">'
        . $title
        . '<div class="route-eta-grid">' . $cells . '</div>'
        . ($note !== '' ? '<div class="route-meta">' . $note . '</div>' : '')
        . '<div class="route-meta">Updated ' . $fmt($r['fetched_at'] ?? null) . '</div>'
        . '</div>';
};
?>
<?php if ($hasOrigin || $hasDest): ?>
<div class="content-card mb-3.5">
  <div class="card-head">
    <span><?= $trackedMode ? 'Tracked Route' : (count($crumbs) >= 2 ? 'GPS Route' : 'Planned Route') ?></span>
    <?php if ($canRefreshRoute && !$trackedMode): ?>
      <button class="btn btn-sm btn-ghost" id="btnRefreshRoute" type="button">Refresh Route</button>
    <?php endif; ?>
  </div>
  <div class="p-3.5">
    <div id="crumbMap"
       data-crumbs="<?= htmlspecialchars(json_encode($crumbs), ENT_QUOTES) ?>"
       data-lat="<?= htmlspecialchars((string) Settings::getFloat('NAAP_GEOFENCE_LAT')) ?>"
       data-lng="<?= htmlspecialchars((string) Settings::getFloat('NAAP_GEOFENCE_LNG')) ?>"
       data-radius="<?= htmlspecialchars((string) Settings::getFloat('NAAP_GEOFENCE_RADIUS_METERS', 500.0)) ?>"
       data-origin-lat="<?= htmlspecialchars((string) Settings::getFloat('NAAP_ORIGIN_LAT')) ?>"
       data-origin-lng="<?= htmlspecialchars((string) Settings::getFloat('NAAP_ORIGIN_LNG')) ?>"
       data-origin-name="<?= htmlspecialchars((string) Settings::get('NAAP_ORIGIN_NAME', '')) ?>"
       data-dest-lat="<?= htmlspecialchars((string) (float) ($t['place_to_visit_lat'] ?: 0)) ?>"
       data-dest-lng="<?= htmlspecialchars((string) (float) ($t['place_to_visit_lon'] ?: 0)) ?>"
       data-dest-name="<?= htmlspecialchars((string) ($t['place_to_visit'] ?? '')) ?>"
       data-active-leg="<?= htmlspecialchars(RouteService::activeLeg($t)) ?>"
       data-tracked="<?= $trackedMode ? '1' : '0' ?>"
       data-live="<?= (!$trackedMode && in_array($USER['role_id'], [1, 2, 3], true) && (int) $t['status_id'] === TripState::ON_GOING && !empty($t['vehicle_id'])) ? '1' : '0' ?>"
       data-route-outbound="<?= (!$trackedMode && $routeOutbound) ? htmlspecialchars(json_encode($routeOutbound), ENT_QUOTES) : '' ?>"
       data-route-return="<?= (!$trackedMode && $routeReturn) ? htmlspecialchars(json_encode($routeReturn), ENT_QUOTES) : '' ?>"
       data-track-out="<?= htmlspecialchars($tracks['outbound'] ?? '', ENT_QUOTES) ?>"
       data-track-ret="<?= htmlspecialchars($tracks['return'] ?? '', ENT_QUOTES) ?>"></div>
    <?php if ($trackedMode): ?>
    <div class="legend">
      <span class="legend-item"><span class="legend-swatch swatch-outbound"></span>Outbound</span>
      <span class="legend-item"><span class="legend-swatch swatch-inbound"></span>Inbound</span>
    </div>
    <?php endif; ?>
    <?php if (!$trackedMode && ($routeOutbound || $routeReturn)): ?>
    <div class="route-eta">
      <?php if ($routeOutbound): ?>
        <?= $routeEtaBlock($routeOutbound, 'Outbound — to ' . mb_strimwidth((string) ($t['place_to_visit'] ?? 'destination'), 0, 42, '…')) ?>
      <?php endif; ?>
      <?php if ($routeReturn): ?>
        <?= $routeEtaBlock($routeReturn, 'Return — back to base') ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($t['vehicle_id'] && (int) $t['status_id'] === TripState::ON_GOING): ?>
<p class="text-text-muted text-xs mt-3.5">No GPS breadcrumbs received yet for this trip.</p>
<?php endif; ?>
</div>

<div class="xl:col-span-2">
<div class="content-card mb-3.5">
  <div class="card-head"><span>Approval &amp; Assignment</span></div>
  <div class="p-3.5">
    <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <?= $row('Approved By', $orDash($t['approver_name'])) ?>
    <?= $row('Approved At', $fmt($t['approved_at'])) ?>
    <?= $row('Assigned By', $orDash($t['staff_name'])) ?>
    <?= $row('Driver', $orDash($t['driver_name'])) ?>
    <?= $row('Vehicle', $t['vehicle_id'] ? htmlspecialchars(($t['plate_number'] ?? '') . ' · ' . ($t['brand'] ?? '') . ' ' . ($t['model_name'] ?? '')) : '—') ?>
    <?= $row('Assigned At', $fmt($t['assigned_at'])) ?>
    </div>

    <?php if (!empty($t['reason'])): ?>
    <div class="reason-note">
      <strong>Reason on record:</strong> <?= nl2br(htmlspecialchars($t['reason'])) ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php $hasExec = $t['actual_departure_at'] || $t['dest_arrival_at'] || $t['dest_departure_at'] || $t['return_at']; ?>
<?php if ($hasExec): ?>
<div class="content-card mb-3.5">
  <div class="card-head"><span>Execution</span></div>
  <div class="p-3.5">
    <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <?php
    $exRow = static function (string $label, ?string $ts, ?string $lat, ?string $lon) use ($row, $fmt, $coord): string {
        $c = $coord($lat, $lon);
        return $row($label, $fmt($ts) . ($c !== '' ? ' <span class="text-text-muted">· GPS ' . $c . '</span>' : ''));
    };
    echo $exRow('Actual Departure', $t['actual_departure_at'], $t['departure_lat'], $t['departure_lon']);
    echo $exRow('Arrived at Destination', $t['dest_arrival_at'], $t['dest_arrival_lat'], $t['dest_arrival_lon']);
    echo $exRow('Left Destination', $t['dest_departure_at'], $t['dest_departure_lat'], $t['dest_departure_lon']);
    echo $exRow('Returned', $t['return_at'], $t['return_lat'], $t['return_lon']);
    ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($t['odometer_start'] !== null): ?>
<div class="content-card mb-3.5">
  <div class="card-head"><span>Section B — Driver's Post-Trip Report</span></div>
  <div class="p-3.5">
    <div class="grid grid-cols-2 max-lg:grid-cols-1 gap-3 gap-x-3.5">
    <?= $row('Odometer Start', number_format((float) $t['odometer_start'], 1) . ' km') ?>
    <?= $row('Odometer End', $t['odometer_end'] !== null ? number_format((float) $t['odometer_end'], 1) . ' km' : '—') ?>
    <?= $row('Distance (official, odometer)', $t['distance_odometer'] !== null ? number_format((float) $t['distance_odometer'], 1) . ' km' : '—') ?>
    <?= $row('GPS Distance (supporting)', $t['gps_distance_km'] !== null ? number_format((float) $t['gps_distance_km'], 1) . ' km' : '—') ?>
    <?= $row('Fuel Type', $orDash($t['fuel_type'])) ?>
    <?= $row('Tank Balance at Start', $orDash($t['fuel_tank_balance_start'])) ?>
    <?= $row('Issued from Stock', $orDash($t['fuel_issued_stock'])) ?>
    <?= $row('Purchased During Trip', $orDash($t['fuel_purchased_trip'])) ?>
    <?= $row('Total Available', $orDash($t['fuel_total_available'])) ?>
    <?= $row('Fuel Used', $orDash($t['fuel_used'])) ?>
    <?= $row('Balance at End', $orDash($t['fuel_balance_end'])) ?>
    <?= $row('Gear Oil Used', $orDash($t['gear_oil_used'])) ?>
    <?= $row('Lubricant Oil Issued', $orDash($t['lubricant_oil_issued'])) ?>
    <?= $row('Grease Issued', $orDash($t['grease_issued'])) ?>
    <div class="span-full"><?= $row('Remarks', nl2br(htmlspecialchars((string) ($t['remarks'] ?? ''))) ?: '—') ?></div>
    <?= $row('Fuel Cost', $t['fuel_cost'] !== null ? '₱' . number_format((float) $t['fuel_cost'], 2) : '—') ?>
    <?= $row('Easytrip Cost', $t['easytrip_cost'] !== null ? '₱' . number_format((float) $t['easytrip_cost'], 2) : '—') ?>
    <?= $row('Autosweep Cost', $t['autosweep_cost'] !== null ? '₱' . number_format((float) $t['autosweep_cost'], 2) : '—') ?>
    <?php if ((int) $t['status_id'] >= TripState::COMPLETED): ?>
      <?= $row('Certified By', $orDash($t['certifier_name'])) ?>
      <?= $row('Certified At', $fmt($t['certified_at'])) ?>
    <?php endif; ?>
  </div>
  </div>
</div>
<?php endif; ?>

<?php if ($gv): ?>
<div class="content-card mb-3.5">
  <div class="card-head"><span>Gate Verifications</span></div>
  <div class="p-3.5 overflow-x-auto">
  <table class="data-table" data-pager>
    <thead><tr><th class="whitespace-nowrap">Phase</th><th class="whitespace-nowrap">Result</th><th class="whitespace-nowrap">Notes</th><th class="whitespace-nowrap">By</th><th class="whitespace-nowrap">At</th></tr></thead>
    <tbody>
    <?php foreach ($gv as $g): ?>
      <tr>
        <td class="whitespace-nowrap"><?= htmlspecialchars($g['phase']) ?></td>
        <td class="whitespace-nowrap"><span class="badge badge-<?= $g['result'] === 'VALIDATED' ? 'completed' : 'rejected' ?>"><?= htmlspecialchars($g['result']) ?></span></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($g['notes'] ?? '—')) ?></td>
        <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($g['guard_name'] ?? '—')) ?></td>
        <td class="whitespace-nowrap"><?= $fmt($g['verified_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

</div>
</div>

<?php if (!empty($t['attachment_path'])):
$fileExt = strtolower((string) pathinfo((string) $t['attachment_path'], PATHINFO_EXTENSION));
$fileUrl = BASE_URL . 'file.php?ticket_id=' . (int) $t['ticket_id'];
$preview = '';
if (in_array($fileExt, ['png', 'jpg', 'jpeg'], true)) {
    $preview = '<img src="' . $fileUrl . '" alt="Request letter" style="width:100%;max-height:60vh;object-fit:contain;">';
} elseif ($fileExt === 'pdf') {
    $preview = '<iframe src="' . $fileUrl . '" style="width:100%;height:60vh;border:1px solid var(--border);border-radius:8px;"></iframe>';
} elseif ($fileExt === 'docx') {
    $preview = '<div id="docxContainer" data-docx-url="' . $fileUrl . '" style="max-height:60vh;overflow-y:auto;"></div>';
} else {
    $preview = '<p class="text-text-muted text-sm m-0">Preview is not available for this file type — use Download to open it.</p>';
}
?>
<div class="modal-backdrop" id="fileModal">
  <div class="modal-body" style="max-width:900px">
    <div class="modal-head">
      <span>Request Letter</span>
      <button class="modal-close" type="button" onclick="UI.modal('fileModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4"><?= $preview ?></div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('fileModal', false)">Close</button>
      <a class="<?= $btnPrimary ?>" href="<?= $fileUrl ?>" download>Download</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$RM_ID = 'reasonModal'; $RM_TITLE = 'Provide Reason'; $RM_OK = 'Confirm';
require_once __DIR__ . '/partials/reason_modal.php';
?>
<?php if ($canStart): ?>
<div class="modal-backdrop" id="startModal">
  <div class="modal-body" style="max-width:380px">
    <div class="modal-head">
      <span>Start Trip — Odometer</span>
      <button class="modal-close" type="button" onclick="UI.modal('startModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <label class="<?= $labelCls ?>">Current odometer reading (km) *</label>
      <input id="odoInput" type="number" min="0" step="0.1" placeholder="e.g. 45230.5" class="<?= $inputCls ?>">
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('startModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="startOk">Start Trip</button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($canResched): ?>
<div class="modal-backdrop" id="reschedModal">
  <div class="modal-body" style="max-width:420px">
    <div class="modal-head">
      <span>Reschedule Trip</span>
      <button class="modal-close" type="button" onclick="UI.modal('reschedModal', false)"><?= icon('x') ?></button>
    </div>
    <div class="modal-pad-x py-4">
      <label class="<?= $labelCls ?>">Reason *</label>
      <textarea id="rsReason" rows="2" maxlength="500" class="<?= $inputCls ?>" placeholder="Why is this trip being rescheduled?"></textarea>
      <p class="text-text-muted text-11-5px mt-1.5">The requester will be asked to pick a new departure/return window, then the trip returns for approval.</p>
      <label class="<?= $labelCls ?> mt-2.5">New Departure *</label>
      <input id="rsDep" type="datetime-local" value="<?= htmlspecialchars($toLocal($t['scheduled_departure'])) ?>" class="<?= $inputCls ?>">
      <label class="<?= $labelCls ?> mt-2.5">New Return *</label>
      <input id="rsRet" type="datetime-local" value="<?= htmlspecialchars($toLocal($t['scheduled_return'])) ?>" class="<?= $inputCls ?>">
    </div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $btnGhost ?>" type="button" onclick="UI.modal('reschedModal', false)">Close</button>
      <button class="<?= $btnPrimary ?>" id="reschedOk">Send for Reschedule</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$PAGE_SCRIPTS = ['geo.js', 'ticket.js'];
if (!empty($t['attachment_path']) && strtolower((string) pathinfo((string) $t['attachment_path'], PATHINFO_EXTENSION)) === 'docx'):
?>
<script src="<?= BASE_URL ?>assets/js/vendor/jszip.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/vendor/docx-preview.min.js"></script>
<?php endif; ?>
<?php require_once __DIR__ . '/partials/layout_foot.php'; ?>
