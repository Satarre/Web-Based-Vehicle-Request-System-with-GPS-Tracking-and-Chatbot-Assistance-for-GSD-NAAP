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
    if (!res.ok) {
      const msg = (json && json.message) ? json.message : ('Request failed (' + res.status + ')');
      if (typeof logClientError === 'function') logClientError('API', msg, res.status);
      const err = new Error(msg); err.status = res.status;
      throw err;
    }
    return json || { success: true };
  }

  /* Multipart POST with upload-progress events. fetch() cannot report upload
   * progress, so the request-letter upload goes through XHR. Same contract as
   * request(): CSRF header, JSON unwrapping, logClientError, err.status. */
  function formWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', BASE + url.replace(/^\//, ''), true);
      xhr.withCredentials = true;
      if (typeof CSRF_TOKEN !== 'undefined') xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
      xhr.upload.onprogress = (e) => {
        if (onProgress && e.lengthComputable && e.total > 0) {
          onProgress(Math.min(100, Math.round((e.loaded / e.total) * 100)));
        }
      };
      xhr.onload = () => {
        let json = null;
        try { json = JSON.parse(xhr.responseText); } catch (_) { /* non-JSON body */ }
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve(json || { success: true });
          return;
        }
        const msg = (json && json.message) ? json.message : ('Request failed (' + xhr.status + ')');
        if (typeof logClientError === 'function') logClientError('API', msg, xhr.status);
        const err = new Error(msg);
        err.status = xhr.status;
        reject(err);
      };
      xhr.onerror = () => {
        if (typeof logClientError === 'function') logClientError('API', 'Network error', 0);
        reject(new Error('Network error — please check your connection.'));
      };
      xhr.onabort = () => reject(new Error('Upload cancelled.'));
      xhr.send(formData);
    });
  }

  window.API = {
    get:  (url)            => request('GET', url),
    post: (url, data)      => request('POST', url, data || {}),
    form: (url, formData)  => request('POST', url, formData, true),
    formProgress: formWithProgress,
  };
})();
