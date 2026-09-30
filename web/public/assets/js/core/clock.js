/**
 * ساعت دقیق: ساعت گوشی یا رایانه ممکن است چند دقیقه جلو یا عقب باشد؛ اختلاف با ساعت سرور یک بار (و هر ۱۰ دقیقه) سنجیده
 * می‌شود تا ساعت تهرانِ نمایش داده‌شده و زنگ هشدارها دقیق باشد.
 */
import { get } from './api.js';

let offset = 0; // میلی‌ثانیه: ساعت سرور - ساعت این دستگاه
let synced = false;
let pending = null;

export function serverNow() {
  return Date.now() + offset;
}

export function clockOffset() {
  return offset;
}

export function isSynced() {
  return synced;
}

/** همگام‌سازی با سرور (بدون خطا در صورت قطع اینترنت) */
export function syncClock(force = false) {
  if (pending && !force) return pending;
  pending = (async () => {
    try {
      const t0 = Date.now();
      const res = await get('/api/time', null, { quiet: true });
      const t1 = Date.now();
      // تأخیر شبکه نصف می‌شود (فرض رفت و برگشت برابر)
      if (t1 - t0 < 5000) {
        offset = res.data.epoch_ms - (t0 + t1) / 2;
        synced = true;
      }
    } catch {
      /* ساعت خود دستگاه */
    }
    return offset;
  })();
  return pending;
}

const tehranFmt = new Intl.DateTimeFormat('fa-IR', { timeZone: 'Asia/Tehran', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });

/** «۱۴:۰۵:۲۳» به وقت تهران */
export function tehranClock(ms = serverNow()) {
  return tehranFmt.format(new Date(ms));
}

/** ساعت و دقیقه تهران یک لحظه */
export function tehranHM(ms, timeZone = 'Asia/Tehran') {
  return new Intl.DateTimeFormat('fa-IR', { timeZone, hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(ms));
}

setInterval(() => { if (!document.hidden) syncClock(true); }, 10 * 60 * 1000);
