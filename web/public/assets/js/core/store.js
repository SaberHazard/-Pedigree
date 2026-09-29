/**
 * وضعیت سراسری برنامه + گذرگاه رویداد ساده.
 *
 * store.user     کاربر واردشده (یا null)
 * store.config   تنظیمات عمومی سرور (/api/bootstrap)
 * رویدادها: user, counters, prefs, tree:reload
 */
const listeners = new Map();

export const store = {
  user: null,
  config: {},

  on(event, fn) {
    if (!listeners.has(event)) listeners.set(event, new Set());
    listeners.get(event).add(fn);
    return () => listeners.get(event)?.delete(fn);
  },

  emit(event, payload) {
    listeners.get(event)?.forEach((fn) => {
      try {
        fn(payload);
      } catch (e) {
        console.error(e);
      }
    });
  },

  setUser(user) {
    this.user = user;
    this.emit('user', user);
  },

  /** ترجیحات نمایش کاربر با مقادیر پیش‌فرض */
  get prefs() {
    return {
      theme: 'auto',
      arc_top: 'name',
      arc_bottom: 'dates',
      children_order: 'rtl',
      show_photos: true,
      calendar: 'jalali',
      compact: false,
      ...localPrefs(),
      ...(this.user?.preferences || {}),
    };
  },
};

/** ترجیحات محلی (برای کاربر مهمان یا قبل از ذخیره در سرور) */
function localPrefs() {
  try {
    return JSON.parse(localStorage.getItem('prefs') || '{}');
  } catch {
    return {};
  }
}

export function saveLocalPrefs(prefs) {
  try {
    localStorage.setItem('prefs', JSON.stringify({ ...localPrefs(), ...prefs }));
  } catch {
    /* حالت خصوصی مرورگر */
  }
}

/** اعمال تم روشن/تیره */
export function applyTheme(theme = store.prefs.theme) {
  const dark = theme === 'dark' || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0e1517' : '#f4f3ee');
  try {
    localStorage.setItem('theme', theme);
  } catch {
    /* ignore */
  }
}
