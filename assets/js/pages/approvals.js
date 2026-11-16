/* approvals.js — approve/reject reason flow for the SAO approval queue. */
(function () {
  'use strict';
  let targetId = null;

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-approve]');
    const rj = e.target.closest('[data-reject]');
    if (btn) {
      const message = 'Approve this trip request? It will go to the GSD Dispatcher for dispatch.';
      if (await UI.confirmDialog({ message, okLabel: 'Approve' })) {
        try {
          const r = await API.post('api/trips.php', { action: 'approve', ticket_id: btn.dataset.approve });
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
