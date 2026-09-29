/**
 * صفحه «در انتظار تأیید عضویت»: کسی که خودش ثبت‌نام کرده تا تأیید مدیر هیچ‌چیز از شجره‌نامه نمی‌بیند.
 * هر دقیقه (و با دکمه) وضعیت بررسی می‌شود؛ بعد از تأیید مستقیم به صفحه اول می‌رود.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { toast, withLoading } from '../core/ui.js';
import { brand, logout } from '../components/header.js';

export default function pendingPage(container) {
  document.title = `در انتظار تأیید | ${store.config.site_name}`;
  const user = store.user;
  const name = user?.person?.first_name;

  const check = async (quiet = false) => {
    try {
      const res = await get('/api/auth/me');
      const fresh = res.data || res;
      if (fresh.status && fresh.status !== 'pending') {
        store.setUser(fresh);
        toast('عضویت شما تأیید شد؛ خوش آمدید!');
        navigate('/', { replace: true });
        return;
      }
      if (!quiet) toast('هنوز در انتظار تأیید است.', 'info');
    } catch {
      // اگر درخواست رد شده باشد حساب مسدود است و api.js به صفحه ورود می‌برد
    }
  };
  const again = h('button', { class: 'btn primary', type: 'button', onclick: () => withLoading(again, () => check(false)) }, icon('refresh'), 'بررسی دوباره');

  container.append(h('div', { class: 'auth-page pending-page' },
    h('div', { class: 'auth-card card pending-card' },
      h('div', { class: 'mb' }, brand()),
      h('div', { class: 'pending-icon' }, icon('clock')),
      h('h1', null, name ? `${name} عزیز، درخواست عضویت شما ثبت شد` : 'درخواست عضویت شما ثبت شد'),
      h('p', { class: 'muted' }, 'برای حفظ حریم خاندان، شجره‌نامه فقط برای اعضای تأییدشده باز است. مدیر سایت به‌زودی معرفی شما را بررسی می‌کند؛ پس از تأیید، همین صفحه خودش باز می‌شود و روی گوشی هم خبرتان می‌کنیم.'),
      user?.join_note ? h('div', { class: 'join-note', dir: 'auto' }, user.join_note) : null,
      h('p', { class: 'muted small' }, 'اگر یکی از بستگانتان شماره موبایل شما را در پروفایلتان در شجره‌نامه ثبت کند، بدون نیاز به تأیید وارد می‌شوید.'),
      h('div', { class: 'row wrap', style: { gap: '8px', justifyContent: 'center' } },
        again,
        h('button', { class: 'btn ghost', type: 'button', onclick: logout }, icon('logout'), 'خروج'),
      ),
    ),
  ));

  const timer = setInterval(() => check(true), 60000);
  return () => clearInterval(timer);
}
