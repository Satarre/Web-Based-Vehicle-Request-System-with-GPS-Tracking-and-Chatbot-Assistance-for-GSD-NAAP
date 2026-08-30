/**
 * assets/js/pages/tracking.js — live map + fleet panel for tracking.php.
 */
(function () {
  'use strict';

  function init() {
    const mapEl = document.getElementById('map');
    if (!mapEl || typeof L === 'undefined') return;

    const lat = parseFloat(mapEl.dataset.lat) || 14.5126;
    const lng = parseFloat(mapEl.dataset.lng) || 121.0182;
    const radius = parseFloat(mapEl.dataset.radius) || 500;

    const map = L.map(mapEl).setView([lat, lng], 15);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    L.circle([lat, lng], {
      radius, color: '#38BDF8', weight: 1.5, dashArray: '6 6', fillOpacity: 0.04,
    }).addTo(map);

    const markers = {};   // device_uid -> marker
    const rows = {};      // device_uid -> <li>
    const list = document.getElementById('trackList');

    function badge(freshness) {
      const cls = { CURRENT: 'b-current', DELAYED: 'b-delayed', OFFLINE: 'b-offline' }[freshness] || 'b-offline';
      return '<span class="inline-flex px-2 py-0.5 rounded-full text-9-5px font-bold text-white ' + cls + '">' + freshness + '</span>';
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

    function popupHtml(v) {
      let trip = '';
      if (v.trip) trip = '<br>Trip ' + esc(v.trip.dtt) + ' · ' + esc(v.trip.status);
      return '<strong>' + esc(v.plate_number) + '</strong> · ' + esc(v.model_name) +
        '<br>' + (v.speed_kph !== null ? v.speed_kph + ' km/h' : '—') + ' · ' + humanAge(v.age_sec ?? 9e9) +
        trip + '<br>' + esc(v.device_uid);
    }

    function render(vehicles) {
      document.getElementById('trackCount').textContent = vehicles.length + ' vehicle(s)';
      list.innerHTML = '';
      vehicles.forEach((v) => {
        const li = document.createElement('li');
        li.className = 'track-row';
        li.innerHTML =
          '<div><strong>' + esc(v.plate_number) + '</strong><span class="text-11px text-text-muted">' + esc(v.model_name) + '</span></div>' +
          '<div class="text-11px text-text-muted">' + (v.trip ? esc(v.trip.dtt) : 'no active trip') + '</div>' +
          badge(v.freshness);
        li.addEventListener('click', () => {
          if (markers[v.device_uid]) map.flyTo(markers[v.device_uid].getLatLng(), Math.max(map.getZoom(), 16));
        });
        list.appendChild(li);

        if (v.lat === null || v.lng === null) return;
        if (!markers[v.device_uid]) {
          markers[v.device_uid] = L.marker([v.lat, v.lng]).addTo(map).bindPopup(popupHtml(v));
        } else {
          markers[v.device_uid].setLatLng([v.lat, v.lng]);
          markers[v.device_uid].setPopupContent(popupHtml(v));
        }
      });
      setTimeout(() => map.invalidateSize(), 50);
    }

    TrackingClient.start(render, 10000);
  }

  // Leaflet is loaded with defer — wait for it.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
