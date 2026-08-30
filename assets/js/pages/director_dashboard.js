/* director_dashboard.js — init status pie chart on HR dashboard. */
(function () {
  'use strict';
  var canvas = document.getElementById('statusPieChart');
  if (!canvas) return;

  var chart   = JSON.parse(canvas.dataset.chart || '[]');
  var labels  = JSON.parse(canvas.dataset.labels || '[]');
  var colors  = JSON.parse(canvas.dataset.colors || '[]');

  /* Filter out zero-count statuses. */
  var filtered = [];
  var filteredLabels = [];
  var filteredColors = [];
  chart.forEach(function (val, i) {
    if (val > 0) { filtered.push(val); filteredLabels.push(labels[i]); filteredColors.push(colors[i]); }
  });

  if (filtered.length === 0) return;

  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: filteredLabels,
      datasets: [{ data: filtered, backgroundColor: filteredColors, borderWidth: 2, borderColor: '#fff' }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, pointStyle: 'circle', font: { size: 11 } } },
        tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + ctx.raw; } } }
      }
    }
  });
})();
