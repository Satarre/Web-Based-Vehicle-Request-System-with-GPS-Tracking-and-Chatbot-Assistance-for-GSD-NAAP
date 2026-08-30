/**
 * assets/js/ui.js — toasts, modals, confirm dialogs, small DOM helpers.
 */
(function () {
  'use strict';

  function ensureToastZone() {
    let z = document.getElementById('toastZone');
    if (!z) { z = document.createElement('div'); z.id = 'toastZone'; document.body.appendChild(z); }
    return z;
  }

  const TOAST_ICONS = {
    ok:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 12l6 6L20 6"/></svg>',
    err:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 11v5"/></svg>',
  };

  function toast(msg, kind) {
    const k = kind || 'info';
    const el = document.createElement('div');
    el.className = 'toast ' + k;
    const ic = document.createElement('span');
    ic.className = 'toast-icon';
    ic.innerHTML = TOAST_ICONS[k] || TOAST_ICONS.info;
    const tx = document.createElement('span');
    tx.className = 'toast-msg';
    tx.textContent = msg;
    el.appendChild(ic);
    el.appendChild(tx);
    ensureToastZone().appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  /** Open/close any .modal-backdrop by id. */
  function modal(id, show) {
    const m = document.getElementById(id);
    if (m) m.classList.toggle('open', show !== false);
  }

  /** Promise-based confirm using an existing modal with #confirmOk/#confirmCancel buttons. */
  function confirmDialog(opts) {
    return new Promise((resolve) => {
      const wrap = document.getElementById('confirmModal');
      if (!wrap) { resolve(window.confirm(opts.message || 'Are you sure?')); return; }
      wrap.querySelector('#confirmMessage').textContent = opts.message || 'Are you sure?';
      const ok = wrap.querySelector('#confirmOk'), cancel = wrap.querySelector('#confirmCancel');
      if (opts.danger) ok.className = 'inline-flex items-center justify-center gap-1.5 rounded-[8px] border border-transparent px-3.5 py-2 font-bold text-xs cursor-pointer bg-rejected text-white hover:opacity-90'; else ok.className = 'inline-flex items-center justify-center gap-1.5 rounded-[8px] border border-transparent px-3.5 py-2 font-bold text-xs cursor-pointer bg-accent-strong text-white hover:bg-accent hover:text-on-accent';
      ok.textContent = opts.okLabel || 'Confirm';
      modal('confirmModal', true);
      const done = (v) => { modal('confirmModal', false); ok.onclick = null; cancel.onclick = null; resolve(v); };
      ok.onclick = () => done(true);
      cancel.onclick = () => done(false);
    });
  }

  const $  = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  window.UI = { toast, modal, confirmDialog, $, $$ };
})();
