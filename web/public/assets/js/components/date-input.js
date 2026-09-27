/**
 * ورودی تاریخ شمسی «جزئی»: فقط سال، یا سال و ماه، یا تاریخ کامل.
 * مقدار: "1305" | "1305-07" | "1305-07-12" | ""
 */
import { h } from '../core/dom.js';
import { MONTHS, fa, latin, monthLength, parsePartial } from '../core/format.js';

export function dateInput(name, value = '', { placeholderYear = 'سال' } = {}) {
  const day = h('select', { class: 'input d-day', 'aria-label': 'روز' });
  const month = h('select', { class: 'input d-month', 'aria-label': 'ماه' },
    h('option', { value: '' }, 'ماه'),
    ...MONTHS.map((m, i) => h('option', { value: String(i + 1) }, m)),
  );
  const year = h('input', { class: 'input d-year', inputmode: 'numeric', maxlength: 4, placeholder: placeholderYear, 'aria-label': 'سال' });
  const hidden = h('input', { type: 'hidden', name });
  const el = h('div', { class: 'date-input' }, day, month, year, hidden);

  function fillDays() {
    const y = +latin(year.value) || 1400;
    const m = +month.value;
    const max = m ? monthLength(y, m) : 31;
    const current = day.value;
    day.replaceChildren(h('option', { value: '' }, 'روز'), ...Array.from({ length: max }, (_, i) => h('option', { value: String(i + 1) }, fa(i + 1))));
    if (current && +current <= max) day.value = current;
    day.disabled = !m;
  }

  function sync() {
    const y = latin(year.value).replace(/\D/g, '');
    if (!y) hidden.value = '';
    else if (!month.value) hidden.value = y;
    else if (!day.value) hidden.value = `${y}-${month.value.padStart(2, '0')}`;
    else hidden.value = `${y}-${month.value.padStart(2, '0')}-${day.value.padStart(2, '0')}`;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function set(v) {
    const p = parsePartial(v);
    year.value = p ? fa(p.y) : '';
    month.value = p?.m ? String(p.m) : '';
    fillDays();
    day.value = p?.d ? String(p.d) : '';
    hidden.value = v || '';
  }

  year.addEventListener('input', () => {
    year.value = fa(latin(year.value).replace(/\D/g, '').slice(0, 4));
    fillDays();
    sync();
  });
  month.addEventListener('change', () => {
    fillDays();
    sync();
  });
  day.addEventListener('change', sync);

  set(value);
  Object.defineProperty(el, 'value', { get: () => hidden.value, set });
  return el;
}
