/**
 * assets/js/settings.js — settings drawer + theme (light/dark) persistence.
 * Theme = data-theme attribute on <html>, stored in localStorage.
 * First visit follows prefers-color-scheme.
 */
(function () {
  'use strict';

  const KEY = 'naap_vts_theme';

  function apply(theme) {
    if (theme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    else document.documentElement.removeAttribute('data-theme');
    document.querySelectorAll('[data-theme-opt]').forEach((b) => {
      b.classList.toggle('on', b.getAttribute('data-theme-opt') === theme);
    });
  }

  function current() {
    return localStorage.getItem(KEY)
      || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  }

  function init() {
    // Apply saved theme before paint is ideal; this also re-syncs after navigation.
    apply(current());

    const backdrop = document.getElementById('drawerBackdrop');
    const drawer   = document.getElementById('settingsDrawer');
    const openBtns = document.querySelectorAll('[data-open-settings]');
    const closeBtn = document.getElementById('drawerClose');

    function setOpen(open) {
      if (!drawer || !backdrop) return;
      drawer.classList.toggle('open', open);
      backdrop.classList.toggle('open', open);
    }

    openBtns.forEach((b) => b.addEventListener('click', () => { syncSeg(); setOpen(true); }));
    if (closeBtn) closeBtn.addEventListener('click', () => setOpen(false));
    if (backdrop) backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });

    const closeMobileNav = () => document.body.classList.remove('has-mobile-nav');
    document.querySelectorAll('[data-toggle-nav]').forEach((b) =>
      b.addEventListener('click', () => document.body.classList.toggle('has-mobile-nav')));
    document.addEventListener('click', (e) => {
      if (!document.body.classList.contains('has-mobile-nav')) return;
      if (e.target.closest('.sidebar') || e.target.closest('[data-toggle-nav]')) return;
      closeMobileNav();
    });
    document.querySelectorAll('.sidebar .nav-item').forEach((a) =>
      a.addEventListener('click', closeMobileNav));

    document.querySelectorAll('[data-theme-opt]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = btn.getAttribute('data-theme-opt');
        localStorage.setItem(KEY, t);
        apply(t);
      });
    });

    function syncSeg() { apply(localStorage.getItem(KEY) || current()); }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  window.ThemeInit = { apply, current };
})();
