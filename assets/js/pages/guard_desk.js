/* guard_desk.js — gate verification from the guard desk. */
(function () {
  'use strict';

  const btnGvSubmit = document.getElementById('btnGvSubmit');
  if (btnGvSubmit) {
    btnGvSubmit.addEventListener('click', async () => {
      if (!(await UI.confirmDialog({ message: 'Record this gate verification?', okLabel: 'Record' }))) return;
      try {
        const r = await API.post('api/trips.php', {
          action: 'verify',
          ticket_id: parseInt(document.getElementById('g_ticket_id').value, 10),
          phase: document.getElementById('g_phase').value,
          result: document.getElementById('g_result').value,
          checked_driver: document.getElementById('g_checked_driver').checked ? 1 : 0,
          checked_vehicle: document.getElementById('g_checked_vehicle').checked ? 1 : 0,
          checked_plate: document.getElementById('g_checked_plate').checked ? 1 : 0,
          checked_passengers: document.getElementById('g_checked_passengers').checked ? 1 : 0,
          notes: document.getElementById('g_notes').value
        });
        UI.toast(r.message, 'ok');
        setTimeout(() => { location.href = BASE_URL + 'guard/desk.php'; }, 600);
      } catch (e) { UI.toast(e.message, 'err'); }
    });
  }
})();
