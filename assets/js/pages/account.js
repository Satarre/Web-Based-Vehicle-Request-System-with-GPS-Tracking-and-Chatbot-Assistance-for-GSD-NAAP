/* account.js — self-service profile & password changes. */
(function () {
  'use strict';

  const btnSave = document.getElementById('btnProfileSave');
  if (btnSave) {
    btnSave.addEventListener('click', async () => {
      const name = document.getElementById('pfName').value.trim();
      if (!name) { UI.toast('Full name is required.', 'err'); return; }
      btnSave.disabled = true;
      try {
        const res = await API.post('api/account.php', { action: 'profile_save', full_name: name });
        UI.toast(res.message || 'Profile updated.', 'ok');
        setTimeout(() => location.reload(), 600);
      } catch (e) {
        UI.toast(e.message, 'err');
        btnSave.disabled = false;
      }
    });
  }

  const btnPw = document.getElementById('btnPasswordSave');
  if (btnPw) {
    btnPw.addEventListener('click', async () => {
      const cur = document.getElementById('pwCurrent').value;
      const nw = document.getElementById('pwNew').value;
      const cf = document.getElementById('pwConfirm').value;
      if (!cur || !nw) { UI.toast('Current and new passwords are required.', 'err'); return; }
      if (nw.length < 8) { UI.toast('New password must be at least 8 characters.', 'err'); return; }
      if (nw !== cf) { UI.toast('New passwords do not match.', 'err'); return; }
      btnPw.disabled = true;
      try {
        const res = await API.post('api/account.php', {
          action: 'password_change', current_password: cur, new_password: nw,
        });
        UI.toast(res.message || 'Password changed.', 'ok');
        document.getElementById('pwCurrent').value = '';
        document.getElementById('pwNew').value = '';
        document.getElementById('pwConfirm').value = '';
      } catch (e) {
        UI.toast(e.message, 'err');
      }
      btnPw.disabled = false;
    });
  }
})();
