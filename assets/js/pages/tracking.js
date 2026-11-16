/**
 * assets/js/pages/tracking.js — live map + fleet panel for tracking.php.
 */
(function () {
  'use strict';

  function init() {
    const mapEl = document.getElementById('map');
    if (!mapEl || typeof google === 'undefined' || !google.maps) return;

    // No hardcoded default location: a configured geofence is used when set,
    // otherwise the map starts on a neutral overview and centers on vehicles.
    const fLat = parseFloat(mapEl.dataset.lat);
    const fLng = parseFloat(mapEl.dataset.lng);
    const fRad = parseFloat(mapEl.dataset.radius);
    const hasFence = isFinite(fLat) && isFinite(fLng) && (fLat !== 0 || fLng !== 0);
    const center = hasFence ? { lat: fLat, lng: fLng } : { lat: 12.8797, lng: 121.774 };
    const zoom = hasFence ? 15 : 6;

    const map = new google.maps.Map(mapEl, {
      center, zoom,
      mapTypeControl: false, streetViewControl: false,
    });
    if (hasFence && isFinite(fRad) && fRad > 0) {
      new google.maps.Circle({
        map, center, radius: fRad,
        fillColor: '#38BDF8', fillOpacity: 0.04,
        strokeColor: '#38BDF8', strokeWeight: 1.5, strokeDashArray: [6, 6],
      });
    }

    // Origin marker (only when a real origin is configured)
    const oLat = parseFloat(mapEl.dataset.originLat);
    const oLng = parseFloat(mapEl.dataset.originLng);
    const oName = mapEl.dataset.originName || 'Origin';
    if (isFinite(oLat) && isFinite(oLng) && (oLat !== 0 || oLng !== 0)) {
      const dot = document.createElement('div');
      dot.style.cssText = 'background:#38BDF8;border:2px solid #fff;border-radius:50%;width:14px;height:14px;box-shadow:0 1px 4px rgba(0,0,0,.3)';
      new google.maps.Marker({
        position: { lat: oLat, lng: oLng }, map,
        content: dot, title: oName,
      });
    }

    const markers = {};   // device_uid -> marker
    const infoWindows = {}; // device_uid -> InfoWindow
    const rows = {};      // device_uid -> <li>
    const list = document.getElementById('trackList');

    function badge(freshness) {
      const cls = { CURRENT: 'b-current', DELAYED: 'b-delayed', OFFLINE: 'b-offline' }[freshness] || 'b-offline';
      return '<span class="inline-flex px-2 py-0.5 rounded-full text-9-5px font-bold text-white ' + cls + '">' + freshness + '</span>';
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

    // age_sec is the POSITION age; link_age_sec is the heartbeat age. When the
    // link is far fresher than the position (a deadzone replay keeps the
    // heartbeat bumping while the cached fix stays frozen), say so explicitly.
    function ageText(v) {
      const pos = v.age_sec ?? null;
      const link = v.link_age_sec ?? null;
      let t = pos === null ? 'no position yet' : humanAge(pos);
      if (link !== null && (pos === null || link - pos > 60)) t += ' · seen ' + humanAge(link);
      return t;
    }

    function lerpMarker(marker, lat, lng, duration) {
      const start = marker.getPosition();
      const startTime = performance.now();
      function step(now) {
        const t = Math.min((now - startTime) / duration, 1);
        const ease = t * (2 - t);
        marker.setPosition({
          lat: start.lat() + (lat - start.lat()) * ease,
          lng: start.lng() + (lng - start.lng()) * ease
        });
        if (t < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    }

    function popupHtml(v) {
      let trip = '';
      if (v.trip) trip = '<br>Trip ' + esc(v.trip.dtt) + ' · ' + esc(v.trip.status);
      return '<strong>' + esc(v.plate_number) + '</strong> · ' + esc(v.model_name) +
        '<br>' + (v.speed_kph !== null ? esc(String(v.speed_kph)) + ' km/h' : '—') + ' · ' + esc(ageText(v)) +
        trip + '<br>' + esc(v.device_uid);
    }

    function render(vehicles) {
      document.getElementById('trackCount').textContent = vehicles.length + ' vehicle(s)';
      list.innerHTML = '';

      // No configured geofence: fit the map to reported vehicles once they exist.
      const pos = vehicles.filter(v => v.lat !== null && v.lng !== null);
      if (!hasFence && pos.length) {
        const b = new google.maps.LatLngBounds();
        pos.forEach(v => b.extend({ lat: v.lat, lng: v.lng }));
        map.fitBounds(b);
      }

      vehicles.forEach((v) => {
        const li = document.createElement('li');
        li.className = 'track-row';
        li.innerHTML =
          '<div><strong>' + esc(v.plate_number) + '</strong><span class="text-11px text-text-muted">' + esc(v.model_name) + '</span></div>' +
          '<div class="text-11px text-text-muted">' + (v.trip ? esc(v.trip.dtt) : 'no active trip') + '</div>' +
          badge(v.freshness);
        li.addEventListener('click', () => {
          if (markers[v.device_uid]) {
            map.panTo(markers[v.device_uid].getPosition());
            map.setZoom(Math.max(map.getZoom(), 16));
          }
        });
        list.appendChild(li);

        if (v.lat === null || v.lng === null) return;
        if (!markers[v.device_uid]) {
          const m = new google.maps.Marker({
            position: { lat: v.lat, lng: v.lng }, map,
          });
          const iw = new google.maps.InfoWindow({ content: popupHtml(v) });
          m.addListener('click', () => iw.open({ anchor: m, map }));
          markers[v.device_uid] = m;
          infoWindows[v.device_uid] = iw;
        } else {
          lerpMarker(markers[v.device_uid], v.lat, v.lng, 400);
          infoWindows[v.device_uid].setContent(popupHtml(v));
        }
      });
      setTimeout(() => google.maps.event.trigger(map, 'resize'), 50);
    }

    TrackingClient.start(render, 10000);
  }

  // Maps library is lazy-loaded (the map is the whole page, so this fires at once).
  if (window.NAAPMaps) {
    NAAPMaps.ready(init);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
