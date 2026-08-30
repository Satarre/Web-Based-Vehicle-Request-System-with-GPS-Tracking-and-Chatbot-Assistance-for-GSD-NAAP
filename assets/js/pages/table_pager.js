/* table_pager.js — client-side 10-row tab pagination for any table[data-pager].
   Shows "1–10 · 11–20 · …" tabs + "Showing rows X–Y of N". Owns search filtering
   for its tables (table_filter.js skips [data-pager]). External filters (e.g.
   stat-card filters) set tr.dataset.statHidden="1" then call TablePager.refresh(id). */
(function () {
  'use strict';

  var per = 10;
  var tables = [];

  function dataRows(table) {
    return Array.prototype.filter.call(
      table.querySelectorAll('tbody tr'),
      function (tr) { return !tr.querySelector('td[colspan]'); }
    );
  }

  function find(table) {
    for (var i = 0; i < tables.length; i++) {
      if (tables[i].table === table) return tables[i];
    }
    return null;
  }

  function filterInput(table) {
    if (!table.id) return null;
    return document.querySelector('input[data-filter="#' + table.id + '"]');
  }

  function buildBar(table) {
    var wrap = table.closest('.overflow-x-auto') || table.parentNode;
    if (wrap.parentNode.querySelector(':scope > .table-pager')) return null;
    var bar = document.createElement('div');
    bar.className = 'table-pager';
    var info = document.createElement('span');
    info.className = 'pager-info';
    var tabs = document.createElement('div');
    tabs.className = 'pager-tabs';
    bar.appendChild(info);
    bar.appendChild(tabs);
    wrap.parentNode.insertBefore(bar, wrap.nextSibling);
    return { bar: bar, info: info, tabs: tabs };
  }

  function pool(st) {
    return st.rows.filter(function (tr) {
      return !tr.dataset.searchHidden && !tr.dataset.statHidden;
    });
  }

  function refresh(table) {
    var st = find(table);
    if (!st) return;
    var rows = pool(st);

    st.rows.forEach(function (tr) { tr.classList.remove('page-visible'); });

    if (!st.bar) {
      rows.forEach(function (tr) { tr.classList.add('page-visible'); });
      return;
    }

    var pages = Math.max(1, Math.ceil(rows.length / per));
    if (st.page > pages) st.page = pages;

    if (rows.length <= per) {
      rows.forEach(function (tr) { tr.classList.add('page-visible'); });
      st.bar.style.display = 'none';
      return;
    }

    rows.forEach(function (tr, i) {
      if (Math.floor(i / per) + 1 === st.page) tr.classList.add('page-visible');
    });

    var start = (st.page - 1) * per + 1;
    var end = Math.min(st.page * per, rows.length);
    st.info.textContent = 'Showing rows ' + start + '\u2013' + end + ' of ' + rows.length;

    var html = '';
    var prevDisabled = st.page <= 1;
    var nextDisabled = st.page >= pages;
    html += '<button type="button" class="pager-tab pager-nav" data-page="' + (st.page - 1) + '"' + (prevDisabled ? ' disabled' : '') + '>\u00AB</button>';
    var lo = Math.max(1, st.page - 1);
    var hi = Math.min(pages, lo + 2);
    lo = Math.max(1, hi - 2);
    for (var i = lo; i <= hi; i++) {
      var s = (i - 1) * per + 1;
      var e = Math.min(i * per, rows.length);
      html += '<button type="button" class="pager-tab' + (i === st.page ? ' active' : '') + '" data-page="' + i + '">' + s + '\u2013' + e + '</button>';
    }
    html += '<button type="button" class="pager-tab pager-nav" data-page="' + (st.page + 1) + '"' + (nextDisabled ? ' disabled' : '') + '>\u00BB</button>';
    st.tabs.innerHTML = html;
    st.bar.style.display = 'flex';
  }

  function attachSearch(st) {
    var inp = filterInput(st.table);
    if (!inp) return;
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase();
      st.rows.forEach(function (tr) {
        if (q ? tr.textContent.toLowerCase().indexOf(q) === -1 : false) {
          tr.dataset.searchHidden = '1';
        } else {
          delete tr.dataset.searchHidden;
        }
      });
      st.page = 1;
      refresh(st.table);
    });
  }

  function initTable(table) {
    var rows = dataRows(table);
    if (rows.length === 0) return;
    var st = { table: table, rows: rows, page: 1, bar: null, info: null, tabs: null };
    if (rows.length > per) {
      var built = buildBar(table);
      if (built) {
        st.bar = built.bar;
        st.info = built.info;
        st.tabs = built.tabs;
        st.tabs.addEventListener('click', function (e) {
          var btn = e.target.closest('.pager-tab');
          if (!btn || btn.disabled) return;
          st.page = parseInt(btn.dataset.page, 10);
          refresh(st.table);
        });
      }
    }
    tables.push(st);
    attachSearch(st);
    refresh(st.table);
  }

  function scan() {
    document.querySelectorAll('table[data-pager]').forEach(function (table) {
      if (!find(table)) initTable(table);
    });
  }

  window.TablePager = {
    refresh: function (id) {
      if (id) {
        var table = document.getElementById(id);
        if (table) refresh(table);
      } else {
        tables.forEach(function (st) { refresh(st.table); });
      }
    },
    scan: scan
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
  else scan();
})();
