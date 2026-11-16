<?php
declare(strict_types=1);
/**
 * personnel/feedback.php — submit service feedback on own trips (Phase 5).
 */
require_once dirname(__DIR__) . '/config/config.php';
$USER = Auth::requirePage($pdo, [6]);
$ACTIVE = BASE_URL . 'personnel/feedback.php';
$PAGE_TITLE = 'Feedback';

$tickets = $pdo->prepare(
    'SELECT t.ticket_id, t.dtt_number, t.place_to_visit, r.status_name
     FROM trip_tickets t JOIN request_status r ON r.status_id = t.status_id
     WHERE t.created_by = ? AND t.is_active = 1 AND t.status_id = ?
     ORDER BY t.ticket_id DESC'
);
$tickets->execute([$USER['id'], TripState::COMPLETED]);
$tickets = $tickets->fetchAll();

$mine = $pdo->prepare(
    'SELECT f.details, f.submitted_at, t.dtt_number, t.place_to_visit
     FROM feedback f JOIN trip_tickets t ON t.ticket_id = f.ticket_id
     WHERE f.is_active = 1 AND t.created_by = ?
     ORDER BY f.feedback_id DESC LIMIT 20'
);
$mine->execute([$USER['id']]);
$mine = $mine->fetchAll();

$fmt = static fn(?string $d): string => $d ? date('M j, Y g:i A', strtotime($d)) : '—';
$PAGE_SCRIPTS = ['feedback.js'];
require_once dirname(__DIR__) . '/partials/layout_head.php';
require_once dirname(__DIR__) . '/partials/shell_open.php';

$inputCls = 'form-input';
$labelCls = 'form-label';
?>

<div class="content-card p-3.5 mb-3.5" style="max-width:640px">
  <h2 class="text-lg font-bold mb-2.5">Tell us how the trip went</h2>
  <?php if (!$tickets): ?>
    <p class="text-text-muted text-md m-0">You have no submitted requests yet — feedback can be given once you have a trip ticket.</p>
  <?php else: ?>
    <label class="<?= $labelCls ?>">Trip *</label>
    <select id="fbTicket" class="<?= $inputCls ?>">
      <option value="">Select a trip…</option>
      <?php foreach ($tickets as $t): ?>
        <option value="<?= (int) $t['ticket_id'] ?>">
          <?= htmlspecialchars(($t['dtt_number'] ?? ('#' . $t['ticket_id'])) . ' — ' . mb_strimwidth($t['place_to_visit'], 0, 40, '…')) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label class="<?= $labelCls ?> mt-2.5">Feedback *</label>
    <textarea id="fbDetails" rows="4" maxlength="2000" class="<?= $inputCls ?>"
              placeholder="Vehicle condition, driver conduct, punctuality, overall service"></textarea>
    <div class="mt-3 flex justify-end">
      <button class="btn btn-primary" id="fbSend">Submit Feedback</button>
    </div>
  <?php endif; ?>
</div>

<div class="content-card">
  <div class="modal-pad-x py-3.5 border-b border-border"><h2 class="text-lg font-bold">Your Previous Feedback</h2></div>
  <div class="overflow-x-auto">
    <table class="data-table" data-pager>
      <thead><tr><th>Trip</th><th>Feedback</th><th>Submitted</th></tr></thead>
      <tbody>
      <?php if (!$mine): ?>
        <tr><td colspan="3" class="px-2.5 py-2 border-b border-border align-middle text-center text-text-muted">No feedback submitted yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($mine as $f): ?>
        <tr>
          <td class="whitespace-nowrap"><strong><?= htmlspecialchars($f['dtt_number'] ?? '—') ?></strong><br><span class="text-text-muted"><?= htmlspecialchars(mb_strimwidth($f['place_to_visit'], 0, 30, '…')) ?></span></td>
          <td><?= nl2br(htmlspecialchars($f['details'])) ?></td>
          <td class="whitespace-nowrap"><?= $fmt($f['submitted_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/partials/layout_foot.php'; ?>
