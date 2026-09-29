/**
 * اجزای رابط کاربری عمومی: پیام شناور، پنجره (مودال)، تأیید، منوی کشویی، دکمه در حال بارگذاری
 */
import { h, $ } from './dom.js';
import { icon } from './icons.js';

// ------------------------------------------------------------------ پیام شناور
const TOAST_ICONS = { success: 'check-circle', error: 'x-circle', info: 'info', warning: 'alert' };

export function toast(message, type = 'success', timeout = 3800) {
  const root = $('#toasts');
  if (!root || !message) return;
  const el = h('div', { class: `toast ${type}`, role: 'status' },
    h('div', { class: 't-icon' }, icon(TOAST_ICONS[type] || 'info')),
    h('div', { class: 'grow' }, message),
  );
  const close = () => {
    el.classList.add('out');
    setTimeout(() => el.remove(), 300);
  };
  el.addEventListener('click', close);
  root.append(el);
  setTimeout(close, timeout);
}

/** نمایش خطای API به صورت پیام */
export function toastError(error) {
  toast(error?.message || 'خطایی رخ داد.', 'error', 5000);
}

// ------------------------------------------------------------------ مودال
let openModals = 0;

/**
 * @param {object} opts
 * @param {string} opts.title
 * @param {Node|Node[]} opts.body
 * @param {Array<{label:string, class?:string, onClick?:Function, close?:boolean, icon?:string}>} [opts.actions]
 * @param {'normal'|'wide'|'xwide'} [opts.size]
 * @param {Function} [opts.onClose]
 */
export function modal({ title, body, actions = [], size = 'normal', onClose, dismissible = true }) {
  const foot = actions.length ? h('div', { class: 'modal-foot' }) : null;
  const box = h('div', { class: `modal ${size !== 'normal' ? size : ''}`, role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
    h('div', { class: 'modal-head' },
      h('h2', null, title),
      dismissible ? h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'بستن', onclick: () => close() }, icon('x')) : null,
    ),
    h('div', { class: 'modal-body' }, body),
    foot,
  );
  const overlay = h('div', { class: 'overlay sheet' }, box);
  let closed = false;

  function close(result) {
    if (closed) return;
    closed = true;
    overlay.classList.add('closing');
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('hashchange', onRoute);
    setTimeout(() => {
      overlay.remove();
      openModals--;
      if (!openModals) document.body.style.overflow = '';
    }, 220);
    onClose?.(result);
  }

  function onKey(e) {
    if (e.key === 'Escape' && dismissible) close();
  }

  // رفتن به صفحه دیگر (یا دکمه «بازگشت» گوشی) پنجره باز را می‌بندد تا روی صفحه تازه جا نماند
  function onRoute() {
    close();
  }

  for (const action of actions) {
    const btn = h('button', { class: `btn ${action.class || ''}`, type: 'button' }, action.icon ? icon(action.icon) : null, action.label);
    btn.addEventListener('click', async () => {
      if (action.onClick) {
        const result = await withLoading(btn, () => action.onClick({ close, button: btn }));
        if (result === false) return;
        if (action.close !== false) close(result);
      } else {
        close();
      }
    });
    foot.append(btn);
  }

  if (dismissible) {
    overlay.addEventListener('mousedown', (e) => {
      if (e.target === overlay) close();
    });
  }
  document.addEventListener('keydown', onKey);
  window.addEventListener('hashchange', onRoute);
  document.body.append(overlay);
  openModals++;
  document.body.style.overflow = 'hidden';
  setTimeout(() => box.querySelector('input:not([type=hidden]),textarea,select')?.focus({ preventScroll: true }), 60);

  return { close, el: box, overlay };
}

/** پرسش تأیید (بله/خیر) */
export function confirmDialog(message, { title = 'تأیید', okLabel = 'بله', danger = false } = {}) {
  return new Promise((resolve) => {
    let answered = false;
    modal({
      title,
      body: h('p', { style: { margin: 0 } }, message),
      actions: [
        { label: 'انصراف', class: 'ghost', onClick: () => { answered = true; resolve(false); } },
        { label: okLabel, class: danger ? 'danger' : 'primary', onClick: () => { answered = true; resolve(true); } },
      ],
      onClose: () => { if (!answered) resolve(false); },
    });
  });
}

// ------------------------------------------------------------------ دکمه در حال بارگذاری
export async function withLoading(button, fn) {
  if (!button) return fn();
  button.classList.add('loading');
  button.disabled = true;
  try {
    return await fn();
  } finally {
    button.classList.remove('loading');
    button.disabled = false;
  }
}

// ------------------------------------------------------------------ منوی کشویی
let activeDropdown = null;

export function closeDropdown() {
  activeDropdown?.remove();
  activeDropdown = null;
}

/**
 * نمایش منو زیر یک دکمه
 * items: [{label, icon, onClick, danger, href}] یا 'sep'
 */
