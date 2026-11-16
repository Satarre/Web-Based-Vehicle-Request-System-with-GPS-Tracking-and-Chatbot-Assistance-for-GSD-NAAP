/* admin.js — shared behaviors for all mis/ pages (binds only what exists). */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const post = (action, extra) => API.post('api/admin.php', Object.assign({ action }, extra || {}));

  async function act(action, extra, onOk) {
    try {
      const r = await post(action, extra);
      UI.toast(r.message, 'ok');
      if (!onOk) setTimeout(() => location.reload(), 600);
      else onOk(r);
    } catch (e) { UI.toast(e.message, 'err'); }
  }

  const on = (id, fn) => { const el = $(id); if (el) el.addEventListener('click', fn); };

  /* ---- generic edit-button pattern: data-edit='<json>' fills inputs by name ---- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-edit]');
    if (!b) return;
    const data = JSON.parse(b.getAttribute('data-edit'));
    const modalId = b.getAttribute('data-modal') || 'editModal';
    const modal = document.getElementById(modalId);
    modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(inp => {
      const k = inp.name;
      inp.value = data[k] !== undefined && data[k] !== null ? data[k] : (inp.type === 'checkbox' ? inp.checked : '');
      if (inp.type === 'checkbox' && k === 'is_available' && data[k] !== undefined && data[k] !== null) inp.checked = !!Number(data[k]);
      // Role is set only at account creation; it cannot be changed while editing.
      if (k === 'role_id') inp.disabled = (data.id || 0) > 0;
      // Let select.js re-render its custom dropdown after a programmatic fill.
      if (inp.tagName === 'SELECT') inp.dispatchEvent(new Event('change', { bubbles: true }));
    });
    const idField = modal.querySelector('[data-role="pk"]');
    if (idField) idField.value = data.id || 0;
    const title = modal.querySelector('[data-role="title"]');
    if (title) title.textContent = data.id ? title.dataset.editLabel : title.dataset.addLabel;

    // Account modal: role is a pickable dropdown on add, static read-only text on edit.
    if (modalId === 'accountModal') {
      const field = modal.querySelector('[data-role-field]');
      const roleSel = field ? field.querySelector('select[name=role_id]') : null;
      const roleDisp = field ? field.querySelector('[data-role-display]') : null;
      const roleLabel = field ? field.querySelector('[data-role-label]') : null;
      const isEdit = (data.id || 0) > 0;
      const control = roleSel ? (roleSel.closest('.c-select') || roleSel) : null;
      if (control) control.style.display = isEdit ? 'none' : '';
      if (roleDisp) {
        roleDisp.textContent = isEdit ? (data.role_name || '') : '';
        roleDisp.style.display = isEdit ? '' : 'none';
      }
      if (roleLabel) roleLabel.textContent = isEdit ? 'Role' : 'Role *';

      const hint = modal.querySelector('[data-role="pass-hint"]');
      const passInput = modal.querySelector('input[name="password"]');
      const reqList = modal.querySelector('[data-pw-req]');
      if (passInput) {
        passInput.required = !isEdit;
        /* Add mode: checklist always visible. Edit mode: blank password means
         * "keep current" (show the hint); typing swaps in the checklist. */
        const syncPwUi = () => {
          const empty = passInput.value === '';
          if (hint) hint.style.display = (isEdit && empty) ? '' : 'none';
          if (reqList) reqList.style.display = (!isEdit || !empty) ? '' : 'none';
        };
        if (!passInput.dataset.pwUiBound) {
          passInput.dataset.pwUiBound = '1';
          passInput.addEventListener('input', syncPwUi);
        }
        syncPwUi();
      }
      // Singleton-role warnings only apply to the add form.
      modal.querySelectorAll('[data-add-only]').forEach(el => { el.style.display = isEdit ? 'none' : ''; });
    }

    UI.modal(modalId, true);
  });

  /* ---- fleet: vehicles ---- */
  on('vehicleFormSubmit', () => {
    const f = $('vehicleForm');
    if (!f.reportValidity()) return;
    act('vehicle_save', {
      vehicle_id: parseInt(f.vehicle_id.value, 10) || 0,
      plate_number: f.plate_number.value,
      model_name: f.model_name.value,
      brand: f.brand.value,
      type_id: parseInt(f.type_id.value, 10),
      capacity: parseInt(f.capacity.value, 10),
      default_fuel_type: f.default_fuel_type.value,
      is_available: f.is_available.checked ? 1 : 0
    });
  });

  /* ---- fleet: token modal (device or tracker registration) ---- */
  function showTokenModal(r) {
    $('deviceTokenOut').textContent = r.api_token;
    const title = $('tokenModalTitle');
    if (title) title.textContent = r.tracker_email ? 'Tracker Account Created' : 'Device Registered';
    const info = $('trackerInfo');
    const em   = $('trackerEmailOut');
    if (info && em) {
      em.textContent = r.tracker_email || '';
      info.style.display = r.tracker_email ? '' : 'none';
    }
    UI.modal('tokenModal', true);
  }

  /* ---- fleet: device register shows raw token in its own modal ---- */
  on('deviceFormSubmit', async () => {
    const f = $('deviceForm');
    if (!f.reportValidity()) return;
    try {
      const r = await post('device_register', {
        device_uid: f.device_uid.value,
        device_name: f.device_name.value,
        manufacturer: f.manufacturer.value,
        model: f.model.value
      });
      $('deviceForm').reset();
      UI.modal('deviceModal', false);
      showTokenModal(r);
    } catch (e) { UI.toast(e.message, 'err'); }
  });

  /* ---- fleet: policy-compliant password generator (tracker logins) ---- */
  function genPassword(len) {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ', lower = 'abcdefghijkmnopqrstuvwxyz';
    const digits = '23456789', symbols = '!@#$%&*?';
    const all = upper + lower + digits + symbols;
    const pick = (s) => {
      if (window.crypto && crypto.getRandomValues) {
        const a = new Uint32Array(1);
        crypto.getRandomValues(a);
        return s[a[0] % s.length];
      }
      return s[Math.floor(Math.random() * s.length)];
    };
    let pw = pick(upper) + pick(lower) + pick(digits) + pick(symbols);
    while (pw.length < (len || 14)) pw += pick(all);
    return pw.split('').sort(() => Math.random() - 0.5).join('');
  }
  function fillPassword(input) {
    if (!input) return;
    input.value = genPassword();
    input.type = 'text'; // reveal the generated value so it can be recorded
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }
  on('trackerPwGenerate', () => { const f = $('trackerForm'); fillPassword(f ? f.password : null); });

  /* ---- fleet: hidden tracker account (owns a paired device token) ---- */
  on('trackerFormSubmit', async () => {
    const f = $('trackerForm');
    if (!f.reportValidity()) return;
    if (typeof PasswordPolicy !== 'undefined') {
      const pwErr = PasswordPolicy.check(f.password.value);
      if (pwErr) return UI.toast(pwErr, 'err');
    }
    try {
      const r = await post('tracker_register', {
        vehicle_id: parseInt(f.vehicle_id.value, 10),
        device_uid: f.device_uid.value,
        device_name: f.device_name.value,
        email: f.email.value,
        password: f.password.value
      });
      f.reset();
      UI.modal('trackerModal', false);
      showTokenModal(r);
    } catch (e) { UI.toast(e.message, 'err'); }
  });

  /* ---- fleet: edit a registered device (login fields for trackers) -----
   * Details are fetched server-side on open so tracker login details never
   * appear in the GPS Devices list markup. */
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-device-edit]');
    if (!b) return;
    const f = $('deviceEditForm');
    if (!f) return;
    try {
      const r = await post('device_get', { device_id: parseInt(b.dataset.deviceEdit, 10) });
      const d = r.device || {};
      f.reset();
      f.device_id.value = d.id || 0;
      f.device_uid.value = d.device_uid || '';
      f.device_name.value = d.device_name || '';
      f.manufacturer.value = d.manufacturer || '';
      f.model.value = d.model || '';
      f.email.value = d.email || '';
      f.password.value = '';
      f.dataset.tracker = d.is_tracker ? '1' : '0';
      f.querySelectorAll('[data-tracker-only]').forEach(el => { el.style.display = d.is_tracker ? '' : 'none'; });
      f.querySelectorAll('[data-hardware-only]').forEach(el => { el.style.display = d.is_tracker ? 'none' : ''; });
      UI.modal('deviceEditModal', true);
    } catch (err) { UI.toast(err.message, 'err'); }
  });
  on('deviceEditPwGenerate', () => { const f = $('deviceEditForm'); fillPassword(f ? f.password : null); });
  on('deviceEditSubmit', async () => {
    const f = $('deviceEditForm');
    if (!f.reportValidity()) return;
    if (f.dataset.tracker === '1' && f.password.value && typeof PasswordPolicy !== 'undefined') {
      const pwErr = PasswordPolicy.check(f.password.value);
      if (pwErr) return UI.toast(pwErr, 'err');
    }
    try {
      const r = await post('device_save', {
        device_id: parseInt(f.device_id.value, 10),
        device_uid: f.device_uid.value,
        device_name: f.device_name.value,
        manufacturer: f.manufacturer.value,
        model: f.model.value,
        email: f.email.value,
        password: f.password.value
      });
      UI.toast(r.message, 'ok');
      UI.modal('deviceEditModal', false);
      setTimeout(() => location.reload(), 600);
    } catch (e) { UI.toast(e.message, 'err'); }
  });
  on('btnTokenCopy', () => {
    const t = $('deviceTokenOut');
    if (!t || !t.textContent) return;
    navigator.clipboard.writeText(t.textContent)
      .then(() => UI.toast('Token copied.', 'ok'))
      .catch(() => UI.toast('Could not copy — select and copy manually.', 'err'));
  });
  on('btnTokenDone', () => {
    UI.modal('tokenModal', false);
    setTimeout(() => location.reload(), 300);
  });

  /* ---- fleet: delete device ---- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-delete-device]');
    if (!b) return;
    UI.confirmDialog({ message: b.dataset.confirm || 'Delete this device?', okLabel: 'Delete', danger: true })
      .then(ok => { if (ok) act('device_delete', { device_id: parseInt(b.dataset.deleteDevice, 10) }); });
  });

  /* ---- fleet / accounts: toggle rows ---- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-toggle-action]');
    if (!b) return;
    UI.confirmDialog({ message: b.dataset.confirm || 'Apply this change?', okLabel: 'Confirm' })
      .then(ok => { if (ok) act(b.dataset.toggleAction, { [b.dataset.pk]: parseInt(b.dataset.val, 10) }); });
  });

  /* ---- fleet: pair/unpair selects ---- */
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-pair-device]');
    if (!sel) return;
    const did = parseInt(sel.dataset.pairDevice, 10);
    const vid = parseInt(sel.value, 10) || 0;
    act(vid ? 'device_pair' : 'device_pair', { device_id: did, vehicle_id: vid });
  });

  /* ---- fleet: vehicle types ---- */
  on('typeFormSubmit', () => {
    const f = $('typeForm');
    if (!f.reportValidity()) return;
    act('type_save', {
      type_id: parseInt(f.type_id.value, 10) || 0,
      type_name: f.type_name.value
    });
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-type-del]');
    if (!b) return;
    UI.confirmDialog({ message: b.dataset.confirm || 'Delete this vehicle type?', okLabel: 'Delete', danger: true })
      .then(ok => { if (ok) act('type_delete', { type_id: parseInt(b.dataset.typeDel, 10) }); });
  });

  /* ---- accounts ---- */
  on('accountFormSubmit', async () => {
    const f = $('accountForm');
    if (!f.reportValidity()) return;
    const isNew = !(parseInt(f.account_id.value, 10) || 0);
    const pass = f.password.value;
    if (isNew && !pass) return UI.toast('A password is required.', 'err');
    if (pass !== f.password_confirm.value) return UI.toast('Password and confirmation do not match.', 'err');
    if (pass && typeof PasswordPolicy !== 'undefined') {
      const pwErr = PasswordPolicy.check(pass);
      if (pwErr) return UI.toast(pwErr, 'err');
    }
    const summary = (isNew ? 'Create' : 'Update') + ' account for ' + (f.first_name.value.trim() + ' ' + f.last_name.value.trim()).trim() +
      ' (' + f.email.value + ')' + (pass ? ' with a new password' : '') + '?';
    const ok = await UI.confirmDialog({ message: summary, okLabel: isNew ? 'Create Account' : 'Save Account' });
    if (!ok) return;
    act('account_save', {
      account_id: parseInt(f.account_id.value, 10) || 0,
      email: f.email.value,
      first_name: f.first_name.value,
      middle_initial: f.middle_initial.value,
      middle_name: f.middle_name.value,
      last_name: f.last_name.value,
      suffix: f.suffix.value,
      role_id: parseInt(f.role_id.value, 10),
      password: pass,
      password_confirm: f.password_confirm.value
    });
  });

  /* ---- settings ---- */
  on('settingsSave', async () => {
    const vals = {};
    document.querySelectorAll('[data-setting]').forEach(inp => { vals[inp.dataset.setting] = inp.value; });
    try {
      const r = await post('settings_save', { settings: vals });
      const n = r.saved || 0;
      if (!n) { UI.toast('No changes made.', 'info'); return; }
      UI.toast(n + (n === 1 ? ' change made.' : ' changes made.'), 'ok');
      // Clear rotated secrets from the screen; a reload would wipe the toast.
      document.querySelectorAll('input[type="password"][data-setting]').forEach(i => { i.value = ''; });
    } catch (e) { UI.toast(e.message, 'err'); }
  });

  /* ---- SMTP test email ---- */
  on('smtpTest', async () => {
    const btn = document.getElementById('smtpTest');
    const to = document.getElementById('smtpTestTo');
    btn.disabled = true;
    try {
      const r = await post('smtp_test', { to: to ? to.value.trim() : '' });
      UI.toast(r.message, 'ok');
    } catch (e) { UI.toast(e.message, 'err'); }
    btn.disabled = false;
  });

  /* ---- geofence map (Maps library loads only when the map nears view) ---- */
  function initGeofenceMap() {
    const el = document.getElementById('geofenceMap');
    if (!el) return;
    NAAPMaps.observe(el, function (ok) {
      if (!ok || typeof google === 'undefined' || !google.maps) return;
      const lat = parseFloat(el.dataset.lat) || 14.5126;
      const lng = parseFloat(el.dataset.lng) || 121.0182;
      const radius = parseFloat(el.dataset.radius) || 500;
      const map = new google.maps.Map(el, { center: { lat, lng }, zoom: 15, mapTypeControl: false, streetViewControl: false });
      let circle = new google.maps.Circle({
        map, center: { lat, lng }, radius,
        fillColor: '#38BDF8', fillOpacity: 0.04,
        strokeColor: '#38BDF8', strokeWeight: 1.5, strokeDashArray: [6, 6],
      });
      function inp(k) { return document.querySelector('[data-setting="' + k + '"]'); }
      function update() {
        const la = parseFloat(inp('NAAP_GEOFENCE_LAT').value);
        const lo = parseFloat(inp('NAAP_GEOFENCE_LNG').value);
        const ra = parseFloat(inp('NAAP_GEOFENCE_RADIUS_METERS').value);
        if (isNaN(la) || isNaN(lo)) return;
        circle.setCenter({ lat: la, lng: lo });
        if (!isNaN(ra)) circle.setRadius(ra);
        map.setCenter({ lat: la, lng: lo });
      }
      ['NAAP_GEOFENCE_LAT', 'NAAP_GEOFENCE_LNG', 'NAAP_GEOFENCE_RADIUS_METERS'].forEach(k => {
        const i = inp(k);
        if (i) i.addEventListener('input', update);
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initGeofenceMap);
  else initGeofenceMap();

  /* ---- origin location map ---- */
  function initOriginMap() {
    const searchEl = document.getElementById('originSearch');
    const mapEl = document.getElementById('originMap');
    const lat = mapEl ? (parseFloat(mapEl.dataset.lat) || 14.5126) : 14.5126;
    const lng = mapEl ? (parseFloat(mapEl.dataset.lng) || 121.0182) : 121.0182;
    const hasLoc = mapEl && mapEl.dataset.lat && mapEl.dataset.lng && isFinite(lat) && isFinite(lng);

    let map = null, marker = null;

    function inp(k) { return document.querySelector('[data-setting="' + k + '"]'); }
    function setOrigin(loc) {
      const la = parseFloat(loc.latitude), lo = parseFloat(loc.longitude);
      if (isNaN(la) || isNaN(lo)) return;
      inp('NAAP_ORIGIN_NAME').value = loc.name || '';
      inp('NAAP_ORIGIN_LAT').value = la;
      inp('NAAP_ORIGIN_LNG').value = lo;
      inp('NAAP_ORIGIN_PLACE_ID').value = loc.place_id || '';
      if (marker) marker.setPosition({ lat: la, lng: lo });
      else if (map) { marker = new google.maps.Marker({ position: { lat: la, lng: lo }, map, title: 'Origin' }); }
      if (map) { map.setCenter({ lat: la, lng: lo }); map.setZoom(15); }
    }

    /* Autocomplete wires up immediately; it lazy-loads Places on first search. */
    if (typeof LocationAutocomplete === 'function' && searchEl) {
      LocationAutocomplete(searchEl, {
        lat: hasLoc ? lat : 14.5126, lon: hasLoc ? lng : 121.0182,
        onSelect: setOrigin
      });
    }

    if (mapEl) {
      NAAPMaps.observe(mapEl, function (ok) {
        if (!ok || typeof google === 'undefined' || !google.maps) return;
        try {
          map = new google.maps.Map(mapEl, {
            center: { lat: hasLoc ? lat : 14.5126, lng: hasLoc ? lng : 121.0182 },
            zoom: hasLoc ? 15 : 12, mapTypeControl: false, streetViewControl: false, mapId: '4a7ad8d621c8e194'
          });
          if (hasLoc) marker = new google.maps.Marker({ position: { lat, lng }, map, title: 'Origin' });
        } catch (e) { /* map init failed — autocomplete still works */ }
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initOriginMap);
  else initOriginMap();

  /* ---- chatbot FAQs ---- */
  on('faqFormSubmit', () => {
    const f = $('faqForm');
    if (!f.reportValidity()) return;
    act('faq_save', { chatbot_id: parseInt(f.chatbot_id.value, 10) || 0, prompt_keyword: f.prompt_keyword.value, keywords: f.keywords.value, response_text: f.response_text.value });
  });
  document.addEventListener('click', (e) => {
    const d = e.target.closest('[data-faq-del]');
    if (d) {
      UI.confirmDialog({ message: 'Delete this FAQ?', okLabel: 'Delete', danger: true })
        .then(ok => { if (ok) act('faq_delete', { chatbot_id: parseInt(d.dataset.faqDel, 10) }); });
      return;
    }
    const t = e.target.closest('[data-faq-toggle]');
    if (t) {
      const on = t.textContent.trim() === 'Active';
      UI.confirmDialog({ message: (on ? 'Deactivate' : 'Activate') + ' this FAQ? It will ' + (on ? 'stop' : 'start') + ' being answered.', okLabel: on ? 'Deactivate' : 'Activate' })
        .then(ok => { if (ok) act('faq_toggle', { chatbot_id: parseInt(t.dataset.faqToggle, 10) }); });
      return;
    }
    const v = e.target.closest('[data-faq-display-toggle]');
    if (v) {
      const shown = v.textContent.trim() === 'Shown';
      UI.confirmDialog({ message: (shown ? 'Hide' : 'Show') + ' this FAQ? Hidden FAQs are not offered to users.', okLabel: shown ? 'Hide' : 'Show' })
        .then(ok => { if (ok) act('faq_display_toggle', { chatbot_id: parseInt(v.dataset.faqDisplayToggle, 10) }); });
      return;
    }
    const fb = e.target.closest('[data-fb-del]');
    if (fb) {
      UI.confirmDialog({ message: 'Remove this feedback?', okLabel: 'Remove', danger: true })
        .then(ok => { if (ok) act('feedback_delete', { feedback_id: parseInt(fb.dataset.fbDel, 10) }); });
    }
  });
})();
