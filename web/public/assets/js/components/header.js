/**
 * هدر بالای صفحه + نوار ناوبری پایین (موبایل)
 */
import { h, fill } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { store, applyTheme, saveLocalPrefs } from '../core/store.js';
import { navigate } from '../core/router.js';
import { post, put, url } from '../core/api.js';
import { dropdown, toast } from '../core/ui.js';
import { fa, fullName } from '../core/format.js';
import { avatar } from './avatar.js';
import { searchBox } from './person-search.js';

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
  const nav = h('nav', { class: 'main-nav' });
  const bottom = h('nav', { class: 'bottom-nav' });
  const bell = h('button', { class: 'icon-btn', type: 'button', title: 'اعلان‌ها', onclick: () => navigate('/notifications') }, icon('bell'));
  const themeBtn = h('button', { class: 'icon-btn hide-mobile', type: 'button', title: 'تغییر تم', onclick: toggleTheme });
  const userBtn = h('button', { class: 'icon-btn', type: 'button', title: 'حساب کاربری', style: { width: 'auto', padding: '0 4px' } });

  const search = searchBox({
    placeholder: 'جستجوی افراد خانواده...',
    onSelect: (p) => navigate(`/tree/${p.id}?focus=${p.id}`),
  });

  const header = h('header', { class: 'app-header' },
    brand(),
    nav,
    search,
    h('div', { class: 'header-actions' }, themeBtn, bell, userBtn),
  );

  function renderThemeIcon() {
    themeBtn.replaceChildren(icon(document.documentElement.dataset.theme === 'dark' ? 'sun' : 'moon'));
  }

  function toggleTheme() {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    saveLocalPrefs({ theme: next });
    if (store.user) {
      store.user.preferences = { ...(store.user.preferences || {}), theme: next };
      put('/api/account/preferences', { theme: next }).catch(() => {});
    }
    applyTheme(next);
    renderThemeIcon();
  }

  function render(path = location.hash.slice(1).split('?')[0] || '/') {
    const counters = store.user?.counters || {};
    const link = (item, mobile) => {
      const count = item.counter ? item.counter(counters) : 0;
      return h('a', { href: '#' + item.path, class: item.match(path) ? 'active' : '' },
        icon(item.icon),
        h('span', null, item.label),
        count ? h('span', { class: 'badge' }, fa(count > 99 ? '99+' : count)) : null,
      );
    };
    const byPath = (path) => NAV.find((i) => i.path === path);
    nav.replaceChildren(...NAV.filter((i) => !i.when || i.when()).map((i) => link(i)));
    const mobileItems = [
      byPath('/'),
      byPath('/tree'),
      store.config.group?.enabled !== false ? byPath('/group') : byPath('/approvals'),
      store.config.messaging?.enabled !== false ? byPath('/messages') : byPath('/notifications'),
      { path: '/account', label: 'من', icon: 'user', match: (p) => p.startsWith('/account') },
    ];
    bottom.replaceChildren(...mobileItems.filter(Boolean).map((i) => link(i, true)));

    const unread = counters.notifications || 0;
    fill(bell, icon('bell'), unread ? h('span', { class: 'badge' }, fa(unread > 99 ? '99+' : unread)) : null);
    bell.classList.toggle('hide-mobile', false);
    userBtn.replaceChildren(store.user?.person ? avatar(store.user.person, 'sm') : icon('user'));
    renderThemeIcon();
  }

  userBtn.addEventListener('click', () => {
    const u = store.user;
    if (!u) return navigate('/login');
    dropdown(userBtn, [
      { label: fullName(u.person) || 'حساب من', icon: 'user', onClick: () => navigate(`/person/${u.person.id}`) },
      { label: 'درخت من', icon: 'tree', onClick: () => navigate(`/tree/${u.person.id}?mode=hourglass`) },
      { label: 'نیاکان من', icon: 'ancestors', onClick: () => navigate(`/tree/${u.person.id}?mode=ancestors`) },
      store.config.map?.enabled !== false ? { label: 'نقشه خاندان', icon: 'pin', onClick: () => navigate('/map') } : null,
      { label: `تأییدها${(u.counters?.votes || 0) + (u.counters?.links || 0) ? ` (${fa((u.counters.votes || 0) + (u.counters.links || 0))})` : ''}`, icon: 'shield', onClick: () => navigate('/approvals') },
      store.config.messaging?.enabled !== false ? { label: 'پیام‌ها', icon: 'chat', onClick: () => navigate('/messages') } : null,
      { label: 'دستیار هوشمند', icon: 'bot', onClick: () => navigate('/assistant') },
      { label: 'بازی‌های خانوادگی', icon: 'gamepad', onClick: () => navigate('/games') },
      { label: 'تبریک مناسبت‌ها', icon: 'cake', onClick: () => navigate('/greetings') },
      { label: `پشتیبانی${u.counters?.support ? ` (${fa(u.counters.support)})` : ''}`, icon: 'headset', onClick: () => navigate('/support') },
      store.config.donate?.enabled !== false ? { label: 'حمایت از سازنده ❤️', icon: 'gift', onClick: () => navigate('/donate') } : null,
      { label: 'جستجوی پیشرفته', icon: 'search', onClick: () => navigate('/search') },
      'sep',
      { label: 'تنظیمات حساب', icon: 'settings', onClick: () => navigate('/account') },
      u.is_admin ? { label: 'مدیریت', icon: 'crown', onClick: () => navigate('/admin') } : null,
      'sep',
      { label: 'خروج', icon: 'logout', danger: true, onClick: logout },
    ]);
  });

  store.on('user', () => render());
  store.on('counters', () => render());
  store.on('route', ({ path }) => render(path));
  render();

  return { header, bottom };
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
