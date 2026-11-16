/* calendar.js — dispatch calendar: renders the month grid client-side from
 * api/calendar.php (pure JSON). Year ◀▶ and month ◀▶ nav swap months without a
 * page reload. Clicking a highlighted day opens a modal of that day's trips.
 * All nodes are built via createElement/textContent — user-supplied fields
 * (destination, names) must never be inserted with innerHTML. */
(function () {
  'use strict';

  const MONTHS = ['January','February','March','April','May','June','July',
                  'August','September','October','November','December'];

  const card = document.querySelector('.cal-card');
  if (!card) return;

  let year   = parseInt(card.dataset.calYear, 10);
  let month  = parseInt(card.dataset.calMonth, 10);
  let tickets = [];
  const cache = {};

  const grid       = document.getElementById('calGrid');
  const yearTitle  = document.getElementById('calYearTitle');
  const monthTitle = document.getElementById('calMonthTitle');
  const modalTitle = document.getElementById('calModalTitle');
  const modalBody  = document.getElementById('calModalBody');
  const modal      = document.getElementById('calModal');

  const pad = n => String(n).padStart(2, '0');
  const dayKey = (y, m, d) => y + '-' + pad(m) + '-' + pad(d);

  function parseLocal(iso) {
    return new Date(String(iso).replace(' ', 'T'));
  }

  function fmtTime(iso) {
    const d = parseLocal(iso);
    if (isNaN(d)) return '—';
    let h = d.getHours();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getDate() + ', ' + h + ':' + pad(d.getMinutes()) + ' ' + ap;
  }

  function fmtDayLabel(key) {
    const d = new Date(key + 'T00:00:00');
    return MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getDate() + ', ' + d.getFullYear();
  }

  function buildModalRows(rows) {
    const frag = document.createDocumentFragment();
    rows.forEach(t => {
      const row = document.createElement('div');
      row.className = 'cal-modal-row';

      const head = document.createElement('div');
      head.className = 'cal-modal-head';
      const dtt = document.createElement('strong');
      dtt.className = 'cal-modal-dtt';
      dtt.textContent = t.dtt_number || ('#' + Number(t.ticket_id));
      const link = document.createElement('a');
      link.className = 'btn btn-sm btn-ghost';
      link.href = BASE_URL + 'ticket.php?id=' + Number(t.ticket_id);
      link.textContent = 'View';
      head.appendChild(dtt);
      head.appendChild(link);

      const grid2 = document.createElement('div');
      grid2.className = 'detail-grid';
      [['Requester', t.requester_name], ['Destination', t.place_to_visit],
       ['Driver', t.driver_name], ['Vehicle', t.plate_number]].forEach(pair => {
        const cell = document.createElement('div');
        cell.className = 'field-cell';
        const l = document.createElement('div');
        l.className = 'cal-modal-label';
        l.textContent = pair[0];
        const v = document.createElement('div');
        v.textContent = pair[1] || '—';
        cell.appendChild(l);
        cell.appendChild(v);
        grid2.appendChild(cell);
      });

      const time = document.createElement('div');
      time.className = 'cal-modal-time';
      time.textContent = fmtTime(t.scheduled_departure) + ' → ' + fmtTime(t.scheduled_return);

      row.appendChild(head);
      row.appendChild(grid2);
      row.appendChild(time);
      frag.appendChild(row);
    });
    return frag;
  }

  function clearGrid() {
    while (grid.children.length > 7) grid.lastChild.remove(); // keep the 7 day-of-week headers
  }

  function render() {
    yearTitle.textContent = String(year);
    monthTitle.textContent = MONTHS[month - 1];
    clearGrid();

    const byDay = {};
    tickets.forEach(t => {
      const d = parseLocal(t.scheduled_departure);
      if (isNaN(d)) return;
      const key = dayKey(d.getFullYear(), d.getMonth() + 1, d.getDate());
      (byDay[key] = byDay[key] || []).push(t);
    });

    const now = new Date();
    const today = dayKey(now.getFullYear(), now.getMonth() + 1, now.getDate());
    const firstDow = new Date(year, month - 1, 1).getDay();
    const daysInMon = new Date(year, month, 0).getDate();

    for (let i = 0; i < firstDow; i++) {
      const e = document.createElement('div');
      e.className = 'cal-day cal-empty';
      grid.appendChild(e);
    }
    for (let d = 1; d <= daysInMon; d++) {
      const key = dayKey(year, month, d);
      const rows = byDay[key] || [];
      const isToday = key === today;
      if (rows.length) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'cal-day cal-has' + (isToday ? ' cal-today' : '');
        b.dataset.calDay = key;
        b.title = rows.length + ' scheduled trip(s)';
        b.textContent = String(d);
        grid.appendChild(b);
      } else {
        const div = document.createElement('div');
        div.className = 'cal-day' + (isToday ? ' cal-today' : '');
        div.textContent = String(d);
        grid.appendChild(div);
      }
    }
  }

  async function load() {
    clearGrid();
    const ld = document.createElement('div');
    ld.className = 'cal-loading';
    ld.textContent = 'Loading…';
    grid.appendChild(ld);

    const key = year + '-' + month;
    if (!cache[key]) {
      const r = await API.get('api/calendar.php?cal_y=' + year + '&cal_m=' + month);
      cache[key] = r.tickets || [];
    }
    tickets = cache[key];
    render();

    const u = new URL(location.href);
    u.searchParams.set('cal_y', String(year));
    u.searchParams.set('cal_m', String(month));
    history.replaceState(null, '', u.pathname + u.search);
  }

  function openDay(key) {
    const rows = (tickets || []).filter(t => {
      const d = parseLocal(t.scheduled_departure);
      return !isNaN(d) && dayKey(d.getFullYear(), d.getMonth() + 1, d.getDate()) === key;
    });
    if (!rows.length) return;
    modalTitle.textContent = 'Scheduled Trips — ' + fmtDayLabel(key);
    modalBody.textContent = '';
    modalBody.appendChild(buildModalRows(rows));
    UI.modal('calModal', true);
  }

  document.addEventListener('click', (e) => {
    const yn = e.target.closest('[data-cal-y-nav]');
    const mn = e.target.closest('[data-cal-m-nav]');
    const day = e.target.closest('button.cal-day.cal-has[data-cal-day]');
    if (yn) { year += parseInt(yn.dataset.calYNav, 10); load(); return; }
    if (mn) {
      month += parseInt(mn.dataset.calMNav, 10);
      if (month < 1)  { month = 12; year--; }
      if (month > 12) { month = 1;  year++; }
      load(); return;
    }
    if (day) { openDay(day.dataset.calDay); }
  });

  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) UI.modal('calModal', false);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();