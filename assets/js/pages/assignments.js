/* assignments.js — assign modal (vehicle/driver/schedule + GPS override). */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);

  function openAssign(btn) {
    $('asTicketId').value = btn.dataset.assign;
    $('asDep').value = btn.dataset.dep || '';
    $('asRet').value = btn.dataset.ret || '';
    $('asVehicle').value = '';
    $('asDriver').value = '';
    $('asOverride').value = '';
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
    const btn = document.querySelector('[data-assign="' + auto.value + '"]');
    if (btn) openAssign(btn);
  }
})();
