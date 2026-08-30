/* location-autocomplete.js — reusable place autocomplete (Photon geocoder via api/locations.php). */
(function () {
  'use strict';

  /** Haversine distance in km between two lat/lng points. */
  function distKm(lat1, lon1, lat2, lon2) {
    var R = 6371;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLon = (lon2 - lon1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
          + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
          * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function fmtDist(km) {
    return km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(1) + ' km';
  }

  /**
   * LocationAutocomplete — attach to an <input> to get Grab-like location suggestions.
   *
   * @param {HTMLInputElement} input  — the text input to enhance
   * @param {object}           opts   — options
   * @param {number}           opts.lat — default centre lat (e.g. NAAP)
   * @param {number}           opts.lon — default centre lon
   * @param {function}         opts.onSelect — callback({name, address, latitude, longitude, place_id})
   * @param {string}           opts.apiUrl  — backend endpoint (default: api/locations.php)
   */
  function LocationAutocomplete(input, opts) {
    opts = opts || {};
    var apiBase = opts.apiUrl || ((typeof BASE_URL !== 'undefined' ? BASE_URL : '/') + 'api/locations.php');
    var defaultLat = opts.lat || 14.5126;
    var defaultLon = opts.lon || 121.0182;
    var onSelect = opts.onSelect || function () {};
    var debounceMs = 350;
    var minChars = 2;

    var userLat = null, userLon = null;
    var timer = null;
    var activeReq = 0;
    var selected = null;
    var highlightIdx = -1;

    /* Build DOM. */
    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var drop = document.createElement('div');
    drop.className = 'la-dropdown';
    drop.style.cssText = 'display:none';
    wrap.appendChild(drop);

    /* Try geolocation once. */
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(function (p) {
        userLat = p.coords.latitude;
        userLon = p.coords.longitude;
      }, function () {}, { timeout: 5000 });
    }

    function showLoading() {
      drop.innerHTML = '<div class="la-loading">Searching\u2026</div>';
      drop.style.display = 'block';
    }

    function showEmpty() {
      drop.innerHTML = '<div class="la-empty">No locations found</div>';
      drop.style.display = 'block';
    }

    function hide() {
      drop.style.display = 'none';
      drop.innerHTML = '';
      highlightIdx = -1;
    }

    function render(results) {
      if (!results.length) { showEmpty(); return; }
      var html = '';
      var lat = userLat || defaultLat;
      var lon = userLon || defaultLon;
      for (var i = 0; i < results.length; i++) {
        var r = results[i];
        var km = distKm(lat, lon, r.latitude, r.longitude);
        var distStr = fmtDist(km);
        html += '<div class="la-item" data-idx="' + i + '">'
              + '<div class="la-item-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>'
              + '<div class="la-item-text">'
              + '<div class="la-item-name">' + esc(r.name) + '</div>'
              + '<div class="la-item-addr">' + distStr + (r.address ? ' \u00b7 ' + esc(r.address) : '') + '</div>'
              + '</div></div>';
      }
      drop.innerHTML = html;
      drop.style.display = 'block';
      highlightIdx = -1;

      /* Click handlers. */
      var items = drop.querySelectorAll('.la-item');
      for (var j = 0; j < items.length; j++) {
        items[j].addEventListener('mousedown', function (e) {
          e.preventDefault();
          pick(parseInt(this.dataset.idx, 10), results);
        });
      }
    }

    function pick(idx, results) {
      var r = results[idx];
      if (!r) return;
      selected = r;
      input.value = r.name;
      hide();
      onSelect(r);
    }

    function search() {
      var q = input.value.trim();
      if (q.length < minChars) { hide(); return; }

      var thisReq = ++activeReq;
      var url = apiBase + '?q=' + encodeURIComponent(q);
      var lat = userLat || defaultLat;
      var lon = userLon || defaultLon;
      url += '&lat=' + lat + '&lon=' + lon;

      showLoading();

      fetch(url)
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (thisReq !== activeReq) return;
          if (Array.isArray(data)) render(sortResults(data, q));
        })
        .catch(function () {
          if (thisReq !== activeReq) return;
          hide();
        });
    }

    /** Score a result name against the query: 0=exact, 1=prefix, 2=contains, 3=no match. */
    function matchTier(name, q) {
      var low = name.toLowerCase();
      var ql = q.toLowerCase();
      if (low === ql) return 0;
      if (low.indexOf(ql) === 0) return 1;
      if (low.indexOf(ql) !== -1) return 2;
      return 3;
    }

    /** Sort results: tier-0 (exact) first, tier-1 (prefix) second, tier-2 (contains) third; within each tier, nearest distance first. */
    function sortResults(results, q) {
      var scored = results.map(function (r) {
        return { r: r, tier: matchTier(r.name, q), dist: r._dist || 0 };
      });
      scored.sort(function (a, b) {
        if (a.tier !== b.tier) return a.tier - b.tier;
        return a.dist - b.dist;
      });
      return scored.map(function (s) { return s.r; });
    }

    function onInput() {
      /* If user edits after selecting, clear the stored selection. */
      if (selected && input.value !== selected.name) selected = null;
      clearTimeout(timer);
      timer = setTimeout(search, debounceMs);
    }

    function esc(s) {
      var d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }

    /* Keyboard navigation. */
    function onKeydown(e) {
      var items = drop.querySelectorAll('.la-item');
      if (!items.length || drop.style.display === 'none') return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightIdx = Math.min(highlightIdx + 1, items.length - 1);
        hl(items);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightIdx = Math.max(highlightIdx - 1, 0);
        hl(items);
      } else if (e.key === 'Enter' && highlightIdx >= 0) {
        e.preventDefault();
        pick(highlightIdx, currentResults());
      } else if (e.key === 'Escape') {
        hide();
      }
    }

    var _results = [];
    function currentResults() { return _results; }

    /* Override render to capture results for keyboard nav. */
    var _origRender = render;
    render = function (results) {
      _results = results;
      _origRender(results);
    };

    function hl(items) {
      for (var i = 0; i < items.length; i++) {
        items[i].classList.toggle('la-highlight', i === highlightIdx);
      }
    }

    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeydown);

    /* Close on outside click. */
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) hide();
    });

    /* Public API. */
    return {
      getValue: function () { return input.value; },
      getSelected: function () { return selected; },
      clear: function () { selected = null; input.value = ''; },
      destroy: function () {
        input.removeEventListener('input', onInput);
        input.removeEventListener('keydown', onKeydown);
        clearTimeout(timer);
        if (wrap.parentNode) wrap.parentNode.insertBefore(input, wrap);
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
      }
    };
  }

  window.LocationAutocomplete = LocationAutocomplete;
})();
