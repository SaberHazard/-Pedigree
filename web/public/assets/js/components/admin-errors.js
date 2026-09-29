/**
 * پنل مدیریت ← «خطاها»: هر خطای پیش‌بینی‌نشده سرور یا مرورگر اعضا با کد پیگیری، محل دقیق (فایل و خط)،
 * تعداد تکرار و آخرین زمان. پیام‌ها از هر چیز حساس (آدرس، کلید، شماره) پاک شده‌اند.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, del } from '../core/api.js';
import { fa, timeAgo, dateTime } from '../core/format.js';
import { segmented, toast, toastError, loader, emptyState, confirmDialog } from '../core/ui.js';
import { authorLink } from './author.js';

export async function adminErrors(body) {
  let status = 'open';
  let source = '';
  const list = h('div', { class: 'err-list' });
  const summary = h('div', { class: 'muted small' });
  const search = h('input', { class: 'input err-search', type: 'search', placeholder: 'کد پیگیری...', maxlength: 12, dir: 'ltr', onchange: () => load() });

  body.replaceChildren(h('div', null,
    h('div', { class: 'card' },
      h('div', { class: 'card-title' }, h('h3', null, 'خطاهای ثبت‌شده'), icon('alert')),
      h('p', { class: 'muted small', style: { marginTop: 0 } }, 'هر خطای پیش‌بینی‌نشده سرور یا مرورگر اعضا اینجا با «کد پیگیری» ثبت می‌شود. کاربر فقط پیام کوتاه و همین کد را می‌بیند و هیچ جزئیاتی از سرور به او نشان داده نمی‌شود. خطاهای یکسان یک ردیف با شمارنده‌اند.'),
      summary,
      h('div', { class: 'row wrap mt-sm', style: { gap: '8px' } },
        segmented([{ value: 'open', label: 'رفع‌نشده' }, { value: 'resolved', label: 'رفع‌شده' }, { value: 'all', label: 'همه' }], status, (v) => { status = v; load(); }, { compact: true }),
        segmented([{ value: '', label: 'همه منابع' }, { value: 'server', label: 'سرور' }, { value: 'client', label: 'مرورگر' }], source, (v) => { source = v; load(); }, { compact: true }),
        search,
        h('span', { class: 'grow' }),
        h('button', { class: 'btn soft sm', type: 'button', onclick: async () => {
          try {
            toast((await post('/api/admin/errors/resolve-all')).message);
            load();
          } catch (e) {
            toastError(e);
          }
        } }, icon('check'), 'همه رفع شد'),
        h('button', { class: 'btn ghost sm', type: 'button', onclick: async () => {
          if (!(await confirmDialog('خطاهای رفع‌شده برای همیشه پاک شوند؟', { danger: true }))) return;
          try {
            toast((await del('/api/admin/errors/resolved')).message);
            load();
          } catch (e) {
            toastError(e);
          }
        } }, icon('trash'), 'پاک کردن رفع‌شده‌ها'),
      ),
    ),
    list,
  ));

  async function load() {
    list.replaceChildren(loader());
    try {
      const params = new URLSearchParams({ status, ...(source ? { source } : {}), ...(search.value.trim() ? { q: search.value.trim() } : {}) });
      const res = await get(`/api/admin/errors?${params}`);
      summary.textContent = `${fa(res.meta.open)} خطای رفع‌نشده • ${fa(res.meta.last_24h)} مورد در ۲۴ ساعت گذشته`;
      list.replaceChildren(...(res.data.length ? res.data.map(row) : [emptyState('check', status === 'open' ? 'خطای رفع‌نشده‌ای نیست. 👌' : 'موردی نیست.')]));
    } catch (e) {
      list.replaceChildren(emptyState('alert', e.message));
    }
  }

  function row(r) {
    const el = h('div', { class: `card err-row${r.resolved_at ? ' resolved' : ''}` },
      h('div', { class: 'row between wrap', style: { gap: '6px' } },
        h('div', { class: 'row wrap', style: { gap: '6px' } },
          h('code', { class: 'err-ref', dir: 'ltr' }, r.ref),
          h('span', { class: `chip ${r.source === 'client' ? 'primary' : 'warning'}` }, r.source === 'client' ? 'مرورگر' : 'سرور'),
          h('span', { class: 'chip', dir: 'ltr' }, r.type),
          r.count > 1 ? h('span', { class: 'chip danger' }, `${fa(r.count)} بار`) : null),
        h('span', { class: 'tiny muted', title: dateTime(r.last_seen_at) }, `آخرین بار ${timeAgo(r.last_seen_at)}`)),
      h('div', { class: 'err-message', dir: 'auto' }, r.message),
      h('div', { class: 'err-meta tiny muted' },
        r.location ? h('span', null, icon('file'), ' ', h('bdi', { dir: 'ltr' }, r.location)) : null,
        r.path ? h('span', null, icon('link'), ' ', h('bdi', { dir: 'ltr' }, `${r.method ? r.method + ' ' : ''}${r.path}`)) : null,
        r.user ? h('span', null, authorLink(r.user, { withIcon: true, label: 'کاربر' })) : null,
        h('span', null, `نخستین بار ${dateTime(r.first_seen_at)}`)),
      r.resolved_at ? h('div', { class: 'tiny muted' }, `رفع شد ${timeAgo(r.resolved_at)}`) : h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        h('button', { class: 'btn soft sm', type: 'button', onclick: async () => {
          try {
            await post(`/api/admin/errors/${r.id}/resolve`);
            el.classList.add('resolved');
            load();
          } catch (e) {
            toastError(e);
          }
        } }, icon('check'), 'رفع شد')),
    );
    return el;
  }

  load();
}
