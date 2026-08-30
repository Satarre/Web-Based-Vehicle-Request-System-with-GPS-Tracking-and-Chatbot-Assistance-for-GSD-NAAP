/**
 * table_filter.js — instant client-side row filter.
 * Usage: <input type="search" data-filter="#tblId"> inside .card-head/.toolbar;
 * optional <span data-filter-count> shows visible row count while typing.
 */
document.addEventListener('input', function (e) {
  var inp = e.target.closest('input[data-filter]');
  if (!inp) return;
  var tbl = document.querySelector(inp.getAttribute('data-filter'));
  if (!tbl) return;
  if (tbl.hasAttribute('data-pager')) return; // table_pager.js owns search + pagination
  var q = inp.value.trim().toLowerCase();
  var shown = 0;
  tbl.querySelectorAll('tbody tr').forEach(function (tr) {
    if (tr.querySelector('td.empty')) return;
    var show = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
    tr.hidden = !show;
    if (show) shown++;
  });
  document.querySelectorAll('[data-filter-count]').forEach(function (c) { c.textContent = shown; });
});
