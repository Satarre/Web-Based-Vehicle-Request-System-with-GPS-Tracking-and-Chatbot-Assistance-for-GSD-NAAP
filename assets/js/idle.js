/**
 * assets/js/idle.js — inactivity auto-logout with warning overlay.
 * Timeout configurable server-side: window.APP_IDLE_TIMEOUT (seconds) injected
 * by partials/layout_foot.php from app_settings.IDLE_TIMEOUT_SEC.
 * EXEMPTION: document.body.dataset.keepSession = "1" disables idle logout
 * (driver mid-trip pages).
 */
(function () {
  const TIMEOUT = ((typeof APP_IDLE_TIMEOUT === 'number') ? APP_IDLE_TIMEOUT : 300) * 1000;
  const WARN_MS = 30 * 1000;
  const LOGOUT_URL = (typeof BASE_URL !== 'undefined' ? BASE_URL : '/') + 'auth/logout.php';

  let idleTimer = null, logoutTimer = null, countdownInt = null;

  function resetIdleTimer() {
    if (document.body.dataset.keepSession === '1') return;
    clearTimeout(idleTimer); clearTimeout(logoutTimer); clearInterval(countdownInt);
    const ov = document.getElementById('idleOverlay');
    if (ov) ov.classList.remove('show');
    idleTimer = setTimeout(showWarning, Math.max(TIMEOUT - WARN_MS, 5000));
  }

  function showWarning() {
    const ov = document.getElementById('idleOverlay');
    const cd = document.getElementById('idleCountdown');
    let remaining = WARN_MS / 1000;
    if (cd) cd.textContent = remaining;
    if (ov) ov.classList.add('show');
    countdownInt = setInterval(() => {
      remaining--;
      if (cd) cd.textContent = remaining > 0 ? remaining : 0;
    }, 1000);
    logoutTimer = setTimeout(() => { window.location.href = LOGOUT_URL; }, WARN_MS);
  }

  window.stayLoggedIn = resetIdleTimer;

  document.addEventListener('DOMContentLoaded', () => {
    // Inject overlay markup once if not server-rendered
    if (!document.getElementById('idleOverlay')) {
      const div = document.createElement('div');
      div.id = 'idleOverlay';
      div.innerHTML = '<div class="idle-box"><h3>Session Idle</h3>' +
        '<p style="margin:4px 0">You will be logged out in</p>' +
        '<div class="idle-count" id="idleCountdown">30</div>' +
        '<button class="inline-flex items-center justify-center gap-1.5 rounded-[8px] border border-transparent px-3.5 py-2 font-bold text-xs cursor-pointer bg-accent-strong text-white hover:bg-accent hover:text-on-accent" onclick="stayLoggedIn()">Stay Logged In</button></div>';
      div.querySelector('.idle-box').style.cssText = 'background:var(--bg-surface);color:var(--text)';
      document.body.appendChild(div);
    }
    resetIdleTimer();
  });

  ['mousemove', 'mousedown', 'keypress', 'scroll', 'touchstart', 'click']
    .forEach((evt) => document.addEventListener(evt, resetIdleTimer, { passive: true }));
})();
