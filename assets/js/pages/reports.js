/* reports.js — charts for mis/reports.php. */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') return;
  const el = document.getElementById('chMonthly');
  const data = JSON.parse(el.dataset.chart || '{"labels":[],"counts":[]}');
  new Chart(el, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [{ label: 'Tickets', data: data.counts, backgroundColor: '#38BDF8', borderRadius: 4 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 5, precision: 0 } } } }
  });
})();
