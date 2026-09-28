/**
 * تبریک تولد: تولدهای امروز و هفته پیش رو، پیامک تبریک از پنل پیامکی سایت،
 * تبریک خودکار از طرف خود کاربر و پیامک‌های ارسالی.
 *
 * ارسال پیامک فقط برای اعضایی است که پروفایلشان به اندازه تعیین‌شده (پیش‌فرض ۹۵٪) کامل است؛
 * در غیر این صورت دقیقاً گفته می‌شود چه بخش‌هایی باید تکمیل شود.
 */
import { h, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, put, patch } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, fullName, timeAgo } from '../core/format.js';
import { modal, toast, toastError, loader, emptyState, switchInput, withLoading } from '../core/ui.js';
import { avatar } from '../components/avatar.js';

const TEMPLATES = [
  '{name} عزیز، زادروزت خجسته باد! سالی پر از سلامتی و شادی برایت آرزو می‌کنم.',
  '{name} جان تولدت مبارک! همیشه سلامت و دلت شاد باشد.',
  'زادروزتان مبارک {name} عزیز؛ سایه‌تان همیشه بر سر خانواده باشد.',
  '{name} عزیز، تولدت مبارک! به امید سالی پر از موفقیت و خبرهای خوب.',
];

const SCOPES = { d1: 'فقط بستگان درجه ۱', d2: 'بستگان تا درجه ۲', d3: 'بستگان تا درجه ۳', d4: 'بستگان تا درجه ۴', all: 'همه اعضای شجره‌نامه' };
const RANK = { d1: 1, d2: 2, d3: 3, d4: 4, all: 99 };

