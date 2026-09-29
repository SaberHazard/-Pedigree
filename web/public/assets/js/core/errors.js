/**
 * تور ایمنی خطاهای مرورگر: هر خطای پیش‌بینی‌نشده در کد صفحه‌ها (یا Promise رهاشده) بی‌صدا برای بخش
 * «خطاها»ی پنل مدیریت فرستاده می‌شود تا بشود رفعش کرد؛ کاربر فقط پیام فارسی کوتاه می‌بیند، نه جزئیات فنی.
 *
 * - فقط خطاهای کد خود سایت (نه افزونه‌های مرورگر یا اسکریپت‌های بیرونی)
 * - در هر بار باز کردن صفحه حداکثر ۵ گزارش و هر پیام یکسان فقط یک بار
 */
import { post } from './api.js';
import { store } from './store.js';

const MAX_REPORTS = 5;
const sent = new Set();
let count = 0;

/** خطاهای بی‌خطر یا بیرون از کنترل ما */
const IGNORED = [
  /ResizeObserver loop/i,
  /^Script error\.?$/i,
  /AbortError|The user aborted|The operation was aborted/i,
  /NotAllowedError|Permission denied|play\(\) request was interrupted/i,
  /Load failed|Failed to fetch|NetworkError|network error/i,
];

export function reportClientError(error, { source = '', line = 0 } = {}) {
  try {
    if (!store.user || count >= MAX_REPORTS) return;
    // خطاهای API پیام فارسی دارند و سمت سرور ثبت می‌شوند؛ دوباره فرستاده نمی‌شوند
    if (error && typeof error === 'object' && 'status' in error && 'data' in error) return;
    const message = String(error?.message || error || '').slice(0, 480);
    if (!message || IGNORED.some((re) => re.test(message))) return;
    if (source && !source.startsWith(location.origin)) return;
    const key = `${message}|${source}|${line}`;
    if (sent.has(key)) return;
    sent.add(key);
    count++;
    const path = source ? new URL(source, location.href).pathname : '';
    post('/api/client-errors', {
      message,
      type: String(error?.name || 'Error').slice(0, 60),
      source: path.slice(0, 280),
      line: Number(line) || 0,
      page: location.hash.split('?')[0].slice(0, 280) || '#/',
    }).catch(() => {});
  } catch {
    /* گزارش خطا هرگز خودش خطا نمی‌دهد */
  }
}

export function installErrorHandlers() {
  window.addEventListener('error', (e) => {
    // خطای بارگذاری تصویر/اسکریپت (بدون error) گزارش نمی‌شود
    if (!e.error && !e.message) return;
    reportClientError(e.error || e.message, { source: e.filename || '', line: e.lineno || 0 });
  });
  window.addEventListener('unhandledrejection', (e) => {
    reportClientError(e.reason);
  });
}
