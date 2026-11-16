/* geo.js — shared Google-polyline decoding, path offset, duration/distance formatting. */
(function () {
  'use strict';

  function decodeGooglePolyline(encoded) {
    var points = [], index = 0, lat = 0, lng = 0;
    while (index < encoded.length) {
      var b, shift = 0, result = 0;
      do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
      lat += ((result & 1) ? ~(result >> 1) : (result >> 1));
      shift = 0; result = 0;
      do { b = encoded.charCodeAt(index++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20);
      lng += ((result & 1) ? ~(result >> 1) : (result >> 1));
      points.push({ lat: lat / 1e5, lng: lng / 1e5 });
    }
    return points;
  }

  function offsetPath(pts, meters) {
    if (!pts || pts.length < 2) return pts;
    var r = 6371000;
    var off = [];
    for (var i = 0; i < pts.length; i++) {
      var dx = 0, dy = 0;
      if (i < pts.length - 1) {
        dx = (pts[i + 1].lng - pts[i].lng) * Math.PI / 180 * r * Math.cos(pts[i].lat * Math.PI / 180);
        dy = (pts[i + 1].lat - pts[i].lat) * Math.PI / 180 * r;
      } else {
        dx = (pts[i].lng - pts[i - 1].lng) * Math.PI / 180 * r * Math.cos(pts[i].lat * Math.PI / 180);
        dy = (pts[i].lat - pts[i - 1].lat) * Math.PI / 180 * r;
      }
      var len = Math.sqrt(dx * dx + dy * dy) || 1;
      var nx = dy / len, ny = -dx / len;
      off.push({ lat: pts[i].lat + (ny * meters) / r * 180 / Math.PI, lng: pts[i].lng + (nx * meters) / (r * Math.cos(pts[i].lat * Math.PI / 180)) * 180 / Math.PI });
    }
    return off;
  }

  /* Project a point onto a polyline (nearest segment). Returns
   * { segmentIndex, point } in {lat,lng}. Distances are computed in a
   * meter-ish scaled plane (lng scaled by cos(lat)) — good enough for
   * trimming a route at the vehicle's position. */
  function projectToPolyline(points, pos) {
    var best = null;
    var lat = pos.lat, lng = pos.lng;
    var px = lng * Math.cos(lat * Math.PI / 180), py = lat;
    for (var i = 0; i < points.length - 1; i++) {
      var a = points[i], b = points[i + 1];
      var ax = a.lng * Math.cos(a.lat * Math.PI / 180), ay = a.lat;
      var bx = b.lng * Math.cos(b.lat * Math.PI / 180), by = b.lat;
      var dx = bx - ax, dy = by - ay;
      var len2 = dx * dx + dy * dy || 1e-12;
      var t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / len2));
      var cx = ax + t * dx, cy = ay + t * dy;
      var d2 = (px - cx) * (px - cx) + (py - cy) * (py - cy);
      if (!best || d2 < best.d2) {
        best = { segmentIndex: i, d2: d2, point: { lat: cy, lng: cx / Math.cos(cy * Math.PI / 180) } };
      }
    }
    return best || { segmentIndex: 0, d2: 0, point: points[0] };
  }

  /* Trim a polyline so it starts at the projection of `pos` and runs to the
   * end — the "remaining" portion of a route behind the vehicle. */
  function trimPolyline(points, pos) {
    if (!points || points.length < 2) return points;
    var proj = projectToPolyline(points, pos);
    if (proj.segmentIndex >= points.length - 2) {
      return [proj.point, points[points.length - 1]];
    }
    return [proj.point].concat(points.slice(proj.segmentIndex + 1));
  }

  function formatDuration(secs) {
    if (!secs) return '';
    var s = Math.round(secs);
    if (s < 60) return s + 's';
    var h = Math.floor(s / 3600), m = Math.ceil((s % 3600) / 60);
    return h > 0 ? h + 'h ' + m + 'm' : m + 'm';
  }

  function formatDistance(m) {
    if (!m) return '';
    return m >= 1000 ? (m / 1000).toFixed(1) + ' km' : m + ' m';
  }

  function haversineMeters(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var toRad = Math.PI / 180;
    var dLat = (lat2 - lat1) * toRad;
    var dLng = (lng2 - lng1) * toRad;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
  }

  window.Geo = { decodeGooglePolyline, offsetPath, projectToPolyline, trimPolyline, haversineMeters, formatDuration, formatDistance };
})();