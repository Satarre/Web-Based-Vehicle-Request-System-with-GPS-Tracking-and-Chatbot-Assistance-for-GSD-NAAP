/* mfa.js — self-service two-factor setup, recovery codes, disable, email verify. */
(function () {
  'use strict';

  const setupCard = document.getElementById('mfaSetupCard');

  function showCodesOnce(codes, title) {
    const wrap = document.createElement('div');
    wrap.className = 'modal-backdrop open';
    const body = document.createElement('div');
    body.className = 'modal-body';
    body.style.maxWidth = '380px';
    body.innerHTML =
      '<div class="modal-head"><span>' + (title || 'Recovery Codes') +
      '</span><button class="modal-close" type="button">×</button></div>' +
      '<div class="px-[18px] py-4"><p class="text-text-muted text-md m-0 mb-3">' +
      'These single-use codes are shown only once. Store them somewhere safe.</p>' +
      '<div class="content-card p-3"></div>' +
      '<div class="mt-3 flex justify-end gap-2"><button class="btn btn-ghost" type="button">Download</button>' +
      '<button class="btn btn-primary" type="button">I Saved Them</button></div></div>';
    const list = body.querySelector('.content-card');
    codes.forEach(function (c) {
      const div = document.createElement('div');
      div.className = 'py-1 border-b border-border last:border-0 flex justify-between items-center';
      const code = document.createElement('code');
      code.className = 'font-bold tracking-[.15em]';
      code.textContent = c;
      const copy = document.createElement('button');
      copy.className = 'btn btn-sm btn-ghost';
      copy.type = 'button';
      copy.textContent = 'Copy';
      copy.addEventListener('click', async function () {
        try { await navigator.clipboard.writeText(c); UI.toast('Copied.', 'ok'); }
        catch (_) { UI.toast('Copy failed.', 'err'); }
      });
      div.append(code, copy);
      list.appendChild(div);
    });
    const footer = body.querySelector('.mt-3');
    const download = footer.querySelector('.btn-ghost');
    download.addEventListener('click', function () {
      const blob = new Blob([codes.join('\n')], { type: 'text/plain' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'recovery-codes.txt';
      a.click();
      URL.revokeObjectURL(a.href);
    });
    body.querySelector('.modal-close').addEventListener('click', function () { wrap.remove(); });
    body.querySelector('.btn-primary').addEventListener('click', function () {
      wrap.remove();
      setTimeout(function () { location.reload(); }, 300);
    });
    wrap.appendChild(body);
    document.body.appendChild(wrap);
  }

  /* ── Enable setup ─────────────────────────────────────────────────── */
  const btnBegin = document.getElementById('btnMfaBegin');
  if (btnBegin) {
    btnBegin.addEventListener('click', async () => {
      btnBegin.disabled = true;
      try {
        const res = await API.post('api/account.php', { action: 'mfa_begin' });
        const qrEl = document.getElementById('mfaQr');
        if (typeof renderQr !== 'function' || !renderQr(qrEl, res.data.otpauth_uri, 200)) {
          qrEl.textContent = 'QR code unavailable — use the manual entry code below.';
        }
        document.getElementById('mfaSecret').value = res.data.secret;
        setupCard.hidden = false;
      } catch (e) {
        UI.toast(e.message, 'err');
      }
      btnBegin.disabled = false;
    });
  }
  const btnEnable = document.getElementById('btnMfaEnable');
  if (btnEnable) {
    btnEnable.addEventListener('click', async () => {
      const code = document.getElementById('mfaCode').value.trim();
      if (!/^\d{6}$/.test(code)) { UI.toast('Enter the 6-digit code.', 'err'); return; }
      btnEnable.disabled = true;
      try {
        const res = await API.post('api/account.php', { action: 'mfa_enable', otp_code: code });
        showCodesOnce(res.data.codes, 'Two-Factor Enabled');
      } catch (e) {
        UI.toast(e.message, 'err');
      }
      btnEnable.disabled = false;
    });
  }
  const btnCancel = document.getElementById('btnMfaCancel');
  if (btnCancel) {
    btnCancel.addEventListener('click', () => { setupCard.hidden = true; });
  }

  /* ── Reconfirm (password + TOTP) before regen/disable ─────────────── */
  const confirmModal = document.getElementById('mfaConfirmModal');
  let pendingAction = null;

  function openConfirm(title, action) {
    pendingAction = action;
    document.getElementById('mfaConfirmTitle').textContent = title;
    document.getElementById('mfaConfirmPassword').value = '';
    document.getElementById('mfaConfirmCode').value = '';
    UI.modal('mfaConfirmModal', true);
  }

  const btnConfirm = document.getElementById('btnMfaConfirmAction');
  if (btnConfirm) {
    btnConfirm.addEventListener('click', async () => {
      if (!pendingAction) return;
      const current_password = document.getElementById('mfaConfirmPassword').value;
      const otp_code = document.getElementById('mfaConfirmCode').value.trim();
      if (!current_password || !otp_code) { UI.toast('Password and code are required.', 'err'); return; }
      btnConfirm.disabled = true;
      try {
        const res = await API.post('api/account.php', { action: pendingAction, current_password, otp_code });
        UI.modal('mfaConfirmModal', false);
        if (pendingAction === 'mfa_regenerate_codes') {
          showCodesOnce(res.data.codes, 'Recovery Codes Regenerated');
        } else {
          UI.toast(res.message || 'Done.', 'ok');
          setTimeout(() => location.reload(), 600);
        }
      } catch (e) {
        UI.toast(e.message, 'err');
      }
      pendingAction = null;
      btnConfirm.disabled = false;
    });
  }

  const btnRegen = document.getElementById('btnMfaRegen');
  if (btnRegen) {
    btnRegen.addEventListener('click', () => openConfirm('Regenerate Recovery Codes', 'mfa_regenerate_codes'));
  }
  const btnDisable = document.getElementById('btnMfaDisable');
  if (btnDisable) {
    btnDisable.addEventListener('click', () => openConfirm('Disable Two-Factor', 'mfa_disable'));
  }

  /* ── Email verification ───────────────────────────────────────────── */
  const btnVerifySend = document.getElementById('btnEmailVerifySend');
  if (btnVerifySend) {
    btnVerifySend.addEventListener('click', async () => {
      btnVerifySend.disabled = true;
      try {
        const res = await API.post('api/account.php', { action: 'email_verify_send' });
        UI.toast(res.message || 'Code sent.', 'ok');
      } catch (e) {
        UI.toast(e.message, 'err');
      }
      btnVerifySend.disabled = false;
    });
  }
  const btnVerifyConfirm = document.getElementById('btnEmailVerifyConfirm');
  if (btnVerifyConfirm) {
    btnVerifyConfirm.addEventListener('click', async () => {
      const code = document.getElementById('emailVerifyCode').value.trim();
      if (!/^\d{6}$/.test(code)) { UI.toast('Enter the 6-digit code.', 'err'); return; }
      btnVerifyConfirm.disabled = true;
      try {
        await API.post('api/account.php', { action: 'email_verify_confirm', otp_code: code });
        UI.toast('Email verified.', 'ok');
        setTimeout(() => location.reload(), 600);
      } catch (e) {
        UI.toast(e.message, 'err');
      }
      btnVerifyConfirm.disabled = false;
    });
  }
})();