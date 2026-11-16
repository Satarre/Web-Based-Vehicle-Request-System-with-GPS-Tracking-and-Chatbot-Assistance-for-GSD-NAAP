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

  function dismissToast(el) {
    if (el.classList.contains('toast-exit')) return;
    el.classList.add('toast-exit');
    el.addEventListener('animationend', () => el.remove(), { once: true });
  }

  /* toast(msg, kind, { href, onClick, timeout }) — href/onClick make the whole
   * toast clickable (used by notifications to open the referenced ticket). */
  /* Callers across the app pass either the canonical 'ok'/'err'/'info' or the
     Facebook-style 'success'/'error'; normalize so both render identically. */
  const TOAST_KIND = { ok: 'ok', success: 'ok', err: 'err', error: 'err', info: 'info' };

  function toast(msg, kind, opts) {
    const k = TOAST_KIND[kind] || 'info';
    const o = opts || {};
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

    if (o.href || o.onClick) {
      el.classList.add('toast-clickable');
      el.addEventListener('click', (e) => {
        if (e.target.closest('.toast-close')) return;
        if (typeof o.onClick === 'function') o.onClick();
        if (o.href) location.href = o.href;
        dismissToast(el);
      });
    }

    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'toast-close';
    x.setAttribute('aria-label', 'Dismiss notification');
    x.innerHTML = TOAST_ICONS.err;
    x.addEventListener('click', (e) => { e.stopPropagation(); dismissToast(el); });
    el.appendChild(x);

    const zone = ensureToastZone();
    // Keep at most 3 toasts on screen: evict the oldest visible one first.
    const visible = zone.querySelectorAll('.toast:not(.toast-exit)');
    if (visible.length >= 3) dismissToast(visible[0]);
    zone.appendChild(el);
    setTimeout(() => dismissToast(el), o.timeout || 4000);
  }

  /** Open/close any .modal-backdrop by id. */
  function modal(id, show) {
    const m = document.getElementById(id);
    if (!m) return;
    if (show !== false) {
      // Guarantee any <select class="form-input"> inside the modal is enhanced
      // into its custom dropdown before it is shown (see select.js).
      if (typeof window.__ensureSelects === 'function') window.__ensureSelects(m);
      m.classList.add('open');
    } else {
      m.classList.remove('open');
    }
  }

  /** Promise-based confirm using an existing modal with #confirmOk/#confirmCancel buttons. */
  function confirmDialog(opts) {
    return new Promise((resolve) => {
      const wrap = document.getElementById('confirmModal');
      if (!wrap) { resolve(window.confirm(opts.message || 'Are you sure?')); return; }
      wrap.querySelector('#confirmMessage').textContent = opts.message || 'Are you sure?';
      const ok = wrap.querySelector('#confirmOk'), cancel = wrap.querySelector('#confirmCancel');
      ok.className = opts.danger ? 'btn btn-danger' : 'btn btn-primary';
      ok.textContent = opts.okLabel || 'Confirm';
      modal('confirmModal', true);
      const done = (v) => { modal('confirmModal', false); ok.onclick = null; cancel.onclick = null; resolve(v); };
      ok.onclick = () => done(true);
      cancel.onclick = () => done(false);
    });
  }

  const $  = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  /* Copy each table's <thead> text into data-label on every body cell so the
     mobile stacked-row layout (app.css ≤900px) keeps column context. Skips
     colspan placeholder rows and empty (action) headers. Re-runnable. */
  function labelizeTables(root) {
    $$('table.data-table', root).forEach((tbl) => {
      const ths = $$('thead th', tbl);
      if (!ths.length) return;
      $$('tbody tr', tbl).forEach((tr) => {
        if ($('td[colspan]', tr)) return;
        Array.from(tr.children).forEach((td, i) => {
          const label = ths[i] ? ths[i].textContent.replace(/\s+/g, ' ').trim() : '';
          if (label) td.setAttribute('data-label', label);
          else td.removeAttribute('data-label');
        });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => labelizeTables());
  else labelizeTables();

  /* ── Dropdowns: one shared open/close contract for every dropdown panel ──
   * Consumers (select.js, location-autocomplete.js) call:
   *   UI.openDropdown(list, anchor, onClose)
   *   UI.closeDropdown()
   * The panel is positioned fixed to the viewport so no ancestor overflow
   * (content-card, table wrapper, modal-body) can clip it, and the single
   * global handlers below give every dropdown identical behavior:
   *   - scrolling INSIDE the open panel scrolls it (never closes it)
   *   - scrolling/resizing the page outside it closes it
   *   - a pointerdown outside the panel + its anchor closes it
   */
  let dd = null;      // currently open panel element
  let ddAnchor = null; // element the panel is anchored to (clicking it won't close)
  let ddAt = 0;       // timestamp of last open (ignore focus-scroll closings)

  function closeDropdown() {
    if (dd) { const c = dd.__ddClose; dd = null; ddAnchor = null; if (c) c(); }
  }

  function openDropdown(list, anchor, onClose) {
    if (dd && dd !== list) closeDropdown();
    list.__ddClose = onClose;
    ddAnchor = anchor;

    const r = anchor.getBoundingClientRect();
    const estHeight = Math.min(list.scrollHeight || 260, 260);
    const placeBelow = r.bottom + 4 + estHeight <= window.innerHeight;

    const fixed = () => {
      list.style.position = 'fixed';
      list.style.left = r.left + 'px';
      list.style.width = Math.max(r.width, 80) + 'px';
      list.style.top = placeBelow ? (r.bottom + 4) + 'px' : 'auto';
      list.style.bottom = placeBelow ? 'auto' : (window.innerHeight - r.top + 4) + 'px';
    };
    const inFlow = () => {
      list.style.position = '';
      list.style.left = '';
      list.style.width = '';
      list.style.top = '';
      list.style.bottom = '';
    };

    fixed();
    const lr = list.getBoundingClientRect();
    const visible = lr.width > 0 && lr.height > 0
      && lr.right > 0 && lr.left < window.innerWidth
      && lr.bottom > 0 && lr.top < window.innerHeight;
    if (!visible) {
      inFlow();
      console.warn('[ui.dropdown] panel position fallback used', lr.toJSON ? lr.toJSON() : lr);
    }

    dd = list;
    ddAt = Date.now();
  }

  // Scroll anywhere except inside the open panel closes it. Wheel-scrolling the
  // panel itself scrolls the panel — the event target is the panel, so it is
  // ignored here instead of closing the just-opened list.
  document.addEventListener('scroll', (e) => {
    if (!dd) return;
    if (e.target === dd || dd.contains(e.target)) return;
    if (Date.now() - ddAt < 250) return;
    closeDropdown();
  }, true);

  window.addEventListener('resize', closeDropdown);

  document.addEventListener('pointerdown', (e) => {
    if (!dd) return;
    if (dd.contains(e.target)) return;
    if (ddAnchor && (e.target === ddAnchor || ddAnchor.contains(e.target))) return;
    closeDropdown();
  });

  /* ── Mobile search collapse ────────────────────────────────────────────
   * Table filter inputs ([data-filter]) collapse into a magnifier icon on
   * mobile (≤900px, the app's stacked-layout breakpoint) and expand on tap.
   * The wrapper is display:contents on desktop so layout is unchanged. */
  const SEARCH_ICON = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4.3-4.3"/></svg>';

  function closeSearchCollapses() {
    $$('.search-collapse.is-open').forEach((w) => {
      w.classList.remove('is-open');
      const b = w.querySelector('.search-toggle');
      if (b) b.setAttribute('aria-expanded', 'false');
    });
  }

  function enhanceMobileSearch() {
    $$('input[type="search"][data-filter]').forEach((inp) => {
      if (inp.parentElement && inp.parentElement.classList.contains('search-collapse')) return;
      const wrap = document.createElement('span');
      wrap.className = 'search-collapse';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'search-toggle';
      btn.setAttribute('aria-label', 'Search');
      btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = SEARCH_ICON;
      inp.parentNode.insertBefore(wrap, inp);
      wrap.appendChild(btn);
      wrap.appendChild(inp);
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = !wrap.classList.contains('is-open');
        closeSearchCollapses();
        if (open) {
          wrap.classList.add('is-open');
          btn.setAttribute('aria-expanded', 'true');
          inp.focus();
        }
      });
    });
  }
  document.addEventListener('pointerdown', (e) => {
    if (!e.target.closest || !e.target.closest('.search-collapse')) closeSearchCollapses();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSearchCollapses(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhanceMobileSearch);
  else enhanceMobileSearch();

  window.UI = { toast, modal, confirmDialog, $, $$, openDropdown, closeDropdown, labelizeTables };
})();
