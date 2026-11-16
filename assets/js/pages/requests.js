/* requests.js — cancel flow + reschedule-resubmit from the requester list. */
(function () {
  'use strict';
  let targetId = null;
  let pickers = null;

  /* The reschedule modal uses the same flatpickr calendar as the trip form
   * (assets/js/schedule_picker.js). Lazy-init on first open so the popover
   * positions correctly for a visible modal. */
  function ensurePickers() {
    if (pickers || typeof SchedulePicker === 'undefined') return pickers;
    const lateEl = document.getElementById('rs_late_days');
    pickers = SchedulePicker.init('#rs_dep', '#rs_ret', {
      lateDays: lateEl ? (parseInt(lateEl.value, 10) || 3) : 3,
    });
    return pickers;
  }

  /* Pre-fill with the rescheduled ticket's current window. */
  function prefill(depStr, retStr) {
    const p = ensurePickers();
    if (!p) return;
    const now = new Date();
    const depD = SchedulePicker.toDate(depStr);
    const retD = SchedulePicker.toDate(retStr);
    p.ret.set('minDate', depD || now);
    p.dep.setDate(depD || null, false);
    p.ret.setDate(retD || null, false);
  }

  document.addEventListener('click', async (e) => {
    const rs = e.target.closest('[data-resubmit]');
    if (rs) {
      document.getElementById('rs_ticket_id').value = rs.dataset.resubmit;
      document.getElementById('rs_reason_display').textContent = rs.dataset.reason || '—';
      prefill(rs.dataset.dep || '', rs.dataset.ret || '');
      UI.modal('resubmitModal', true);
      return;
    }
    if (e.target.closest('#rs_submit')) {
      const ticketId = document.getElementById('rs_ticket_id').value;
      const depEl = document.getElementById('rs_dep');
      const retEl = document.getElementById('rs_ret');
      const p = ensurePickers();
      const depD = (p && p.dep.selectedDates[0]) || SchedulePicker.toDate(depEl.value);
      const retD = (p && p.ret.selectedDates[0]) || SchedulePicker.toDate(retEl.value);
      if (!depD || !retD) return UI.toast('Both new schedule fields are required.', 'err');
      if (retD <= depD) return UI.toast('Return must be after departure.', 'err');
      if (depD <= new Date()) return UI.toast('Scheduled departure must be in the future.', 'err');
      if (!(await UI.confirmDialog({ message: 'Submit this new schedule for SAO approval?', okLabel: 'Submit', danger: true }))) return;
      try {
        const r = await API.post('api/trips.php', {
          action: 'resubmit_rescheduled', ticket_id: ticketId,
          scheduled_departure: depEl.value, scheduled_return: retEl.value,
        });
        UI.modal('resubmitModal', false);
        UI.toast(r.message, 'ok');
        setTimeout(() => location.reload(), 600);
      } catch (err) { UI.toast(err.message, 'err'); }
      return;
    }
    const btn = e.target.closest('[data-cancel]');
    if (btn) {
      targetId = btn.dataset.cancel;
      const isDraft = btn.dataset.status === '1';
      if (isDraft) {
        if (await UI.confirmDialog({ message: 'Delete this draft?', okLabel: 'Delete', danger: true })) {
          try {
            const r = await API.post('api/trips.php', { action: 'erase_draft', draft_id: targetId });
            UI.toast(r.message, 'ok');
            setTimeout(() => location.reload(), 600);
          } catch (err) { UI.toast(err.message, 'err'); }
        }
      } else {
        document.getElementById('cancelModalText').value = '';
        UI.modal('cancelModal', true);
      }
      return;
    }
    if (e.target.closest('#cancelModalOk')) {
      const reason = document.getElementById('cancelModalText').value.trim();
      if (!reason) return UI.toast('A cancellation reason is required.', 'err');
      try {
        const r = await API.post('api/trips.php', { action: 'cancel', ticket_id: targetId, reason });
        UI.modal('cancelModal', false);
        UI.toast(r.message, 'ok');
        setTimeout(() => location.reload(), 600);
      } catch (err) { UI.toast(err.message, 'err'); }
    }
  });
})();
