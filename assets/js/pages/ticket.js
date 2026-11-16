/* ticket.js — role-aware actions on the shared ticket view. */
(function () {
  'use strict';

  /* Escape text before it is placed into any HTML sink (e.g. InfoWindow content). */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  const tid = new URLSearchParams(location.search).get('id');
  const post = (action, extra) => API.post('api/trips.php', Object.assign({ action, ticket_id: tid }, extra || {}));

  async function act(action, extra) {
    try {
      const r = await post(action, extra);
      UI.toast(r.message, 'ok');
      if (r.late) {
        UI.toast('Trip started ' + r.late + ' late.', 'warn');
      }
      const card = document.querySelector('.content-card');
      if (card) { card.classList.add('flash-success'); card.addEventListener('animationend', () => card.classList.remove('flash-success'), { once: true }); }
      setTimeout(() => location.reload(), 600);
    } catch (e) { UI.toast(e.message, 'err'); }
  }

  const on = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };

  const docxContainer = document.getElementById('docxContainer');
  if (docxContainer && typeof docx !== 'undefined' && typeof JSZip !== 'undefined') {
    fetch(docxContainer.dataset.docxUrl)
      .then(r => r.arrayBuffer())
      .then(buf => docx.renderAsync(buf, docxContainer))
      .catch(() => { docxContainer.innerHTML = '<p class="text-text-muted text-sm m-0">Preview failed — use Download to open the file.</p>'; });
  }

  on('btnApprove', () => UI.confirmDialog({ message: 'Approve this trip request? It will go to the GSD Dispatcher for dispatch.', okLabel: 'Approve' })
    .then(ok => { if (ok) act('approve'); }));

  let rejectMode = false;
  let stopMode = false;
  let correctMode = false;
  on('btnRejectOpen', () => {
    rejectMode = true;
    cancelMode = false;
    document.getElementById('reasonModalText').value = '';
    document.querySelector('#reasonModal .modal-head span').textContent = 'Reject Request';
    document.getElementById('reasonModalOk').textContent = 'Reject Request';
    document.getElementById('reasonModalOk').className = 'btn btn-danger';
    UI.modal('reasonModal', true);
  });

  let cancelMode = false;
  on('btnCancelOpen', async () => {
    const btn = document.getElementById('btnCancelOpen');
    if (btn.dataset.status === '1') {
      if (await UI.confirmDialog({ message: 'Are you sure you want to cancel your draft?', okLabel: 'Cancel Draft', danger: true })) {
        try {
          const r = await post('cancel');
          UI.toast(r.message, 'ok');
          setTimeout(() => location.href = BASE_URL + 'personnel/requests.php', 600);
        } catch (e) { UI.toast(e.message, 'err'); }
      }
    } else {
      cancelMode = true;
      rejectMode = false;
      document.getElementById('reasonModalText').value = '';
      document.querySelector('#reasonModal .modal-head span').textContent = 'Cancel Ticket';
      document.getElementById('reasonModalOk').textContent = 'Cancel Ticket';
      document.getElementById('reasonModalOk').className = 'btn btn-danger';
      UI.modal('reasonModal', true);
    }
  });

  on('reasonModalOk', () => {
    const reason = document.getElementById('reasonModalText').value.trim();
    if (!reason) return UI.toast('A reason is required.', 'err');
    if (rejectMode) { rejectMode = false; return act('reject', { reason }); }
    if (cancelMode) { cancelMode = false; return act('cancel', { reason }); }
    if (stopMode) { stopMode = false; return act('stop_trip', { reason }); }
    if (correctMode) { correctMode = false; return act('correct_sub_event', { reason }); }
  });

  const reasonModal = document.getElementById('reasonModal');
  if (reasonModal) {
    reasonModal.addEventListener('click', (e) => {
      if (e.target === reasonModal || e.target.closest('.modal-close')) {
        rejectMode = false;
        cancelMode = false;
        stopMode = false;
        correctMode = false;
      }
    });
  }

  on('btnStart', () => {
    document.getElementById('odoInput').value = '';
    UI.modal('startModal', true);
  });
  on('startOk', () => {
    const v = parseFloat(document.getElementById('odoInput').value);
    if (isNaN(v) || v < 0) return UI.toast('Enter a valid odometer reading.', 'err');
    act('start_trip', { odometer_start: v });
  });

  on('btnArrive', () => UI.confirmDialog({ message: 'Record arrival at the destination?', okLabel: 'Destination Reached' })
    .then(ok => { if (ok) act('record_arrival'); }));
  on('btnLeave', () => UI.confirmDialog({ message: 'Start the return trip from the destination?', okLabel: 'Start Return' })
    .then(ok => { if (ok) act('record_leave_dest'); }));
  on('btnTripComplete', () => UI.confirmDialog({
    message: 'Complete this trip? This records your return to base, then opens the post-trip report.',
    okLabel: 'Trip Complete', danger: true,
  }).then(async (ok) => {
    if (!ok) return;
    try {
      const r = await post('record_return');
      UI.toast(r.message, 'ok');
      setTimeout(() => { location.href = BASE_URL + 'driver/posttrip.php?id=' + tid; }, 500);
    } catch (e) { UI.toast(e.message, 'err'); }
  }));
  on('btnComplete', () => UI.confirmDialog({ message: 'Complete this trip ticket? This records your return to base.', okLabel: 'Complete Trip Ticket', danger: true })
    .then(ok => { if (ok) act('complete_trip'); }));
  on('btnStopOpen', () => {
    stopMode = true; rejectMode = false; cancelMode = false; correctMode = false;
    document.getElementById('reasonModalText').value = '';
    document.querySelector('#reasonModal .modal-head span').textContent = 'Stop Trip';
    document.getElementById('reasonModalOk').textContent = 'Stop Trip';
    document.getElementById('reasonModalOk').className = 'btn btn-danger';
    UI.modal('reasonModal', true);
  });
  on('btnCorrectOpen', () => {
    correctMode = true; rejectMode = false; cancelMode = false; stopMode = false;
    document.getElementById('reasonModalText').value = '';
    document.querySelector('#reasonModal .modal-head span').textContent = 'Correct Last Step';
    document.getElementById('reasonModalOk').textContent = 'Correct Last Step';
    document.getElementById('reasonModalOk').className = 'btn btn-primary';
    UI.modal('reasonModal', true);
  });

  on('btnReschedOpen', () => {
    document.getElementById('rsReason').value = '';
    UI.modal('reschedModal', true);
  });
  on('btnRefreshRoute', async () => {
    try {
      const r = await API.post('api/trips.php', { action: 'refresh_route', ticket_id: tid });
      UI.toast(r.message, 'ok');
      setTimeout(() => location.reload(), 700);
    } catch (e) { UI.toast(e.message, 'err'); }
  });
  on('reschedOk', async () => {
    const dep = document.getElementById('rsDep').value;
    const ret = document.getElementById('rsRet').value;
    const reason = document.getElementById('rsReason').value.trim();
    if (!reason) return UI.toast('A reason is required to reschedule.', 'err');
    if (!dep || !ret) return UI.toast('Both new schedule fields are required.', 'err');
    if (new Date(ret) <= new Date(dep)) return UI.toast('Return must be after departure.', 'err');
    if (new Date(dep) <= new Date()) return UI.toast('Scheduled departure must be in the future.', 'err');
    if (!(await UI.confirmDialog({ message: 'Send this trip back to the requester to pick a new schedule?', okLabel: 'Send for Reschedule', danger: true }))) return;
    act('reschedule', { scheduled_departure: dep, scheduled_return: ret, reason });
  });

  /* ── Map: traveled GPS trail + planned/remaining route legs ──────────
   * Blue = outbound (base → destination), orange = return (destination →
   * base). The active leg's already-traveled path is drawn as a thinner,
   * ~70%-opacity GPS trail in the same color; the remaining planned route
   * is trimmed at the vehicle and drawn solid + thicker on top. */
  const LEG_COLOR = { outbound: '#38BDF8', return: '#f97316' };

  function parseRoute(raw) {
    if (!raw || raw === 'null') return null;
    try { return JSON.parse(raw); } catch (e) { return null; }
  }

  function drawPolyline(map, bounds, opts) {
    const pts = opts.path;
    if (!pts || pts.length < 2) return false;
    new google.maps.Polyline({
      map, path: pts,
      strokeColor: opts.color,
      strokeWeight: opts.weight || 3,
      strokeOpacity: opts.opacity != null ? opts.opacity : 1,
      strokeLineCap: 'round', strokeLineJoin: 'round',
      zIndex: opts.zIndex || 1,
      clickable: false,
      icons: opts.dash ? [{ icon: { path: 'M 0,-1 0,1', strokeOpacity: 1 }, offset: '0', repeat: '14px' }] : undefined,
    });
    pts.forEach(p => bounds.extend(new google.maps.LatLng(p.lat, p.lng)));
    return true;
  }

  /* One route leg. `opts`: color, weight, opacity, dash, offset (meters),
   * zIndex, trimTo ({lat,lng} → show only the remaining portion). */
  function drawRouteLeg(map, bounds, route, opts) {
    if (!route || !route.polyline) return false;
    let pts = Geo.decodeGooglePolyline(route.polyline);
    if (opts.offset) pts = Geo.offsetPath(pts, opts.offset);
    if (opts.trimTo && pts.length >= 2) pts = Geo.trimPolyline(pts, opts.trimTo);
    const offline = route.route_source === 'offline';
    return drawPolyline(map, bounds, {
      color: opts.color,
      weight: opts.weight || 3,
      opacity: offline ? 0.7 : (opts.opacity != null ? opts.opacity : 1),
      dash: offline || opts.dash,
      zIndex: opts.zIndex || 1,
      path: pts,
    });
  }

  /* Edge-case fallback: straight dashed line between origin & destination. */
  function drawStraightLine(map, bounds, el, color) {
    const oLat = parseFloat(el.dataset.originLat), oLng = parseFloat(el.dataset.originLng);
    const dLat = parseFloat(el.dataset.destLat), dLng = parseFloat(el.dataset.destLng);
    if (!isFinite(oLat) || !isFinite(oLng) || !isFinite(dLat) || !isFinite(dLng)) return;
    drawPolyline(map, bounds, {
      color, weight: 3, opacity: 0.7, dash: true, zIndex: 2,
      path: [{ lat: oLat, lng: oLng }, { lat: dLat, lng: dLng }],
    });
  }

  let routeMapInited = false;
  let trailPolyline = null;
  let trailPts = [];
  let vehicleMarker = null;

  function initRouteMap() {
    const el = document.getElementById('crumbMap');
    if (!el || routeMapInited) return;
    if (typeof google === 'undefined' || !google.maps) {
      /* Maps script still loading (async) — callback __naapOnMapsReady retries. */
      return;
    }
    routeMapInited = true;

    const tracked = el.dataset.tracked === '1';
    const activeLeg = el.dataset.activeLeg === 'return' ? 'return' : 'outbound';
    const activeColor = LEG_COLOR[activeLeg];
    const inactiveLeg = activeLeg === 'outbound' ? 'return' : 'outbound';

    const oLat = parseFloat(el.dataset.originLat);
    const oLng = parseFloat(el.dataset.originLng);
    const dLat = parseFloat(el.dataset.destLat);
    const dLng = parseFloat(el.dataset.destLng);
    const hasOrigin = isFinite(oLat) && isFinite(oLng);
    const hasDest   = isFinite(dLat) && isFinite(dLng);

    let crumbs = [];
    try { crumbs = JSON.parse(el.dataset.crumbs); } catch (e) {}
    const hasCrumbs = Array.isArray(crumbs) && crumbs.length >= 2;
    const crumbPts = hasCrumbs
      ? crumbs.map(c => ({ lat: c[0], lng: c[1], leg: c[4] === 'ret' ? 'ret' : 'out' }))
      : [];
    const vehiclePos = hasCrumbs ? { lat: crumbs[crumbs.length - 1][0], lng: crumbs[crumbs.length - 1][1] } : null;

    /* Recorded road-snapped route per leg (written at completion). */
    const trackOut = el.dataset.trackOut || '';
    const trackRet = el.dataset.trackRet || '';
    const hasTracks = !!(trackOut || trackRet);

    if (!hasOrigin && !hasDest && !hasTracks && !hasCrumbs) return;

    const map = new google.maps.Map(el, {
      zoom: 13, mapTypeControl: false, streetViewControl: false,
      mapId: '4a7ad8d621c8e194',
    });

    /* Geofence circle */
    const gLat = parseFloat(el.dataset.lat), gLng = parseFloat(el.dataset.lng);
    if (isFinite(gLat) && isFinite(gLng)) {
      new google.maps.Circle({
        map, center: { lat: gLat, lng: gLng }, radius: parseFloat(el.dataset.radius) || 500,
        fillColor: '#38BDF8', fillOpacity: 0.04,
        strokeColor: '#38BDF8', strokeWeight: 1.5, strokeDashArray: [6, 6],
      });
    }

    const bounds = new google.maps.LatLngBounds();
    if (hasOrigin) bounds.extend(new google.maps.LatLng(oLat, oLng));
    if (hasDest)   bounds.extend(new google.maps.LatLng(dLat, dLng));

    /* Origin marker */
    if (hasOrigin) {
      addLabelMarker(map, { lat: oLat, lng: oLng }, el.dataset.originName || 'Origin', '#94a3b8');
    }

    /* Destination marker (visible even before dispatch). */
    if (hasDest) {
      addLabelMarker(map, { lat: dLat, lng: dLng }, el.dataset.destName || 'Destination', '#ef4444');
    }

    /* Completed trip: animate the ACTUAL recorded GPS points in order — blue
     * outbound (base → destination), orange return (destination → base). The
     * planned/optimized route, the raw trail line and the snapped track are
     * deliberately not drawn. */
    if (tracked) {
      crumbPts.forEach(p => bounds.extend(new google.maps.LatLng(p.lat, p.lng)));
      map.fitBounds(bounds, 40);
      animateTrackedPoints(map, crumbPts);
      return;
    }

    /* Traveled GPS trail — active direction color, ~70% opacity, thin. */
    if (hasCrumbs) {
      trailPts = crumbs.map(c => ({ lat: c[0], lng: c[1] }));
      trailPolyline = new google.maps.Polyline({
        map, path: trailPts,
        strokeColor: activeColor, strokeWeight: 3, strokeOpacity: hasTracks ? 0.25 : 0.7,
        strokeLineCap: 'round', strokeLineJoin: 'round',
        zIndex: 1, clickable: false,
      });
      trailPts.forEach(p => bounds.extend(new google.maps.LatLng(p.lat, p.lng)));
      addLabelMarker(map, trailPts[0], 'Departure · ' + crumbs[0][3], '#22c55e');
      /* Vehicle marker always renders above both polylines. */
      vehicleMarker = addLabelMarker(map, vehiclePos, 'Vehicle · ' + crumbs[crumbs.length - 1][3], '#ef4444', 100);
    }

    /* Route legs.
     * Active leg: remaining planned route trimmed at the vehicle, solid +
     * thick + full opacity. Inactive leg: subdued dashed preview (context). */
    const outboundRoute = parseRoute(el.dataset.routeOutbound);
    const returnRoute = parseRoute(el.dataset.routeReturn);

    const drewActive = drawRouteLeg(map, bounds, activeLeg === 'outbound' ? outboundRoute : returnRoute, {
      color: activeColor, weight: 5, opacity: 1, trimTo: vehiclePos, zIndex: 3,
    });
    drawRouteLeg(map, bounds, inactiveLeg === 'outbound' ? outboundRoute : returnRoute, {
      color: LEG_COLOR[inactiveLeg], weight: 3, opacity: 0.5,
      dash: true, offset: inactiveLeg === 'outbound' ? -3 : 3, zIndex: 2,
    });
    if (!drewActive && hasOrigin && hasDest) drawStraightLine(map, bounds, el, activeColor);

    /* Recorded (snapped) route overrides the raw trail when available. */
    if (hasTracks) {
      if (trackOut) drawPolyline(map, bounds, { color: LEG_COLOR.outbound, weight: 4, opacity: 0.95, zIndex: 4, path: Geo.decodeGooglePolyline(trackOut) });
      if (trackRet) drawPolyline(map, bounds, { color: LEG_COLOR.return, weight: 4, opacity: 0.95, zIndex: 4, path: Geo.decodeGooglePolyline(trackRet) });
    }

    map.fitBounds(bounds, 40);

    startLiveExtension(el, map);
  }

  /* Reveal the recorded GPS points in order over ~5 s: dots (blue outbound,
   * orange return) plus their connecting polyline, both growing point-by-point.
   * The return line is seeded with the last outbound point so the two legs join
   * at the destination instead of leaving a gap. */
  function animateTrackedPoints(map, pts) {
    if (!pts || !pts.length) return;
    const TOTAL_MS = 5000;
    const step = Math.max(8, Math.floor(TOTAL_MS / pts.length));

    const makeLine = (color) => new google.maps.Polyline({
      map, path: [],
      strokeColor: color, strokeWeight: 3, strokeOpacity: 0.85,
      strokeLineCap: 'round', strokeLineJoin: 'round',
      zIndex: 4, clickable: false,
    });
    const outLine = makeLine(LEG_COLOR.outbound);
    const retLine = makeLine(LEG_COLOR.return);
    const outPath = [];
    const retPath = [];
    let lastOut = null;
    let i = 0;

    const tick = () => {
      if (i >= pts.length) return;
      const p = pts[i++];
      const pos = { lat: p.lat, lng: p.lng };
      if (p.leg === 'ret') {
        if (!retPath.length && lastOut) retPath.push(lastOut);
        retPath.push(pos);
        retLine.setPath(retPath);
      } else {
        outPath.push(pos);
        lastOut = pos;
        outLine.setPath(outPath);
      }
      new google.maps.Marker({
        position: pos,
        map: map,
        clickable: false,
        zIndex: 5,
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 4,
          fillColor: p.leg === 'ret' ? LEG_COLOR.return : LEG_COLOR.outbound,
          fillOpacity: 1, strokeOpacity: 0,
        },
      });
      setTimeout(tick, step);
    };
    tick();
  }

  /* Progressive extension: while the trip is ongoing (roles 1/2/3), append
   * the vehicle's newest fix to the traveled trail and move the marker. */
  function startLiveExtension(el, map) {
    if (el.dataset.live !== '1') return;
    const tid = new URLSearchParams(location.search).get('id');
    if (!tid) return;
    const interval = setInterval(() => {
      if (document.hidden) return;
      fetch(BASE_URL + 'api/gps/latest.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
          if (!j || !j.success) return;
          const v = (j.vehicles || []).find(x => x.trip && String(x.trip.ticket_id) === String(tid));
          if (!v || v.lat == null || v.lng == null) {
            clearInterval(interval); // trip no longer active
            return;
          }
          const last = trailPts[trailPts.length - 1];
          if (last && Math.abs(last.lat - v.lat) < 1e-6 && Math.abs(last.lng - v.lng) < 1e-6) return;
          trailPts.push({ lat: v.lat, lng: v.lng });
          if (trailPolyline) trailPolyline.setPath(trailPts);
          if (vehicleMarker) vehicleMarker.setPosition({ lat: v.lat, lng: v.lng });
        })
        .catch(() => {});
    }, 15000);
  }

  function addLabelMarker(map, pos, label, color, zIndex) {
    var marker = new google.maps.Marker({
      position: pos, map: map, title: label, zIndex: zIndex || 0,
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 7, fillColor: color, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
    });
    var iw = new google.maps.InfoWindow({ content: '<div style="font-size:12px;padding:2px 6px">' + esc(label) + '</div>' });
    marker.addListener('click', function () { iw.open({ anchor: marker, map: map }); });
    return marker;
  }

  /* Maps library is lazy-loaded when the route map nears the viewport. */
  var crumbEl = document.getElementById('crumbMap');
  if (crumbEl && window.NAAPMaps) {
    NAAPMaps.observe(crumbEl, function (ok) { if (ok) initRouteMap(); });
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRouteMap);
  } else {
    initRouteMap();
  }
})();
