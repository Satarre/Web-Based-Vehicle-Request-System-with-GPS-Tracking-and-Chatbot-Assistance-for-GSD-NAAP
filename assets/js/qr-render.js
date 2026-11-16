/**
 * assets/js/qr-render.js — render an otpauth:// URI to a QR code entirely in
 * the browser using the vendored qrcode-generator library (assets/js/vendor/qrcode.js).
 * The MFA secret never leaves the origin (no external QR image service).
 */
(function () {
  'use strict';

  /**
   * Render `text` as a QR code into `el`.
   * @param {HTMLElement} el   container element
   * @param {string}      text otpauth:// provisioning URI
   * @param {number}      [size] target pixel size (default 200)
   * @returns {boolean} true on success, false if the library is missing/failed
   */
  function renderQr(el, text, size) {
    if (!el || !text || typeof qrcode === 'undefined') {
      return false;
    }
    size = size || 200;
    try {
      var qr = qrcode(0, 'M'); // type 0 = auto-size, medium error correction
      qr.addData(text);
      qr.make();
      var cell = Math.max(2, Math.floor(size / (qr.getModuleCount() + 8)));
      el.textContent = '';
      var img = document.createElement('img');
      img.alt = 'QR code for authenticator app';
      img.width = size;
      img.height = size;
      img.style.imageRendering = 'pixelated';
      img.src = qr.createDataURL(cell, 4);
      el.appendChild(img);
      return true;
    } catch (e) {
      return false;
    }
  }

  window.renderQr = renderQr;
})();
