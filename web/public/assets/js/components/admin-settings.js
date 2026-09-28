/**
 * پنل مدیریت ← «تنظیمات و اتصال‌ها» (فقط مدیر کل)
 *
 * هر پنل پیامکی / سرویس یک کارت دارد: فیلدها، وضعیت (تنظیم‌شده، برای کد ورود/تبریک)،
 * دکمه‌های آزمایش (اعتبار پنل، پیامک آزمایشی به موبایل خود مدیر، Firebase، شبکه اجتماعی).
 * کلیدها هرگز از سرور برنمی‌گردند؛ فقط «تنظیم شده ••••۱۲۳۴». خالی گذاشتن = بدون تغییر.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, put, post } from '../core/api.js';
import { fa } from '../core/format.js';
import { toast, toastError, loader, emptyState, withLoading, confirmDialog, showFormErrors, clearFormErrors } from '../core/ui.js';

const SOCIAL_TESTS = [['telegram', 'تلگرام'], ['instagram', 'اینستاگرام'], ['github', 'گیت‌هاب'], ['bluesky', 'بلواسکای'], ['aparat', 'آپارات'], ['x', 'ایکس'], ['youtube', 'یوتیوب']];

export async function adminSettings(body) {
  body.replaceChildren(loader());
  let groups;
  try {
    groups = (await get('/api/admin/settings')).data;
  } catch (e) {
    body.replaceChildren(emptyState('lock', e.message));
    return;
  }
  const nav = h('div', { class: 'settings-nav' });
  const list = h('div', { class: 'settings-list' });
  body.replaceChildren(
    h('div', { class: 'card settings-intro' },
      icon('shield'), ' ',
      h('span', null, 'کلیدهای API رمزنگاری‌شده ذخیره می‌شوند و هرگز دوباره نمایش داده نمی‌شوند. مقداری که اینجا وارد شود بر فایل ', h('code', null, '.env'), ' مقدم است؛ «بازگشت به .env» مقدار پنل را پاک می‌کند.'),
    ),
    h('div', { class: 'settings-layout' }, nav, list),
  );
  render();

  function render() {
    nav.replaceChildren(...groups.map((g) => h('a', { href: `#set-${g.key}`, onclick: (e) => { e.preventDefault(); document.getElementById(`set-${g.key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); } },
      icon(g.icon), h('span', null, g.label), statusDot(g))));
    list.replaceChildren(...groups.map(card));
  }

  function statusDot(g) {
    if (!g.status) return null;
    if (g.status.roles?.length) return h('i', { class: 'dot on', title: g.status.roles.join('، ') });
    return h('i', { class: `dot ${g.status.configured ? 'ok' : ''}` });
  }

  function card(g) {
    const form = h('form', { class: 'form-grid', novalidate: true });
    const inputs = new Map();
    const clear = new Set();

    for (const f of g.fields) {
      const { el, read } = fieldInput(f, clear);
      inputs.set(f.key, read);
      form.append(el);
    }

    const saveBtn = h('button', { class: 'btn primary sm', type: 'submit' }, icon('check'), 'ذخیره');
    form.append(h('div', { class: 'field full settings-actions' }, saveBtn, ...testButtons(g)));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFormErrors(form);
      const values = {};
      for (const [key, read] of inputs) {
        const v = read();
        if (v !== undefined) values[key] = v;
      }
      await withLoading(saveBtn, async () => {
        try {
          const res = await put('/api/admin/settings', { values, clear: [...clear] });
          groups = res.data;
          toast(res.message);
          render();
          document.getElementById(`set-${g.key}`)?.scrollIntoView({ block: 'start' });
        } catch (err) {
          // خطای values.KEY → فیلد متناظر
          if (err?.errors) {
            const mapped = {};
            for (const [k, v] of Object.entries(err.errors)) mapped[k.replace(/^values\./, '')] = v;
            showFormErrors(form, { ...err, errors: mapped });
          } else toastError(err);
        }
      });
    });

    const roles = g.status?.roles?.length ? h('span', { class: 'chip success' }, icon('check'), 'فعال برای ', g.status.roles.join(' و ')) : null;
    const configured = g.status ? h('span', { class: `chip ${g.status.configured ? 'primary' : ''}` }, g.status.configured ? 'کلید تنظیم شده' : 'تنظیم نشده') : null;
    return h('section', { class: 'card settings-card', id: `set-${g.key}` },
      h('div', { class: 'settings-head' },
        h('div', { class: 's-icon' }, icon(g.icon)),
        h('div', { class: 'grow' }, h('h3', null, g.label), g.description ? h('p', { class: 'muted small' }, g.description) : null),
        h('div', { class: 'row wrap', style: { gap: '6px', justifyContent: 'flex-end' } }, roles, configured,
          g.link ? h('a', { class: 'btn ghost xs', href: g.link, target: '_blank', rel: 'noopener noreferrer' }, icon('external'), 'پنل / مستندات') : null),
      ),
      form,
    );
  }

  /** یک فیلد؛ read() مقدار برای ارسال یا undefined (بدون تغییر) */
  function fieldInput(f, clear) {
    const id = `f-${f.key.replace(/\W/g, '-')}`;
    const source = f.source === 'panel' ? h('span', { class: 'chip primary tiny-chip', title: 'مقدار از پنل مدیریت' }, 'پنل') : null;
    const label = h('label', { for: id }, f.label, ' ', source);
    const hint = f.help ? h('div', { class: 'hint' }, f.help) : null;
    const resetBtn = f.source === 'panel' && !['secret', 'secret_json'].includes(f.type)
      ? h('button', { class: 'btn ghost xs', type: 'button', title: 'حذف مقدار پنل و استفاده از فایل .env', onclick: (e) => { clear.add(f.key); e.currentTarget.replaceWith(h('span', { class: 'muted tiny' }, 'پس از ذخیره به .env برمی‌گردد')); } }, 'بازگشت به .env')
      : null;

    if (f.type === 'bool') {
      const input = h('input', { type: 'checkbox', id, name: f.key, checked: !!f.value });
      return {
        el: h('div', { class: 'field full' }, h('label', { class: 'switch' }, input, h('span', { class: 'track' }), h('span', null, f.label, ' ', source)), hint, resetBtn),
        read: () => (input.checked !== !!f.value ? input.checked : undefined),
      };
    }
    if (f.type === 'select') {
      const input = h('select', { class: 'input', id, name: f.key }, ...f.options.map((o) => h('option', { value: o.value, selected: String(f.value ?? '') === o.value }, o.label)));
      return { el: h('div', { class: 'field' }, label, input, hint, resetBtn), read: () => (input.value !== String(f.value ?? '') ? input.value : undefined) };
    }
    if (f.type === 'secret' || f.type === 'secret_json') {
      const isJson = f.type === 'secret_json';
      const input = isJson
        ? h('textarea', { class: 'input ltr-input', id, name: f.key, rows: 3, placeholder: f.is_set ? 'برای تغییر، محتوای جدید را بچسبانید' : '{ "type": "service_account", ... }', spellcheck: 'false' })
        : h('input', { class: 'input ltr-input', id, name: f.key, type: 'password', autocomplete: 'new-password', spellcheck: 'false', placeholder: f.is_set ? `تنظیم شده ${f.hint || ''} — برای تغییر مقدار جدید` : (f.placeholder || 'وارد نشده') });
      const status = f.is_set
        ? h('span', { class: 'chip success tiny-chip' }, icon('check'), f.hint || 'تنظیم شده')
        : h('span', { class: 'chip tiny-chip' }, 'وارد نشده');
      const show = !isJson ? h('button', { class: 'icon-btn sm', type: 'button', title: 'نمایش آنچه تایپ می‌کنید', onclick: () => { input.type = input.type === 'password' ? 'text' : 'password'; } }, icon('eye')) : null;
      let removed = false;
      const remove = f.is_set && f.source === 'panel' ? h('button', { class: 'btn ghost xs danger-text', type: 'button', onclick: async (e) => {
        if (!(await confirmDialog(`«${f.label}» از پنل حذف شود؟`, { danger: true, okLabel: 'حذف' }))) return;
        removed = true;
        clear.add(f.key);
        e.currentTarget.replaceWith(h('span', { class: 'muted tiny' }, 'پس از ذخیره حذف می‌شود'));
      } }, icon('trash'), 'حذف') : null;
      return {
        el: h('div', { class: `field ${isJson ? 'full' : ''}` }, h('label', { for: id }, f.label, ' ', status, ' ', source),
          h('div', { class: 'row', style: { gap: '4px' } }, input, show), hint, remove,
          f.link ? h('a', { class: 'tiny', href: f.link, target: '_blank', rel: 'noopener noreferrer' }, icon('external'), ' دریافت کلید') : null),
        read: () => (removed ? undefined : (input.value.trim() ? input.value.trim() : undefined)),
      };
    }
    const isInt = f.type === 'int';
    const input = h('input', {
      class: `input ${isInt || f.type === 'url' || /api|line|sender|number|id|code|username|variable|template|parameter|model|base_url/.test(f.key) ? 'ltr-input' : ''}`,
      id, name: f.key, value: f.value ?? '', placeholder: f.placeholder || '',
      type: isInt ? 'number' : 'text', inputmode: isInt ? 'numeric' : null, min: f.min ?? null, max: isInt ? f.max : null, maxlength: !isInt ? f.max || 500 : null,
      autocomplete: 'off', spellcheck: 'false',
    });
    return {
      el: h('div', { class: `field ${f.type === 'url' ? 'full' : ''}` }, label, input, hint || (isInt && f.min !== null ? h('div', { class: 'hint' }, `بین ${fa(f.min)} و ${fa(f.max)}`) : null), resetBtn),
      read: () => {
        const v = input.value.trim();
        const old = f.value === null || f.value === undefined ? '' : String(f.value);
        if (v === old) return undefined;
        return isInt ? (v === '' ? undefined : Number(v)) : v;
      },
    };
  }

  /** دکمه‌های آزمایش هر کارت */
  function testButtons(g) {
    const out = [];
    const result = h('div', { class: 'test-result', hidden: true });
    const run = (btn, payload) => withLoading(btn, async () => {
      result.hidden = false;
      result.className = 'test-result';
      result.textContent = 'در حال آزمایش...';
      try {
        const res = await post('/api/admin/settings/test', payload);
        result.classList.add('ok');
        result.textContent = res.message;
      } catch (e) {
        result.classList.add('fail');
        result.textContent = e.message || 'ناموفق';
      }
    });
    if (g.provider) {
      const credit = h('button', { class: 'btn soft sm', type: 'button', onclick: () => run(credit, { action: 'sms_credit', provider: g.provider }) }, icon('refresh'), 'بررسی اتصال و اعتبار');
      const send = h('button', { class: 'btn soft sm', type: 'button', onclick: async () => {
        if (!(await confirmDialog('یک پیامک آزمایشی به شماره موبایل خودتان ارسال شود؟ (هزینه آن از اعتبار پنل کم می‌شود)'))) return;
        run(send, { action: 'sms_send', provider: g.provider });
      } }, icon('mail'), 'پیامک آزمایشی به من');
      out.push(credit, send);
    }
    if (g.key === 'push') {
      const test = h('button', { class: 'btn soft sm', type: 'button', onclick: () => run(test, { action: 'push' }) }, icon('refresh'), 'بررسی اتصال Firebase');
      out.push(test);
    }
    if (g.key === 'social') {
      const net = h('select', { class: 'input', style: { width: 'auto' } }, ...SOCIAL_TESTS.map(([k, l]) => h('option', { value: k }, l)));
      const handle = h('input', { class: 'input ltr-input', placeholder: '@username', style: { width: '160px' } });
      const test = h('button', { class: 'btn soft sm', type: 'button', onclick: () => {
        if (!handle.value.trim()) return toast('یک شناسه عمومی برای آزمایش وارد کنید.', 'warning');
        run(test, { action: 'social', network: net.value, handle: handle.value.trim() });
      } }, icon('refresh'), 'آزمایش دریافت');
      out.push(h('span', { class: 'row wrap', style: { gap: '6px' } }, net, handle, test));
    }
    if (g.key === 'ai' || g.key === 'ai_keys') {
      const test = h('button', { class: 'btn soft sm', type: 'button', title: 'یک پیام کوتاه با سرویس انتخاب‌شده (پس از ذخیره)', onclick: () => run(test, { action: 'ai' }) }, icon('bot'), 'آزمایش دستیار');
      out.push(test);
    }
    if (out.length) out.push(result);
    return out;
  }
}

