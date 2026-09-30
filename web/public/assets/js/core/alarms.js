/**
 * زنگ هشدارها و یادآورها:
 *  - در صفحه باز سایت: سر ثانیه (با ساعت همگام‌شده با سرور) پنجره هشدار با صدای زنگ، و اگر زبانه پشت زبانه‌های
 *    دیگر است، اعلان مرورگر (با اجازه کاربر). هر زنگ در همه زبانه‌ها فقط یک بار.
 *  - داخل اپ اندروید/iOS: فهرست ۳۰ روز آینده به خود گوشی داده می‌شود تا حتی با اپ بسته و بدون اینترنت زنگ بزند.
 */
import { h } from './dom.js';
import { icon } from './icons.js';
import { get } from './api.js';
import { store } from './store.js';
import { modal } from './ui.js';
import { syncClock, serverNow, clockOffset } from './clock.js';
import { isNativeApp, scheduleNativeAlarms } from './native.js';

const timers = new Map();
let refreshTimer = null;
let ctx = null;

/** دوباره خواندن فهرست زنگ‌ها (پس از ساختن یا ویرایش هشدار هم صدا زده می‌شود) */
export async function refreshAlarms() {
  if (!store.user || store.user.status === 'pending') return;
  try {
    await syncClock();
    const native = isNativeApp();
    const res = await get(`/api/reminders/upcoming?days=${native ? 30 : 2}${native ? '&native=1' : ''}`, null, { quiet: true });
    // زمان‌ها به ساعت سرور است؛ اگر ساعت گوشی جلو/عقب باشد، زنگ محلی به همان اندازه جابه‌جا تنظیم می‌شود
    if (native) scheduleNativeAlarms(res.data.map((i) => ({ ...i, at: i.at - clockOffset() })));
    schedule(res.data);
  } catch {
    /* بعداً دوباره */
  }
}

function schedule(items) {
  for (const t of timers.values()) clearTimeout(t);
  timers.clear();
  const now = serverNow();
  for (const item of items) {
    const delay = item.at - now;
    // setTimeout بیش از ۲۴ روز پشتیبانی نمی‌شود؛ فقط ۲۶ ساعت آینده (بقیه در دور بعد)
    if (delay <= 0 || delay > 26 * 3600 * 1000) continue;
    timers.set(item.key, setTimeout(() => ring(item), delay));
  }
}

function claim(key) {
  try {
    const k = `alarm-rang:${key}`;
    if (localStorage.getItem(k)) return false;
    localStorage.setItem(k, String(Date.now()));
    // پاک کردن کلیدهای قدیمی
    for (let i = 0; i < localStorage.length; i++) {
      const name = localStorage.key(i);
      if (name?.startsWith('alarm-rang:') && Date.now() - Number(localStorage.getItem(name)) > 3 * 86400000) localStorage.removeItem(name);
    }
  } catch {
    /* بدون localStorage */
  }
  return true;
}

function ring(item) {
  timers.delete(item.key);
  if (!claim(item.key)) return;
  // داخل اپ، خود گوشی زنگ می‌زند؛ اینجا فقط پنجره
  const sound = isNativeApp() ? null : beep();
  if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
    try {
      new Notification(item.title, { body: item.body, tag: item.key, requireInteraction: true });
    } catch {
      /* برخی مرورگرهای موبایل */
    }
  }
  modal({
    title: item.title,
    body: h('div', { class: 'alarm-box' },
      h('div', { class: 'alarm-bell', 'aria-hidden': 'true' }, icon('bell')),
      h('p', { class: 'alarm-body' }, item.body || ''),
    ),
    actions: [
      { label: 'پنج دقیقه دیگر', icon: 'clock', onClick: () => { timers.set(`${item.key}-snooze`, setTimeout(() => ring({ ...item, key: `${item.key}-s${Date.now()}` }), 5 * 60 * 1000)); } },
      { label: 'دیدم', class: 'primary', icon: 'check' },
    ],
    onClose: () => sound?.stop(),
  });
}

/** صدای زنگ ملایم (بدون فایل صوتی) تا ۴۵ ثانیه یا تا بستن پنجره */
function beep() {
  try {
    ctx ??= new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    let stopped = false;
    const play = (at) => {
      [0, 0.18, 0.36].forEach((offset, i) => {
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = [880, 988, 1175][i];
        g.gain.setValueAtTime(0.0001, at + offset);
        g.gain.exponentialRampToValueAtTime(0.25, at + offset + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, at + offset + 0.16);
        o.connect(g).connect(ctx.destination);
        o.start(at + offset);
        o.stop(at + offset + 0.17);
      });
    };
    const start = ctx.currentTime;
    for (let i = 0; i < 30; i++) play(start + i * 1.5);
    const stop = () => {
      if (stopped) return;
      stopped = true;
      ctx.close().catch(() => {});
      ctx = null;
    };
    setTimeout(stop, 46000);
    return { stop };
  } catch {
    return null;
  }
}

/** اجازه اعلان مرورگر (فقط با کلیک کاربر) */
export async function askNotificationPermission() {
  if (!('Notification' in window) || Notification.permission !== 'default') return Notification?.permission;
  try {
    return await Notification.requestPermission();
  } catch {
    return 'denied';
  }
}

export function startAlarms() {
  refreshAlarms();
  clearInterval(refreshTimer);
  refreshTimer = setInterval(refreshAlarms, 10 * 60 * 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshAlarms(); });
}
