/**
 * سرویس‌ورکر (PWA): کش فایل‌های ثابت برای بارگذاری سریع و نصب روی گوشی.
 * درخواست‌های API و فایل‌های رسانه هرگز کش نمی‌شوند (اطلاعات خصوصی).
 */
const CACHE = 'pedigree-static-v2';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // فقط فایل‌های ثابت (فونت، استایل، اسکریپت، آیکن) کش می‌شوند
  if (url.pathname.includes('/assets/')) {
    event.respondWith(
      caches.open(CACHE).then(async (cache) => {
        const cached = await cache.match(req);
        if (cached) return cached;
        const res = await fetch(req);
        if (res.ok) {
          await cache.put(req, res.clone());
          // نسخه‌های قدیمی همین فایل (?v=...) پاک شوند تا حافظه گوشی پر نشود
          for (const old of await cache.keys()) {
            const u = new URL(old.url);
            if (u.pathname === url.pathname && u.search !== url.search) cache.delete(old);
          }
        }
        return res;
      }),
    );
  }
});
