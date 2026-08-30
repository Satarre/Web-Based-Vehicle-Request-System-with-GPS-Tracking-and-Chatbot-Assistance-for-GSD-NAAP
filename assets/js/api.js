/**
 * assets/js/api.js — fetch wrapper for /api/* endpoints.
 * Auto-attaches CSRF token; unwraps JSON; routes errors through error_logger.
 */
(function () {
  'use strict';
  const BASE = (typeof BASE_URL !== 'undefined') ? BASE_URL : '/';

  async function request(method, url, body, isForm) {
    const opts = { method, credentials: 'same-origin', headers: {} };
    if (typeof CSRF_TOKEN !== 'undefined') opts.headers['X-CSRF-Token'] = CSRF_TOKEN;
    if (body !== undefined && body !== null) {
      if (isForm) {
        opts.body = body; // FormData (multipart uploads)
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify({ ...body, ...(typeof CSRF_TOKEN !== 'undefined' ? { csrf_token: CSRF_TOKEN } : {}) });
      }
    }
    let res;
    try {
      res = await fetch(BASE + url.replace(/^\//, ''), opts);
    } catch (e) {
      if (typeof logClientError === 'function') logClientError('API', e, 0);
      throw new Error('Network error — please check your connection.');
    }
    let json = null;
    try { json = await res.json(); } catch (_) { /* empty body (204) */ }
    window._lastApiCall = Date.now(); // tells session_guard this unload is app-driven
    if (!res.ok) {
      const msg = (json && json.message) ? json.message : ('Request failed (' + res.status + ')');
      if (typeof logClientError === 'function') logClientError('API', msg, res.status);
      const err = new Error(msg); err.status = res.status;
      throw err;
    }
    return json || { success: true };
  }

  window.API = {
    get:  (url)            => request('GET', url),
    post: (url, data)      => request('POST', url, data || {}),
    form: (url, formData)  => request('POST', url, formData, true),
  };
})();
