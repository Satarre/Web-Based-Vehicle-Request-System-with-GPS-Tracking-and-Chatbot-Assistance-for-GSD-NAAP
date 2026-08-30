/* director_reports.js — charts + client-side xlsx export for director/reports.php. */
(function () {
  'use strict';

  const el = document.getElementById('chMonthly');
  if (el && typeof Chart !== 'undefined') {
    const data = JSON.parse(el.dataset.chart || '{"labels":[],"counts":[]}');
    new Chart(el, {
      type: 'bar',
      data: {
        labels: data.labels,
        datasets: [{ label: 'Tickets', data: data.counts, backgroundColor: '#38BDF8', borderRadius: 4 }]
      },
      options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
  }

  const vEl = document.getElementById('chVehicleUsage');
  if (vEl && typeof Chart !== 'undefined') {
    const vData = JSON.parse(vEl.dataset.chart || '{"labels":[],"counts":[]}');
    new Chart(vEl, {
      type: 'bar',
      data: {
        labels: vData.labels,
        datasets: [{ label: 'Trips', data: vData.counts, backgroundColor: '#22c55e', borderRadius: 4 }]
      },
      options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
  }

  const btn = document.getElementById('btnExportXlsx');
  const payload = document.getElementById('exportData');
  if (!btn || !payload || typeof XLSX === 'undefined') return;

  btn.addEventListener('click', () => {
    let data;
    try { data = JSON.parse(payload.value); } catch (_) { UI.toast('Export data unavailable.', 'error'); return; }
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data.tripLog), 'Trip Log');
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data.perVehicle), 'Per Vehicle');
    XLSX.writeFile(wb, 'NAAP-GSD-Trip-Report-' + new Date().toISOString().slice(0, 10) + '.xlsx');
  });
})();