export default async function greetingsPage(container, { query }) {
  const page = h('div', { class: 'page narrow greetings' }, loader());
  container.append(page);
  document.title = `تبریک تولد | ${store.config.site_name}`;

  let state;
  try {
    state = await get('/api/greetings');
  } catch (e) {
    page.replaceChildren(emptyState('alert', e.message));
    return;
  }
  const me = store.user?.person;

  render();
  if (query?.person) {
    const row = state.birthdays.find((r) => r.person.id === query.person);
    if (row?.can_sms) compose(row);
  }

  function render() {
    const today = state.birthdays.filter((r) => r.in_days <= 0);
    const upcoming = state.birthdays.filter((r) => r.in_days > 0);
    page.replaceChildren(...[
      h('div', { class: 'page-head' },
        h('div', null,
          h('h1', null, icon('cake'), ' تبریک تولد'),
          h('p', { class: 'muted', style: { margin: 0 } }, 'تولد هر عضو به همه اعضا اعلان داده می‌شود؛ می‌توانید با پنل پیامکی سایت به نام خودتان تبریک بگویید.'),
        ),
      ),
      eligibilityBox(),
      h('section', { class: 'card mt' },
        h('div', { class: 'card-title' }, h('h3', null, '🎂 تولدهای امروز'), today.length ? h('span', { class: 'chip primary' }, fa(today.length)) : null),
        today.length ? h('div', { class: 'bd-list' }, ...today.map(row)) : h('p', { class: 'muted' }, 'امروز تولد کسی نیست.'),
      ),
      upcoming.length ? h('section', { class: 'card mt' },
        h('div', { class: 'card-title' }, h('h3', null, icon('calendar'), ' هفته پیش رو')),
        h('div', { class: 'bd-list' }, ...upcoming.map(row)),
      ) : null,
      autoBox(),
      receiveBox(),
      historyBox(),
    ].filter(Boolean));
  }

  // ------------------------------------------------------------ مجوز ارسال
  function eligibilityBox() {
    const e = state.eligibility;
    if (e.eligible) {
      return h('div', { class: 'card sms-ok mt' },
        icon('check'), ' می‌توانید از پنل پیامکی سایت تبریک بفرستید',
        h('span', { class: 'muted small' }, ` — امروز ${fa(e.limits.daily_left)} از ${fa(e.limits.daily)} پیامک باقی مانده است.`));
    }
    if (e.reason === 'incomplete') {
      const pct = Math.max(0, Math.min(100, e.percent || 0));
      return h('div', { class: 'card sms-locked mt' },
        h('div', { class: 'row', style: { gap: '10px', alignItems: 'flex-start' } },
          h('div', { class: 's-icon' }, icon('lock')),
          h('div', { class: 'grow' },
            h('b', null, `پیامک تبریک از پنل سایت برای پروفایل‌های بالای ${fa(e.required)}٪ است`),
            h('p', { class: 'small', style: { margin: '6px 0' } }, `پروفایل شما ${fa(pct)}٪ کامل است. با تکمیل این بخش‌ها می‌توانید به نام خودتان (حتی خودکار) پیامک تبریک بفرستید:`),
            h('div', { class: 'row wrap', style: { gap: '6px' } }, ...(e.missing || []).map((m) => h('span', { class: 'chip warning' }, m.label))),
            h('div', { class: 'sms-progress mt-sm' }, h('div', { style: { width: `${pct}%` } }), h('i', { style: { insetInlineStart: `${e.required}%` } })),
            me ? h('div', { class: 'row wrap mt-sm', style: { gap: '8px' } },
              h('a', { class: 'btn primary sm', href: `#/person/${me.id}/interview` }, icon('sparkles'), 'تکمیل با پرسش‌وپاسخ'),
              h('a', { class: 'btn sm', href: `#/person/${me.id}` }, icon('user'), 'پروفایل من'),
            ) : null,
          ),
        ),
      );
    }
    return h('div', { class: 'card sms-locked mt' }, icon('info'), ' ', e.message || 'ارسال پیامک ممکن نیست.');
  }

  // ------------------------------------------------------------ یک تولد
  function row(r) {
    const p = r.person;
    const when = r.in_days === 0 ? 'امروز' : r.in_days === -1 ? 'دیروز' : r.in_days === 1 ? 'فردا' : `${fa(r.in_days)} روز دیگر`;
    const sub = [when, r.age ? `${fa(r.age)} سالگی` : null, r.relation].filter(Boolean).join(' • ');
    let action;
    if (r.is_me) action = h('span', { class: 'chip primary' }, '🎉 تولد شما مبارک!');
    else if (r.greeted) action = h('span', { class: 'chip success' }, icon('check'), 'تبریک گفته‌اید');
    else if (r.in_days > 0) action = null;
    else {
      action = h('button', {
        class: `btn ${r.can_sms ? 'primary' : ''} sm`, type: 'button',
        title: r.can_sms ? 'ارسال پیامک تبریک به نام شما' : r.problem || state.eligibility.message || '',
        onclick: () => (r.can_sms ? compose(r) : explain(r)),
      }, icon('mail'), 'تبریک', h('span', { class: 'hide-mobile' }, ' پیامکی'));
    }
    return h('div', { class: `bd-row ${r.in_days === 0 ? 'today' : ''}` },
      h('a', { href: `#/person/${p.id}`, class: 'row grow', style: { gap: '10px', minWidth: 0, color: 'inherit', textDecoration: 'none' } },
        avatar(p, 'sm'),
        h('div', { style: { minWidth: 0 } }, h('div', { class: 'bold ellipsis' }, fullName(p)), h('div', { class: 'muted small ellipsis' }, sub)),
      ),
      action,
    );
  }

  function explain(r) {
    const e = state.eligibility;
    modal({
      title: 'ارسال پیامک تبریک ممکن نیست',
      body: h('div', null,
        h('p', null, !e.eligible ? e.message : r.problem),
        !e.eligible && e.reason === 'incomplete' && me ? h('a', { class: 'btn primary', href: `#/person/${me.id}/interview` }, icon('sparkles'), 'تکمیل پروفایل') : null,
      ),
      actions: [{ label: 'باشه' }],
    });
  }

  // ------------------------------------------------------------ نوشتن پیامک
  function compose(r) {
    const p = r.person;
    const area = h('textarea', { class: 'input', rows: 4, maxlength: state.eligibility.limits.max_length }, TEMPLATES[0]);
    const previewBox = h('div', { class: 'sms-preview' });
    const counter = h('div', { class: 'muted tiny' });
    const refresh = debounce(async () => {
      try {
        const res = await post('/api/greetings/preview', { person_id: p.id, message: area.value });
        previewBox.textContent = res.text;
        counter.textContent = `${fa(res.length)} نویسه • ${fa(res.parts)} بخش پیامک`;
      } catch (e) {
        previewBox.textContent = e.message;
        counter.textContent = '';
      }
    }, 350);
    area.addEventListener('input', refresh);
    refresh();
    modal({
      title: `تبریک تولد ${fullName(p)}`,
      body: h('div', { class: 'compose-sms' },
        h('div', { class: 'row wrap', style: { gap: '6px', marginBottom: '8px' } },
          ...TEMPLATES.map((t, i) => h('button', { class: 'chip preset', type: 'button', onclick: () => { area.value = t; refresh(); } }, `متن ${fa(i + 1)}`))),
        h('label', { class: 'label' }, 'متن پیامک ({name} = نام گیرنده)'),
        area,
        h('label', { class: 'label mt-sm' }, 'پیش‌نمایش پیامکی که ارسال می‌شود'),
        previewBox,
        counter,
        h('p', { class: 'muted tiny' }, icon('lock'), ' شماره گیرنده به شما نشان داده نمی‌شود. نام شما و نام سایت پایین پیامک می‌آید؛ لینک مجاز نیست.'),
      ),
      actions: [
        { label: 'انصراف' },
        { label: 'ارسال پیامک', class: 'primary', icon: 'mail', onClick: async () => {
          try {
            const res = await post('/api/greetings/sms', { person_id: p.id, message: area.value });
            toast(res.message);
            state.eligibility.limits = res.limits;
            r.greeted = true;
            state.history.unshift({ id: res.data.id, recipient: p, status: 'sent', auto: false, body: res.data.body, created_at: new Date().toISOString() });
            render();
            return true;
          } catch (e) {
            toastError(e);
            return false;
          }
        } },
      ],
    });
  }

  // ------------------------------------------------------------ تبریک خودکار
  function autoBox() {
    const a = state.auto;
    if (!a.allowed) return null;
    const allowedScopes = Object.entries(SCOPES).filter(([k]) => RANK[k] <= RANK[a.max_scope]);
    const scope = h('select', { class: 'input' }, ...allowedScopes.map(([k, l]) => h('option', { value: k, selected: k === a.scope }, l)));
    const template = h('textarea', { class: 'input', rows: 3, maxlength: state.eligibility.limits?.max_length || 250 }, a.template || state.default_template);
    const sw = switchInput('auto', 'روشن', !!a.auto);
    const save = h('button', { class: 'btn primary sm', type: 'button', onclick: () => withLoading(save, async () => {
      try {
        const res = await put('/api/greetings/auto', { auto: sw.querySelector('input').checked, scope: scope.value, template: template.value });
        state.auto = { ...state.auto, ...res.auto };
        toast(res.message);
      } catch (e) {
        toastError(e);
      }
    }) }, icon('check'), 'ذخیره');
    return h('section', { class: 'card mt' },
      h('div', { class: 'card-title' }, h('h3', null, icon('sparkles'), ' تبریک خودکار از طرف من'), sw),
      h('p', { class: 'muted small' }, `هر روز ساعت ${fa(a.send_hour)} به کسانی که تولدشان است (در دامنه انتخابی) از طرف شما پیامک تبریک فرستاده می‌شود؛ هر نفر سالی یک بار. فقط وقتی پروفایل شما بالای ${fa(state.eligibility.required)}٪ کامل باشد.`),
      h('div', { class: 'form-grid' },
        h('div', { class: 'field' }, h('label', null, 'به چه کسانی'), scope),
        h('div', { class: 'field full' }, h('label', null, 'متن ({name} = نام شخص)'), template),
      ),
      h('div', { class: 'row', style: { justifyContent: 'flex-end' } }, save),
    );
  }

  // ------------------------------------------------------------ دریافت پیامک
  function receiveBox() {
    if (!me) return null;
    const sw = switchInput('accept', 'پیامک تبریک اعضا را دریافت کنم', !!state.accept_greeting_sms, async (v) => {
      try {
        await patch(`/api/persons/${me.id}`, { accept_greeting_sms: v });
        state.accept_greeting_sms = v;
        toast(v ? 'پیامک‌های تبریک برای شما ارسال می‌شود.' : 'پیامک تبریک برای شما ارسال نمی‌شود.');
      } catch (e) {
        toastError(e);
      }
    });
    return h('section', { class: 'card mt' }, sw, h('p', { class: 'muted tiny', style: { margin: '6px 0 0' } }, 'اعلان تولد شما در سایت همچنان برای اعضا نمایش داده می‌شود.'));
  }

  // ------------------------------------------------------------ پیامک‌های من
  function historyBox() {
    if (!state.history?.length) return null;
    return h('section', { class: 'card mt' },
      h('div', { class: 'card-title' }, h('h3', null, icon('history'), ' پیامک‌های ارسالی من')),
      h('div', { class: 'sms-history' }, ...state.history.map((m) => h('div', { class: 'sms-item' },
        m.recipient ? avatar(m.recipient, 'xs') : null,
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', { class: 'small' }, h('b', null, m.recipient ? fullName(m.recipient) : '—'), ' ',
            h('span', { class: `chip ${m.status === 'sent' ? 'success' : 'danger'}` }, m.status === 'sent' ? 'ارسال شد' : 'ناموفق'),
            m.auto ? h('span', { class: 'chip' }, 'خودکار') : null),
          h('div', { class: 'muted tiny sms-body' }, m.body),
        ),
        h('span', { class: 'muted tiny' }, timeAgo(m.created_at)),
      ))),
    );
  }
}
