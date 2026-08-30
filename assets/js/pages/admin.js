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
      if (inp.type === 'checkbox' && k === 'is_available') inp.checked = !!Number(data[k]);
    });
    const idField = modal.querySelector('[data-role="pk"]');
    if (idField) idField.value = data.id || 0;
    const title = modal.querySelector('[data-role="title"]');
    if (title) title.textContent = data.id ? title.dataset.editLabel : title.dataset.addLabel;
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

  /* ---- fleet: device register shows raw token once ---- */
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
      $('deviceTokenOut').textContent = r.api_token;
      $('tokenBox').style.display = 'block';
      $('deviceForm').reset();
      UI.toast('Device registered.', 'ok');
    } catch (e) { UI.toast(e.message, 'err'); }
  });
  on('btnTokenDone', () => {
    UI.modal('deviceModal', false);
    window._navGuardOnce = true;
    setTimeout(() => location.reload(), 300);
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

  /* ---- fleet: add vehicle type ---- */
  on('typeAddBtn', () => {
    const inp = $('typeAddInput');
    if (!inp || !inp.value.trim()) return UI.toast('Type name required.', 'err');
    act('type_add', { type_name: inp.value.trim() });
  });

  /* ---- accounts ---- */
  on('accountFormSubmit', () => {
    const f = $('accountForm');
    if (!f.reportValidity()) return;
    act('account_save', {
      account_id: parseInt(f.account_id.value, 10) || 0,
      email: f.email.value,
      full_name: f.full_name.value,
      role_id: parseInt(f.role_id.value, 10),
      password: f.password.value
    });
  });

  /* ---- settings ---- */
  on('settingsSave', () => {
    const vals = {};
    document.querySelectorAll('[data-setting]').forEach(inp => { vals[inp.dataset.setting] = inp.value; });
    act('settings_save', { settings: vals });
  });

  /* ---- geofence map ---- */
  function initGeofenceMap() {
    const el = document.getElementById('geofenceMap');
    if (!el || typeof L === 'undefined') return;
    const lat = parseFloat(el.dataset.lat) || 14.5126;
    const lng = parseFloat(el.dataset.lng) || 121.0182;
    const radius = parseFloat(el.dataset.radius) || 500;
    const map = L.map(el).setView([lat, lng], 15);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    let circle = L.circle([lat, lng], { radius: radius, color: '#38BDF8', weight: 1.5, dashArray: '6 6', fillOpacity: 0.04 }).addTo(map);
    function inp(k) { return document.querySelector('[data-setting="' + k + '"]'); }
    function update() {
      const la = parseFloat(inp('NAAP_GEOFENCE_LAT').value);
      const lo = parseFloat(inp('NAAP_GEOFENCE_LNG').value);
      const ra = parseFloat(inp('NAAP_GEOFENCE_RADIUS_METERS').value);
      if (isNaN(la) || isNaN(lo)) return;
      circle.setLatLng([la, lo]);
      if (!isNaN(ra)) circle.setRadius(ra);
      map.setView([la, lo]);
    }
    ['NAAP_GEOFENCE_LAT', 'NAAP_GEOFENCE_LNG', 'NAAP_GEOFENCE_RADIUS_METERS'].forEach(k => {
      const i = inp(k);
      if (i) i.addEventListener('input', update);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initGeofenceMap);
  else initGeofenceMap();

  /* ---- chatbot FAQs ---- */
  on('faqFormSubmit', () => {
    const f = $('faqForm');
    if (!f.reportValidity()) return;
    act('faq_save', { chatbot_id: parseInt(f.chatbot_id.value, 10) || 0, prompt_keyword: f.prompt_keyword.value, response_text: f.response_text.value });
  });
  document.addEventListener('click', (e) => {
    const d = e.target.closest('[data-faq-del]');
    if (d) {
      UI.confirmDialog({ message: 'Delete this FAQ?', okLabel: 'Delete', danger: true })
        .then(ok => { if (ok) act('faq_delete', { chatbot_id: parseInt(d.dataset.faqDel, 10) }); });
      return;
    }
    const fb = e.target.closest('[data-fb-del]');
    if (fb) {
      UI.confirmDialog({ message: 'Remove this feedback?', okLabel: 'Remove', danger: true })
        .then(ok => { if (ok) act('feedback_delete', { feedback_id: parseInt(fb.dataset.fbDel, 10) }); });
    }
  });
})();
