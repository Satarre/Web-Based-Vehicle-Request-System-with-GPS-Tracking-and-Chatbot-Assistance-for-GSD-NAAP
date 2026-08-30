/* requests.js — cancel flow from the requester list. */
(function () {
  'use strict';
  let targetId = null;

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-cancel]');
    if (btn) {
      targetId = btn.dataset.cancel;
      const isDraft = btn.dataset.status === '1';
      if (isDraft) {
        if (await UI.confirmDialog({ message: 'Are you sure you want to delete your draft?', okLabel: 'Erase Draft', danger: true })) {
          try {
            const r = await API.post('api/trips.php', { action: 'erase_draft', ticket_id: targetId });
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
