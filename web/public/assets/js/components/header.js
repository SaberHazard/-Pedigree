/**
 * هدر بالای صفحه + نوار ناوبری پایین (موبایل)
 *
 * منوها جمع‌وجورند: «سه‌خط» بالا سمت راست منوی اصلی (همه صفحه‌ها و ابزارها) را از راست باز می‌کند
 * و دکمه حساب (عکس + سه‌خط) بالا سمت چپ منوی حساب و تنظیمات را از چپ. روی گوشی هدر و نوار پایین
 * با پیمایش رو به پایین کنار می‌روند و در حالت افقی گوشی نوار پایین نمایش داده نمی‌شود.
 */
import { h, fill } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { store, applyTheme, saveLocalPrefs } from '../core/store.js';
import { navigate } from '../core/router.js';
import { post, put, url } from '../core/api.js';
import { toast } from '../core/ui.js';
import { fa, fullName } from '../core/format.js';
import { avatar } from './avatar.js';
import { searchBox } from './person-search.js';
import { openDrawer, closeDrawer } from './drawer.js';

// با تغییر لوگو این عدد بالا برود تا نسخه کش‌شده قدیمی نمایش داده نشود
const LOGO_VERSION = 2;

const NAV = [
  { path: '/', label: 'خانه', icon: 'home', match: (p) => p === '/' },
  { path: '/tree', label: 'درخت', icon: 'tree', match: (p) => p.startsWith('/tree') },
  { path: '/map', label: 'نقشه', icon: 'pin', match: (p) => p.startsWith('/map'), when: () => store.config.map?.enabled !== false },
  { path: '/group', label: 'گروه', icon: 'users', match: (p) => p.startsWith('/group'), counter: (c) => c?.group || 0, when: () => store.config.group?.enabled !== false },
  { path: '/messages', label: 'پیام‌ها', icon: 'chat', match: (p) => p.startsWith('/messages'), counter: (c) => c?.messages || 0, when: () => store.config.messaging?.enabled !== false },
  { path: '/approvals', label: 'تأییدها', icon: 'shield', match: (p) => p.startsWith('/approvals'), counter: (c) => (c?.votes || 0) + (c?.links || 0) },
  { path: '/notifications', label: 'اعلان‌ها', icon: 'bell', match: (p) => p.startsWith('/notifications'), counter: (c) => c?.notifications || 0 },
];

export function brand() {
  const logo = h('span', { class: 'brand-logo' }, h('img', { src: url(`/assets/img/icon.svg?v=${LOGO_VERSION}`), alt: '', width: 38, height: 38, decoding: 'async' }));
  return h('a', { class: 'brand', href: '#/' }, logo, h('span', null, store.config.site_name || 'شجره‌نامه'));
}

