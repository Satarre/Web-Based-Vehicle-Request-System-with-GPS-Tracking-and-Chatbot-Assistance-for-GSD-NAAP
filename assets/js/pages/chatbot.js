(function () {
  'use strict';
  var panel = document.getElementById('chatPanel');
  if (!panel || typeof API === 'undefined') return;

  var body = document.getElementById('chatBody');
  var chipsEl = document.getElementById('chatChips');
  var form = document.getElementById('chatForm');
  var input = document.getElementById('chatText');
  var greeted = false;

  function addMsg(txt, who) {
    var d = document.createElement('div');
    d.className = 'msg ' + who;
    d.textContent = txt;
    body.appendChild(d);
    body.scrollTop = body.scrollHeight;
  }

  function setChips(list) {
    chipsEl.innerHTML = '';
    (list || []).forEach(function (k) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'chip';
      b.textContent = k;
      b.addEventListener('click', function () { ask(k); });
      chipsEl.appendChild(b);
    });
  }

  function ask(q) {
    q = (q || '').trim();
    if (!q) return;
    addMsg(q, 'me');
    setChips([]);
    API.post('api/chatbot.php', { action: 'ask', question: q })
      .then(function (r) {
        if (r.answer) {
          addMsg(r.answer, 'bot');
        } else {
          addMsg("I'm not sure about that one. Try one of these topics instead:", 'bot');
          loadChips();
        }
      })
      .catch(function (e) {
        addMsg(e.message || 'Something went wrong.', 'bot');
      });
  }

  function loadChips() {
    API.get('api/chatbot.php?action=list')
      .then(function (r) { setChips(r.keywords || []); })
      .catch(function (e) { addMsg(e.message || 'Assistant is unavailable right now.', 'bot'); });
  }

  function open() {
    panel.hidden = false;
    if (!greeted) {
      greeted = true;
      addMsg('Hi! Pick a topic below or type your question about trip tickets.', 'bot');
      loadChips();
    }
    input.focus();
  }

  function close() { panel.hidden = true; }

  document.querySelectorAll('[data-chat-open]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      panel.hidden ? open() : close();
    });
  });
  document.getElementById('chatClose').addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) close();
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = input.value;
    input.value = '';
    ask(v);
  });
})();
