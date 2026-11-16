<?php
/**
 * partials/calendar.php — dispatch calendar card shell.
 * The grid is rendered client-side by assets/js/pages/calendar.js from
 * api/calendar.php (pure JSON). This partial only outputs the card chrome
 * (year/month nav + grid container) and the shared day-details modal.
 * Expects $pdo / BASE_URL available (included after shell_open).
 */
$calYear  = (int) ($_GET['cal_y'] ?? date('Y'));
$calMonth = (int) ($_GET['cal_m'] ?? date('n'));
if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
if ($calMonth > 12) { $calMonth = 1;  $calYear++; }
?>
<div class="content-card cal-card" data-cal-year="<?= $calYear ?>" data-cal-month="<?= $calMonth ?>">
  <div class="card-head">
    <span>Dispatch Calendar</span>
    <div class="cal-nav">
      <div class="cal-nav-group">
        <button type="button" class="btn btn-sm btn-ghost" data-cal-y-nav="-1" title="Previous year">◀</button>
        <span class="cal-title" id="calYearTitle"><?= $calYear ?></span>
        <button type="button" class="btn btn-sm btn-ghost" data-cal-y-nav="1" title="Next year">▶</button>
      </div>
      <div class="cal-nav-group">
        <button type="button" class="btn btn-sm btn-ghost" data-cal-m-nav="-1" title="Previous month">◀</button>
        <span class="cal-title" id="calMonthTitle"><?= date('F', mktime(0, 0, 0, $calMonth, 1, $calYear)) ?></span>
        <button type="button" class="btn btn-sm btn-ghost" data-cal-m-nav="1" title="Next month">▶</button>
      </div>
    </div>
  </div>
  <div class="p-3">
    <div class="cal-grid" id="calGrid">
      <div class="cal-dow">Sun</div><div class="cal-dow">Mon</div><div class="cal-dow">Tue</div>
      <div class="cal-dow">Wed</div><div class="cal-dow">Thu</div><div class="cal-dow">Fri</div><div class="cal-dow">Sat</div>
      <div class="cal-loading">Loading…</div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="calModal">
  <div class="modal-body" style="max-width:540px">
    <div class="modal-head">
      <span id="calModalTitle">Scheduled Trips</span>
      <button class="modal-close" type="button" onclick="UI.modal('calModal', false)"><?= function_exists('icon') ? icon('x') : '×' ?></button>
    </div>
    <div class="modal-pad-x py-4" id="calModalBody"></div>
    <div class="modal-pad-x py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" type="button" onclick="UI.modal('calModal', false)">Close</button>
    </div>
  </div>
</div>