/* skeleton.js — shimmer loading placeholders.
 * Pages opt in with a [data-skeleton-page] marker on any container; its
 * .stat-card-value elements and .data-table bodies show a skeleton until
 * window.load (or a 1.5s safety net), then the real content fades back in.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function applySkeleton() {
    const root = document.querySelector('[data-skeleton-page]');
    if (!root) return;
    root.querySelectorAll('.stat-card-value').forEach(el => el.classList.add('skeleton'));
    root.querySelectorAll('table.data-table').forEach(tbl => {
      const tbody = tbl.querySelector('tbody');
      const cols = tbl.querySelectorAll('thead th').length || 1;
      if (tbody && !tbody.dataset.html) {
        tbody.dataset.html = tbody.innerHTML;
        tbody.innerHTML = '<tr class="skeleton-row"><td colspan="' + cols + '"><div class="skeleton skeleton-line"></div></td></tr>';
      }
    });
  }

  function clearSkeleton() {
    const root = document.querySelector('[data-skeleton-page]');
    if (!root) return;
    root.querySelectorAll('.stat-card-value.skeleton').forEach(el => el.classList.remove('skeleton'));
    root.querySelectorAll('table.data-table tbody[data-html]').forEach(tbody => {
      tbody.innerHTML = tbody.dataset.html;
      delete tbody.dataset.html;
    });
  }

  ready(() => {
    applySkeleton();
    let done = false;
    const clear = () => {
      if (done) return;
      done = true;
      clearSkeleton();
      window.removeEventListener('load', clear);
    };
    window.addEventListener('load', clear);
    setTimeout(clear, 1500); // safety net if load never fires
  });
})();