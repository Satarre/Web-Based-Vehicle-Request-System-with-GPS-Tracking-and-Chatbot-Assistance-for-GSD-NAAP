/* account.js — self-service profile & password changes. */
(function () {
  'use strict';

  const btnSave = document.getElementById('btnProfileSave');
  if (btnSave) {
    btnSave.addEventListener('click', async () => {
      const first = document.getElementById('pfFirstName').value.trim();
      const last = document.getElementById('pfLastName').value.trim();
      if (!first || !last) { UI.toast('First name and last name are required.', 'err'); return; }
      btnSave.disabled = true;
      try {
        const res = await API.post('api/account.php', {
          action: 'profile_save',
          first_name: first,
          middle_initial: document.getElementById('pfMiddleInitial').value.trim(),
          middle_name: document.getElementById('pfMiddleName').value.trim(),
          last_name: last,
          suffix: document.getElementById('pfSuffix').value.trim(),
        });
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
      if (typeof PasswordPolicy !== 'undefined') {
        const pwErr = PasswordPolicy.check(nw);
        if (pwErr) { UI.toast(pwErr, 'err'); return; }
      }
      if (nw === cur) { UI.toast('New password must be different from the current password.', 'err'); return; }
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
