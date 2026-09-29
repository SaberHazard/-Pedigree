/**
 * منوهای کشویی کناری (همبرگری): منوی اصلی از سمت راست و منوی حساب و ابزارها از سمت چپ.
 *
 * - جمع‌وجور و روی صفحه (صفحه زیرش جابه‌جا نمی‌شود)؛ با کلیک بیرون، Esc، کشیدن به سمت لبه،
 *   دکمه «برگشت» گوشی (اندروید/iOS) یا رفتن به صفحه دیگر بسته می‌شود.
 * - با باز شدن یک ورودی موقت در تاریخچه ساخته می‌شود تا «برگشت» فقط منو را ببندد و
 *   رفتن از منو به صفحه دیگر جای همان ورودی را بگیرد (تاریخچه شلوغ نشود).
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { navigate } from '../core/router.js';
import { fa } from '../core/format.js';

let active = null;

export function closeDrawer() {
  active?.close();
}

export function isDrawerOpen(side = null) {
  return !!active && (!side || active.side === side);
}

/**
 * @param {{
 *   side: 'right'|'left', label: string, head?: Node, trigger?: HTMLElement,
 *   sections: Array<{title?: string, grid?: boolean, items: Array<null|{label: string, icon?: string, path?: string,
 *     onClick?: Function, badge?: number, active?: boolean, danger?: boolean, hint?: string, keepOpen?: boolean, node?: Node}>}>,
 *   footer?: Node,
 * }} opts
 */
export function openDrawer({ side = 'right', label, head = null, sections = [], footer = null, trigger = null }) {
  if (active) {
    const same = active.side === side;
    active.close();
    if (same) return null;
  }

  let closed = false;
  let pushed = false;
  const previousFocus = trigger || document.activeElement;

  const go = (path) => {
    // ورودی موقت تاریخچه با صفحه جدید جایگزین می‌شود
    const replace = pushed && history.state?.pedigreeDrawer;
    pushed = false;
    close({ fromHistory: true });
    navigate(path, { replace: !!replace });
  };

  const itemNode = (item, grid) => {
    if (!item) return null;
    if (item.node) return item.node;
    const badge = item.badge ? h('span', { class: 'badge' }, fa(item.badge > 99 ? '99+' : item.badge)) : null;
    const attrs = {
      class: `dw-item${item.active ? ' active' : ''}${item.danger ? ' danger' : ''}`,
      'aria-current': item.active ? 'page' : null,
    };
    const content = grid
      ? [h('span', { class: 'dw-tile-icon' }, icon(item.icon || 'grid'), badge), h('span', { class: 'dw-label' }, item.label)]
      : [icon(item.icon || 'grid'), h('span', { class: 'dw-label' }, item.label, item.hint ? h('small', null, item.hint) : null), badge];
    if (item.path) {
      return h('a', {
        ...attrs,
        href: '#' + item.path,
        onclick: (e) => {
          if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
          e.preventDefault();
          go(item.path);
        },
      }, ...content);
    }
    return h('button', {
      ...attrs,
      type: 'button',
      onclick: () => {
        if (!item.keepOpen) close();
        item.onClick?.();
      },
    }, ...content);
  };

  const closeBtn = h('button', { class: 'icon-btn dw-close', type: 'button', title: 'بستن منو', 'aria-label': 'بستن منو', onclick: () => close() }, icon('x'));
  const panel = h('aside', {
    class: `side-menu side-menu-${side}`,
    role: 'dialog',
    'aria-modal': 'true',
    'aria-label': label,
    tabindex: '-1',
  },
  h('div', { class: 'dw-head' }, head || h('b', null, label), closeBtn),
  h('div', { class: 'dw-body' },
    ...sections.filter((s) => s && s.items.some(Boolean)).map((s) => h('section', { class: `dw-section${s.grid ? ' grid' : ''}` },
      s.title ? h('div', { class: 'dw-title' }, s.title) : null,
      h('div', { class: s.grid ? 'dw-grid' : 'dw-list' }, ...s.items.map((i) => itemNode(i, s.grid))),
    )),
  ),
  footer ? h('div', { class: 'dw-foot' }, footer) : null,
  );
  const overlay = h('div', { class: 'side-menu-overlay', onclick: () => close() });
  const root = h('div', { class: 'side-menu-root' }, overlay, panel);

  // کشیدن منو به سمت لبه برای بستن (گوشی)
  let startX = null;
  let startY = 0;
  let dx = 0;
  panel.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1) return;
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    dx = 0;
  }, { passive: true });
  panel.addEventListener('touchmove', (e) => {
    if (startX === null) return;
    const mx = e.touches[0].clientX - startX;
    const my = e.touches[0].clientY - startY;
    if (Math.abs(my) > Math.abs(mx) && Math.abs(dx) < 10) {
      startX = null; // پیمایش عمودی فهرست
      return;
    }
    dx = side === 'right' ? Math.max(0, mx) : Math.min(0, mx);
    panel.style.transform = `translateX(${dx}px)`;
  }, { passive: true });
  panel.addEventListener('touchend', () => {
    if (startX === null) return;
    startX = null;
    panel.style.transform = '';
    if (Math.abs(dx) > 70) close();
  });

  function onKey(e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      close();
    } else if (e.key === 'Tab') {
      // فوکوس داخل منو بماند
      const focusables = [...panel.querySelectorAll('a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])')].filter((el) => el.offsetParent !== null);
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }
  function onPop() {
    // دکمه برگشت گوشی یا مرورگر: فقط منو بسته شود
    pushed = false;
    close({ fromHistory: true });
  }
  function onRoute() {
    pushed = false;
    close({ fromHistory: true });
  }

  function close({ fromHistory = false } = {}) {
    if (closed) return;
    closed = true;
    if (active === api) active = null;
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('popstate', onPop);
    window.removeEventListener('hashchange', onRoute);
    document.documentElement.classList.remove('side-menu-open');
    trigger?.setAttribute('aria-expanded', 'false');
    root.classList.add('closing');
    const done = () => root.remove();
    panel.addEventListener('transitionend', done, { once: true });
    setTimeout(done, 350);
    if (!fromHistory && pushed && history.state?.pedigreeDrawer) {
      pushed = false;
      history.back();
    }
    if (previousFocus && document.contains(previousFocus)) previousFocus.focus?.({ preventScroll: true });
  }

  const api = { side, close };
  active = api;
  document.body.append(root);
  document.documentElement.classList.add('side-menu-open');
  trigger?.setAttribute('aria-expanded', 'true');
  try {
    history.pushState({ ...(history.state || {}), pedigreeDrawer: side }, '', location.href);
    pushed = true;
  } catch {
    /* بدون تاریخچه هم کار می‌کند */
  }
  document.addEventListener('keydown', onKey, true);
  window.addEventListener('popstate', onPop);
  window.addEventListener('hashchange', onRoute);
  requestAnimationFrame(() => {
    root.classList.add('open');
    (panel.querySelector('.dw-item.active') || closeBtn).focus({ preventScroll: true });
  });
  return api;
}
