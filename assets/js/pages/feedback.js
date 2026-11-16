/* feedback.js — personnel feedback submission. */
(function () {
  'use strict';
  const btn = document.getElementById('fbSend');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const ticketId = document.getElementById('fbTicket').value;
    const details = document.getElementById('fbDetails').value.trim();
    if (!ticketId) { UI.toast('Select the trip this feedback refers to.', 'error'); return; }
    if (!details) { UI.toast('Feedback details are required.', 'error'); return; }
    btn.disabled = true;
    try {
      const res = await API.post('api/trips.php', {
        action: 'feedback_save', ticket_id: ticketId, details,
      });
      UI.toast(res.message || 'Feedback submitted.', 'success');
      setTimeout(() => location.reload(), 1200);
    } catch (e) {
      UI.toast(e.message, 'error');
      btn.disabled = false;
    }
  });
})();
