/* request_form.js — draft autosave (10 s when dirty), validation, request-letter upload, pickers, map. */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const state = $('saveState');
  let dirty = false;
  let touched = false;
  let saving = false;
  let uploading = false;
  let openPaxAfterValidate = false;
  /* A failed save pauses the 10 s loop until the next edit: retrying a request
   * the server has already rejected only multiplies drafts and error rows. */
  let autosavePaused = false;
  let failStreak = 0;
  /* True when the form was opened fresh (not an existing draft). */
  const wasNew = (parseInt($('f_draft_id').value, 10) || 0) === 0;

  const fields = ['passenger_name', 'surname', 'middle_initial', 'middle_name', 'suffix', 'id_number',
    'requester_type', 'email', 'number_of_passengers', 'place_to_visit', 'purpose',
    'scheduled_departure', 'scheduled_return'];

  const REQUIRED = ['passenger_name', 'id_number', 'email', 'place_to_visit', 'purpose',
    'scheduled_departure', 'scheduled_return'];

  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const FILE_RE = /\.(pdf|png|jpg|jpeg|doc|docx)$/i;

  const uploadDrop = $('uploadDrop');
  /* Cap aligned with PHP (upload_max_filesize / post_max_size), not just 20 MB. */
  const MAX_BYTES = parseInt(uploadDrop.dataset.maxBytes, 10) || 20 * 1024 * 1024;
  const UPLOAD_IDLE_TEXT = $('uploadText').textContent;
  let destMap = null, destMarker = null;
  let routeLayers = [];

  /* ── Passenger manifest ────────────────────────────────────────────── */
  const PAX_MAX = 999;
  const paxRowsEl = $('paxRows');
  let paxRows = [];         // committed [{surname, first_name, middle_initial}]
  let paxEdit = [];         // working copy while the modal is open
  let paxSelf = true;       // committed "Add yourself" state (on by default)
  let paxEditSelf = true;   // "Add yourself" state while the modal is open
  let paxLastCount = 1;     // committed count; Cancel reverts the input to it
  let paxModalOpen = false;

  const paxBlank = () => ({ surname: '', first_name: '', middle_initial: '', middle_name: '' });
  const paxSelfName = () => ({
    surname: ($('f_surname').value || '').trim().toUpperCase(),
    first_name: ($('f_passenger_name').value || '').trim().toUpperCase(),
    middle_initial: ($('f_middle_initial').value || '').trim().toUpperCase(),
    middle_name: ($('f_middle_name').value || '').trim().toUpperCase(),
  });
  const paxCount = () => Math.max(1, Math.min(PAX_MAX, parseInt($('f_number_of_passengers').value, 10) || 1));
  const paxComplete = r => !!(r && (r.surname || '').trim() && (r.first_name || '').trim());
  const paxSame = (a, b) => (a.surname || '') === (b.surname || '')
    && (a.first_name || '') === (b.first_name || '')
    && (a.middle_initial || '') === (b.middle_initial || '')
    && (a.middle_name || '') === (b.middle_name || '');
  const paxValid = () => {
    const n = paxCount();
    return paxRows.length === n && paxRows.every(paxComplete);
  };
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  (function initPax() {
    let saved = [];
    try { saved = JSON.parse(($('f_passengers_json') || {}).textContent || '[]') || []; } catch (e) { saved = []; }
    if (!Array.isArray(saved)) saved = [];
    const n = paxCount();
    paxLastCount = n;
    if (saved.length) {
      paxRows = saved.slice(0, n).map(r => ({
        surname: ((r && r.surname) || '').toUpperCase(),
        first_name: ((r && r.first_name) || '').toUpperCase(),
        middle_initial: ((r && r.middle_initial) || '').toUpperCase(),
        middle_name: ((r && r.middle_name) || '').toUpperCase(),
      }));
      while (paxRows.length < n) paxRows.push(paxBlank());
      /* "Add yourself" was on when the first passenger carries the requester's name. */
      const self = paxSelfName();
      paxSelf = !!self.first_name && paxSame(paxRows[0], self);
      if (paxSelf) paxRows[0] = self;
    } else {
      paxRows = [paxBlank()];
      while (paxRows.length < n) paxRows.push(paxBlank());
      if (paxSelf) paxRows[0] = paxSelfName();
    }
  })();

  function renderPaxRows() {
    if (!paxRowsEl) return;
    paxRowsEl.innerHTML = '';
    paxEdit.forEach((r, i) => {
      const locked = paxEditSelf && i === 0;
      const row = document.createElement('div');
      row.className = 'pax-row';
      row.innerHTML =
        '<div class="pax-row-head">Passenger ' + (i + 1) + (locked ? ' (you)' : '') + '</div>' +
        '<div class="grid grid-cols-4 max-lg:grid-cols-2 gap-3 gap-x-3.5">' +
          '<div class="col-span-2"><label class="form-label">Surname *</label>' +
            '<input class="form-input input-cap" maxlength="100" data-pax="surname" value="' + esc(r.surname) + '"' + (locked ? ' readonly' : '') + '></div>' +
          '<div class="col-span-2"><label class="form-label">First Name *</label>' +
            '<input class="form-input input-cap" maxlength="150" data-pax="first_name" value="' + esc(r.first_name) + '"' + (locked ? ' readonly' : '') + '></div>' +
          '<div><label class="form-label">M.I.</label>' +
            '<input class="form-input input-cap" maxlength="3" data-pax="middle_initial" value="' + esc(r.middle_initial) + '"' + (locked ? ' readonly' : '') + '></div>' +
          '<div class="col-span-3"><label class="form-label">Middle Initial</label>' +
            '<input class="form-input input-cap" maxlength="100" data-pax="middle_name" value="' + esc(r.middle_name) + '"' + (locked ? ' readonly' : '') + '></div>' +
        '</div>';
      paxRowsEl.appendChild(row);
    });
    paxRowsEl.querySelectorAll('[data-pax]').forEach(inp => {
      inp.addEventListener('input', e => {
        const idx = Array.prototype.indexOf.call(paxRowsEl.children, e.target.closest('.pax-row'));
        if (idx >= 0 && paxEdit[idx]) paxEdit[idx][e.target.dataset.pax] = e.target.value.toUpperCase();
      });
    });
  }

  function updatePaxSummary() {
    const el = $('paxSummary');
    if (!el) return;
    const n = paxCount();
    el.textContent = paxValid()
      ? 'All ' + n + ' passenger name' + (n === 1 ? '' : 's') + ' recorded'
      : 'Passenger names incomplete';
  }

  /* Keep the locked first row in step with the travel-order name fields. */
  function syncPaxSelf() {
    if (paxModalOpen && paxEditSelf && paxEdit.length) {
      paxEdit[0] = paxSelfName();
      const first = paxRowsEl.children[0];
      if (first) first.querySelectorAll('[data-pax]').forEach(inp => { inp.value = paxEdit[0][inp.dataset.pax] || ''; });
    } else if (!paxModalOpen && paxSelf && paxRows.length) {
      paxRows[0] = paxSelfName();
    }
  }

  function openPaxModal() {
    const n = paxCount();
    $('f_number_of_passengers').value = n;
    paxEdit = paxRows.slice(0, n).map(r => Object.assign(paxBlank(), r));
    while (paxEdit.length < n) paxEdit.push(paxBlank());
    paxEditSelf = paxSelf;
    $('paxAddSelf').checked = paxEditSelf;
    if (paxEditSelf) paxEdit[0] = paxSelfName();
    renderPaxRows();
    paxModalOpen = true;
    UI.modal('paxModal', true);
  }

  function closePaxModal(revert) {
    paxModalOpen = false;
    UI.modal('paxModal', false);
    if (revert) {
      $('f_number_of_passengers').value = paxLastCount;
      updatePaxSummary();
    }
  }

  if (paxRowsEl) {
    $('f_number_of_passengers').addEventListener('change', () => {
      if (!paxModalOpen && paxCount() !== paxLastCount) openPaxModal();
      else if (!paxModalOpen) updatePaxSummary();
    });
    $('f_number_of_passengers').addEventListener('input', updatePaxSummary);
    $('btnPaxNames').addEventListener('click', openPaxModal);
    $('paxClose').addEventListener('click', () => closePaxModal(true));
    $('paxCancel').addEventListener('click', () => closePaxModal(true));
    $('paxAddSelf').addEventListener('change', e => {
      paxEditSelf = e.target.checked;
      if (paxEditSelf && paxEdit.length) paxEdit[0] = paxSelfName();
      renderPaxRows();
    });
    $('paxSave').addEventListener('click', () => {
      for (let i = 0; i < paxEdit.length; i++) {
        if (!paxComplete(paxEdit[i])) {
          UI.toast('Enter the surname and first name of passenger ' + (i + 1) + '.', 'err');
          return;
        }
      }
      paxRows = paxEdit.map(r => ({
        surname: (r.surname || '').trim().toUpperCase(),
        first_name: (r.first_name || '').trim().toUpperCase(),
        middle_initial: (r.middle_initial || '').trim().toUpperCase(),
        middle_name: (r.middle_name || '').trim().toUpperCase(),
      }));
      paxSelf = paxEditSelf;
      paxLastCount = paxCount();
      dirty = true;
      autosavePaused = false;
      touched = true;
      closePaxModal(false);
      updatePaxSummary();
      UI.toast('Passenger names saved.', 'ok');
    });
    ['f_passenger_name', 'f_middle_initial', 'f_middle_name', 'f_surname'].forEach(id => {
      const el = $(id);
      if (el) el.addEventListener('input', () => { syncPaxSelf(); updatePaxSummary(); });
    });
  }

  function payload() {
    const p = { action: 'save_draft', draft_id: parseInt($('f_draft_id').value, 10) || 0 };
    fields.forEach(f => { p[f] = $('f_' + f).value; });
    ['passenger_name', 'surname', 'middle_initial', 'middle_name', 'suffix', 'id_number'].forEach(f => { p[f] = (p[f] || '').toUpperCase(); });
    p.passengers = paxRows.map(r => ({
      surname: (r.surname || '').trim(),
      first_name: (r.first_name || '').trim(),
      middle_initial: (r.middle_initial || '').trim(),
      middle_name: (r.middle_name || '').trim(),
    }));
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
    openPaxAfterValidate = false;

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
    if (dep && new Date(dep) <= new Date()) {
      markInvalid($('f_scheduled_departure'), true);
      if (!firstBad) firstBad = $('f_scheduled_departure');
      msg = 'Scheduled departure must be in the future.';
    }

    if (!$('f_attachment_path').value) {
      uploadDrop.classList.add('invalid');
      if (!firstBad) firstBad = uploadDrop;
      if (!msg) msg = 'Upload your request letter.';
    }

    /* Manifest must name every declared passenger before submission.
     * Only auto-open the modal when nothing else is missing, so it does not
     * pile on top of ordinary field errors. */
    if (!paxValid()) {
      if (!firstBad) openPaxAfterValidate = true;
      const n = paxCount();
      if (!msg) msg = 'Add the names of all ' + n + ' passenger' + (n === 1 ? '' : 's') + '.';
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
      $('f_draft_id').value = r.draft_id;
      dirty = false;
      failStreak = 0;
      autosavePaused = false;
      if (!silent) UI.toast(r.message, 'ok');
      else state.textContent = 'Autosaved ' + new Date().toLocaleTimeString();
      if (history.replaceState && location.search.indexOf('id=') === -1) {
        history.replaceState(null, '', location.pathname + '?id=' + r.draft_id);
      }
      return true;
    } catch (e) {
      failStreak++;
      autosavePaused = true;
      const label = silent ? 'Autosave failed — ' : 'Save failed — ';
      const missingId = parseInt($('f_draft_id').value, 10) || 0;
      if (missingId && /Draft not found|own drafts/i.test(e.message || '')) {
        /* The draft was removed (another tab, auto-purge): let the next save
         * start a fresh draft instead of being stuck on a dead id. */
        $('f_draft_id').value = '';
        state.textContent = 'Draft was removed — your next save starts a new one.';
      } else {
        state.textContent = label + (e.message || 'please retry');
      }
      if (!silent || failStreak === 1) UI.toast(e.message, 'err');
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

  /* ── Upload progress (XHR events; fetch cannot report upload progress) ── */
  const uploadProg = $('uploadProgress');
  const uploadBar = $('uploadBar');
  const uploadPct = $('uploadPercent');

  function setUploadProgress(pct, state) {
    if (!uploadProg) return;
    const value = Math.max(0, Math.min(100, Math.round(pct)));
    uploadProg.hidden = false;
    uploadProg.classList.toggle('is-done', state === 'done');
    uploadProg.classList.toggle('is-error', state === 'error');
    uploadProg.setAttribute('aria-valuenow', String(value));
    if (uploadBar) uploadBar.style.width = value + '%';
    if (uploadPct) {
      uploadPct.classList.toggle('is-error', state === 'error');
      uploadPct.textContent = state === 'error' ? 'Upload failed'
        : (state === 'done' ? 'Uploaded' : value + '%');
    }
  }

  function resetUploadProgress() {
    if (!uploadProg) return;
    uploadProg.hidden = true;
    uploadProg.classList.remove('is-done', 'is-error');
    uploadProg.setAttribute('aria-valuenow', '0');
    if (uploadBar) uploadBar.style.width = '0%';
    if (uploadPct) { uploadPct.classList.remove('is-error'); uploadPct.textContent = ''; }
  }

  async function removeLetter() {
    try {
      const did = parseInt($('f_draft_id').value, 10);
      if (did) await API.post('api/trips.php', { action: 'remove_request_letter', draft_id: did });
      $('f_attachment_path').value = '';
      $('uploadText').textContent = UPLOAD_IDLE_TEXT;
      uploadDrop.classList.remove('has-file');
      const b = $('btnRemoveLetter'); if (b) b.remove();
      resetUploadProgress();
      dirty = true;
      autosavePaused = false;
      UI.toast('Request letter removed.', 'info');
    } catch (e) { UI.toast(e.message, 'err'); }
  }

  $('f_request_letter').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    e.target.value = '';
    if (uploading) return UI.toast('An upload is already in progress.', 'info');
    if (file.size > MAX_BYTES) {
      return UI.toast('File exceeds the ' + Math.floor(MAX_BYTES / 1048576) + ' MB limit.', 'err');
    }
    if (file.size < 1) return UI.toast('The selected file is empty.', 'err');
    if (!FILE_RE.test(file.name)) return UI.toast('Only PDF, image, or Word documents are allowed.', 'err');
    if (saving) return UI.toast('Please wait — the form is still saving.', 'info');

    const previousText = $('uploadText').textContent;
    let lastPct = 0;
    uploading = true;
    uploadDrop.classList.add('is-uploading');
    setUploadProgress(0, 'uploading');
    $('uploadText').textContent = 'Uploading ' + file.name;
    try {
      if (!parseInt($('f_draft_id').value, 10) && !(await save(true))) {
        resetUploadProgress();
        $('uploadText').textContent = previousText;
        return;
      }
      const fd = new FormData();
      fd.append('action', 'upload_request_letter');
      fd.append('draft_id', $('f_draft_id').value);
      fd.append('file', file);
      const r = await API.formProgress('api/trips.php', fd, (pct) => {
        lastPct = pct;
        setUploadProgress(pct, 'uploading');
      });
      $('f_attachment_path').value = r.attachment_path || '';
      renderUpload(fileName(r.attachment_path) || file.name);
      setUploadProgress(100, 'done');
      dirty = true;
      autosavePaused = false;
      UI.toast(r.message, 'ok');
    } catch (err) {
      setUploadProgress(lastPct, 'error');
      $('uploadText').textContent = previousText;
      UI.toast(err.message, 'err');
    } finally {
      uploading = false;
      uploadDrop.classList.remove('is-uploading');
    }
  });

  /* ── Buttons ───────────────────────────────────────────────────────── */
  $('btnSave').addEventListener('click', async () => {
    /* Explicit save clears the "unsaved changes" prompt; the silent
     * autosave must NOT (it runs continuously in the background). */
    if (await save(false)) touched = false;
  });
  $('btnSubmit').addEventListener('click', async () => {
    const err = validate();
    if (err) {
      if (openPaxAfterValidate) openPaxModal();
      return UI.toast(err, 'err');
    }

    /* LATE submission: scheduled departure within LATE_ADVANCE_DAYS of now. */
    const dep = $('f_scheduled_departure').value;
    const lateDays = parseInt($('f_late_days').value, 10) || 3;
    let lateMsg = null;
    if (dep) {
      const diff = new Date(dep).getTime() - Date.now();
      if (diff < lateDays * 86400000) {
        lateMsg = 'This trip ticket is LATE because it is scheduled within ' + lateDays +
          ' day' + (lateDays > 1 ? 's' : '') + '. It will be tagged as LATE. Submit anyway?';
      }
    }

    const ok = await UI.confirmDialog(lateMsg
      ? { message: lateMsg, okLabel: 'Submit LATE', danger: true }
      : { message: 'Submit this trip request for SAO approval?', okLabel: 'Submit' });
    if (!ok) return;
    if (!(await save(true))) return;
    const submitId = parseInt($('f_draft_id').value, 10) || 0;
    if (!submitId) {
      state.textContent = 'Cannot submit — the request was not saved.';
      return UI.toast('The draft could not be saved, so there is nothing to submit yet.', 'err');
    }
    try {
      const r = await API.post('api/trips.php', { action: 'submit', draft_id: submitId });
      UI.toast(r.message, 'ok');
      setTimeout(() => { location.href = BASE_URL + 'ticket.php?id=' + r.ticket_id; }, 600);
    } catch (e) { UI.toast(e.message, 'err'); }
  });

  document.querySelectorAll('#reqForm input, #reqForm select, #reqForm textarea')
    .forEach(el => el.addEventListener('input', () => { dirty = true; touched = true; autosavePaused = false; }));

  setInterval(() => { if (dirty && !autosavePaused) save(true); }, 10000);
  window.addEventListener('beforeunload', (e) => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ── Exit confirmation: offer to save the draft when navigating away ── */
  let pendingHref = null;

  document.addEventListener('click', (e) => {
    const exitEl = document.getElementById('exitDraftModal');
    if (!touched || (exitEl && exitEl.classList.contains('open'))) return;
    const link = e.target.closest('a[href]');
    if (!link || link.dataset.chatOpen) return;
    const href = link.getAttribute('href') || '';
    if (!href || href === '#' || href.indexOf('javascript:') === 0 || link.target === '_blank') return;
    e.preventDefault();
    pendingHref = link.href;
    UI.modal('exitDraftModal', true);
  });

  const exitBtn = (id, fn) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', fn);
  };
  exitBtn('exitSaveLeave', async () => {
    const href = pendingHref;
    pendingHref = null;
    UI.modal('exitDraftModal', false);
    /* Do not navigate away on a failed save — that is how work is lost. */
    if (dirty && !(await save(true))) {
      UI.modal('exitDraftModal', true);
      return;
    }
    dirty = false;
    location.href = href;
  });
  exitBtn('exitLeaveNoSave', async () => {
    const href = pendingHref;
    pendingHref = null;
    UI.modal('exitDraftModal', false);
    dirty = false;
    /* A brand-new ticket that was only auto-saved this session should not
     * leave a draft behind when the user chooses not to save. */
    const did = parseInt($('f_draft_id').value, 10) || 0;
    if (wasNew && did > 0) {
      try { await API.post('api/trips.php', { action: 'erase_draft', draft_id: did }); } catch (e) {}
    }
    location.href = href;
  });
  exitBtn('exitStay', () => {
    pendingHref = null;
    UI.modal('exitDraftModal', false);
  });


  /* ── Destination mini-map (Google Maps) ────────────────────────────── */
  function moveTo(lat, lon, zoom) {
    if (!destMap) return;
    if (destMarker) destMarker.setPosition({ lat, lng: lon });
    else destMarker = new google.maps.Marker({ position: { lat, lng: lon }, map: destMap });
    destMap.setCenter({ lat, lng: lon });
    destMap.setZoom(zoom || 15);
  }

  function clearRoute() {
    routeLayers.forEach(function (l) { l.setMap(null); });
    routeLayers = [];
  }

  var routeInfoEl = null;

  /* Route preview cache: a page refresh reuses the drawn preview instead of
   * calling the Directions API again while the origin/destination pair and
   * the 30-minute TTL hold. */
  var ROUTE_CACHE_KEY = 'naapRoutePreview';
  var ROUTE_CACHE_TTL_MS = 30 * 60 * 1000;

  function routeKey(oLat, oLng, dLat, dLng) {
    return [oLat, oLng, dLat, dLng].map(function (v) { return v.toFixed(5); }).join(',');
  }

  function readRouteCache(key) {
    try {
      var c = JSON.parse(sessionStorage.getItem(ROUTE_CACHE_KEY) || 'null');
      if (c && c.key === key && (Date.now() - c.ts) < ROUTE_CACHE_TTL_MS) return c;
    } catch (e) { /* unavailable storage — fall through to a live call */ }
    return null;
  }

  function writeRouteCache(preview) {
    try { sessionStorage.setItem(ROUTE_CACHE_KEY, JSON.stringify(preview)); } catch (e) { /* ignore */ }
  }

  function showRouteInfo(route) {
    if (!route) return;
    var dist = route.distanceMeters || 0;
    var dur = route.duration ? route.duration.seconds || route.duration : 0;
    var txt = 'Outbound: ' + Geo.formatDuration(dur) + ' · ' + Geo.formatDistance(dist) + ' (each way)';
    if (!routeInfoEl) {
      routeInfoEl = document.createElement('div');
      routeInfoEl.className = 'route-info';
      var parent = $('destMap').parentNode;
      parent.appendChild(routeInfoEl);
    }
    routeInfoEl.textContent = txt;
  }

  function drawFallbackLine(oLat, oLng, dLat, dLng) {
    if (typeof google === 'undefined' || !google.maps) return;
    var pts = [{ lat: oLat, lng: oLng }, { lat: dLat, lng: dLng }];
    routeLayers.push(new google.maps.Polyline({
      map: destMap, path: pts,
      strokeColor: '#94a3b8', strokeWeight: 2.5, strokeOpacity: 0.85,
      icons: [{ icon: { path: 'M 0,-1 0,1', strokeOpacity: 1 }, offset: '0', repeat: '12px' }],
      clickable: false,
    }));
    var bounds = new google.maps.LatLngBounds();
    pts.forEach(function (p) { bounds.extend(p); });
    destMap.fitBounds(bounds, 40);
    if (routeInfoEl) { routeInfoEl.remove(); routeInfoEl = null; }
    routeInfoEl = document.createElement('div');
    routeInfoEl.className = 'route-info';
    routeInfoEl.textContent = 'Estimated route (offline) — straight line, no traffic data';
    $('destMap').parentNode.appendChild(routeInfoEl);
  }

  /* Draw a preview from a plain {path, durationSec, distanceM} object so live
   * Directions results and sessionStorage-cached previews render identically.
   * Single planned route: outbound base → destination (solid blue). */
  function drawDirectionsRoute(preview) {
    routeLayers.push(new google.maps.Polyline({
      map: destMap, path: preview.path,
      strokeColor: '#38BDF8', strokeWeight: 5, strokeOpacity: 0.9,
      clickable: false,
    }));
    var bounds = new google.maps.LatLngBounds();
    preview.path.forEach(function (p) { bounds.extend(p); });
    destMap.fitBounds(bounds, 40);
    showRouteInfo({ duration: { seconds: preview.durationSec }, distanceMeters: preview.distanceM });
  }

  /* Normalize a live Directions result into the cached preview shape. */
  function previewFromDirections(result, key) {
    var r0 = result.routes[0];
    return {
      key: key,
      ts: Date.now(),
      path: Geo.decodeGooglePolyline(r0.overview_polyline),
      durationSec: r0.legs[0].duration.value,
      distanceM: r0.legs[0].distance.value,
    };
  }

  function fetchAndDrawRoute(destLat, destLng) {
    clearRoute();
    if (routeInfoEl) { routeInfoEl.remove(); routeInfoEl = null; }
    if (typeof google === 'undefined' || !google.maps) return;
    var el = $('destMap');
    var oLat = parseFloat(el.dataset.originLat);
    var oLng = parseFloat(el.dataset.originLng);
    if (!el.dataset.originLat || !el.dataset.originLng || isNaN(oLat) || isNaN(oLng)) return;
    if (oLat === destLat && oLng === destLng) return;

    routeLayers.push(new google.maps.Marker({
      position: { lat: oLat, lng: oLng }, map: destMap, title: 'Origin',
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 6, fillColor: '#38BDF8', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
    }));

    var key = routeKey(oLat, oLng, destLat, destLng);
    var cached = readRouteCache(key);
    if (cached) { drawDirectionsRoute(cached); return; }

    new google.maps.DirectionsService().route({
      origin: { lat: oLat, lng: oLng },
      destination: { lat: destLat, lng: destLng },
      travelMode: 'DRIVING',
    }, function (result, status) {
      if (status !== 'OK' || !result || !result.routes || !result.routes[0]) {
        drawFallbackLine(oLat, oLng, destLat, destLng);
        return;
      }
      var preview = previewFromDirections(result, key);
      writeRouteCache(preview);
      drawDirectionsRoute(preview);
    });
  }

  /* Typed destination (no autocomplete pick): let Directions geocode the text
   * so the planned route draws and the hidden coordinates get saved for the
   * submit-time route computation. */
  var lastResolved = '';

  function resolveDestinationFromText() {
    var inp = $('f_place_to_visit');
    var text = inp ? inp.value.trim() : '';
    if (!text) return;
    if (typeof google === 'undefined' || !google.maps) return;
    var el = $('destMap');
    var oLat = parseFloat(el.dataset.originLat);
    var oLng = parseFloat(el.dataset.originLng);
    if (!el.dataset.originLat || !el.dataset.originLng || isNaN(oLat) || isNaN(oLng)) return;
    if (text === lastResolved && $('f_place_to_visit_lat').value) return;

    new google.maps.DirectionsService().route({
      origin: { lat: oLat, lng: oLng },
      destination: text,
      travelMode: 'DRIVING',
    }, function (result, status) {
      if (status !== 'OK' || !result || !result.routes || !result.routes[0] || !result.routes[0].legs[0]) {
        UI.toast('Couldn\u2019t locate that destination \u2014 please pick from the suggestions.', 'err');
        return;
      }
      var leg = result.routes[0].legs[0];
      var end = leg.end_location;
      var endLat = end.lat(), endLng = end.lng();
      $('f_place_to_visit_lat').value = endLat;
      $('f_place_to_visit_lon').value = endLng;
      $('f_place_to_visit_name').value = leg.end_address || text;
      lastResolved = text;
      moveTo(endLat, endLng);
      clearRoute();
      if (routeInfoEl) { routeInfoEl.remove(); routeInfoEl = null; }
      var preview = previewFromDirections(result, routeKey(oLat, oLng, endLat, endLng));
      writeRouteCache(preview);
      drawDirectionsRoute(preview);
    });
  }

  function initMap() {
    if (!$('destMap')) return;
    /* Maps library is lazy-loaded; fall back to the SVG map if it can't load. */
    if (window.NAAPMaps) {
      NAAPMaps.observe($('destMap'), function (ok) {
        if (ok && typeof google !== 'undefined' && google.maps) initOnlineMap();
        else initOfflineMap();
      });
    } else if (typeof google !== 'undefined' && google.maps) {
      initOnlineMap();
    } else {
      initOfflineMap();
    }
  }

  function initOnlineMap() {
    const lat = parseFloat($('f_place_to_visit_lat').value);
    const lon = parseFloat($('f_place_to_visit_lon').value);
    const hasLoc = isFinite(lat) && isFinite(lon);
    destMap = new google.maps.Map($('destMap'), {
      center: { lat: hasLoc ? lat : 14.5126, lng: hasLoc ? lon : 121.0182 },
      zoom: hasLoc ? 15 : 12, mapTypeControl: false, streetViewControl: false,
    });
    if (hasLoc) {
      moveTo(lat, lon);
      /* Saved draft already has coordinates: remember the resolved text so a
       * blur doesn't geocode it again, and redraw the preview from cache. */
      if ($('f_place_to_visit')) lastResolved = $('f_place_to_visit').value;
      fetchAndDrawRoute(lat, lon);
    }
    /* Saved draft typed without picking a suggestion: resolve it so the
     * planned route shows once the map is ready. */
    if ($('f_place_to_visit') && $('f_place_to_visit').value.trim() && !$('f_place_to_visit_lat').value) {
      resolveDestinationFromText();
    }
  }

  /* ── Offline fallback: dependency-free SVG schematic ─────────────────
   * Draws the campus origin and, when the destination already has saved
   * coordinates, a straight-line route + distance/ETA estimate. */
  const SVGNS = 'http://www.w3.org/2000/svg';

  function svgEl(tag, attrs) {
    const n = document.createElementNS(SVGNS, tag);
    for (const k in attrs) n.setAttribute(k, attrs[k]);
    return n;
  }

  function initOfflineMap() {
    const el = $('destMap');
    if (!el) return;
    const oLat = parseFloat(el.dataset.originLat);
    const oLng = parseFloat(el.dataset.originLng);
    const hasOrigin = isFinite(oLat) && isFinite(oLng) && (oLat !== 0 || oLng !== 0);
    const dLat = parseFloat($('f_place_to_visit_lat').value);
    const dLng = parseFloat($('f_place_to_visit_lon').value);
    const hasDest = isFinite(dLat) && isFinite(dLng) && (dLat !== 0 || dLng !== 0);

    const W = el.clientWidth || 600;
    const H = el.clientHeight || 220;
    const pad = 42;

    el.innerHTML = '';
    const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, width: '100%', height: '100%', preserveAspectRatio: 'xMidYMid meet' });
    const bg = svgEl('rect', { x: 0, y: 0, width: W, height: H });
    bg.style.fill = 'var(--bg-page)';
    svg.appendChild(bg);
    for (let x = 40; x < W; x += 40) { const l = svgEl('line', { x1: x, y1: 0, x2: x, y2: H }); l.style.stroke = 'var(--border)'; l.style.strokeWidth = '1'; svg.appendChild(l); }
    for (let y = 40; y < H; y += 40) { const l = svgEl('line', { x1: 0, y1: y, x2: W, y2: y }); l.style.stroke = 'var(--border)'; l.style.strokeWidth = '1'; svg.appendChild(l); }

    let minLat, maxLat, minLng, maxLng;
    if (hasDest && hasOrigin) {
      minLat = Math.min(oLat, dLat); maxLat = Math.max(oLat, dLat);
      minLng = Math.min(oLng, dLng); maxLng = Math.max(oLng, dLng);
    } else if (hasOrigin) {
      minLat = oLat - 0.02; maxLat = oLat + 0.02; minLng = oLng - 0.02; maxLng = oLng + 0.02;
    } else {
      minLat = 14.4; maxLat = 14.7; minLng = 120.9; maxLng = 121.2;
    }
    const spanLat = (maxLat - minLat) || 0.001;
    const spanLng = (maxLng - minLng) || 0.001;
    const proj = (lat, lng) => ({
      x: pad + (lng - minLng) / spanLng * (W - 2 * pad),
      y: H - pad - (lat - minLat) / spanLat * (H - 2 * pad),
    });
    const marker = (x, y, color, title) => {
      svg.appendChild(svgEl('circle', { cx: x, cy: y, r: 7, fill: color, stroke: '#fff', 'stroke-width': 2 }));
      if (title) {
        const t = svgEl('text', { x: x, y: y - 12, 'text-anchor': 'middle', 'font-size': 11 });
        t.style.fill = 'var(--text-muted)';
        t.textContent = title.length > 26 ? title.slice(0, 25) + '\u2026' : title;
        svg.appendChild(t);
      }
    };
    const label = (x, y, text, color) => {
      const t = svgEl('text', { x: x, y: y, 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 600 });
      t.style.fill = color;
      t.textContent = text;
      svg.appendChild(t);
    };

    if (hasOrigin) {
      const o = proj(oLat, oLng);
      svg.appendChild(svgEl('circle', { cx: o.x, cy: o.y, r: 18, fill: 'rgba(56,189,248,.12)', stroke: 'rgba(56,189,248,.4)', 'stroke-width': 1.5 }));
      if (hasDest) {
        const d = proj(dLat, dLng);
        const line = svgEl('line', { x1: o.x, y1: o.y, x2: d.x, y2: d.y, stroke: '#38BDF8', 'stroke-width': 4, 'stroke-linecap': 'round', 'stroke-dasharray': '10 8' });
        svg.appendChild(line);
        marker(o.x, o.y, '#38BDF8', el.dataset.originName || 'Origin');
        marker(d.x, d.y, '#ef4444', $('f_place_to_visit').value || 'Destination');
        const meters = Geo.haversineMeters(oLat, oLng, dLat, dLng);
        const kph = parseFloat(el.dataset.estKph) || 40;
        const mins = Math.max(1, Math.round(meters / 1000 / kph * 60));
        label(W / 2, 18, 'Estimated route (offline) \u00b7 ' + Geo.formatDistance(Math.round(meters)) + ' \u00b7 ~' + mins + ' min', '#D97706');
      } else {
        marker(o.x, o.y, '#38BDF8', el.dataset.originName || 'Origin');
        label(W / 2, H / 2, 'Offline \u2014 pick a destination while online, or reopen a saved draft.', '#64748B');
      }
    } else {
      label(W / 2, H / 2, 'Offline \u2014 map unavailable.', '#64748B');
    }

    el.appendChild(svg);

    if (routeInfoEl) { routeInfoEl.remove(); routeInfoEl = null; }
    routeInfoEl = document.createElement('div');
    routeInfoEl.className = 'route-info';
    routeInfoEl.textContent = hasDest
      ? 'Offline mode \u2014 route shown as a straight-line estimate.'
      : 'Offline mode \u2014 Google Maps unavailable.';
    el.parentNode.appendChild(routeInfoEl);
  }

  /* ── Location autocomplete on destination field ────────────────────── */
  if (typeof LocationAutocomplete === 'function' && $('f_place_to_visit')) {
    const dm = destMap || document.getElementById('destMap');
    LocationAutocomplete($('f_place_to_visit'), {
      lat: dm ? (parseFloat(dm.dataset.originLat) || 14.5126) : 14.5126,
      lon: dm ? (parseFloat(dm.dataset.originLng) || 121.0182) : 121.0182,
      onSelect: function (loc) {
        $('f_place_to_visit_name').value = loc.name || '';
        $('f_place_to_visit_lat').value = loc.latitude || '';
        $('f_place_to_visit_lon').value = loc.longitude || '';
        $('f_place_to_visit_place_id').value = loc.place_id || '';
        lastResolved = $('f_place_to_visit').value;
        const la = parseFloat(loc.latitude), lo = parseFloat(loc.longitude);
        if (isFinite(la) && isFinite(lo)) {
          moveTo(la, lo);
          fetchAndDrawRoute(la, lo);
        }
        dirty = true;
        autosavePaused = false;
      }
    });
    /* Typed destination (no suggestion picked): geocode on blur so the planned
     * route still draws and the coordinates are saved. */
    $('f_place_to_visit').addEventListener('blur', resolveDestinationFromText);
  }

  /* ── Boot ──────────────────────────────────────────────────────────── */
  const boot = () => {
    /* Shared picker setup — same calendar as the rescheduler (schedule_picker.js). */
    const lateDays = parseInt($('f_late_days') ? $('f_late_days').value : '3', 10) || 3;
    SchedulePicker.init('#f_scheduled_departure', '#f_scheduled_return', {
      lateDays,
      onDepChange: () => { dirty = true; autosavePaused = false; },
      onRetChange: () => { dirty = true; autosavePaused = false; },
    });
    initMap();
    updatePaxSummary();
    if ($('f_attachment_path').value) {
      renderUpload(fileName($('f_attachment_path').value));
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();