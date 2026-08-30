/* approvals.js — HR verify / reject from the director queue. */
(function () {
  'use strict';
  let targetId = null;

  document.addEventListener('click', async (e) => {
    const ap = e.target.closest('[data-approve]');
    const rj = e.target.closest('[data-reject]');
    if (ap) {
      if (await UI.confirmDialog({ message: 'Verify this request? It will go to GSD Staff for approval.', okLabel: 'Verify' })) {
        try {
          const r = await API.post('api/trips.php', { action: 'hr_verify', ticket_id: ap.dataset.approve });
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
