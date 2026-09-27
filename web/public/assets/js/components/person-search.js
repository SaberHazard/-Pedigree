/**
 * جستجوی اشخاص با پیشنهاد فوری (در هدر و در پنجره‌های انتخاب شخص)
 */
import { h, debounce } from '../core/dom.js';
import { get } from '../core/api.js';
import { icon } from '../core/icons.js';
import { fa, fullName, lifespan } from '../core/format.js';
import { avatar } from './avatar.js';
import { modal, emptyState } from '../core/ui.js';

export function personRow(p, { onClick, extra } = {}) {
  const meta = [p.father_name ? `فرزند ${p.father_name}` : null, lifespan(p)].filter(Boolean).join(' • ');
  return h('div', { class: 'person-row', role: 'option', tabindex: 0, onclick: () => onClick?.(p), onkeydown: (e) => e.key === 'Enter' && onClick?.(p) },
    avatar(p, 'sm'),
    h('div', { class: 'grow' },
      h('div', { class: 'name ellipsis' }, fullName(p), p.nickname ? h('span', { class: 'muted small' }, ` (${p.nickname})`) : null),
      meta ? h('div', { class: 'meta ellipsis' }, fa(meta)) : null,
    ),
    extra || null,
  );
}

/**
 * جعبه جستجو با فهرست نتایج کشویی
 * @param {object} opts onSelect(person), placeholder, filters {gender, deceased}
 */
export function searchBox({ onSelect, placeholder = 'جستجوی نام...', filters = {}, autofocus = false, inline = false } = {}) {
  const input = h('input', { class: 'input', type: 'search', placeholder, autocomplete: 'off', enterkeyhint: 'search', 'aria-label': placeholder });
  const results = h('div', { class: inline ? 'choice-list' : 'dropdown search-results', hidden: !inline });
  const wrap = h('div', { class: inline ? 'col' : 'header-search' }, inline ? null : icon('search'), input, results);
  let controller = null;
  let items = [];
  let active = -1;

  const close = () => {
    if (!inline) results.hidden = true;
  };

  const render = (list, query) => {
    items = list;
    active = -1;
    results.replaceChildren();
    if (!query) {
      close();
      return;
    }
    if (!list.length) {
      results.append(emptyState('search', 'کسی با این نام پیدا نشد.'));
    } else {
      list.forEach((p) => results.append(personRow(p, { onClick: select })));
    }
    results.hidden = false;
  };

  const select = (p) => {
    close();
    if (!inline) input.value = '';
    onSelect?.(p);
  };

  const search = debounce(async () => {
    const q = input.value.trim();
    if (q.length < 2) return render([], q.length ? q : '');
    controller?.abort();
    controller = new AbortController();
    try {
      const res = await get('/api/persons', { q, per_page: 12, ...filters }, { signal: controller.signal });
      render(res.data, q);
    } catch (e) {
      if (e.name !== 'AbortError') render([], q);
    }
  }, 250);

  input.addEventListener('input', search);
  input.addEventListener('focus', () => items.length && (results.hidden = false));
  input.addEventListener('keydown', (e) => {
    const rows = [...results.querySelectorAll('.person-row')];
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      active = Math.max(0, Math.min(rows.length - 1, active + (e.key === 'ArrowDown' ? 1 : -1)));
      rows.forEach((r, i) => r.classList.toggle('active', i === active));
    } else if (e.key === 'Enter' && items[active]) {
      e.preventDefault();
      select(items[active]);
    } else if (e.key === 'Escape') {
      close();
      input.blur();
    }
  });
  if (!inline) {
    document.addEventListener('pointerdown', (e) => {
      if (!wrap.contains(e.target)) close();
    });
  }
  if (autofocus) setTimeout(() => input.focus(), 50);
  wrap.input = input;
  return wrap;
}

/**
 * پنجره انتخاب یک شخص موجود
 * @returns {Promise<object|null>}
 */
export function pickPerson({ title = 'انتخاب شخص', filters = {}, hint } = {}) {
  return new Promise((resolve) => {
    let chosen = null;
    const box = searchBox({
      inline: true,
      autofocus: true,
      filters,
      placeholder: 'نام یا کد شخص را بنویسید...',
      onSelect: (p) => {
        chosen = p;
        dlg.close();
      },
    });
    const dlg = modal({
      title,
      body: h('div', null, hint ? h('p', { class: 'muted small' }, hint) : null, box),
      onClose: () => resolve(chosen),
    });
  });
}
