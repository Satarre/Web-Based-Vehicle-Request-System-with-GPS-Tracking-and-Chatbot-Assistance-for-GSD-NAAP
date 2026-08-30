/* flags.js — resolve flow for flags.php. */
(function () {
  'use strict';
  let currentFlag = null;

  document.querySelectorAll('[data-resolve]').forEach((btn) => {
    btn.addEventListener('click', () => {
      currentFlag = btn.dataset.resolve;
      document.getElementById('resolveModalText').value = '';
      UI.modal('resolveModal', true);
    });
  });

  const ok = document.getElementById('resolveModalOk');
  if (ok) {
    ok.addEventListener('click', async () => {
      const reason = document.getElementById('resolveModalText').value.trim();
      if (!reason) { UI.toast('A resolution reason is required.', 'error'); return; }
      ok.disabled = true;
      try {
        const res = await API.post('api/trips.php', {
          action: 'resolve_flag', flag_id: currentFlag, reason,
        });
        UI.toast(res.message || 'Flag resolved.', 'success');
        setTimeout(() => location.reload(), 600);
      } catch (e) {
        UI.toast(e.message, 'error');
        ok.disabled = false;
      }
    });
  }
})();
