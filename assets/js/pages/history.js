/* history.js — stat-card filtering for personnel/history.php. */
(function () {
  'use strict';

  function rows() {
    return Array.prototype.filter.call(
      document.querySelectorAll('#tblHistory tbody tr'),
      function (tr) { return tr.querySelector('td'); }
    );
  }

  /* Attach each row's category list from the hidden stat-row spans (same order as rows). */
  var spans = document.querySelectorAll('[data-stat-row]');
  var trs = rows();
  spans.forEach(function (span, i) { if (trs[i]) trs[i].dataset.stat = span.dataset.stat; });

  document.addEventListener('click', function (e) {
    var card = e.target.closest('.stat-card[data-stat]');
    if (!card) return;
    var key = card.dataset.stat;
    if (card.classList.contains('is-filtered')) {
      card.classList.remove('is-filtered');
      rows().forEach(function (tr) { delete tr.dataset.statHidden; });
      if (window.TablePager) window.TablePager.refresh('tblHistory');
      return;
    }
    document.querySelectorAll('.stat-card[data-stat]').forEach(function (c) { c.classList.remove('is-filtered'); });
    card.classList.add('is-filtered');
    rows().forEach(function (tr) {
      var list = (tr.dataset.stat || '').split(' ');
      if (list.indexOf(key) === -1) { tr.dataset.statHidden = '1'; } else { delete tr.dataset.statHidden; }
    });
    if (window.TablePager) window.TablePager.refresh('tblHistory');
  });
})();