/* assignments.js — assign modal (vehicle/driver/schedule + GPS override). */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);

  /* Disable vehicles/drivers already dispatched for an overlapping window. */
  function applyBusyFilter() {
    const dep = $('asDep').value, ret = $('asRet').value;
    const depT = dep ? new Date(dep).getTime() : NaN;
    const retT = ret ? new Date(ret).getTime() : NaN;
    const overlaps = (w) => {
      if (!isFinite(depT) || !isFinite(retT)) return false;
      const wDep = new Date(w[0]).getTime(), wRet = new Date(w[1]).getTime();
      return depT < wRet && wDep < retT;
    };
    ['asVehicle', 'asDriver'].forEach(id => {
      Array.from($(id).options).forEach(opt => {
        if (opt.value === '') return;
        let wins = [];
        try { wins = JSON.parse(opt.dataset.busy || '[]'); } catch (e) {}
        opt.disabled = wins.some(overlaps);
      });
    });
  }

  function openAssign(btn) {
    $('asTicketId').value = btn.dataset.assign;
    $('asDep').value = btn.dataset.dep || '';
    $('asRet').value = btn.dataset.ret || '';
    $('asVehicle').value = '';
    $('asDriver').value = '';
    $('asOverride').value = '';
    applyBusyFilter();
    syncOverride();
    UI.modal('assignModal', true);
  }

  function syncOverride() {
    const sel = $('asVehicle');
    const opt = sel.options[sel.selectedIndex];
    const chosen = opt && opt.value !== '';
    const unpaired = chosen && opt.dataset.paired === '0';
    $('asOverrideWrap').style.display = unpaired ? 'block' : 'none';
  }

  document.addEventListener('click', async (e) => {
    const ab = e.target.closest('[data-assign]');
    if (ab) return openAssign(ab);

    if (!e.target.closest('#asOk')) return;
    const body = {
      action: 'assign',
      ticket_id: parseInt($('asTicketId').value, 10),
      vehicle_id: parseInt($('asVehicle').value, 10) || 0,
      driver_id: parseInt($('asDriver').value, 10) || 0,
      scheduled_departure: $('asDep').value,
      scheduled_return: $('asRet').value,
      gps_override_reason: $('asOverride').value.trim()
    };
    const vehicleLabel = $('asVehicle').options[$('asVehicle').selectedIndex]?.text.replace(/\s*·\s*NO GPS.*$/, '') || '';
    const driverLabel = $('asDriver').options[$('asDriver').selectedIndex]?.text || '';
    if (!(await UI.confirmDialog({ message: 'Assign and dispatch this trip with ' + vehicleLabel + ' / ' + driverLabel + '?', okLabel: 'Assign & Dispatch' }))) return;
    try {
      const r = await API.post('api/trips.php', body);
      UI.modal('assignModal', false);
      UI.toast(r.message, 'ok');
      setTimeout(() => location.reload(), 700);
    } catch (err) {
      UI.toast(err.message, 'err');
      if (err.needs_override || /override/i.test(err.message)) $('asOverrideWrap').style.display = 'block';
    }
  });

  document.addEventListener('change', (e) => {
    if (e.target.id === 'asVehicle') syncOverride();
  });

  const auto = $('autoOpenTicket');
  if (auto) {
    const btn = document.querySelector('[data-assign="' + CSS.escape(auto.value) + '"]');
    if (btn) openAssign(btn);
  }
})();
