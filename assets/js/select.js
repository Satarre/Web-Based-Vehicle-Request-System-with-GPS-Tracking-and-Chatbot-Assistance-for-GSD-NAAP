/* select.js — modern dropdown for every <select class="form-input">.
 * Renders a styled button + list over the native select, which stays in the
 * DOM (hidden) as the source of truth so form validation, `.value` reads, and
 * existing `change` handlers (e.g. data-pair-device, onchange submit filters)
 * keep working. Only one list open at a time.
 */
(function () {
  'use strict';

  let openList = null; // the <div class="c-select-list"> currently shown

  function close() {
    if (!openList) return;
    const wrap = openList.parentNode;
    if (wrap) wrap.classList.remove('open');
    openList.hidden = true;
    openList = null;
  }

  function enhance(sel) {
    if (sel.dataset.customSelect) return;
    if (sel.hasAttribute('data-native')) return; // keep native (e.g. account role)
    sel.dataset.customSelect = '1';

    const wrap = document.createElement('div');
    wrap.className = 'c-select';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'c-select-btn';
    btn.setAttribute('aria-haspopup', 'listbox');

    const list = document.createElement('div');
    list.className = 'c-select-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    wrap.appendChild(btn);
    wrap.appendChild(list);
    sel.classList.add('c-select-native');
    sel.tabIndex = -1;
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);

    function render() {
      const opt = sel.options[sel.selectedIndex];
      btn.textContent = opt ? opt.textContent : '';
      btn.disabled = sel.disabled;
      wrap.classList.toggle('c-select-disabled', sel.disabled);
      list.innerHTML = '';
      Array.prototype.forEach.call(sel.options, (option, i) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'c-select-item' + (option.selected ? ' selected' : '');
        item.textContent = option.textContent;
        item.setAttribute('role', 'option');
        item.addEventListener('click', (e) => { e.stopPropagation(); select(i); });
        list.appendChild(item);
      });
    }

    function select(i) {
      if (sel.disabled) return;
      sel.selectedIndex = i;
      render();
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      close();
    }

    function open() {
      if (sel.disabled) return;
      close();
      render();
      list.hidden = false;
      wrap.classList.add('open');
      /* Position/close handled by the shared dropdown helper (ui.js): the list
       * is placed fixed to the viewport so no ancestor overflow clips it, and
       * scrolling inside it scrolls it instead of closing it. */
      UI.openDropdown(list, btn, close);
      openList = list;
      btn.focus();
    }

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      list.hidden ? open() : close();
    });

    btn.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const cur = sel.selectedIndex;
        const next = e.key === 'ArrowDown'
          ? Math.min(cur + 1, sel.options.length - 1)
          : Math.max(cur - 1, 0);
        select(next);
      } else if (e.key === 'Escape') {
        close();
      }
    });

    // Reflect programmatic value/disabled changes (e.g. admin.js data-edit fills
    // and MFA/account modals) so the custom button and disabled state stay in sync.
    sel.addEventListener('change', render);

    render();
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.c-select')) close();
  });
  // Outside-pointerdown close, resize close, and inside-panel scroll pass-through
  // are handled centrally by the shared dropdown helper in ui.js.

  function boot() {
    document.querySelectorAll('select.form-input').forEach(enhance);
  }

  // Re-enhance any selects inside a container (idempotent) — used by UI.modal()
  // so dropdowns are guaranteed to exist at the moment a modal opens.
  window.__ensureSelects = (root) => {
    (root || document).querySelectorAll('select.form-input').forEach(enhance);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();