/* tracker.js — role GPS TRACKER.
 * 1) Status polling (api/gps/me.php) for the live card.
 * 2) Browser location sharing: watchPosition → queue → POST
 *    api/gps/tracker_ping.php (session-auth; same pipeline as hardware).
 *    Fixes that fail to send are queued with their original timestamps and
 *    replayed when connectivity returns (hardware-parity offline behavior). */
(function () {
  'use strict';
  const STATUS_MS = 10000;
  const BADGE_CLS = 'inline-flex px-2 py-0.5 rounded-full text-9-5px font-bold text-white ';
  const FRESH = { CURRENT: 'b-current', DELAYED: 'b-delayed', OFFLINE: 'b-offline' };
  const QUEUE_MAX = 50;
  const DRAIN_BURST = 5; // max POSTs per tick while replaying a backlog

  const $id = (id) => document.getElementById(id);
  function set(id, text) { const el = $id(id); if (el) el.textContent = text; }

  /* ── live status card ─────────────────────────────────────────────── */
  function render(d) {
    if (!d || !d.configured) { set('trkMsg', 'No tracker device is configured for this account.'); return; }
    const badge = $id('trkFresh');
    if (badge) {
      badge.textContent = d.freshness || 'OFFLINE';
      badge.className = BADGE_CLS + (FRESH[d.freshness] || 'b-offline');
    }
    const msg = $id('trkMsg');
    if (msg) msg.textContent = d.is_active ? '' : 'This tracker has been disabled by MIS.';
    // age_sec = position age, link_age_sec = heartbeat age (diverges during a
    // stale backlog replay — show both so the tracker knows what's stale).
    const posTxt = d.age_sec === null
      ? 'No position yet'
      : (typeof humanAge === 'function' ? humanAge(d.age_sec) : d.age_sec + ' s ago');
    const link = d.link_age_sec ?? null;
    const diverged = link !== null && (d.age_sec === null || link - d.age_sec > 60);
    set('trkSeen', diverged ? posTxt + ' · seen ' + (typeof humanAge === 'function' ? humanAge(link) : link + ' s ago') : posTxt);
    set('trkPos', (d.lat === null || d.lng === null) ? '—' : d.lat.toFixed(6) + ', ' + d.lng.toFixed(6));
    set('trkSpeed', d.speed_kph === null ? '—' : d.speed_kph + ' km/h');
    set('trkHeading', d.heading === null ? '—' : Math.round(d.heading) + '°');
  }

  async function tickStatus() {
    try {
      const res = await fetch(BASE_URL + 'api/gps/me.php', { credentials: 'same-origin' });
      if (res.status === 401 || res.status === 403) {
        window.location.href = BASE_URL + 'auth/login.php';
        return;
      }
      if (res.ok) render(await res.json());
    } catch (e) {
      if (typeof logClientError === 'function') logClientError('TRACKER', e.message, 0);
    }
    setTimeout(tickStatus, STATUS_MS);
  }
  tickStatus();

  /* ── location sharing ─────────────────────────────────────────────── */
  const card = $id('trkShareCard');
  if (!card) return;

  const intervalSec = Math.max(5, parseInt(card.dataset.intervalSec, 10) || 20);
  const queueKey = 'naap_tracker_queue_' + (card.dataset.deviceUid || 'unknown');

  function loadQueue() {
    try {
      const q = JSON.parse(localStorage.getItem(queueKey) || '[]');
      return Array.isArray(q) ? q : [];
    } catch (e) { return []; }
  }
  function saveQueue() {
    try { localStorage.setItem(queueKey, JSON.stringify(queue)); } catch (e) { /* storage denied */ }
  }

  let queue = loadQueue();
  let lastQueuedTs = queue.length ? queue[queue.length - 1].recorded_at : null;
  let watchId = null;
  let sendTimer = null;
  let shared = false;
  let busy = false;
  let wakeLock = null;
  let lastCapture = null; // newest raw fix from watchPosition
  const stats = { sent: 0, stored: 0, throttled: 0, failed: 0 };

  function updateStats() {
    set('trkSent', stats.sent);
    set('trkStored', stats.stored);
    set('trkThrottled', stats.throttled);
    set('trkQueued', queue.length);
  }
  function msg(text, ok) {
    const el = $id('trkShareMsg');
    if (!el) return;
    el.textContent = text;
    el.className = 'text-sm mt-2.5 ' + (ok === true ? 'text-completed' : (ok === false ? 'text-rejected' : 'text-text-muted'));
  }
  function setSharing(on) {
    const start = $id('trkShareStart'), stop = $id('trkShareStop'), badge = $id('trkShareBadge');
    if (start) start.style.display = on ? 'none' : '';
    if (stop) stop.style.display = on ? '' : 'none';
    if (badge) {
      badge.textContent = on ? 'SHARING' : 'STOPPED';
      badge.className = 'badge ' + (on ? 'badge-completed' : 'badge-draft');
    }
  }

  function enqueue(p) {
    const c = p.coords;
    const fix = {
      latitude: c.latitude,
      longitude: c.longitude,
      accuracy_m: (typeof c.accuracy === 'number' && isFinite(c.accuracy)) ? c.accuracy : null,
      speed_kph: (typeof c.speed === 'number' && isFinite(c.speed)) ? Math.max(0, c.speed * 3.6) : null,
      heading: (typeof c.heading === 'number' && isFinite(c.heading)) ? c.heading : null,
      altitude_m: (typeof c.altitude === 'number' && isFinite(c.altitude)) ? c.altitude : null,
      recorded_at: Math.floor(p.timestamp / 1000),
    };
    if (lastQueuedTs !== null && fix.recorded_at <= lastQueuedTs) return;
    queue.push(fix);
    lastQueuedTs = fix.recorded_at;
    while (queue.length > QUEUE_MAX) queue.shift();
    saveQueue();
    updateStats();
  }

  async function sendOne(fix) {
    const r = await API.post('api/gps/tracker_ping.php', fix);
    stats.sent++;
    if (r.stored) stats.stored++;
    if (r.throttled) stats.throttled++;
    updateStats();
  }

  /** Sends up to `burst` fixes, oldest first. Returns false when the run stopped early. */
  async function flushQueue(burst) {
    let sent = 0;
    while (queue.length && sent < burst) {
      try {
        await sendOne(queue[0]);
      } catch (e) {
        stats.failed++;
        updateStats();
        if (e.status === 401 || e.status === 403) {
          window.location.href = BASE_URL + 'auth/login.php';
          return false;
        }
        if (e.status === 409) {
          stopSharing(e.message || 'This tracker is disabled or unpaired.');
          return false;
        }
        msg('Send failed — ' + queue.length + ' fix(es) queued; retrying automatically.', false);
        return false;
      }
      queue.shift();
      saveQueue();
      updateStats();
      sent++;
    }
    return true;
  }

  async function tick() {
    if (!shared || busy) return;
    busy = true;
    try {
      if (lastCapture) enqueue(lastCapture);
      const burst = queue.length > 3 ? DRAIN_BURST : 1;
      const ok = await flushQueue(burst);
      if (ok && queue.length === 0) {
        msg('Sharing · last fix sent ' + new Date().toLocaleTimeString() + '.', true);
      } else if (ok) {
        msg('Replaying ' + queue.length + ' queued fix(es)…', true);
      }
    } finally {
      busy = false;
    }
  }

  function onPos(p) { lastCapture = p; }
  function onPosErr(e) {
    if (e.code === 1) msg('Location permission denied — allow location access for this site, then start again.', false);
    else if (e.code === 2) msg('Location unavailable — waiting for a GPS fix…', false);
    else msg('Still locating…', false);
  }

  async function requestWakeLock() {
    try {
      if ('wakeLock' in navigator && document.visibilityState === 'visible') {
        wakeLock = await navigator.wakeLock.request('screen');
      }
    } catch (e) { /* optional — not all browsers allow it */ }
  }
  function releaseWakeLock() {
    if (wakeLock) { try { wakeLock.release(); } catch (e) { /* ignore */ } wakeLock = null; }
  }
  document.addEventListener('visibilitychange', function () {
    if (shared && document.visibilityState === 'visible') requestWakeLock();
  });

  function startSharing() {
    if (!navigator.geolocation) { msg('This browser does not support location access.', false); return; }
    if (!window.isSecureContext) {
      msg('Location sharing requires HTTPS — open this page on the secure deployment (or localhost).', false);
      return;
    }
    shared = true;
    setSharing(true);
    msg('Waiting for the first GPS fix…');
    watchId = navigator.geolocation.watchPosition(onPos, onPosErr, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
    requestWakeLock();
    tick(); // flush any offline backlog immediately
    sendTimer = setInterval(tick, intervalSec * 1000);
  }

  function stopSharing(reason) {
    shared = false;
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    clearInterval(sendTimer); sendTimer = null;
    releaseWakeLock();
    lastCapture = null;
    setSharing(false);
    msg(reason || 'Sharing stopped. Queued fixes will be replayed next time you start.', true);
  }

  const startBtn = $id('trkShareStart');
  const stopBtn = $id('trkShareStop');
  if (startBtn) startBtn.addEventListener('click', startSharing);
  if (stopBtn) stopBtn.addEventListener('click', function () { stopSharing(); });

  updateStats();
  if (queue.length) msg(queue.length + ' fix(es) queued from a previous session — they will be replayed when sharing starts.');
})();
