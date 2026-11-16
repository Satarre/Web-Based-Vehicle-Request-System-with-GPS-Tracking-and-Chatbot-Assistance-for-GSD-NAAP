/* SSEClient.js — Server-Sent Events wrapper for real-time badge + notification updates.
 * Usage: SSEClient.start(onNotification, onBadgeUpdate)
 *        SSEClient.stop()
 * Falls back to polling if EventSource is unavailable.
 */
const SSEClient = (() => {
  'use strict';
  let es = null;
  let pollTimer = null;
  let onNotifCb = null;
  let onBadgeCb = null;
  let started = false;   // SSE only acts after start() is called (live pages)
  let paused = false;    // true while the tab is hidden / offline

  /* ── Update sidebar badge pills ─────────────────────────────────────── */
  function applyBadges(data) {
    document.querySelectorAll('.nav-badge[data-badge]').forEach(el => {
      const key = el.dataset.badge;
      let count = 0;
      if (key === '2') count = data.pending  || 0;  // PENDING
      if (key === '4') count = data.approved || 0;  // APPROVED
      if (count > 0) {
        el.textContent = count > 99 ? '99+' : count;
        el.classList.remove('hidden');
      } else {
        el.classList.add('hidden');
      }
    });

    if (onBadgeCb) onBadgeCb(data);
  }

  /* ── Show toast for incoming notification ───────────────────────────── */
  function showNotification(data) {
    if (typeof UI !== 'undefined' && UI.toast) {
      UI.toast(data.message, 'info', {
        href: data.ticket_id ? BASE_URL + 'ticket.php?id=' + data.ticket_id : null,
      });
    }
    if (onNotifCb) onNotifCb(data);
  }

  /* ── SSE connection ─────────────────────────────────────────────────── */
  function connect() {
    if (es || paused || !started) return;
    es = new EventSource(BASE_URL + 'api/sse.php');

    es.addEventListener('notification', (e) => {
      try { showNotification(JSON.parse(e.data)); } catch (_) {}
    });

    es.addEventListener('badges', (e) => {
      try { applyBadges(JSON.parse(e.data)); } catch (_) {}
    });

    es.onerror = () => {
      es.close();
      es = null;
      /* Fallback to polling after a brief delay */
      setTimeout(startPolling, 3000);
    };
  }

  /* ── Polling fallback ───────────────────────────────────────────────── */
  function pollOnce() {
    if (!started) return;
    fetch(BASE_URL + 'api/badge_counts.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(j => { if (j.success) applyBadges(j); })
      .catch(() => {});
  }

  function startPolling() {
    if (pollTimer) return;
    pollOnce();
    pollTimer = setInterval(pollOnce, 30000);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  /* ── Pause/resume: free the connection slot when the tab is hidden or
   * the network is down; reconnect + refresh badges when it returns. */
  function pause() {
    if (!started) return;
    paused = true;
    if (es) { es.close(); es = null; }
    stopPolling();
  }

  function resume() {
    if (!started) return;
    paused = false;
    pollOnce();
    connect();
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) pause(); else resume();
  });
  window.addEventListener('online', resume);
  window.addEventListener('offline', pause);

  /* ── Public API ─────────────────────────────────────────────────────── */
  return {
    start(onNotification, onBadgeUpdate) {
      started = true;
      paused = false;
      onNotifCb = onNotification || null;
      onBadgeCb = onBadgeUpdate || null;
      if (typeof EventSource !== 'undefined') {
        connect();
      } else {
        startPolling();
      }
    },
    stop() {
      started = false;
      if (es) { es.close(); es = null; }
      stopPolling();
    },
    refresh() {
      pollOnce();
    }
  };
})();
