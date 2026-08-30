/* director_trips.js — cancel actions for director/trips.php. */
(function () {
  'use strict';
  var modal = document.getElementById('cancelModal');
  var modalInput = document.getElementById('rmInput');
  var modalOk = document.getElementById('rmOk');
  var cancelId = null;

  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) { modal.classList.add('hidden'); cancelId = null; }
    });
    document.getElementById('rmCancel').addEventListener('click', function () { modal.classList.add('hidden'); cancelId = null; });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cancel]');
    if (!btn) return;
    cancelId = parseInt(btn.dataset.cancel, 10);
    if (modal) { modal.classList.remove('hidden'); modalInput.value = ''; modalInput.focus(); }
  });

  if (modalOk) {
    modalOk.addEventListener('click', async function () {
      if (!cancelId) return;
      var reason = modalInput.value.trim();
      if (!reason) { UI.toast('Please provide a reason.', 'err'); return; }
      try {
        var r = await API.post('api/trips.php', { action: 'cancel', ticket_id: cancelId, reason: reason });
        UI.toast(r.message, 'ok');
        modal.classList.add('hidden');
        cancelId = null;
        setTimeout(function () { location.reload(); }, 400);
      } catch (err) { UI.toast(err.message, 'err'); }
    });
  }
})();
