/* posttrip.js — submit Section B report. */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  $('btnPtSubmit').addEventListener('click', async () => {
    const odo = parseFloat($('f_odometer_end').value);
    if (isNaN(odo) || odo < 0) return UI.toast('Odometer end reading is required.', 'err');
    const odoStartEl = $('f_odometer_start');
    const odoStart = odoStartEl && !odoStartEl.disabled ? parseFloat(odoStartEl.value) : null;
    if (odoStartEl && !odoStartEl.disabled && (isNaN(odoStart) || odoStart < 0)) return UI.toast('Odometer start reading is required.', 'err');
    try {
      const payload = {
        action: 'submit_posttrip',
        ticket_id: parseInt($('f_ticket_id').value, 10),
        odometer_end: odo,
        fuel_type: $('f_fuel_type').value,
        fuel_tank_balance_start: $('f_fuel_tank_balance_start').value,
        fuel_issued_stock: $('f_fuel_issued_stock').value,
        fuel_purchased_trip: $('f_fuel_purchased_trip').value,
        fuel_used: $('f_fuel_used').value,
        gear_oil_used: $('f_gear_oil_used').value,
        lubricant_oil_issued: $('f_lubricant_oil_issued').value,
        grease_issued: $('f_grease_issued').value,
        remarks: $('f_remarks').value,
        fuel_cost: $('f_fuel_cost').value,
        easytrip_cost: $('f_easytrip_cost').value,
        autosweep_cost: $('f_autosweep_cost').value
      };
      if (odoStart !== null && !isNaN(odoStart)) {
        payload.odometer_start = odoStart;
      }
      const r = await API.post('api/trips.php', payload);
      UI.toast(r.message, 'ok');
      setTimeout(() => { location.href = BASE_URL + 'ticket.php?id=' + $('f_ticket_id').value; }, 600);
    } catch (e) { UI.toast(e.message, 'err'); }
  });
})();
