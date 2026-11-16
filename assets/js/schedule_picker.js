/* assets/js/schedule_picker.js — shared Flatpickr schedule pickers.
 *
 * The trip request form and the rescheduler must offer the SAME calendar:
 * flatpickr with time, a 12-hour clock, the custom month/year popover and the
 * amber "will be tagged LATE" day marking. Flatpickr JS/CSS are loaded by the
 * page via $HEAD_EXTRAS (see personnel/request_form.php, personnel/requests.php).
 *
 * Usage:
 *   SchedulePicker.init('#dep', '#ret', {
 *     lateDays: 3, lateHighlight: true, onDepChange: fn, onRetChange: fn,
 *   });
 * Returns the { dep, ret } flatpickr instances (or null when the elements /
 * library are unavailable).
 */
(function () {
  'use strict';

  const FP_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  /* "Y-m-d H:i" / "Y-m-dTH:i" (native datetime-local) → local Date. */
  function toDate(v) {
    const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : undefined;
  }

  /* Flatpickr's native month <select> + year input open OS-styled controls;
   * replace them with a calendar-matched popover — a year stepper plus a
   * compact month grid — anchored below the "September 2026" trigger. */
  function initMonthDropdown(fp) {
    const sel = fp.monthElements && fp.monthElements[0];
    if (!sel || sel.parentNode.querySelector('.fp-month')) return;
    const calEl = sel.closest('.flatpickr-calendar');
    sel.style.display = 'none';
    const yearInp = fp.yearElements && fp.yearElements[0];
    if (yearInp && yearInp.parentNode) yearInp.parentNode.style.display = 'none';

    const wrap = document.createElement('div');
    wrap.className = 'c-select c-select-inline fp-month';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'c-select-btn';
    wrap.appendChild(btn);
    sel.parentNode.appendChild(wrap);

    const pop = document.createElement('div');
    pop.className = 'fp-month-pop';
    pop.hidden = true;
    if (calEl) calEl.appendChild(pop);

    let open = false;

    function label() {
      return (FP_MONTHS[fp.currentMonth] || '') + ' ' + fp.currentYear;
    }

    function render() {
      pop.innerHTML = '';
      /* Year stepper row */
      const yearRow = document.createElement('div');
      yearRow.className = 'fp-pop-year';
      const minus = document.createElement('button');
      minus.type = 'button';
      minus.className = 'fp-year-btn';
      minus.textContent = '\u2212';
      minus.addEventListener('click', function (e) {
        e.stopPropagation();
        fp.changeYear(fp.currentYear - 1);
        sync();
      });
      const val = document.createElement('span');
      val.className = 'fp-year-val';
      val.textContent = fp.currentYear;
      const plus = document.createElement('button');
      plus.type = 'button';
      plus.className = 'fp-year-btn';
      plus.textContent = '+';
      plus.addEventListener('click', function (e) {
        e.stopPropagation();
        fp.changeYear(fp.currentYear + 1);
        sync();
      });
      yearRow.appendChild(minus);
      yearRow.appendChild(val);
      yearRow.appendChild(plus);
      pop.appendChild(yearRow);

      /* Compact month grid (4 × 3) */
      const grid = document.createElement('div');
      grid.className = 'fp-pop-grid';
      FP_MONTHS.forEach(function (name, i) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'fp-pop-month' + (i === fp.currentMonth ? ' selected' : '');
        item.textContent = name;
        item.addEventListener('click', function (e) {
          e.stopPropagation();
          fp.changeMonth(i - fp.currentMonth);
          close();
        });
        grid.appendChild(item);
      });
      pop.appendChild(grid);
    }

    function sync() {
      btn.textContent = label();
      if (open) render();
    }

    function openPop() {
      render();
      pop.hidden = false;
      wrap.classList.add('open');
      open = true;
    }

    function close() {
      if (!open) return;
      pop.hidden = true;
      wrap.classList.remove('open');
      open = false;
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      open ? close() : openPop();
    });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.fp-month') && !e.target.closest('.fp-month-pop')) close();
    });
    document.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);

    if (Array.isArray(fp.config.onMonthChange)) {
      fp.config.onMonthChange.push(function () { sync(); });
    }
    if (Array.isArray(fp.config.onYearChange)) {
      fp.config.onYearChange.push(function () { sync(); });
    }
    sync();
  }

  /* Mark the departure dates that WILL be tagged LATE (within lateDays of
   * today). Flatpickr calls this as fn(selectedDates, inputValue, fp, dayElem);
   * the day's Date is on dayElem.dateObj. */
  function lateDayCreate(lateDays) {
    return function (dObj, dStr, fp, dayElem) {
      const d = (dayElem && dayElem.dateObj) ? dayElem.dateObj : dObj;
      if (!(d instanceof Date) || isNaN(d.getTime())) return;
      const start = new Date(); start.setHours(0, 0, 0, 0);
      const cutoff = new Date(start); cutoff.setDate(start.getDate() + lateDays);
      if (d >= start && d < cutoff && !dayElem.classList.contains('flatpickr-disabled')) {
        dayElem.classList.add('late');
        dayElem.title = 'Will be tagged LATE';
      }
    };
  }

  function init(depSel, retSel, opts) {
    if (typeof flatpickr === 'undefined') return null;
    const o = opts || {};
    const depEl = document.querySelector(depSel);
    const retEl = document.querySelector(retSel);
    if (!depEl || !retEl) return null;

    const ret = flatpickr(retEl, {
      enableTime: true, time_24hr: false, dateFormat: 'Y-m-d H:i', minDate: new Date(),
      defaultDate: toDate(retEl.value),
      onChange: o.onRetChange || undefined,
    });
    initMonthDropdown(ret);

    const dep = flatpickr(depEl, {
      enableTime: true, time_24hr: false, dateFormat: 'Y-m-d H:i', minDate: new Date(),
      defaultDate: toDate(depEl.value),
      onChange: function (sel) {
        const now = new Date();
        ret.set('minDate', sel[0] || now);
        /* Selected departure slipped into the past → clear it. */
        if (sel[0] && sel[0] <= now) {
          UI.toast('Scheduled departure must be in the future.', 'err');
          dep.clear();
        }
        if (o.onDepChange) o.onDepChange(sel);
      },
      onDayCreate: o.lateHighlight === false ? undefined : lateDayCreate(o.lateDays || 3),
    });
    initMonthDropdown(dep);

    return { dep: dep, ret: ret };
  }

  window.SchedulePicker = { toDate: toDate, init: init };
})();
