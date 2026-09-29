/**
 * مسیریاب ساده مبتنی بر # (hash).
 *
 * هر صفحه یک ماژول است که به صورت تنبل (lazy) بارگذاری می‌شود و تابع
 * پیش‌فرضش (container, ctx) را می‌گیرد و می‌تواند یک تابع پاک‌سازی برگرداند.
 */
import { store } from './store.js';
import { reportClientError } from './errors.js';

const routes = [];
let cleanup = null;
let currentToken = 0;
let onRender = null;

/** پیام خطای صفحه با دکمه «دوباره» (بدون جزئیات فنی) */
function failed(container, message) {
  const box = document.createElement('div');
  box.className = 'page';
  const empty = document.createElement('div');
  empty.className = 'empty';
  const text = document.createElement('div');
  text.textContent = message;
  const retry = document.createElement('button');
  retry.type = 'button';
  retry.className = 'btn soft mt';
  retry.textContent = 'دوباره';
  retry.addEventListener('click', () => resolve());
  empty.append(text, retry);
  box.append(empty);
  container.replaceChildren(box);
}

export function route(pattern, loader, options = {}) {
  const keys = [];
  const regex = new RegExp('^' + pattern.replace(/:([a-zA-Z_]+)/g, (_, k) => {
    keys.push(k);
    return '([^/]+)';
  }) + '/?$');
  routes.push({ pattern, regex, keys, loader, ...options });
}

export function current() {
  const hash = location.hash.replace(/^#/, '') || '/';
  const [path, qs] = hash.split('?');
  return { path: path || '/', query: Object.fromEntries(new URLSearchParams(qs || '')) };
}

export function navigate(path, { replace = false } = {}) {
  const target = '#' + path;
  if (replace) {
    history.replaceState(null, '', target);
    resolve();
  } else if (location.hash === target) {
    resolve();
  } else {
    location.hash = target;
  }
}

/** به‌روزرسانی پارامترهای آدرس بدون بارگذاری مجدد صفحه */
export function updateQuery(params) {
  const { path, query } = current();
  const q = new URLSearchParams({ ...query, ...params });
  for (const [k, v] of [...q.entries()]) if (v === '' || v === 'undefined' || v === 'null') q.delete(k);
  const s = q.toString();
  history.replaceState(null, '', '#' + path + (s ? '?' + s : ''));
}

export function start(renderLayout) {
  onRender = renderLayout;
  window.addEventListener('hashchange', resolve);
  resolve();
}

async function resolve() {
  const { path, query } = current();
  const match = routes.find((r) => r.regex.test(path));
  if (!match) {
    navigate('/', { replace: true });
    return;
  }

  if (!match.public && !store.user) {
    navigate('/login?next=' + encodeURIComponent(path), { replace: true });
    return;
  }
  // عضو در انتظار تأیید فقط صفحه «در انتظار تأیید» را می‌بیند
  if (store.user?.status === 'pending' && match.pattern !== '/pending') {
    navigate('/pending', { replace: true });
    return;
  }
  if (match.pattern === '/pending' && store.user?.status !== 'pending') {
    navigate('/', { replace: true });
    return;
  }
  if (match.guestOnly && store.user) {
    navigate('/', { replace: true });
    return;
  }

  const values = path.match(match.regex).slice(1).map(decodeURIComponent);
  const params = Object.fromEntries(match.keys.map((k, i) => [k, values[i]]));
  const token = ++currentToken;

  if (typeof cleanup === 'function') {
    try {
      cleanup();
    } catch (e) {
      console.error(e);
    }
  }
  cleanup = null;

  const container = onRender(match);
  let module;
  try {
    module = await match.loader();
  } catch (e) {
    // فایل صفحه بارگذاری نشد (قطعی اینترنت یا نسخه تازه سایت)
    if (token === currentToken) failed(container, 'بارگذاری این صفحه ممکن نشد؛ اتصال اینترنت را بررسی کنید و دوباره امتحان کنید.');
    return;
  }
  if (token !== currentToken) return; // کاربر در این فاصله صفحه را عوض کرده

  window.scrollTo({ top: 0 });
  try {
    cleanup = await module.default(container, { params, query, path });
  } catch (e) {
    console.error(e);
    if (token !== currentToken) return;
    // خطای API پیام فارسی خودش را دارد؛ خطای کد برای مدیر گزارش و به کاربر پیام کلی نشان داده می‌شود
    const api = e && typeof e === 'object' && 'status' in e && 'data' in e;
    if (!api) reportClientError(e);
    failed(container, api && e.message ? e.message : 'نمایش این صفحه با خطا روبه‌رو شد و برای مدیر سایت گزارش شد. دوباره امتحان کنید.');
  }
  store.emit('route', { path, name: match.name });
}
