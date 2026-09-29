/**
 * نقطه شروع وب‌اپ شجره‌نامه.
 *
 * ساختار پوشه‌ها:
 *   core/        ابزارهای پایه (DOM، API، مسیریاب، قالب‌بندی، رابط کاربری)
 *   components/  اجزای قابل استفاده مجدد (هدر، جستجو، آواتار، فرم‌ها ...)
 *   tree/        چیدمان، رسم، بزرگ‌نمایی و خروجی درخت
 *   pages/       صفحات برنامه (هر صفحه یک ماژول)
 */
import { h, $ } from './core/dom.js';
import { store, applyTheme } from './core/store.js';
import { route, start, navigate } from './core/router.js';
import { get, post } from './core/api.js';
import { renderHeader, brand } from './components/header.js';
import { installErrorHandlers } from './core/errors.js';

// خطاهای پیش‌بینی‌نشده مرورگر برای پنل مدیریت گزارش می‌شوند (بدون نمایش جزئیات به کاربر)
installErrorHandlers();

// ------------------------------------------------------------------ داده اولیه از سرور
const boot = JSON.parse($('#boot-data')?.textContent || '{}');
store.config = boot.config || {};
store.user = boot.user || null;
applyTheme();
window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => applyTheme());

// ------------------------------------------------------------------ مسیرها
route('/login', () => import('./pages/login.js'), { public: true, guestOnly: true, layout: 'bare' });
route('/pending', () => import('./pages/pending.js'), { layout: 'bare' });
route('/', () => import('./pages/dashboard.js'));
route('/tree', () => import('./pages/tree.js'), { layout: 'full' });
route('/tree/:id', () => import('./pages/tree.js'), { layout: 'full' });
route('/search', () => import('./pages/search.js'));
route('/new-person', () => import('./pages/person-edit.js'));
route('/person/:id/edit', () => import('./pages/person-edit.js'));
route('/person/:id/interview', () => import('./pages/interview.js'));
route('/person/:id', () => import('./pages/person.js'));
route('/person/:id/:tab', () => import('./pages/person.js'));
// @نام‌کاربری (مثل تلگرام) ← پروفایل همان شخص
route('/@:username', () => import('./pages/user.js'));
route('/approvals', () => import('./pages/approvals.js'));
route('/map', () => import('./pages/map.js'), { layout: 'full' });
route('/notifications', () => import('./pages/notifications.js'));
route('/greetings', () => import('./pages/greetings.js'));
route('/messages', () => import('./pages/messages.js'), { layout: 'full' });
route('/messages/:id', () => import('./pages/messages.js'), { layout: 'full' });
route('/assistant', () => import('./pages/assistant.js'));
route('/group', () => import('./pages/group.js'));
route('/games', () => import('./pages/games.js'));
route('/insights', () => import('./pages/insights.js'));
route('/support', () => import('./pages/support.js'));
route('/donate', () => import('./pages/donate.js'));
route('/account', () => import('./pages/account.js'));
route('/admin', () => import('./pages/admin.js'));
route('/admin/:tab', () => import('./pages/admin.js'));

// ------------------------------------------------------------------ قالب صفحه
const app = $('#app');
let shell = null;

function renderLayout(match) {
  const container = h('div', { class: 'page-container' });

  if (match.layout === 'bare') {
    shell = null;
    app.replaceChildren(container);
    return container;
  }

  // صفحه‌های عمومی (مثل نتیجه پرداخت) برای کسی که وارد نشده: هدر ساده با دکمه ورود
  if (!store.user) {
    shell = null;
    const header = h('header', { class: 'app-header' }, brand(), h('span', { class: 'grow' }),
      h('a', { class: 'btn primary sm', href: '#/login' }, 'ورود'));
    app.replaceChildren(header, h('main', null, container));
    return container;
  }

  if (!shell) {
    const { header, bottom } = renderHeader();
    const main = h('main', { id: 'main' });
    shell = { header, main, bottom };
    app.replaceChildren(header, main, bottom);
  }
  shell.main.replaceChildren(container);
  return container;
}

// ------------------------------------------------------------------ رویدادهای سراسری
store.on('unauthorized', () => {
  if (!store.user) return;
  store.setUser(null);
  shell = null;
  navigate('/login');
});

store.on('user', (user) => {
  if (!user) shell = null;
});

/** به‌روزرسانی شمارنده‌ها (اعلان، رأی) هر دقیقه */
async function refreshCounters() {
  if (!store.user || document.hidden) return;
  try {
    const res = await get('/api/auth/me');
    store.user = res.data;
    store.emit('counters', res.data.counters);
  } catch {
    /* ignore */
  }
}
setInterval(refreshCounters, 60000);
document.addEventListener('visibilitychange', () => !document.hidden && refreshCounters());
store.refreshCounters = refreshCounters;

// ------------------------------------------------------------------ اپ موبایل
/**
 * اپ‌های اندروید/iOS توکن پوش‌نوتیفیکیشن را با این تابع به وب‌اپ می‌دهند؛
 * پس از ورود کاربر، توکن در سرور ثبت می‌شود (POST /api/account/devices).
 */
let pendingDevice = null;
async function registerDevice() {
  if (!store.user || !pendingDevice) return;
  try {
    await post('/api/account/devices', pendingDevice);
    pendingDevice = null;
  } catch {
    /* بعداً دوباره تلاش می‌شود */
  }
}
window.pedigreeRegisterDevice = (platform, token, appVersion = null) => {
  pendingDevice = { platform, token, app_version: appVersion };
  registerDevice();
};
store.on('user', registerDevice);

// ------------------------------------------------------------------ PWA
if ('serviceWorker' in navigator && location.protocol === 'https:') {
  window.addEventListener('load', () => navigator.serviceWorker.register((document.querySelector('meta[name="base-url"]')?.content || '') + '/sw.js').catch(() => {}));
}

// شروع؛ صبر برای بارگذاری فونت تا اندازه‌گیری متن‌های دور دایره دقیق باشد
const fontsReady = document.fonts
  ? Promise.all(['400 14px Vazirmatn', '500 11px Vazirmatn', '700 13px Vazirmatn'].map((f) => document.fonts.load(f))).catch(() => {})
  : Promise.resolve();
Promise.race([fontsReady, new Promise((r) => setTimeout(r, 2500))]).then(() => start(renderLayout));