export function renderHeader() {
  const nav = h('nav', { class: 'main-nav', 'aria-label': 'ناوبری اصلی' });
  const bottom = h('nav', { class: 'bottom-nav', 'aria-label': 'ناوبری پایین' });
  const menuBtn = h('button', { class: 'icon-btn menu-btn', type: 'button', title: 'منوی اصلی', 'aria-label': 'منوی اصلی', 'aria-haspopup': 'dialog', 'aria-expanded': 'false' });
  const bell = h('button', { class: 'icon-btn bell-btn', type: 'button', title: 'اعلان‌ها', onclick: () => navigate('/notifications') }, icon('bell'));
  const themeBtn = h('button', { class: 'icon-btn hide-mobile', type: 'button', title: 'تغییر تم', onclick: toggleTheme });
  const userBtn = h('button', { class: 'icon-btn user-menu-btn', type: 'button', title: 'حساب من و تنظیمات', 'aria-label': 'منوی حساب', 'aria-haspopup': 'dialog', 'aria-expanded': 'false' });

  const search = searchBox({
    placeholder: 'جستجوی افراد خانواده...',
    onSelect: (p) => navigate(`/tree/${p.id}?focus=${p.id}`),
  });

  const header = h('header', { class: 'app-header' },
    menuBtn,
    brand(),
    nav,
    search,
    h('div', { class: 'header-actions' }, themeBtn, bell, userBtn),
  );

  const isDark = () => document.documentElement.dataset.theme === 'dark';

  function renderThemeIcon() {
    themeBtn.replaceChildren(icon(isDark() ? 'sun' : 'moon'));
  }

  function toggleTheme() {
    const next = isDark() ? 'light' : 'dark';
    saveLocalPrefs({ theme: next });
    if (store.user) {
      store.user.preferences = { ...(store.user.preferences || {}), theme: next };
      put('/api/account/preferences', { theme: next }).catch(() => {});
    }
    applyTheme(next);
    renderThemeIcon();
  }

  const currentPath = () => location.hash.slice(1).split('?')[0] || '/';
  const enabled = (i) => i && (!i.when || i.when());

  function render(path = currentPath()) {
    const counters = store.user?.counters || {};
    const link = (item) => {
      const count = item.counter ? item.counter(counters) : 0;
      return h('a', { href: '#' + item.path, class: item.match(path) ? 'active' : '', 'aria-current': item.match(path) ? 'page' : null },
        icon(item.icon),
        h('span', null, item.label),
        count ? h('span', { class: 'badge' }, fa(count > 99 ? '99+' : count)) : null,
      );
    };
    const byPath = (p) => NAV.find((i) => i.path === p);
    nav.replaceChildren(...NAV.filter(enabled).map((i) => link(i)));
    const mobileItems = [
      byPath('/'),
      byPath('/tree'),
      store.config.group?.enabled !== false ? byPath('/group') : byPath('/approvals'),
      store.config.messaging?.enabled !== false ? byPath('/messages') : byPath('/notifications'),
    ];
    const me = store.user?.person;
    const mine = path.startsWith('/account') || (me && path.startsWith(`/person/${me.id}`));
    bottom.replaceChildren(...mobileItems.filter(Boolean).map((i) => link(i)),
      h('button', { type: 'button', class: mine ? 'active' : '', 'aria-haspopup': 'dialog', onclick: (e) => openAccount(e.currentTarget) },
        icon('user'), h('span', null, 'من'),
        counters.support ? h('span', { class: 'badge' }, fa(counters.support)) : null));

    const unread = counters.notifications || 0;
    fill(bell, icon('bell'), unread ? h('span', { class: 'badge' }, fa(unread > 99 ? '99+' : unread)) : null);
    // مجموع موارد منتظر (برای نقطه روی دکمه سه‌خط در گوشی که منوی بالا دیده نمی‌شود)
    const waiting = (counters.votes || 0) + (counters.links || 0) + (counters.support || 0);
    fill(menuBtn, icon('menu'), waiting ? h('span', { class: 'badge dot', 'aria-label': `${fa(waiting)} مورد منتظر` }) : null);
    userBtn.replaceChildren(
      store.user?.person ? avatar(store.user.person, 'sm') : icon('user'),
      h('span', { class: 'um-lines', 'aria-hidden': 'true' }, icon('menu')),
    );
    renderThemeIcon();
  }

  // ------------------------------------------------------------ منوی اصلی (راست)
  function openMain() {
    const u = store.user;
    if (!u?.person) return navigate('/login');
    const c = u.counters || {};
    const path = currentPath();
    const navItem = (i) => enabled(i) ? { label: i.label, icon: i.icon, path: i.path, active: i.match(path), badge: i.counter ? i.counter(c) : 0 } : null;
    const page = (p, label, ic, extra = {}) => ({ label, icon: ic, path: p, active: path.startsWith(p), ...extra });
    openDrawer({
      side: 'right',
      label: 'منوی اصلی',
      trigger: menuBtn,
      head: brand(),
      sections: [
        { grid: true, items: [...NAV.map(navItem), page('/search', 'جستجو', 'search')] },
        { title: 'ابزارها و هوش مصنوعی', items: [
          page('/calendar', 'تقویم و هشدارها', 'calendar', { hint: 'مناسبت‌ها، اوقات شرعی، یادآور تولد و سالگرد' }),
          store.config.calls?.enabled !== false || store.config.calls?.links !== false ? page('/calls', 'تماس‌ها', 'call', { hint: 'تماس صوتی و تصویری، دونفره و گروهی' }) : null,
          page('/assistant', 'دستیار هوشمند', 'bot', { hint: 'پرسش، تماس صوتی، بازسازی عکس' }),
          page('/insights', 'بینش‌های خاندان', 'layers', { hint: 'آمار، نمودارها و بررسی درستی اطلاعات' }),
          page('/games', 'بازی‌های خانوادگی', 'gamepad'),
          page('/greetings', 'تبریک مناسبت‌ها', 'cake', { hint: 'تولد، سالگرد، اعیاد' }),
          { label: 'درخت من', icon: 'tree', path: `/tree/${u.person.id}?mode=hourglass` },
          { label: 'نیاکان من', icon: 'ancestors', path: `/tree/${u.person.id}?mode=ancestors` },
        ] },
        { title: 'بیشتر', items: [
          page('/support', 'پشتیبانی', 'headset', { badge: c.support || 0 }),
          store.config.donate?.enabled !== false ? page('/donate', 'حمایت از سازنده ❤️', 'gift') : null,
          u.is_admin ? page('/admin', 'مدیریت سایت', 'crown') : null,
        ] },
      ],
    });
  }

  // ------------------------------------------------------------ منوی حساب (چپ)
  function openAccount(trigger = userBtn) {
    const u = store.user;
    if (!u?.person) return navigate('/login');
    const me = u.person;
    const path = currentPath();
    const themeSwitch = h('span', { class: 'dw-switch', role: 'presentation' });
    const themeItem = h('button', {
      class: 'dw-item',
      type: 'button',
      role: 'switch',
      'aria-checked': String(isDark()),
      onclick: () => {
        toggleTheme();
        themeItem.setAttribute('aria-checked', String(isDark()));
        themeItem.firstChild.replaceWith(icon(isDark() ? 'sun' : 'moon'));
      },
    }, icon(isDark() ? 'sun' : 'moon'), h('span', { class: 'dw-label' }, 'حالت تیره'), themeSwitch);

    const head = h('a', {
      class: 'dw-profile',
      href: `#/person/${me.id}`,
      onclick: (e) => {
        e.preventDefault();
        closeDrawer();
        navigate(`/person/${me.id}`);
      },
    },
    avatar(me, 'md'),
    h('span', { class: 'dw-profile-text' },
      h('b', null, fullName(me) || 'حساب من'),
      u.username ? h('bdi', { class: 'dw-handle', dir: 'ltr' }, `@${u.username}`) : h('small', { class: 'muted' }, 'نام کاربری ندارید'),
    ));

    openDrawer({
      side: 'left',
      label: 'منوی حساب',
      trigger,
      head,
      sections: [
        { items: [
          { label: 'پروفایل من', icon: 'user', path: `/person/${me.id}`, active: path === `/person/${me.id}` },
          { label: 'ویرایش پروفایل', icon: 'edit', path: `/person/${me.id}/edit` },
          { label: 'پرسش‌وپاسخ پروفایل', icon: 'sparkles', path: `/person/${me.id}/interview`, hint: 'تکمیل پروفایل با چند سؤال' },
          { label: 'درخت من', icon: 'tree', path: `/tree/${me.id}?mode=hourglass` },
        ] },
        { title: 'تنظیمات', items: [
          { label: 'تنظیمات حساب', icon: 'settings', path: '/account', active: path.startsWith('/account'), hint: u.username ? null : 'تعیین نام کاربری (مثل تلگرام)' },
          { node: themeItem },
          u.is_admin ? { label: 'مدیریت سایت', icon: 'crown', path: '/admin', active: path.startsWith('/admin') } : null,
        ] },
        { items: [{ label: 'خروج از حساب', icon: 'logout', danger: true, onClick: logout }] },
      ],
    });
  }

  menuBtn.addEventListener('click', openMain);
  userBtn.addEventListener('click', () => openAccount(userBtn));

  bindAutoHide();

  store.on('user', () => render());
  store.on('counters', () => render());
  store.on('route', ({ path }) => {
    document.documentElement.classList.remove('nav-hidden');
    render(path);
  });
  render();

  return { header, bottom };
}

