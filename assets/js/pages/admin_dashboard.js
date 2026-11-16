/* admin_dashboard.js — MIS dashboard charts (mis/dashboard.php). */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') return;
  var el = document.getElementById('chStatus');
  if (!el) return;
  var data = JSON.parse(el.dataset.chart || '{"labels":[],"counts":[],"names":[]}');
  // One bar color per status, resolved from the canonical --st-* tokens so the
  // bars match the status badges (app.css :root).
  var root = getComputedStyle(document.documentElement);
  var colors = (data.names || []).map(function (n) {
    return root.getPropertyValue('--st-' + String(n).toLowerCase().replace(/_/g, '-')).trim() || '#38BDF8';
  });
  new Chart(el, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [{ label: 'Tickets', data: data.counts, backgroundColor: colors.length ? colors : '#38BDF8', borderRadius: 4 }]
    },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
        x: { ticks: { autoSkip: false, maxRotation: 60, minRotation: 0 } }
      }
    }
  });
})();