export function dropdown(anchor, items, { align = 'end', width } = {}) {
  closeDropdown();
  const menu = h('div', { class: 'dropdown', role: 'menu' });
  if (width) menu.style.width = width + 'px';
  for (const item of items) {
    if (item === 'sep') {
      menu.append(h('hr'));
      continue;
    }
    if (!item) continue;
    const tag = item.href ? 'a' : 'button';
    menu.append(h(tag, {
      class: `item ${item.danger ? 'danger' : ''}`,
      href: item.href,
      type: item.href ? null : 'button',
      role: 'menuitem',
      onclick: () => {
        closeDropdown();
        item.onClick?.();
      },
    }, item.icon ? icon(item.icon) : null, h('span', { class: 'grow' }, item.label), item.badge ? h('span', { class: 'badge' }, item.badge) : null));
  }
  document.body.append(menu);

  const r = anchor.getBoundingClientRect();
  const mw = menu.offsetWidth;
  const rtl = document.dir === 'rtl';
  let left = (align === 'end') === rtl ? r.left : r.right - mw;
  left = Math.max(8, Math.min(left, window.innerWidth - mw - 8));
  let top = r.bottom + 8;
  if (top + menu.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - menu.offsetHeight - 8);
  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
  menu.style.position = 'fixed';
  activeDropdown = menu;

  setTimeout(() => {
    const off = (e) => {
      if (!menu.contains(e.target)) {
        closeDropdown();
        document.removeEventListener('pointerdown', off, true);
      }
    };
    document.addEventListener('pointerdown', off, true);
  });
  return menu;
}

// ------------------------------------------------------------------ خطاهای فرم
/** نمایش خطاهای اعتبارسنجی سرور زیر فیلدهای فرم */
export function showFormErrors(form, error) {
  clearFormErrors(form);
  const errors = error?.errors || {};
  let first = null;
  for (const [name, messages] of Object.entries(errors)) {
    const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
    const field = input?.closest('.field');
    if (!field) continue;
    input.classList.add('invalid');
    field.append(h('div', { class: 'error' }, Array.isArray(messages) ? messages[0] : messages));
    first ??= input;
  }
  first?.focus();
  if (!first) toastError(error);
}

export function clearFormErrors(form) {
  form.querySelectorAll('.field .error').forEach((e) => e.remove());
  form.querySelectorAll('.invalid').forEach((e) => e.classList.remove('invalid'));
}

/** خواندن مقادیر فرم به صورت شیء */
export function formData(form) {
  const data = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled) continue;
    if (el.type === 'checkbox') data[el.name] = el.checked;
    else if (el.type === 'radio') {
      if (el.checked) data[el.name] = el.value;
    } else data[el.name] = el.value.trim();
  }
  return data;
}

// ------------------------------------------------------------------ اجزای کوچک
/** فیلد فرم استاندارد */
export function field(label, input, { hint, full = false } = {}) {
  return h('div', { class: `field ${full ? 'full' : ''}` }, h('label', null, label), input, hint ? h('div', { class: 'hint' }, hint) : null);
}

export function switchInput(name, label, checked = false, onChange) {
  const input = h('input', { type: 'checkbox', name, checked, onchange: (e) => onChange?.(e.target.checked) });
  return h('label', { class: 'switch' }, input, h('span', { class: 'track' }), h('span', null, label));
}

/**
 * کنترل چندگزینه‌ای
 * compact: در موبایل فقط آیکن نمایش داده شود (برای نوار ابزار شلوغ)
 */
export function segmented(options, value, onChange, { compact = false } = {}) {
  const el = h('div', { class: 'segmented', role: 'tablist' });
  const render = (current) => {
    el.replaceChildren(...options.map((o) => h('button', {
      type: 'button',
      class: o.value === current ? 'active' : '',
      role: 'tab',
      'aria-selected': o.value === current ? 'true' : 'false',
      title: o.title || o.label,
      onclick: () => {
        render(o.value);
        onChange(o.value);
      },
    }, o.icon ? icon(o.icon) : null, o.label ? h('span', { class: o.icon && compact ? 'hide-mobile' : '' }, o.label) : null)));
  };
  render(value);
  el.setValue = render;
  return el;
}

export function tabs(items, active, onChange) {
  const el = h('div', { class: 'tabs', role: 'tablist' });
  const render = (current) => {
    el.replaceChildren(...items.map((t) => h('button', {
      type: 'button',
      class: t.value === current ? 'active' : '',
      onclick: () => {
        render(t.value);
        onChange(t.value);
      },
    }, t.icon ? icon(t.icon) : null, t.label, t.badge ? h('span', { class: 'badge' }, t.badge) : null)));
  };
  render(active);
  el.setActive = render;
  return el;
}

export function loader() {
  return h('div', { class: 'page-loader' }, h('div', { class: 'spinner' }));
}

export function emptyState(iconName, text, action) {
  return h('div', { class: 'empty' }, icon(iconName), h('div', null, text), action ? h('div', { class: 'mt' }, action) : null);
}

/** شمارنده متحرک (برای آمار داشبورد) */
export function countUp(el, target, formatter = String, duration = 1200) {
  const start = performance.now();
  const step = (now) => {
    const t = Math.min(1, (now - start) / duration);
    const eased = 1 - Math.pow(1 - t, 3);
    el.textContent = formatter(Math.round(target * eased));
    if (t < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}
