/* table_sort.js — clickable-header sorting for tables with [data-sortable].
 * Each sortable <th data-sort="key"> toggles asc/desc over the rendered rows;
 * cells carry a raw value in data-sort (falls back to textContent). Numeric
 * strings compare numerically. Works with plain <table class="data-table">.
 *
 * Paginated tables ([data-pager]) hide all but the current page, so auto table
 * layout only sees those rows and columns would resize when sorting pages to a
 * different row set. We freeze the natural column widths (measured against the
 * whole dataset) on desktop so sorting/filtering never shifts the columns.
 */
(function () {
  'use strict';

  var MOBILE_MAX = 900; // matches the app's stacked-card breakpoint

  function cellVal(td) {
    const raw = td.getAttribute('data-sort');
    return raw === null ? td.textContent : raw;
  }

  function sortTable(table, key, asc) {
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    let trs = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    if (trs.length <= 1) return;
    const empty = trs.find(tr => tr.querySelector('td[colspan]'));
    if (empty) return;

    let colIndex = null;
    Array.prototype.forEach.call(table.querySelectorAll('thead th'), (th, i) => {
      if (th.dataset.sort === key) colIndex = i;
    });
    if (colIndex === null) return;

    trs.sort((a, b) => {
      /* Pinned rows (e.g. drafts in My Requests) always lead, whichever
       * direction the column is sorted in. */
      const pinA = a.hasAttribute('data-pin') ? 1 : 0;
      const pinB = b.hasAttribute('data-pin') ? 1 : 0;
      if (pinA !== pinB) return pinB - pinA;
      const av = a.cells[colIndex] ? cellVal(a.cells[colIndex]) : '';
      const bv = b.cells[colIndex] ? cellVal(b.cells[colIndex]) : '';
      const sA = String(av).trim(), sB = String(bv).trim();
      const isNum = (s) => s !== '' && /^-?\d+(\.\d+)?$/.test(s);
      let cmp;
      if (isNum(sA) && isNum(sB)) cmp = parseFloat(sA) - parseFloat(sB);
      else cmp = sA.localeCompare(sB);
      return asc ? cmp : -cmp;
    });
    trs.forEach(tr => tbody.appendChild(tr));
    /* Re-page so the visible window follows the new order (tables with [data-pager]). */
    if (table.id && table.hasAttribute('data-pager') && window.TablePager) TablePager.refresh(table.id);
  }

  /* Rows that carry data (skip colspan placeholders and JS-hidden rows). */
  function dataRows(table) {
    return Array.prototype.filter.call(table.querySelectorAll('tbody tr'), function (tr) {
      return !tr.querySelector('td[colspan]') && !tr.dataset.searchHidden && !tr.dataset.statHidden;
    });
  }

  /* Freeze the natural column widths computed from the whole dataset. */
  function lockWidths(table) {
    if (table.dataset.colLocked || window.innerWidth <= MOBILE_MAX) return;
    var ths = Array.prototype.slice.call(table.querySelectorAll('thead th'));
    if (ths.length < 2) return;

    var rows = dataRows(table);
    var hidden = rows.filter(function (tr) { return !tr.classList.contains('page-visible'); });
    hidden.forEach(function (tr) { tr.classList.add('page-visible'); });

    var widths = ths.map(function (th) { return th.getBoundingClientRect().width; });
    var total = widths.reduce(function (a, b) { return a + b; }, 0);
    table.style.tableLayout = 'fixed';
    table.style.width = Math.ceil(total) + 'px';
    ths.forEach(function (th, i) { th.style.width = widths[i].toFixed(1) + 'px'; });

    hidden.forEach(function (tr) { tr.classList.remove('page-visible'); });
    table.dataset.colLocked = '1';
  }

  function unlockWidths(table) {
    if (!table.dataset.colLocked) return;
    delete table.dataset.colLocked;
    table.style.tableLayout = '';
    table.style.width = '';
    Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (th) { th.style.width = ''; });
  }

  function lockAll() {
    document.querySelectorAll('table[data-sortable][data-pager]').forEach(function (table) {
      unlockWidths(table); // re-measure on every (re)lock so resizes stay in sync
      lockWidths(table);
    });
  }

  document.querySelectorAll('table[data-sortable]').forEach((table) => {
    const headers = Array.prototype.slice.call(table.querySelectorAll('th[data-sort]'));
    headers.forEach((th) => {
      th.classList.add('sortable');
      th.addEventListener('click', () => {
        const asc = th.dataset.dir !== 'asc';
        th.dataset.dir = asc ? 'asc' : 'desc';
        headers.forEach(h => { if (h !== th) h.dataset.dir = ''; });
        sortTable(table, th.dataset.sort, asc);
      });
    });

    /* Optional default sort, e.g. data-sort-default="ticket:desc". Applied on
       load so the header shows the active direction and the first click
       inverts it (desc → asc). */
    const def = (table.dataset.sortDefault || '').split(':');
    if (def.length === 2 && def[0]) {
      const target = headers.find(h => h.dataset.sort === def[0]);
      if (target) {
        const asc = def[1] === 'asc';
        headers.forEach(h => { h.dataset.dir = h === target ? (asc ? 'asc' : 'desc') : ''; });
        sortTable(table, def[0], asc);
      }
    }
  });

  /* Measure after the webfont settles, then keep in sync with viewport size. */
  function initWidths() {
    lockAll();
    if (typeof document.fonts !== 'undefined' && document.fonts.ready) {
      document.fonts.ready.then(lockAll);
    }
  }
  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(lockAll, 150);
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initWidths);
  else initWidths();
})();
