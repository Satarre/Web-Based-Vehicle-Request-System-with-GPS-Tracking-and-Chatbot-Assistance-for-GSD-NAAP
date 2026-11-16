/* assets/js/password-policy.js — client mirror of core/PasswordPolicy.php.
 * Keep the rules in sync with the server (which is the authority).
 * Hardcoded by design: NOT configurable from the frontend.
 * PasswordPolicy.check(pw) returns '' when valid, else the first failure message.
 * PasswordPolicy.checklist(pw) returns [{label, ok}] used for live UI feedback.
 *
 * Auto-binds on load (pages with password fields):
 *   [data-pw-toggle="<input id>"]  — eye button that reveals/hides the input
 *   [data-pw-req="<input id>"]     — live requirement checklist container  */
(function () {
  'use strict';

  var MIN_LENGTH = 8;

  function check(pw) {
    pw = pw || '';
    if (pw.length < MIN_LENGTH) return 'Password must be at least ' + MIN_LENGTH + ' characters.';
    if (!/[A-Z]/.test(pw)) return 'Password must contain at least one uppercase letter.';
    if (!/[a-z]/.test(pw)) return 'Password must contain at least one lowercase letter.';
    if (!/[0-9]/.test(pw)) return 'Password must contain at least one number.';
    if (!/[^A-Za-z0-9]/.test(pw)) return 'Password must contain at least one special character.';
    return '';
  }

  /* Live UI rules. The number rule stays server-side only by design; keep the
   * first three lines aligned with check() above. */
  function checklist(pw) {
    pw = pw || '';
    return [
      { label: 'At least ' + MIN_LENGTH + ' characters', ok: pw.length >= MIN_LENGTH },
      { label: 'Has uppercase and lowercase letters', ok: /[A-Z]/.test(pw) && /[a-z]/.test(pw) },
      { label: 'Has a special character', ok: /[^A-Za-z0-9]/.test(pw) }
    ];
  }

  function renderChecklist(box) {
    var input = document.getElementById(box.dataset.pwReq);
    if (!input) return;
    box.innerHTML = checklist(input.value).map(function (it) {
      return '<div class="pw-req-item' + (it.ok ? ' ok' : '') + '">' +
        '<span class="pw-req-mark">' + (it.ok ? '\u2713' : '\u2716') + '</span>' +
        '<span>' + it.label + '</span></div>';
    }).join('');
  }

  function bind() {
    document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
      if (btn.dataset.pwBound) return;
      btn.dataset.pwBound = '1';
      var input = document.getElementById(btn.dataset.pwToggle);
      if (!input) return;
      btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('pw-on', show);
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
    });
    document.querySelectorAll('[data-pw-req]').forEach(function (box) {
      if (box.dataset.pwBound) return;
      box.dataset.pwBound = '1';
      var input = document.getElementById(box.dataset.pwReq);
      if (!input) return;
      input.addEventListener('input', function () { renderChecklist(box); });
      renderChecklist(box);
    });
  }

  window.PasswordPolicy = { MIN_LENGTH: MIN_LENGTH, check: check, checklist: checklist };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
})();
