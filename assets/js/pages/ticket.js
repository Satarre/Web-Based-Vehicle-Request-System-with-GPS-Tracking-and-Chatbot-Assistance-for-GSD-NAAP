/* ticket.js — role-aware actions on the shared ticket view. */
(function () {
  'use strict';
  const tid = new URLSearchParams(location.search).get('id');
  const post = (action, extra) => API.post('api/trips.php', Object.assign({ action, ticket_id: tid }, extra || {}));

  async function act(action, extra) {
    try {
      const r = await post(action, extra);
      UI.toast(r.message, 'ok');
      if (r.late) {
        UI.toast('Trip started ' + r.late + ' late.', 'warn');
      }
      setTimeout(() => location.reload(), 600);
    } catch (e) { UI.toast(e.message, 'err'); }
  }

  const on = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };

  on('btnVerify', () => UI.confirmDialog({ message: 'Verify this request? It will go to GSD Staff for approval.', okLabel: 'Verify' })
    .then(ok => { if (ok) act('hr_verify'); }));

  on('btnApprove', () => UI.confirmDialog({ message: 'Approve this trip request?', okLabel: 'Approve' })
    .then(ok => { if (ok) act('approve'); }));

  let rejectMode = false;
  on('btnRejectOpen', () => {
    rejectMode = true;
    document.getElementById('reasonModalText').value = '';
    document.querySelector('#reasonModal .modal-head span').textContent = 'Reject Request';
    document.getElementById('reasonModalOk').textContent = 'Reject Request';
    document.getElementById('reasonModalOk').className = 'inline-flex items-center justify-center gap-1.5 rounded-[8px] border border-transparent px-3.5 py-2 font-bold text-xs cursor-pointer bg-rejected text-white hover:opacity-90';
    UI.modal('reasonModal', true);
  });

  let cancelMode = false;
  on('btnCancelOpen', async () => {
    const btn = document.getElementById('btnCancelOpen');
    if (btn.dataset.status === '1') {
      if (await UI.confirmDialog({ message: 'Are you sure you want to delete your draft?', okLabel: 'Cancel Draft', danger: true })) {
        try {
          const r = await post('cancel');
          UI.toast(r.message, 'ok');
          setTimeout(() => location.href = BASE_URL + 'personnel/requests.php', 600);
        } catch (e) { UI.toast(e.message, 'err'); }
      }
    } else {
      cancelMode = true;
      document.getElementById('reasonModalText').value = '';
      document.querySelector('#reasonModal .modal-head span').textContent = 'Cancel Ticket';
      document.getElementById('reasonModalOk').textContent = 'Cancel Ticket';
      document.getElementById('reasonModalOk').className = 'inline-flex items-center justify-center gap-1.5 rounded-[8px] border border-transparent px-3.5 py-2 font-bold text-xs cursor-pointer bg-rejected text-white hover:opacity-90';
      UI.modal('reasonModal', true);
    }
  });

  on('reasonModalOk', () => {
    const reason = document.getElementById('reasonModalText').value.trim();
    if (!reason) return UI.toast('A reason is required.', 'err');
    if (rejectMode) { rejectMode = false; return act('reject', { reason }); }
    if (cancelMode) { cancelMode = false; return act('cancel', { reason }); }
  });

  on('btnStart', () => {
    document.getElementById('odoInput').value = '';
    UI.modal('startModal', true);
  });
  on('startOk', () => {
    const v = parseFloat(document.getElementById('odoInput').value);
    if (isNaN(v) || v < 0) return UI.toast('Enter a valid odometer reading.', 'err');
    act('start_trip', { odometer_start: v });
  });

  on('btnArrive', () => UI.confirmDialog({ message: 'Record arrival at destination?', okLabel: 'Arrived' })
    .then(ok => { if (ok) act('record_arrival'); }));
  on('btnLeave', () => UI.confirmDialog({ message: 'Record departure from destination?', okLabel: 'Confirm' })
    .then(ok => { if (ok) act('record_leave_dest'); }));
  on('btnReturn', () => UI.confirmDialog({ message: 'Record return to base?', okLabel: 'Confirm' })
    .then(ok => { if (ok) act('record_return'); }));
  on('btnComplete', () => UI.confirmDialog({ message: 'Complete this trip? Post-trip report must be submitted first.', okLabel: 'Complete', danger: true })
    .then(ok => { if (ok) act('complete_trip'); }));

  on('btnReschedOpen', () => UI.modal('reschedModal', true));
  on('reschedOk', () => {
    const dep = document.getElementById('rsDep').value;
    const ret = document.getElementById('rsRet').value;
    if (!dep || !ret) return UI.toast('Both new schedule fields are required.', 'err');
    if (new Date(ret) <= new Date(dep)) return UI.toast('Return must be after departure.', 'err');
    act('reschedule', { scheduled_departure: dep, scheduled_return: ret });
  });

  // GPS breadcrumb map (leaflet loads with defer — init after DOM ready).
  function initCrumbMap() {
    const el = document.getElementById('crumbMap');
    if (!el || typeof L === 'undefined') return;
    const crumbs = JSON.parse(el.dataset.crumbs);
    const lat = parseFloat(el.dataset.lat), lng = parseFloat(el.dataset.lng);
    const map = L.map(el);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    if (lat && lng) {
      L.circle([lat, lng], { radius: parseFloat(el.dataset.radius) || 500,
        color: '#38BDF8', weight: 1.5, dashArray: '6 6', fillOpacity: 0.04 }).addTo(map);
    }
    const pts = crumbs.map(c => [c[0], c[1]]);
    L.polyline(pts, { color: '#38BDF8', weight: 3 }).addTo(map);
    const mark = (p, label) => L.marker(p).addTo(map).bindPopup(label);
    mark(pts[0], 'Departure · ' + crumbs[0][3]);
    mark(pts[pts.length - 1], 'Latest fix · ' + crumbs[crumbs.length - 1][3]);
    map.fitBounds(L.latLngBounds(pts).pad(0.25));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initCrumbMap);
  else initCrumbMap();
})();
