/* request_form.js — draft autosave (10 s when dirty), validation, request-letter upload, pickers, map. */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const state = $('saveState');
  let dirty = false;
  let saving = false;

  const fields = ['passenger_name', 'surname', 'middle_initial', 'suffix', 'id_number',
    'requester_type', 'email', 'number_of_passengers', 'place_to_visit', 'purpose',
    'scheduled_departure', 'scheduled_return'];

  const REQUIRED = ['passenger_name', 'id_number', 'email', 'place_to_visit', 'purpose',
    'scheduled_departure', 'scheduled_return'];

  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const MAX_BYTES = 20 * 1024 * 1024;
  const FILE_RE = /\.(pdf|png|jpg|jpeg|doc|docx)$/i;

  const uploadDrop = $('uploadDrop');
  let destMap = null, destMarker = null;

  function payload() {
    const p = { action: 'save_draft', ticket_id: parseInt($('f_ticket_id').value, 10) || 0 };
    fields.forEach(f => { p[f] = $('f_' + f).value; });
    /* Include structured destination data if available. */
    var lat = $('f_place_to_visit_lat').value;
    var lon = $('f_place_to_visit_lon').value;
    if (lat && lon) {
      p.place_to_visit_name = $('f_place_to_visit_name').value;
      p.place_to_visit_lat = parseFloat(lat);
      p.place_to_visit_lon = parseFloat(lon);
      p.place_to_visit_place_id = $('f_place_to_visit_place_id').value;
    }
    return p;
  }

  function markInvalid(el, bad) {
    if (el) el.classList.toggle('invalid', !!bad);
  }

  function validate() {
    document.querySelectorAll('#reqForm .invalid').forEach(el => el.classList.remove('invalid'));
    uploadDrop.classList.remove('invalid');
    let firstBad = null, msg = null;

    for (const f of REQUIRED) {
      const el = $('f_' + f);
      const bad = !el.value.trim();
      markInvalid(el, bad);
      if (bad && !firstBad) firstBad = el;
    }

    const email = $('f_email').value.trim();
    if (email && !EMAIL_RE.test(email)) {
      markInvalid($('f_email'), true);
      if (!firstBad) firstBad = $('f_email');
      msg = 'Enter a valid email address.';
    }

    const dep = $('f_scheduled_departure').value, ret = $('f_scheduled_return').value;
    if (dep && ret && new Date(ret) <= new Date(dep)) {
      markInvalid($('f_scheduled_return'), true);
      if (!firstBad) firstBad = $('f_scheduled_return');
      msg = 'Return must be after departure.';
    }

    if (!$('f_attachment_path').value) {
      uploadDrop.classList.add('invalid');
      if (!firstBad) firstBad = uploadDrop;
      if (!msg) msg = 'Upload your request letter.';
    }

    if (firstBad) {
      if (!msg) msg = 'Please complete the highlighted fields.';
      firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return msg;
    }
    return null;
  }

  async function save(silent) {
    if (saving) return false;
    saving = true;
    try {
      const r = await API.post('api/trips.php', payload());
      $('f_ticket_id').value = r.ticket_id;
      dirty = false;
      if (!silent) UI.toast(r.message, 'ok');
      else state.textContent = 'Autosaved ' + new Date().toLocaleTimeString();
      if (history.replaceState && location.search.indexOf('id=') === -1) {
        history.replaceState(null, '', location.pathname + '?id=' + r.ticket_id);
      }
      return true;
    } catch (e) {
      if (!silent) UI.toast(e.message, 'err');
      else state.textContent = 'Autosave failed';
      return false;
    } finally {
      saving = false;
    }
  }

  /* ── Request letter upload ─────────────────────────────────────────── */
  function fileName(path) {
    return path ? path.split('/').pop() : '';
  }

  function renderUpload(name) {
    $('uploadText').textContent = name;
    uploadDrop.classList.add('has-file');
    if ($('btnRemoveLetter')) return;
    const b = document.createElement('button');
    b.id = 'btnRemoveLetter';
    b.type = 'button';
    b.className = 'btn btn-sm btn-danger';
    b.textContent = 'Remove';
    b.addEventListener('click', (e) => { e.preventDefault(); removeLetter(); });
    uploadDrop.appendChild(b);
  }

  async function removeLetter() {
    try {
      const tid = parseInt($('f_ticket_id').value, 10);
      if (tid) await API.post('api/trips.php', { action: 'remove_request_letter', ticket_id: tid });
      $('f_attachment_path').value = '';
      $('uploadText').textContent = 'Upload File (Max 20mb)';
      uploadDrop.classList.remove('has-file');
      const b = $('btnRemoveLetter'); if (b) b.remove();
      dirty = true;
      UI.toast('Request letter removed.', 'info');
    } catch (e) { UI.toast(e.message, 'err'); }
  }

  $('f_request_letter').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    e.target.value = '';
    if (file.size > MAX_BYTES) return UI.toast('File exceeds the 20 MB limit.', 'err');
    if (!FILE_RE.test(file.name)) return UI.toast('Only PDF, image, or Word documents are allowed.', 'err');
    if (saving) return UI.toast('Please wait — the form is still saving.', 'info');
    try {
      if (!parseInt($('f_ticket_id').value, 10) && !(await save(true))) return;
      const fd = new FormData();
      fd.append('action', 'upload_request_letter');
      fd.append('ticket_id', $('f_ticket_id').value);
      fd.append('file', file);
      const r = await API.form('api/trips.php', fd);
      $('f_attachment_path').value = r.attachment_path || '';
      renderUpload(fileName(r.attachment_path) || file.name);
      dirty = true;
      UI.toast(r.message, 'ok');
    } catch (err) {
      UI.toast(err.message, 'err');
    }
  });

  /* ── Buttons ───────────────────────────────────────────────────────── */
  $('btnSave').addEventListener('click', () => save(false));
  $('btnSubmit').addEventListener('click', async () => {
    const err = validate();
    if (err) return UI.toast(err, 'err');
    if (!(await save(true))) return;
    try {
      const r = await API.post('api/trips.php', { action: 'submit', ticket_id: parseInt($('f_ticket_id').value, 10) });
      UI.toast(r.message, 'ok');
      setTimeout(() => { location.href = BASE_URL + 'ticket.php?id=' + $('f_ticket_id').value; }, 600);
    } catch (e) { UI.toast(e.message, 'err'); }
  });

  document.querySelectorAll('#reqForm input, #reqForm select, #reqForm textarea')
    .forEach(el => el.addEventListener('input', () => { dirty = true; }));

  setInterval(() => { if (dirty) save(true); }, 10000);
  window.addEventListener('beforeunload', (e) => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ── Date & time pickers (Flatpickr) ───────────────────────────────── */
  function initPickers() {
    if (typeof flatpickr === 'undefined') return;
    const toDate = (v) => {
      const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
      return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : undefined;
    };
    const ret = flatpickr('#f_scheduled_return', {
      enableTime: true, time_24hr: false, dateFormat: 'Y-m-d H:i', minDate: 'today',
      defaultDate: toDate($('f_scheduled_return').value),
      onChange: () => { dirty = true; },
    });
    flatpickr('#f_scheduled_departure', {
      enableTime: true, time_24hr: false, dateFormat: 'Y-m-d H:i', minDate: 'today',
      defaultDate: toDate($('f_scheduled_departure').value),
      onChange: (sel) => {
        ret.set('minDate', sel[0] || 'today');
        dirty = true;
      },
    });
  }

  /* ── Destination mini-map (Leaflet) ────────────────────────────────── */
  function moveTo(lat, lon, zoom) {
    if (!destMap) return;
    if (destMarker) destMarker.setLatLng([lat, lon]);
    else destMarker = L.marker([lat, lon]).addTo(destMap);
    destMap.setView([lat, lon], zoom || 15);
  }

  function initMap() {
    if (typeof L === 'undefined' || !$('destMap')) return;
    const lat = parseFloat($('f_place_to_visit_lat').value);
    const lon = parseFloat($('f_place_to_visit_lon').value);
    const hasLoc = isFinite(lat) && isFinite(lon);
    destMap = L.map($('destMap')).setView([hasLoc ? lat : 14.5126, hasLoc ? lon : 121.0182], hasLoc ? 15 : 12);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(destMap);
    if (hasLoc) moveTo(lat, lon);
  }

  /* ── Location autocomplete on destination field ────────────────────── */
  if (typeof LocationAutocomplete === 'function' && $('f_place_to_visit')) {
    LocationAutocomplete($('f_place_to_visit'), {
      lat: 14.5126, lon: 121.0182,
      onSelect: function (loc) {
        $('f_place_to_visit_name').value = loc.name || '';
        $('f_place_to_visit_lat').value = loc.latitude || '';
        $('f_place_to_visit_lon').value = loc.longitude || '';
        $('f_place_to_visit_place_id').value = loc.place_id || '';
        const la = parseFloat(loc.latitude), lo = parseFloat(loc.longitude);
        if (isFinite(la) && isFinite(lo)) moveTo(la, lo);
        dirty = true;
      }
    });
  }

  /* ── Boot ──────────────────────────────────────────────────────────── */
  const boot = () => {
    initPickers();
    initMap();
    if ($('f_attachment_path').value) {
      renderUpload(fileName($('f_attachment_path').value));
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();