let autoHideBound = false;
/** یک بار برای کل برنامه: با پیمایش رو به پایین هدر و نوار پایین (فقط در گوشی، طبق CSS) کنار می‌روند */
function bindAutoHide() {
  if (autoHideBound) return;
  autoHideBound = true;
  const root = document.documentElement;
  let lastY = window.scrollY;
  let ticking = false;
  window.addEventListener('scroll', () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      ticking = false;
      const y = window.scrollY;
      if (Math.abs(y - lastY) < 6) return;
      const typing = document.activeElement?.matches?.('input, textarea, select, [contenteditable]');
      root.classList.toggle('nav-hidden', y > lastY && y > 90 && !typing && !root.classList.contains('side-menu-open'));
      lastY = y;
    });
  }, { passive: true });
}

export async function logout() {
  try {
    await post('/api/auth/logout');
  } catch {
    /* ignore */
  }
  // گفتگوی دستیار هوشمند روی این مرورگر نماند (رایانه مشترک)
  try {
    Object.keys(sessionStorage).filter((k) => k.startsWith('ai-chat:')).forEach((k) => sessionStorage.removeItem(k));
  } catch {
    /* ignore */
  }
  store.setUser(null);
  toast('از حساب خارج شدید.', 'info');
  navigate('/login');
}
