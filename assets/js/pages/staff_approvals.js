/* staff_approvals.js — GSD Staff approve / reject from the approve queue. */
(function () {
  'use strict';
  let targetId = null;

  document.addEventListener('click', async (e) => {
    const ap = e.target.closest('[data-approve]');
    const rj = e.target.closest('[data-reject]');
    if (ap) {
      if (await UI.confirmDialog({ message: 'Approve this trip request? It will go to staff for driver/vehicle assignment.', okLabel: 'Approve' })) {
        try {
          const r = await API.post('api/trips.php', { action: 'approve', ticket_id: ap.dataset.approve });
          UI.toast(r.message, 'ok');
          setTimeout(() => location.reload(), 600);
        } catch (err) { UI.toast(err.message, 'err'); }
      }
      return;
    }
    if (rj) {
      targetId = rj.dataset.reject;
      document.getElementById('rejectModalText').value = '';
      UI.modal('rejectModal', true);
      return;
    }
    if (e.target.closest('#rejectModalOk')) {
      const reason = document.getElementById('rejectModalText').value.trim();
      if (!reason) return UI.toast('A rejection reason is required.', 'err');
      try {
        const r = await API.post('api/trips.php', { action: 'reject', ticket_id: targetId, reason });
        UI.modal('rejectModal', false);
        UI.toast(r.message, 'ok');
        setTimeout(() => location.reload(), 600);
      } catch (err) { UI.toast(err.message, 'err'); }
    }
  });
})();
