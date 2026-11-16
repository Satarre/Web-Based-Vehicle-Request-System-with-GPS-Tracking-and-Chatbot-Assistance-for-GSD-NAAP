/* location-autocomplete.js — reusable place autocomplete (Google Places API)
   Custom dropdown with per-suggestion distance from an origin point. */
(function () {
  'use strict';

  function haversineKm(lat1, lon1, lat2, lon2) {
    var R = 6371;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLon = (lon2 - lon1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
      + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
      * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function fmtDist(km) {
    if (km < 1) return Math.round(km * 1000) + ' m';
    return (Math.round(km * 10) / 10) + ' km';
  }

  /**
   * LocationAutocomplete — attach to an <input> to get Google Places suggestions
   * with distance from the given origin.
   *
   * @param {HTMLInputElement} input  — the text input to enhance
   * @param {object}           opts
   * @param {number}           opts.lat — origin latitude (distance reference)
   * @param {number}           opts.lon — origin longitude
   * @param {function}         opts.onSelect — callback({name,address,latitude,longitude,place_id,distance})
   */
  function LocationAutocomplete(input, opts) {
    opts = opts || {};
    var onSelect = opts.onSelect || function () {};
    var originLat = parseFloat(opts.lat) || 14.5126;
    var originLon = parseFloat(opts.lon) || 121.0182;

    var wrapper = document.createElement('div');
    wrapper.className = 'la-input-wrap';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    var dropdown = document.createElement('div');
    dropdown.className = 'la-dropdown';
    dropdown.style.display = 'none';
    wrapper.appendChild(dropdown);

    var items = [], active = -1, reqSeq = 0, timer = null, cache = {};
    var placesService = null;
    var sessionToken = null;

    function close() {
      dropdown.style.display = 'none';
      dropdown.innerHTML = '';
      items = [];
      active = -1;
    }

    function setActive(i) {
      active = i;
      var nodes = dropdown.children;
      for (var j = 0; j < nodes.length; j++) {
        nodes[j].className = 'la-item' + (j === i ? ' la-highlight' : '');
      }
    }

    function render(list) {
      items = list;
      dropdown.innerHTML = '';
      if (!list.length) {
        var empty = document.createElement('div');
        empty.className = 'la-empty';
        empty.textContent = 'No matches found.';
        dropdown.appendChild(empty);
      } else {
        list.forEach(function (r, i) {
          var it = document.createElement('div');
          it.className = 'la-item' + (i === active ? ' la-highlight' : '');
          var txt = document.createElement('div');
          txt.className = 'la-item-text';
          var nm = document.createElement('div');
          nm.className = 'la-item-name';
          nm.textContent = r.name;
          txt.appendChild(nm);
          var ad = document.createElement('div');
          ad.className = 'la-item-addr';
          ad.textContent = [r.address, r.distance].filter(Boolean).join(' · ');
          txt.appendChild(ad);
          it.appendChild(txt);
          it.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
          it.addEventListener('mouseenter', function () { setActive(i); });
          dropdown.appendChild(it);
        });
      }
      dropdown.style.display = 'block';
      /* Shared dropdown positioning/close contract (ui.js): panel is placed
       * fixed to the viewport so ancestor overflow can't clip it, and
       * scrolling inside it scrolls it instead of closing it. */
      if (window.UI && window.UI.openDropdown) UI.openDropdown(dropdown, input, close);
    }

    /* One Place Details call per pick (never for the whole suggestion list);
     * predictions already carry the distance when an origin is sent. */
    function pick(i) {
      var r = items[i];
      if (!r) return;
      input.value = r.name;
      close();
      var hit = cache[r.place_id];
      if (hit) { sessionToken = null; onSelect(hit); return; }
      withPlaces(function () {
        placesService.getDetails(
          { placeId: r.place_id, fields: ['name', 'formatted_address', 'geometry'], sessionToken: sessionToken || undefined },
          function (place, st) {
            sessionToken = null;
            if (st !== 'OK' || !place || !place.geometry || !place.geometry.location) return;
            var lat = place.geometry.location.lat();
            var lng = place.geometry.location.lng();
            var res = {
              place_id: r.place_id,
              name: place.name || r.name,
              address: place.formatted_address || r.address || '',
              latitude: lat,
              longitude: lng,
              distance: fmtDist(haversineKm(originLat, originLon, lat, lng)),
            };
            cache[r.place_id] = res;
            onSelect(res);
          }
        );
      });
    }

    function toItem(p) {
      var sf = p.structured_formatting || {};
      return {
        place_id: p.place_id,
        name: sf.main_text || p.description || '',
        address: sf.secondary_text || p.description || '',
        distance: p.distance_meters ? fmtDist(p.distance_meters / 1000) : '',
      };
    }

    function search(q) {
      var seq = ++reqSeq;
      /* Session token: autocomplete requests in a session are free once the
       * pick concludes the session with one Place Details call. */
      if (!sessionToken) sessionToken = new google.maps.places.AutocompleteSessionToken();
      var svc = new google.maps.places.AutocompleteService();
      svc.getPlacePredictions({
        input: q,
        componentRestrictions: { country: 'ph' },
        bounds: new google.maps.LatLngBounds(
          { lat: originLat - 0.5, lng: originLon - 0.5 },
          { lat: originLat + 0.5, lng: originLon + 0.5 }
        ),
        origin: { lat: originLat, lng: originLon },
        sessionToken: sessionToken,
      }, function (preds, status) {
        if (seq !== reqSeq) return;
        if (status !== 'OK' || !preds) { close(); return; }
        render(preds.slice(0, 6).map(toItem));
      });
    }

    /* Resolve Places on first search — the Maps library is lazy-loaded. */
    function withPlaces(cb) {
      if (placesService) { cb(); return; }
      function make() { placesService = new google.maps.places.PlacesService(document.createElement('div')); cb(); }
      if (window.NAAPMaps) {
        NAAPMaps.ready(function (ok) {
          if (!ok || typeof google === 'undefined' || !google.maps || !google.maps.places) return;
          make();
        });
      } else if (typeof google !== 'undefined' && google.maps && google.maps.places) {
        make();
      }
    }

    function init() {
      input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { close(); return; }
        timer = setTimeout(function () { withPlaces(function () { search(q); }); }, 350);
      });

      input.addEventListener('keydown', function (e) {
        if (dropdown.style.display === 'none') return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(active + 1, items.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(active - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) pick(active); }
        else if (e.key === 'Escape') { close(); }
      });

      document.addEventListener('mousedown', function (e) {
        if (!wrapper.contains(e.target)) close();
      });
    }

    init();
  }

  window.LocationAutocomplete = LocationAutocomplete;
})();