/** گزارش پیامک‌های تبریک اعضا */
export async function adminSmsReport(body, { dateTime, fullName, avatar }) {
  body.replaceChildren(loader());
  try {
    const res = await get('/api/admin/sms-messages');
    const s = res.stats;
    body.replaceChildren(
      h('div', { class: 'stats' },
        stat('mail', 'پیامک امروز', s.today),
        stat('calendar', '۳۰ روز اخیر', s.month),
        stat('alert', 'ناموفق (۳۰ روز)', s.failed_month),
        h('div', { class: 'card stat' }, h('div', { class: 's-icon' }, icon('key')), h('div', null, h('div', { class: 'muted small' }, 'پنل پیامک تبریک'), h('b', null, s.provider))),
      ),
      res.data.length ? h('div', { class: 'card table-wrap mt' }, h('table', { class: 'table' },
        h('thead', null, h('tr', null, ...['زمان', 'فرستنده', 'گیرنده', 'متن', 'وضعیت'].map((t) => h('th', null, t)))),
        h('tbody', null, ...res.data.map((m) => h('tr', null,
          h('td', { class: 'small nowrap' }, dateTime(m.created_at)),
          h('td', null, m.sender ? h('a', { class: 'row', href: `#/person/${m.sender.id}` }, avatar(m.sender, 'xs'), fullName(m.sender)) : '—'),
          h('td', null, m.recipient ? h('a', { class: 'row', href: `#/person/${m.recipient.id}` }, avatar(m.recipient, 'xs'), fullName(m.recipient)) : '—', h('div', { class: 'tiny muted ltr' }, m.phone_hint || '')),
          h('td', { class: 'small sms-body' }, m.body),
          h('td', null, h('span', { class: `chip ${m.status === 'sent' ? 'success' : 'danger'}`, title: m.error || '' }, m.status === 'sent' ? 'ارسال شد' : 'ناموفق'), m.auto ? h('span', { class: 'chip' }, 'خودکار') : null),
        ))),
      )) : emptyState('mail', 'هنوز پیامک تبریکی ارسال نشده است.'),
    );
  } catch (e) {
    body.replaceChildren(emptyState('alert', e.message));
  }

  function stat(ic, label, value) {
    return h('div', { class: 'card stat' }, h('div', { class: 's-icon' }, icon(ic)), h('div', null, h('div', { class: 'muted small' }, label), h('b', null, fa(value ?? 0))));
  }
}
