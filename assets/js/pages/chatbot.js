/**
 * assets/js/pages/chatbot.js — GSD Assistant panel (personnel).
 *
 * Rule-based: it renders exactly what api/chatbot.php returns.
 *  - strip: page-aware topic chips (a handful) + "Browse all topics"
 *  - browse view: grouped topics with friendly labels, filterable by
 *    label / canonical keyword / synonyms
 *  - answers: FAQ text, live ticket cards, form warnings with fix buttons,
 *    link actions and follow-up ask-chips
 *  - fallback: muted "not an answer" bubble; 3 consecutive misses escalate to
 *    a contact card
 *
 * Chip values are always the canonical keywords, so the matcher, the stored
 * FAQ text and the conversation log are unaffected by the display labels.
 */
(function () {
  'use strict';
  var panel = document.getElementById('chatPanel');
  if (!panel || typeof API === 'undefined') return;

  var body = document.getElementById('chatBody');
  var browse = document.getElementById('chatBrowse');
  var topicsBar = document.getElementById('chatTopicsBar');
  var form = document.getElementById('chatForm');
  var input = document.getElementById('chatText');

  var greeted = false;
  var topicBlock = null;
  var browseLink = null;

  /* Topic state: page chips for the strip, every entry for auto-suggest, and
     the server's groups for the browse view. */
  var allTopics = [];
  var pageTopics = [];
  var groups = [];
  var browseListEl = null;
  var filterTimer = null;
  var browsing = false;

  var CONTACT_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">'
    + '<rect x="5" y="8" width="14" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="2"/>'
    + '<circle cx="9.5" cy="13" r="1.4" fill="currentColor"/><circle cx="14.5" cy="13" r="1.4" fill="currentColor"/>'
    + '<path d="M12 8V4m-9 9h2m16 0h2" stroke="currentColor" stroke-width="2"/></svg>';

  var CLOUD_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">'
    + '<path d="M7 18a4.5 4.5 0 01-.4-9A6 6 0 0119 11a3.5 3.5 0 011 6.9M8 13l4-4m0 0l4 4m-4-4v9"'
    + ' fill="none" stroke="currentColor" stroke-width="2"/></svg>';

  /* ── messages ────────────────────────────────────────────────────────── */

  function scrollBody() {
    body.scrollTop = body.scrollHeight;
  }

  function addMsg(txt, who) {
    var d = document.createElement('div');
    d.className = 'msg ' + who;
    d.textContent = txt;
    body.appendChild(d);
    scrollBody();
  }

  function addTyping() {
    var d = document.createElement('div');
    d.className = 'msg bot typing';
    d.innerHTML = '<i></i><i></i><i></i>';
    body.appendChild(d);
    scrollBody();
    return d;
  }

  function removeTyping(d) {
    if (d && d.parentNode) d.parentNode.removeChild(d);
  }

  /* A turn groups everything one bot reply contains (kind label, warning block,
     cards, text, action chips) so spacing inside a reply is tight. */
  function turn() {
    var t = document.createElement('div');
    t.className = 'chat-turn';
    body.appendChild(t);
    return t;
  }

  function bubble(parent, txt) {
    var d = document.createElement('div');
    d.className = 'msg bot';
    d.textContent = txt;
    parent.appendChild(d);
    return d;
  }

  /* ── chips ───────────────────────────────────────────────────────────── */

  /* Links: only app-relative paths are ever navigated to. The server also
     allowlists every href; this is the client-side second check. */
  function safeUrl(href) {
    href = String(href || '');
    if (!href) return null;
    if (/^[a-z][a-z0-9+.-]*:/i.test(href)) return null; // scheme (javascript:, http:, …)
    if (href.indexOf('//') === 0) return null;           // protocol-relative
    if (href.indexOf('..') !== -1) return null;          // path traversal
    var base = (typeof BASE_URL !== 'undefined') ? BASE_URL : '/';
    return base + href.replace(/^\/+/, '');
  }

  function chipEl(item, extraClass, onActivate) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'chip' + (item.type === 'link' ? ' chip-action' : '') + (extraClass ? ' ' + extraClass : '');
    b.textContent = item.label || '';
    if (item.title) b.title = String(item.title);
    b.addEventListener('click', function () {
      if (typeof onActivate === 'function') { onActivate(item); return; }
      if (item.type === 'link') {
        var url = safeUrl(item.href);
        if (url) location.assign(url);
      } else {
        ask(item.value);
      }
    });
    return b;
  }

  function askChip(entry, extraClass, onActivate) {
    return chipEl({ type: 'ask', label: entry.label, value: entry.value, title: entry.value },
      extraClass, onActivate);
  }

  /* ── topic strip (pinned above the input) ────────────────────────────── */

  function removeTopics() {
    if (topicBlock && topicBlock.parentNode) topicBlock.parentNode.removeChild(topicBlock);
    topicBlock = null;
    syncTopicsBar();
  }

  /* The strip stays visible whenever it holds chips or the browse control. */
  function syncTopicsBar() {
    var hasLink = browseLink && !browseLink.hidden;
    topicsBar.hidden = browsing || (!topicBlock && !hasLink);
  }

  function ensureBrowseLink() {
    if (!browseLink) {
      browseLink = document.createElement('button');
      browseLink.type = 'button';
      browseLink.className = 'chat-show-all';
      browseLink.textContent = 'Browse all topics';
      browseLink.addEventListener('click', openBrowse);
      topicsBar.appendChild(browseLink);
    }
    return browseLink;
  }

  function showBrowseLink() {
    ensureBrowseLink().hidden = false;
    syncTopicsBar();
  }

  function hideBrowseLink() {
    if (browseLink) browseLink.hidden = true;
    syncTopicsBar();
  }

  function renderTopics(entries) {
    removeTopics();
    hideBrowseLink();
    if (!entries || !entries.length) {
      syncTopicsBar();
      return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'chat-topics';
    entries.forEach(function (t) { wrap.appendChild(askChip(t)); });
    topicsBar.appendChild(wrap);
    topicBlock = wrap;
    syncTopicsBar();
  }

  /* ── grouping + filtering ────────────────────────────────────────────── */

  function flattenGroups(list) {
    var out = [];
    (list || []).forEach(function (g) {
      (g.topics || []).forEach(function (t) { out.push(t); });
    });
    return out;
  }

  function collectTopics(list, page) {
    var seen = {};
    var out = [];
    (page || []).concat(flattenGroups(list)).forEach(function (t) {
      if (!t || !t.value) return;
      var key = String(t.value).toLowerCase();
      if (seen[key]) return;
      seen[key] = 1;
      out.push(t);
    });
    return out;
  }

  /* Match a topic against the query using its label, keyword and synonyms. */
  function topicMatches(t, q) {
    var hay = (t && t.search && t.search.length) ? t.search : [t.label, t.value];
    for (var i = 0; i < hay.length; i++) {
      if (String(hay[i]).toLowerCase().indexOf(q) !== -1) return true;
    }
    return false;
  }

  /* ── browse view ─────────────────────────────────────────────────────── */

  function openBrowse() {
    browsing = true;
    body.hidden = true;
    removeTopics();
    hideBrowseLink();
    browse.hidden = false;
    paintBrowse();
  }

  function closeBrowse() {
    if (!browsing) return;
    browsing = false;
    browse.hidden = true;
    body.hidden = false;
    renderTopics(pageTopics);
    showBrowseLink();
  }

  function paintBrowse() {
    browse.innerHTML = '';

    var head = document.createElement('div');
    head.className = 'chat-browse-head';
    var back = document.createElement('button');
    back.type = 'button';
    back.className = 'chat-browse-back';
    back.textContent = '← All topics';
    back.addEventListener('click', closeBrowse);
    head.appendChild(back);
    browse.appendChild(head);

    var flt = document.createElement('input');
    flt.type = 'search';
    flt.className = 'chat-browse-filter';
    flt.placeholder = 'Filter topics…';
    flt.setAttribute('autocomplete', 'off');
    flt.addEventListener('input', function () { paintList(flt.value); });
    browse.appendChild(flt);

    browseListEl = document.createElement('div');
    browseListEl.className = 'chat-browse-list';
    browse.appendChild(browseListEl);

    paintList('');
  }

  function paintList(q) {
    q = (q || '').trim().toLowerCase();
    browseListEl.innerHTML = '';

    var sections = [];
    if (pageTopics.length) {
      sections.push({ title: 'For this page', topics: pageTopics });
    }
    sections = sections.concat(groups);

    var shown = 0;
    sections.forEach(function (g) {
      var topics = (g.topics || []).filter(function (t) { return !q || topicMatches(t, q); });
      var actions = q ? [] : (g.actions || []);
      if (!topics.length && !actions.length) return;

      var sec = document.createElement('section');
      sec.className = 'chat-group';

      var title = document.createElement('div');
      title.className = 'chat-group-title';
      title.textContent = g.title;
      sec.appendChild(title);

      var grid = document.createElement('div');
      grid.className = 'chat-topic-grid';
      actions.forEach(function (a) { grid.appendChild(chipEl(a, 'chat-topic')); });
      topics.forEach(function (t) {
        grid.appendChild(askChip(t, 'chat-topic', function () {
          closeBrowse();
          ask(t.value);
        }));
      });
      sec.appendChild(grid);
      browseListEl.appendChild(sec);
      shown++;
    });

    if (!shown) {
      var empty = document.createElement('p');
      empty.className = 'chat-browse-empty';
      empty.textContent = 'No matching topics.';
      browseListEl.appendChild(empty);

      var contact = null;
      allTopics.forEach(function (t) {
        if (String(t.value).toLowerCase() === 'contact gsd') contact = t;
      });
      if (contact) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-topic-grid';
        wrap.appendChild(askChip(contact, 'chat-topic', function () {
          closeBrowse();
          ask(contact.value);
        }));
        browseListEl.appendChild(wrap);
      }
    }
  }

  /* ── auto-suggest ────────────────────────────────────────────────────── */

  function applyFilter() {
    if (browsing) return;
    var q = (input.value || '').trim().toLowerCase();
    if (!q) {
      renderTopics(pageTopics);
      showBrowseLink();
      return;
    }
    var out = [];
    for (var i = 0; i < allTopics.length && out.length < 6; i++) {
      if (topicMatches(allTopics[i], q)) out.push(allTopics[i]);
    }
    renderTopics(out);
  }

  input.addEventListener('input', function () {
    if (filterTimer) clearTimeout(filterTimer);
    filterTimer = setTimeout(applyFilter, 120);
  });

  /* ── rich answer blocks ──────────────────────────────────────────────── */

  function kindLabel(parent, text) {
    if (!text) return;
    var k = document.createElement('div');
    k.className = 'chat-kind';
    k.textContent = text;
    parent.appendChild(k);
  }

  function renderCards(parent, cards) {
    var wrap = document.createElement('div');
    wrap.className = 'chat-cards';

    cards.forEach(function (c) {
      var url = safeUrl(c.href);
      var el = document.createElement(url ? 'a' : 'div');
      el.className = 'stat-card chat-card';
      if (url) el.href = url;

      var head = document.createElement('span');
      head.className = 'chat-card-head';

      var title = document.createElement('span');
      title.className = 'chat-card-title';
      title.textContent = c.dtt || '';
      head.appendChild(title);

      if (c.status) {
        var badge = document.createElement('span');
        badge.className = 'badge badge-' + String(c.status_key || '').replace(/[^a-z0-9-]/g, '');
        badge.textContent = c.status;
        head.appendChild(badge);
      }

      if (url) {
        var go = document.createElement('span');
        go.className = 'chat-card-go';
        go.textContent = 'View →';
        head.appendChild(go);
      }
      el.appendChild(head);

      var meta = [];
      if (c.place) meta.push(c.place);
      if (c.schedule) meta.push(c.schedule);
      if (meta.length) {
        var m = document.createElement('span');
        m.className = 'chat-card-meta';
        m.textContent = meta.join(' · ');
        el.appendChild(m);
      }

      if (c.vehicle || c.driver) {
        var dl = document.createElement('dl');
        dl.className = 'chat-card-pair';
        if (c.vehicle) {
          dl.appendChild(pair('Vehicle', c.vehicle));
        }
        if (c.driver) {
          dl.appendChild(pair('Driver', c.driver));
        }
        el.appendChild(dl);
      }

      if (c.guidance) {
        var note = document.createElement('span');
        note.className = 'chat-card-note';
        note.textContent = c.guidance;
        el.appendChild(note);
      }

      wrap.appendChild(el);
    });

    parent.appendChild(wrap);
  }

  function pair(label, value) {
    var frag = document.createDocumentFragment();
    var dt = document.createElement('dt');
    dt.textContent = label;
    var dd = document.createElement('dd');
    dd.textContent = value;
    frag.appendChild(dt);
    frag.appendChild(dd);
    return frag;
  }

  function renderAlert(parent, warnings) {
    var box = document.createElement('div');
    box.className = 'alert alert-warning chat-alert';

    var title = document.createElement('div');
    title.className = 'chat-alert-title';
    title.textContent = warnings.length === 1
      ? 'One thing to fix'
      : warnings.length + ' things to fix';
    box.appendChild(title);

    var ul = document.createElement('ul');
    ul.className = 'chat-alert-list';
    warnings.forEach(function (w) {
      var li = document.createElement('li');
      li.textContent = w.message || '';
      if (w.field) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'chat-fix';
        btn.textContent = 'Fix →';
        btn.addEventListener('click', function () { focusField(w.field); });
        li.appendChild(document.createTextNode(' '));
        li.appendChild(btn);
      }
      ul.appendChild(li);
    });
    box.appendChild(ul);
    parent.appendChild(box);
  }

  /* Scroll to, focus and flash the form control a warning refers to. */
  function focusField(field) {
    var el = document.getElementById('f_' + field);
    if (!el) return;
    if (el.scrollIntoView) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.focus({ preventScroll: true });
    el.classList.add('chat-field-flash');
    setTimeout(function () { el.classList.remove('chat-field-flash'); }, 1600);
  }

  function renderContact(parent, fb) {
    var box = document.createElement('div');
    box.className = 'chat-contact';

    var head = document.createElement('div');
    head.className = 'chat-contact-head';
    var ic = document.createElement('span');
    ic.className = 'stat-card-icon';
    ic.innerHTML = CONTACT_ICON;
    head.appendChild(ic);

    var label = document.createElement('span');
    label.className = 'chat-contact-title';
    label.textContent = 'Contact the GSD';
    head.appendChild(label);
    box.appendChild(head);

    if (fb.message) {
      var msg = document.createElement('div');
      msg.className = 'chat-contact-lead';
      msg.textContent = fb.message;
      box.appendChild(msg);
    }
    if (fb.detail) {
      var detail = document.createElement('div');
      detail.className = 'chat-contact-body';
      detail.textContent = fb.detail;
      box.appendChild(detail);
    }
    parent.appendChild(box);
  }

  /* ── guided request wizard ───────────────────────────────────────────── */

  var wizard = null;
  var wizardRun = 0;

  function startWizard(steps) {
    wizard = { steps: steps, index: 0, data: {}, draftId: 0, attachment: '', busy: false, run: ++wizardRun };
    var t = turn();
    kindLabel(t, 'New trip request');
    var intro = document.createElement('div');
    intro.className = 'msg bot';
    intro.textContent = "Let's build your trip request step by step. "
      + 'I will ask for the details, then you attach the signed letter and submit it for approval.';
    t.appendChild(intro);
    scrollBody();
    renderStep();
  }

  function activeWizardStep() {
    return wizard && wizard.steps[wizard.index] ? wizard.steps[wizard.index] : null;
  }

  /* One input (or select/textarea/file) with its label. Ids are per-run so a
     second wizard in the same page session cannot collide with the first. */
  function wizardField(f, step, card) {
    var wrap = document.createElement('div');
    wrap.className = 'chat-field';
    var fieldId = 'wz' + (wizard.run || 0) + '_' + f.name;

    var lab = document.createElement('label');
    lab.textContent = f.label;
    lab.setAttribute('for', fieldId);
    wrap.appendChild(lab);

    var input;
    if (f.type === 'select') {
      input = document.createElement('select');
      (f.options || []).forEach(function (o) {
        var op = document.createElement('option');
        op.value = o;
        op.textContent = o;
        input.appendChild(op);
      });
    } else if (f.type === 'textarea') {
      input = document.createElement('textarea');
      input.rows = 3;
    } else {
      input = document.createElement('input');
      if (f.type === 'datetime-local') input.type = 'datetime-local';
      else if (f.type === 'number') {
        input.type = 'number';
        if (f.min != null) input.min = String(f.min);
        if (f.max != null) input.max = String(f.max);
      } else if (f.type === 'email') input.type = 'email';
      else if (f.type === 'file') { input.type = 'file'; if (f.accept) input.accept = f.accept; }
      else input.type = 'text';
      if (f.maxlength) input.maxLength = f.maxlength;
    }
    input.className = 'form-input' + (f.cap ? ' input-cap' : '');
    input.id = fieldId;
    if (f.placeholder && f.type !== 'file') input.placeholder = f.placeholder;

    /* Prefill from what was already entered (Back / re-render safe). */
    if (f.type === 'number' && wizard.data[f.name] == null) {
      wizard.data[f.name] = 1;
    }
    if (wizard.data[f.name] != null && f.type !== 'file') {
      input.value = wizard.data[f.name];
    }

    input.addEventListener('input', function () { wizard.data[f.name] = input.value; });
    input.addEventListener('change', function () { wizard.data[f.name] = input.value; });
    if (f.type !== 'file') {
      wrap.appendChild(input);
    }

    if (f.type === 'place') {
      var chosen = document.createElement('div');
      chosen.className = 'chat-form-hint';
      chosen.id = 'wz' + (wizard.run || 0) + '_place_note';
      chosen.textContent = wizard.data.place_to_visit_place_id ? 'Place confirmed ✓' : 'Anywhere you type works; pick a suggestion for routing.';
      wrap.appendChild(chosen);
      attachPlaces(input, step.origin, chosen);
    }

    if (f.type === 'file') {
      /* Same drop-zone affordance as the request form (classes reused). */
      var drop = document.createElement('label');
      drop.className = 'upload-drop';
      drop.setAttribute('for', fieldId);

      var icon = document.createElement('span');
      icon.className = 'upload-icon';
      icon.innerHTML = CLOUD_ICON;
      drop.appendChild(icon);

      var dropText = document.createElement('span');
      dropText.textContent = 'Upload File (Max 20mb)';
      drop.appendChild(dropText);

      var dropHint = document.createElement('span');
      dropHint.className = 'upload-hint';
      dropHint.textContent = 'PDF, PNG, JPG, DOC, DOCX';
      drop.appendChild(dropHint);

      drop.appendChild(input); // hidden by .upload-drop input[type=file]
      wrap.appendChild(drop);

      var note = document.createElement('div');
      note.className = 'chat-file-note';
      note.textContent = wizard.attachment ? 'Uploaded ✓' : 'No file yet.';
      if (wizard.attachment) drop.classList.add('has-file');

      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        note.className = 'chat-file-note';
        note.textContent = 'Uploading ' + file.name + '…';
        drop.classList.remove('has-file', 'invalid');
        uploadLetter(file, card, note).then(function () {
          note.className = 'chat-file-ok';
          note.textContent = 'Uploaded: ' + file.name;
          drop.classList.add('has-file');
        }).catch(function (e) {
          note.className = 'chat-err';
          note.textContent = (e.message || 'Upload failed.')
            + (e.uploadPct ? ' (' + e.uploadPct + '% uploaded)' : '');
          drop.classList.add('invalid');
        });
      });
      wrap.appendChild(note);
    }

    return wrap;
  }

  /* Attach the shared Places autocomplete (falls back to plain text). */
  function attachPlaces(input, origin, note) {
    if (typeof NAAPMaps === 'undefined' || typeof LocationAutocomplete === 'undefined' || !origin) {
      return;
    }
    NAAPMaps.ready(function (ok) {
      if (!ok) return; // free-text fallback
      LocationAutocomplete(input, {
        lat: origin.lat,
        lon: origin.lng,
        onSelect: function (loc) {
          wizard.data.place_to_visit = loc.name || input.value;
          wizard.data.place_to_visit_name = loc.name || '';
          wizard.data.place_to_visit_lat = loc.latitude || null;
          wizard.data.place_to_visit_lon = loc.longitude || null;
          wizard.data.place_to_visit_place_id = loc.place_id || '';
          input.value = loc.name || input.value;
          if (note) note.textContent = 'Place confirmed ✓';
        }
      });
    });
  }

  /* Passenger manifest rows for the passengers step. */
  function wizardManifest(step, card) {
    var wrap = document.createElement('div');
    wrap.className = 'chat-fields';
    /* box holds the rows and is rebuilt on every paint; everything that must
       survive a repaint (the count input lives in `fields`, the "use my name"
       helper here) stays outside it. */
    var box = document.createElement('div');
    box.className = 'chat-fields';
    wrap.appendChild(box);

    function paint() {
      box.innerHTML = '';
      var n = Math.max(1, Math.min(step.max || 999, parseInt(wizard.data.number_of_passengers, 10) || 1));
      wizard.data.number_of_passengers = n;
      if (!Array.isArray(wizard.data.passengers)) wizard.data.passengers = [];
      while (wizard.data.passengers.length < n) {
        wizard.data.passengers.push({ surname: '', first_name: '', middle_initial: '', middle_name: '' });
      }
      wizard.data.passengers.length = n;

      for (let i = 0; i < n; i++) {
        var title = document.createElement('div');
        title.className = 'chat-pax-title';
        title.textContent = 'Passenger ' + (i + 1);
        box.appendChild(title);

        var row = document.createElement('div');
        row.className = 'chat-field-row';
        ['surname', 'first_name', 'middle_initial', 'middle_name'].forEach(function (key) {
          var f = {
            name: 'pax_' + i + '_' + key,
            label: (key === 'surname' ? 'Surname *' : key === 'first_name' ? 'First name *'
              : key === 'middle_initial' ? 'M.I.' : 'Middle name'),
            type: 'text',
            cap: true,
            placeholder: key === 'surname' ? 'Surname' : key === 'first_name' ? 'First Name'
              : key === 'middle_initial' ? 'M.' : 'REYES',
            maxlength: key === 'surname' ? 100 : key === 'first_name' ? 150 : key === 'middle_initial' ? 3 : 100,
          };
          var cell = wizardField(f, step, card);
          var ctl = cell.querySelector('input');
          ctl.value = wizard.data.passengers[i][key] || '';
          ctl.addEventListener('input', function () { wizard.data.passengers[i][key] = ctl.value; });
          ctl.addEventListener('change', function () { wizard.data.passengers[i][key] = ctl.value; });
          row.appendChild(cell);
        });
        box.appendChild(row);
      }
    }

    paint();

    var countInput = card.querySelector('#wz' + (wizard.run || 0) + '_number_of_passengers');
    if (countInput) {
      countInput.addEventListener('input', paint);
      countInput.addEventListener('change', paint);
    }

    if (step.add_self) {
      var selfRow = document.createElement('div');
      selfRow.className = 'chat-form-hint';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'chat-fix';
      btn.textContent = 'Use my name';
      btn.addEventListener('click', function () {
        var p = wizard.data.passengers[0];
        p.surname = String(wizard.data.surname || '').toUpperCase();
        p.first_name = String(wizard.data.passenger_name || '').toUpperCase();
        p.middle_initial = String(wizard.data.middle_initial || '').toUpperCase();
        p.middle_name = String(wizard.data.middle_name || '').toUpperCase();
        paint();
        /* Let the step header re-evaluate its "Finish later" affordance. */
        card.dispatchEvent(new Event('input', { bubbles: true }));
      });
      selfRow.appendChild(btn);
      selfRow.appendChild(document.createTextNode(': fills passenger 1 from your own name.'));
      wrap.appendChild(selfRow);
    }

    return wrap;
  }

  function renderStep() {
    if (!wizard) return;
    var step = activeWizardStep();
    if (!step) return;

    var t = turn();
    kindLabel(t, step.title + ' · Step ' + (wizard.index + 1) + ' of ' + wizard.steps.length);

    var card = document.createElement('div');
    card.className = 'chat-form-card';

    var head = document.createElement('div');
    head.className = 'chat-wizard-head';
    var title = document.createElement('span');
    title.className = 'chat-form-title';
    title.textContent = step.title;
    head.appendChild(title);
    var actions = document.createElement('span');
    actions.className = 'chat-wizard-actions';

    /* Session-level "keep it for later". Hidden while nothing is entered, so a
       pristine step cannot create an empty draft; it appears as soon as any
       field (or the letter) has a value. */
    var finish = document.createElement('button');
    finish.type = 'button';
    finish.className = 'chat-wizard-link';
    finish.textContent = 'Finish later';
    finish.addEventListener('click', function () { saveDraftWizard(finish, errBox); });
    actions.appendChild(finish);

    function syncFinish() {
      finish.hidden = !wizardHasData();
    }
    syncFinish();
    card.addEventListener('input', syncFinish);
    card.addEventListener('change', syncFinish);

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'chat-wizard-link';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', cancelWizard);
    actions.appendChild(cancel);

    head.appendChild(actions);
    card.appendChild(head);

    if (step.hint) {
      var hint = document.createElement('div');
      hint.className = 'chat-form-hint';
      hint.textContent = step.hint;
      card.appendChild(hint);
    }

    if (step.id === 'review') {
      renderWizardReview(card);
    } else {
      var fields = document.createElement('div');
      fields.className = 'chat-fields';
      var openRow = null;
      step.fields.forEach(function (f) {
        if (f.half) {
          if (!openRow) {
            openRow = document.createElement('div');
            openRow.className = 'chat-field-row';
            fields.appendChild(openRow);
          }
          openRow.appendChild(wizardField(f, step, card));
          if (openRow.childNodes.length >= 2) openRow = null;
        } else {
          openRow = null;
          fields.appendChild(wizardField(f, step, card));
        }
      });
      card.appendChild(fields);
      if (step.manifest) card.appendChild(wizardManifest(step, card));
    }

    var errBox = document.createElement('div');
    errBox.className = 'chat-err';
    errBox.hidden = true;
    card.appendChild(errBox);

    /* Step buttons */
    var nav = document.createElement('div');
    nav.className = 'chat-actions';
    if (wizard.index > 0) {
      nav.appendChild(wizardButton('Back', 'btn-ghost', function () {
        wizard.index--;
        renderStep();
      }));
    }
    if (step.id === 'review') {
      var submitBtn = wizardButton('Submit for approval', 'btn-primary', function (btn) {
        submitWizard(btn, errBox);
      });
      var submitMissing = missingRequired();
      if (submitMissing.length) {
        submitBtn.disabled = true;
        submitBtn.title = 'Still needed: ' + submitMissing.join(', ') + '.';
      }
      nav.appendChild(submitBtn);
      nav.appendChild(wizardButton(
        wizard.attachment ? 'Save as draft' : 'Save draft & finish later',
        'btn-ghost',
        function (btn) { saveDraftWizard(btn, errBox); }
      ));
    } else {
      nav.appendChild(wizardButton('Continue', 'btn-primary', function () {
        var errs = validateStep(step);
        if (errs.length) return showWizardErrors(errBox, errs);
        wizard.index++;
        renderStep();
      }));
      nav.appendChild(wizardButton('Finish step later', 'btn-ghost', function () {
        wizard.index++;
        renderStep();
      }));
    }
    card.appendChild(nav);

    t.appendChild(card);
    scrollBody();
  }

  function wizardButton(label, style, onClick) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-sm ' + style;
    b.textContent = label;
    b.addEventListener('click', function () { onClick(b); });
    return b;
  }

  function showWizardErrors(errBox, messages) {
    errBox.hidden = false;
    errBox.textContent = messages.join(' ');
    scrollBody();
  }

  function validateStep(step) {
    var d = wizard.data;
    var errs = [];
    var txt = function (v) { return String(v == null ? '' : v).trim(); };

    if (step.id === 'who' && txt(d.passenger_name) === '') {
      errs.push('First name is required.');
    }
    if (step.id === 'requester') {
      if (txt(d.id_number) === '') errs.push('ID number is required.');
      var email = txt(d.email);
      if (email === '') errs.push('Email is required.');
      else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) errs.push('Enter a valid email address.');
    }
    if (step.id === 'trip') {
      if (txt(d.place_to_visit) === '') errs.push('Destination is required.');
      if (txt(d.purpose) === '') errs.push('Purpose of travel is required.');
    }
    if (step.id === 'schedule') {
      var dep = txt(d.scheduled_departure);
      var ret = txt(d.scheduled_return);
      if (dep === '' || ret === '') {
        errs.push('Departure and return are both required.');
      } else {
        var depTs = new Date(dep).getTime();
        var retTs = new Date(ret).getTime();
        if (!(depTs > Date.now())) errs.push('Departure must be in the future.');
        if (!(retTs > depTs)) errs.push('Return must be after departure.');
      }
    }
    if (step.id === 'passengers') {
      var n = parseInt(d.number_of_passengers, 10) || 1;
      var list = Array.isArray(d.passengers) ? d.passengers : [];
      if (list.length !== n) {
        errs.push('Add all ' + n + ' passengers.');
      } else {
        for (var i = 0; i < list.length; i++) {
          if (txt(list[i].surname) === '' || txt(list[i].first_name) === '') {
            errs.push('Passenger ' + (i + 1) + ' needs a surname and first name.');
            break;
          }
        }
      }
    }
    if (step.id === 'letter' && !wizard.attachment) {
      errs.push('Upload the signed request letter, or choose Finish step later.');
    }
    return errs;
  }

  /* Anything entered yet? Gates the header's "Finish later" so a pristine
     wizard cannot leave an empty draft in My Requests. */
  function wizardHasData() {
    var d = wizard.data;
    var keys = ['passenger_name', 'surname', 'middle_initial', 'middle_name', 'suffix',
      'id_number', 'email', 'place_to_visit', 'purpose',
      'scheduled_departure', 'scheduled_return'];
    for (var i = 0; i < keys.length; i++) {
      if (txt2(d[keys[i]]) !== '') return true;
    }
    if (wizard.attachment) return true;
    return (Array.isArray(d.passengers) ? d.passengers : []).some(function (p) {
      return p && (txt2(p.surname) !== '' || txt2(p.first_name) !== '');
    });
  }

  /* Everything api/trips.php submit will insist on. Drives the review gate and
     the submit guard, so skipped steps stay visible until they are filled. */
  function missingRequired() {
    var d = wizard.data;
    var missing = [];
    var labels = {
      passenger_name: 'First name',
      id_number: 'ID number',
      email: 'Email',
      place_to_visit: 'Destination',
      purpose: 'Purpose of travel',
      scheduled_departure: 'Scheduled departure',
      scheduled_return: 'Scheduled return',
    };
    Object.keys(labels).forEach(function (k) {
      if (txt2(d[k]) === '') missing.push(labels[k]);
    });
    if (txt2(d.email) !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(txt2(d.email))) {
      missing.push('A valid email address');
    }
    if (txt2(d.scheduled_departure) !== '' && txt2(d.scheduled_return) !== '') {
      var depTs = new Date(d.scheduled_departure).getTime();
      var retTs = new Date(d.scheduled_return).getTime();
      if (!(depTs > Date.now())) missing.push('A departure in the future');
      if (!(retTs > depTs)) missing.push('A return after departure');
    }
    var n = parseInt(d.number_of_passengers, 10) || 1;
    var list = Array.isArray(d.passengers) ? d.passengers : [];
    var namesOk = list.length === n && !list.some(function (p) {
      return txt2(p.surname) === '' || txt2(p.first_name) === '';
    });
    if (!namesOk) missing.push('All ' + n + ' passenger name' + (n === 1 ? '' : 's'));
    if (!wizard.attachment) missing.push('The signed request letter');
    return missing;
  }

  function renderWizardReview(card) {
    var d = wizard.data;
    var dl = document.createElement('dl');
    dl.className = 'chat-review';

    function row(label, value) {
      var dt = document.createElement('dt');
      dt.textContent = label;
      var dd = document.createElement('dd');
      dd.textContent = value || 'Not set';
      dl.appendChild(dt);
      dl.appendChild(dd);
    }

    var name = [up(d.surname), up(d.passenger_name)].filter(Boolean).join(', ');
    if (up(d.middle_initial)) name += ' ' + up(d.middle_initial) + '.';
    if (up(d.middle_name)) name += ' ' + up(d.middle_name);
    if (up(d.suffix)) name += ' ' + up(d.suffix);

    row('Passenger', name);
    row('ID number', up(d.id_number));
    row('Requester type', d.requester_type || 'Personnel');
    row('Email', d.email);
    row('Destination', d.place_to_visit);
    row('Purpose', d.purpose);
    row('Schedule', fmtWhen(d.scheduled_departure) + ' → ' + fmtWhen(d.scheduled_return));
    row('Passengers', (parseInt(d.number_of_passengers, 10) || 1) + ' named');
    row('Request letter', wizard.attachment ? 'Uploaded ✓' : 'Not uploaded yet');

    card.appendChild(dl);

    var missing = missingRequired();
    if (missing.length) {
      var box = document.createElement('div');
      box.className = 'alert alert-warning chat-alert';
      var boxTitle = document.createElement('div');
      boxTitle.className = 'chat-alert-title';
      boxTitle.textContent = 'Still needed before submitting';
      box.appendChild(boxTitle);
      var ul = document.createElement('ul');
      ul.className = 'chat-alert-list';
      missing.forEach(function (m) {
        var li = document.createElement('li');
        li.textContent = m;
        ul.appendChild(li);
      });
      box.appendChild(ul);
      card.appendChild(box);
    }
  }

  function txt2(v) { return String(v == null ? '' : v).trim(); }

  /* Names are stored uppercase, exactly like the request form does
     (request_form.js uppercases passenger_name/surname/M.I./middle/suffix
     and every manifest row before saving). */
  function up(v) { return txt2(v).toUpperCase(); }

  function fmtWhen(v) {
    if (!v) return 'Not set';
    var d = new Date(v);
    if (isNaN(d.getTime())) return v;
    return d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  /* Every field api/trips.php save_draft accepts. */
  function wizardPayload() {
    var d = wizard.data;
    return {
      action: 'save_draft',
      draft_id: wizard.draftId || 0,
      passenger_name: up(d.passenger_name),
      surname: up(d.surname),
      middle_initial: up(d.middle_initial) || null,
      middle_name: up(d.middle_name) || null,
      suffix: up(d.suffix) || null,
      id_number: up(d.id_number),
      requester_type: d.requester_type === 'Student' ? 'Student' : 'Personnel',
      email: txt2(d.email),
      place_to_visit: txt2(d.place_to_visit),
      place_to_visit_name: txt2(d.place_to_visit_name) || null,
      place_to_visit_lat: d.place_to_visit_lat || null,
      place_to_visit_lon: d.place_to_visit_lon || null,
      place_to_visit_place_id: txt2(d.place_to_visit_place_id) || null,
      purpose: txt2(d.purpose),
      number_of_passengers: parseInt(d.number_of_passengers, 10) || 1,
      scheduled_departure: txt2(d.scheduled_departure),
      scheduled_return: txt2(d.scheduled_return),
      passengers: (Array.isArray(d.passengers) ? d.passengers : []).map(function (p) {
        return {
          surname: up(p.surname),
          first_name: up(p.first_name),
          middle_initial: up(p.middle_initial),
          middle_name: up(p.middle_name),
        };
      }),
    };
  }

  /* Draft first (the letter needs a draft_id), then the upload. */
  function uploadLetter(file, card, note) {
    if (wizard.busy) return Promise.reject(new Error('Still working…'));
    wizard.busy = true;
    var lastPct = 0;
    return API.post('api/trips.php', wizardPayload())
      .then(function (r) {
        wizard.draftId = r.draft_id;
        var fd = new FormData();
        fd.append('action', 'upload_request_letter');
        fd.append('draft_id', String(r.draft_id));
        fd.append('file', file);
        return API.formProgress('api/trips.php', fd, function (pct) {
          lastPct = pct;
          if (note) {
            note.className = 'chat-file-note';
            note.textContent = 'Uploading ' + file.name + '… ' + pct + '%';
          }
        });
      })
      .then(function (r) {
        wizard.attachment = r.attachment_path || 'uploaded';
        wizard.busy = false;
        return r;
      })
      .catch(function (e) {
        e.uploadPct = lastPct;
        wizard.busy = false;
        throw e;
      });
  }

  function submitWizard(btn, errBox) {
    var missing = missingRequired();
    if (missing.length) {
      showWizardErrors(errBox, ['Still needed: ' + missing.join(', ') + '.']);
      return;
    }
    btn.disabled = true;
    errBox.hidden = true;
    /* Persist the latest values first — submit copies from the draft row. */
    API.post('api/trips.php', wizardPayload())
      .then(function () {
        return API.post('api/trips.php', { action: 'submit', draft_id: wizard.draftId });
      })
      .then(function (r) {
        var d = wizard.data;
        wizard = null;
        var t = turn();
        kindLabel(t, 'Submitted');
        renderCards(t, [{
          dtt: r.dtt_number || ('#' + r.ticket_id),
          status: 'PENDING',
          status_key: 'pending',
          schedule: fmtWhen(d.scheduled_departure) + ' → ' + fmtWhen(d.scheduled_return),
          place: txt2(d.place_to_visit),
          guidance: 'Sent to the SAO for approval.'
            + (r.is_late ? ' Flagged LATE because it was scheduled within a few days of submission.' : ''),
          href: 'ticket.php?id=' + r.ticket_id,
        }]);
        addActions(t, [
          { type: 'ask', label: 'Check my request status', value: 'my request status' },
          { type: 'link', label: 'Open My Requests', href: 'personnel/requests.php' },
        ]);
        scrollBody();
      })
      .catch(function (e) {
        btn.disabled = false;
        showWizardErrors(errBox, [e.message || 'Submit failed.']);
      });
  }

  function saveDraftWizard(btn, errBox) {
    btn.disabled = true;
    errBox.hidden = true;
    API.post('api/trips.php', wizardPayload())
      .then(function (r) {
        var d = wizard.data;
        wizard = null;
        var t = turn();
        kindLabel(t, 'Draft saved');
        renderCards(t, [{
          dtt: 'Draft saved',
          status: 'DRAFT',
          status_key: 'draft',
          schedule: fmtWhen(d.scheduled_departure) + ' → ' + fmtWhen(d.scheduled_return),
          place: txt2(d.place_to_visit),
          guidance: 'Nothing is submitted yet. Finish it on the request form whenever you are ready.',
          href: 'personnel/request_form.php?id=' + r.draft_id,
        }]);
        scrollBody();
      })
      .catch(function (e) {
        btn.disabled = false;
        showWizardErrors(errBox, [e.message || 'Could not save the draft.']);
      });
  }

  function cancelWizard() {
    var draftId = wizard ? wizard.draftId : 0;
    wizard = null;
    var t = turn();
    kindLabel(t, 'Cancelled');
    var p = document.createElement('div');
    p.className = 'msg bot chat-fallback';
    p.textContent = draftId
      ? 'Guided request cancelled. The draft it created was removed.'
      : 'Guided request cancelled. Nothing was saved.';
    t.appendChild(p);
    if (draftId) {
      API.post('api/trips.php', { action: 'erase_draft', draft_id: draftId }).catch(function () {});
    }
    scrollBody();
  }

  /* ── ask / answer ────────────────────────────────────────────────────── */

  function addActions(parent, actions) {
    if (!actions || !actions.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'chat-actions';
    actions.forEach(function (item) {
      if (item && item.label) wrap.appendChild(chipEl(item));
    });
    if (wrap.childNodes.length) parent.appendChild(wrap);
  }

  function collectFormState() {
    var data = {};
    var fields = ['passenger_name', 'id_number', 'place_to_visit', 'purpose',
      'scheduled_departure', 'scheduled_return'];
    for (var i = 0; i < fields.length; i++) {
      var el = document.getElementById('f_' + fields[i]);
      if (el) data[fields[i]] = el.value;
    }
    return Object.keys(data).length ? data : null;
  }

  function ask(q) {
    q = (q || '').trim();
    if (!q) return;
    if (browsing) closeBrowse(); // never answer into a hidden log
    addMsg(q, 'me');
    var typing = addTyping();

    API.post('api/chatbot.php', {
      action: 'ask',
      question: q,
      page_url: location.pathname,
      form: collectFormState(),
    })
      .then(function (r) {
        removeTyping(typing);

        var t = turn();
        var answered = false;

        if (r.kind === 'wizard' && r.steps && r.steps.length) {
          startWizard(r.steps);
          answered = true;
        } else if (r.kind === 'escalation') {
          renderContact(t, r.fallback || {});
          answered = true;
        } else if (r.kind === 'fallback') {
          var fb = bubble(t, (r.fallback || {}).message || "I'm not sure about that one.");
          fb.classList.add('chat-fallback');
        } else {
          if (r.kind === 'live') kindLabel(t, r.kind_label || '');
          if (r.warnings && r.warnings.length) renderAlert(t, r.warnings);
          if (r.cards && r.cards.length) {
            renderCards(t, r.cards);
            answered = true;
          } else if (r.answer) {
            bubble(t, r.answer);
            answered = true;
          }
        }

        addActions(t, (r.actions || []).concat(r.suggestions || []));

        if (answered) {
          removeTopics();
          showBrowseLink();
        } else {
          /* Unanswered: offer the page's topics and the browser. */
          renderTopics(pageTopics);
          showBrowseLink();
        }
        scrollBody();
      })
      .catch(function (e) {
        removeTyping(typing);
        var t = turn();
        bubble(t, e.message || 'Something went wrong.');
        removeTopics();
        showBrowseLink();
        scrollBody();
      });
  }

  /* ── boot / panel ────────────────────────────────────────────────────── */

  function loadTopics() {
    API.get('api/chatbot.php?action=list&page_url=' + encodeURIComponent(location.pathname))
      .then(function (r) {
        groups = r.groups || [];
        pageTopics = (r.page_group && r.page_group.topics) ? r.page_group.topics : [];
        allTopics = collectTopics(groups, pageTopics);
        renderTopics(pageTopics);
        showBrowseLink();
        addActions(turn(), r.page_actions || []);
        scrollBody();
      })
      .catch(function (e) {
        addMsg(e.message || 'Assistant is unavailable right now.', 'bot');
        showBrowseLink();
      });
  }

  function open() {
    panel.hidden = false;
    if (!greeted) {
      greeted = true;
      addMsg('Hi! Pick a topic below or type your question about trip tickets.', 'bot');
      loadTopics();
    }
    input.focus();
  }

  function close() {
    browse.hidden = true;
    browsing = false;
    body.hidden = false;
    panel.hidden = true;
  }

  document.querySelectorAll('[data-chat-open]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      panel.hidden ? open() : close();
    });
  });
  document.getElementById('chatClose').addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (browsing) { closeBrowse(); return; }
    if (!panel.hidden) close();
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    /* Drop any pending auto-suggest filter so it cannot refill the strip
       after the answer/escalation has already cleared it. */
    if (filterTimer) {
      clearTimeout(filterTimer);
      filterTimer = null;
    }
    var v = input.value;
    input.value = '';
    ask(v);
  });
})();
