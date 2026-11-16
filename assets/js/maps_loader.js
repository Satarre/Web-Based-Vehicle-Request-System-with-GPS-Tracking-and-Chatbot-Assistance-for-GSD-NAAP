/* assets/js/maps_loader.js — lazy Google Maps JS loader.
 * Pages set window.NAAP_MAPS_KEY before this runs. The Maps library is
 * fetched only when a registered element nears the viewport (NAAPMaps.observe)
 * or immediately when a page needs it up front (NAAPMaps.ready). Callbacks
 * receive `true` when the API is ready, `false` when loading failed. */
(function () {
  'use strict';

  var state = 'idle';          // idle | loading | ready | failed
  var cbs = [];

  function isReady() { return typeof google !== 'undefined' && google.maps; }

  function flush(ok) {
    var q = cbs;
    cbs = [];
    q.forEach(function (cb) { try { cb(ok); } catch (e) { console.error(e); } });
  }

  function inject() {
    if (state !== 'idle') return;
    state = 'loading';
    window.__naapMapsLoaded = function () { state = 'ready'; flush(true); };
    var s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(window.NAAP_MAPS_KEY || '')
      + '&libraries=places&v=weekly&auth_referrer_policy=origin&callback=__naapMapsLoaded';
    s.async = true;
    s.onerror = function () { state = 'failed'; flush(false); };
    document.head.appendChild(s);
  }

  function ready(cb) {
    if (isReady()) { cb(true); return; }
    cbs.push(cb);
    inject();
  }

  function observe(el, cb) {
    if (!el) return;
    if (isReady()) { cb(true); return; }
    if (!('IntersectionObserver' in window)) { ready(cb); return; }
    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) { io.disconnect(); ready(cb); return; }
      }
    }, { rootMargin: '300px 0px' });
    io.observe(el);
  }

  window.NAAPMaps = { load: inject, ready: ready, observe: observe };
})();
