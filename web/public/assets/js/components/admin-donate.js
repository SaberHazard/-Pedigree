/**
 * پنل مدیریت ← «حمایت» (فقط مدیر کل): شماره کارت، شبا، حساب و لینک‌های پرداخت + گزارش پرداخت‌های درگاه
 * (کلید درگاه‌ها در «تنظیمات و اتصال‌ها ← حمایت از سازنده»)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, put, del } from '../core/api.js';
import { fa, dateTime, bankNumber } from '../core/format.js';
import { toast, toastError, loader, emptyState, modal, confirmDialog, showFormErrors, clearFormErrors, field, switchInput } from '../core/ui.js';

const KINDS = { card: 'شماره کارت', sheba: 'شماره شبا', account: 'شماره حساب', link: 'لینک پرداخت (بلو، زرین‌لینک، PayPal ...)' };
const money = (n) => `${fa(Number(n).toLocaleString('en-US'))} تومان`;

export async function adminDonate(body) {
  body.replaceChildren(loader());
  let data;
  try {
    data = await get('/api/admin/donations');
  } catch (e) {
    body.replaceChildren(emptyState('lock', e.message));
    return;
  }
  const t = data.totals;
  body.replaceChildren(
    h('div', { class: 'stats' },
      stat('gift', 'تعداد حمایت‌ها', fa(t.count)),
      stat('card', 'جمع کل', money(t.sum)),
      stat('calendar', '۳۰ روز اخیر', money(t.month)),
    ),
    h('section', { class: 'card mt' },
      h('div', { class: 'card-title' },
        h('h3', null, icon('card'), ' حساب‌ها و لینک‌ها'),
        h('div', { class: 'row', style: { gap: '6px' } },
          h('a', { class: 'btn ghost sm', href: '#/admin/settings', onclick: () => setTimeout(() => document.getElementById('set-donate')?.scrollIntoView({ block: 'start' }), 600) }, icon('settings'), 'درگاه پرداخت'),
          h('button', { class: 'btn primary sm', type: 'button', onclick: () => edit(null) }, icon('plus'), 'افزودن'))),
      data.accounts.length
        ? h('div', { class: 'acc-list' }, ...data.accounts.map((a) => h('div', { class: `acc-row ${a.active ? '' : 'inactive'}` },
          h('div', { class: 'grow', style: { minWidth: 0 } },
            h('div', { class: 'muted tiny' }, `${KINDS[a.kind]} • ${a.title}`, a.holder ? ` • ${a.holder}` : '', a.active ? '' : ' • غیرفعال'),
            h('div', { class: 'acc-value ltr', dir: 'ltr' }, bankNumber(a.kind, a.value))),
          h('button', { class: 'icon-btn', type: 'button', title: 'ویرایش', onclick: () => edit(a) }, icon('edit')),
          h('button', { class: 'icon-btn danger-text', type: 'button', title: 'حذف', onclick: () => remove(a) }, icon('trash')),
        )))
        : emptyState('card', 'هنوز شماره کارت یا حسابی اضافه نکرده‌اید.'),
    ),
    h('section', { class: 'card mt table-wrap' },
      h('div', { class: 'card-title' }, h('h3', null, icon('history'), ' پرداخت‌های درگاه')),
      data.donations.length ? h('table', { class: 'table' },
        h('thead', null, h('tr', null, ...['زمان', 'حامی', 'مبلغ', 'وضعیت', 'کد پیگیری', 'پیام'].map((x) => h('th', null, x)))),
        h('tbody', null, ...data.donations.map((d) => h('tr', null,
          h('td', { class: 'small nowrap' }, dateTime(d.at)),
          h('td', null, d.name, d.anonymous ? h('span', { class: 'chip tiny-chip' }, 'ناشناس') : null),
          h('td', { class: 'nowrap' }, money(d.amount)),
          h('td', null, h('span', { class: `chip ${d.status === 'paid' ? 'success' : d.status === 'failed' ? 'danger' : ''}` }, d.status === 'paid' ? 'پرداخت شد' : d.status === 'failed' ? 'ناموفق' : 'در انتظار')),
          h('td', { class: 'small ltr' }, d.ref || '—'),
          h('td', { class: 'small', dir: 'auto' }, d.message || ''),
        )))) : emptyState('gift', 'هنوز پرداختی نیست.'),
    ),
  );

  function stat(ic, label, value) {
    return h('div', { class: 'card stat' }, h('div', { class: 's-icon' }, icon(ic)), h('div', null, h('div', { class: 'muted small' }, label), h('b', null, value)));
  }

  function edit(a) {
    const form = h('form', { novalidate: true });
    const kind = h('select', { class: 'input', name: 'kind' }, ...Object.entries(KINDS).map(([k, l]) => h('option', { value: k, selected: a?.kind === k }, l)));
    const title = h('input', { class: 'input', name: 'title', maxlength: 80, value: a?.title || '', placeholder: 'مثلاً «بانک ملت» یا «لینک پرداخت بلو»' });
    const holder = h('input', { class: 'input', name: 'holder', maxlength: 80, value: a?.holder || '', placeholder: 'نام صاحب حساب' });
    const value = h('input', { class: 'input ltr-input', name: 'value', maxlength: 300, value: a?.value || '', placeholder: '6037...، IR...، یا https://...' });
    const note = h('input', { class: 'input', name: 'note', maxlength: 200, value: a?.note || '' });
    const active = switchInput('active', 'نمایش در صفحه حمایت', a ? a.active : true);
    form.append(field('نوع', kind), field('عنوان', title), field('صاحب حساب', holder), field('شماره یا لینک', value, { hint: 'شماره کارت و شبا بررسی می‌شوند؛ لینک فقط https' }), field('توضیح (اختیاری)', note), active);
    modal({
      title: a ? 'ویرایش' : 'افزودن کارت، حساب یا لینک',
      body: form,
      actions: [
        { label: 'انصراف' },
        { label: 'ذخیره', class: 'primary', icon: 'check', onClick: async () => {
          clearFormErrors(form);
          const payload = { kind: kind.value, title: title.value.trim(), holder: holder.value.trim() || null, value: value.value.trim(), note: note.value.trim() || null, active: active.querySelector('input').checked };
          try {
            const res = a ? await put(`/api/admin/donation-accounts/${a.id}`, payload) : await post('/api/admin/donation-accounts', payload);
            toast(res.message);
            adminDonate(body);
            return true;
          } catch (e) {
            showFormErrors(form, e);
            return false;
          }
        } },
      ],
    });
  }

  async function remove(a) {
    if (!(await confirmDialog(`«${a.title}» حذف شود؟`, { danger: true, okLabel: 'حذف' }))) return;
    try {
      await del(`/api/admin/donation-accounts/${a.id}`);
      toast('حذف شد.');
      adminDonate(body);
    } catch (e) {
      toastError(e);
    }
  }
}
