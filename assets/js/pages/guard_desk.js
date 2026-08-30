/* guard_desk.js — gate verification + start trip from guard desk. */
(function () {
  'use strict';

  document.addEventListener('click', async (e) => {
    const st = e.target.closest('[data-start-trip]');
    if (st) {
      if (await UI.confirmDialog({ message: 'Start this trip now? Enter the current odometer reading on the next screen.', okLabel: 'Start Trip' })) {
        location.href = BASE_URL + 'ticket.php?id=' + st.dataset.startTrip;
      }
      return;
    }
  });

  const btnGuardStart = document.getElementById('btnGuardStart');
  if (btnGuardStart) {
    btnGuardStart.addEventListener('click', () => {
      location.href = BASE_URL + 'ticket.php?id=' + document.getElementById('g_ticket_id').value;
    });
  }

  const btnGvSubmit = document.getElementById('btnGvSubmit');
  if (btnGvSubmit) {
    btnGvSubmit.addEventListener('click', async () => {
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
