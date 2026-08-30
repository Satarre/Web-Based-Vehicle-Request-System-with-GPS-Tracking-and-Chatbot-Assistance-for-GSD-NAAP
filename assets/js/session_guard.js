/**
 * assets/js/session_guard.js — was the tab-close auto-logout via sendBeacon.
 * Beacon removed (race condition with popstate/beforeunload caused random logouts).
 * Idle timeout (idle.js) + server-side gc_maxlifetime handle session cleanup now.
 */
(function () {
  /* Intentionally empty — kept as a placeholder for future session-related
     client-side logic if needed. */
})();
