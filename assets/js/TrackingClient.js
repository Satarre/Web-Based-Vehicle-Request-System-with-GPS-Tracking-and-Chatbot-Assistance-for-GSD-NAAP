/**
 * assets/js/TrackingClient.js — polls api/gps/latest.php for live vehicle data.
 */
(function () {
  'use strict';
  const BASE = (typeof BASE_URL !== 'undefined') ? BASE_URL : '/';
  let timer = null;

  window.TrackingClient = {
    start(onData, intervalMs = 10000) {
      if (timer) return;
      const tick = async () => {
        try {
          const res = await fetch(BASE + 'api/gps/latest.php', { credentials: 'same-origin' });
          if (!res.ok) throw new Error('latest.php HTTP ' + res.status);
          onData((await res.json()).vehicles || []);
        } catch (e) {
          if (typeof logClientError === 'function') logClientError('TRACKING', e.message, 0);
        }
        timer = setTimeout(tick, intervalMs);
      };
      tick();
    },
    stop() {
      clearTimeout(timer);
      timer = null;
    },
  };

  /** Freshness buckets mirror app_settings GPS_FRESH_SEC / GPS_DELAYED_SEC. */
  window.humanAge = function (sec) {
    sec = Math.max(0, Math.round(sec));
    if (sec < 60) return sec + ' seconds ago';
    const m = Math.floor(sec / 60), s = sec % 60;
    return m + ' min ' + (s ? s + ' s' : '') + ' ago';
  };
})();