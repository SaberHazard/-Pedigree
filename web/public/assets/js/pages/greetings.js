/**
 * تبریک مناسبت‌ها (تولد، سالگرد ازدواج، نوروز، یلدا): کسانی که امروز می‌شود به آن‌ها تبریک گفت،
 * پیامک تبریک از پنل پیامکی سایت با متن‌های ثابت مدیر، تبریک خودکار تولد و پیامک‌های ارسالی.
 *
 * ارسال پیامک فقط برای اعضایی است که پروفایلشان به اندازه تعیین‌شده (پیش‌فرض ۹۵٪) کامل است؛
 * در غیر این صورت دقیقاً گفته می‌شود چه بخش‌هایی باید تکمیل شود.
 */
import { h, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, put } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, fullName, timeAgo } from '../core/format.js';
import { modal, toast, toastError, loader, emptyState, switchInput, withLoading, segmented } from '../core/ui.js';
import { avatar } from '../components/avatar.js';
import { pickPerson } from '../components/person-search.js';


const SCOPES = { d1: 'فقط بستگان درجه ۱', d2: 'بستگان تا درجه ۲', d3: 'بستگان تا درجه ۳', d4: 'بستگان تا درجه ۴', all: 'همه اعضای شجره‌نامه' };
const RANK = { d1: 1, d2: 2, d3: 3, d4: 4, all: 99 };

export default async function greetingsPage(container, { query }) {
  const page = h('div', { class: 'page narrow greetings' }, loader());
  container.append(page);
  document.title = `تبریک مناسبت‌ها | ${store.config.site_name}`;

  let state;
  try {
    state = await get('/api/greetings');
  } catch (e) {
    page.replaceChildren(emptyState('alert', e.message));
    return;
  }
  const me = store.user?.person;
  const occ = (key) => state.occasions.find((o) => o.key === key);
  let tab = occ(query?.occasion) ? query.occasion : 'birthday';
  if (!query?.occasion && !state.birthdays.some((r) => r.in_days <= 0)) {
    // اگر امروز تولدی نیست ولی سالگرد یا نوروز/یلدا باز است، همان را نشان بده
    if (state.anniversaries.some((r) => r.in_days <= 0)) tab = 'anniversary';
    else tab = ['nowruz', 'yalda'].find((k) => occ(k)?.open) || 'birthday';
  }

  render();
  if (query?.person) {
    const row = state.birthdays.find((r) => r.person.id === query.person);
    if (row?.can_sms) compose(row.person, 'birthday', () => { row.greeted = true; });
  }

  function render() {
    const tabs = segmented(state.occasions.map((o) => ({
      value: o.key,
      label: `${o.emoji} ${o.label}`,
      title: o.open ? o.label : `${o.label} — ${o.window}`,
    })), tab, (v) => { tab = v; render(); });
    page.replaceChildren(...[
      h('div', { class: 'page-head' },
        h('div', null,
          h('h1', null, icon('cake'), ' تبریک مناسبت‌ها'),
          h('p', { class: 'muted', style: { margin: 0 } }, 'تولد، سالگرد ازدواج، نوروز و شب یلدای بستگان را با پنل پیامکی سایت به نام خودتان تبریک بگویید.'),
        ),
      ),
      eligibilityBox(),
      h('div', { class: 'scroll-x mt', 'data-scroll-x': '' }, tabs),
      ...(tab === 'birthday' ? birthdayTab() : tab === 'anniversary' ? anniversaryTab() : seasonalTab(occ(tab))),
      historyBox(),
    ].filter(Boolean));
  }

  function birthdayTab() {
    const today = state.birthdays.filter((r) => r.in_days <= 0);
    const upcoming = state.birthdays.filter((r) => r.in_days > 0);
    return [
      h('section', { class: 'card mt' },
        h('div', { class: 'card-title' }, h('h3', null, '🎂 تولدهای امروز'), today.length ? h('span', { class: 'chip primary' }, fa(today.length)) : null),
        today.length ? h('div', { class: 'bd-list' }, ...today.map(row)) : h('p', { class: 'muted' }, 'امروز تولد کسی نیست.'),
      ),
      upcoming.length ? h('section', { class: 'card mt' },
        h('div', { class: 'card-title' }, h('h3', null, icon('calendar'), ' هفته پیش رو')),
        h('div', { class: 'bd-list' }, ...upcoming.map(row)),
      ) : null,
      autoBox(),
    ];
  }

  function anniversaryTab() {
    const today = state.anniversaries.filter((r) => r.in_days <= 0);
    const upcoming = state.anniversaries.filter((r) => r.in_days > 0);
    return [
      h('section', { class: 'card mt' },
        h('div', { class: 'card-title' }, h('h3', null, '💍 سالگردهای ازدواج امروز')),
        today.length ? h('div', { class: 'bd-list' }, ...today.map(row)) : h('p', { class: 'muted' }, 'امروز سالگرد ازدواج کسی نیست (فقط ازدواج‌هایی که تاریخ کامل دارند).'),
      ),
      upcoming.length ? h('section', { class: 'card mt' },
        h('div', { class: 'card-title' }, h('h3', null, icon('calendar'), ' هفته پیش رو')),
        h('div', { class: 'bd-list' }, ...upcoming.map(row)),
      ) : null,
    ];
  }

  /** نوروز و یلدا: فهرست بستگان تا درجه ۴ و جستجوی هر عضو دیگر */
  function seasonalTab(o) {
    if (!o.open) {
      return [h('section', { class: 'card mt' }, emptyState('calendar', `پیامک تبریک ${o.label} ${o.window} فعال می‌شود.`))];
    }
    if (!o.templates.length) {
      return [h('section', { class: 'card mt' }, emptyState('mail', `مدیر سایت هنوز متنی برای ${o.label} تعیین نکرده است.`))];
    }
    const list = h('div', { class: 'bd-list' });
    const filter = h('input', { class: 'input', type: 'search', placeholder: 'جستجو در بستگان...' });
    const draw = () => {
      const q = filter.value.trim();
      const rows = state.relatives.filter((r) => !q || fullName(r.person).includes(q) || (r.relation || '').includes(q));
      list.replaceChildren(...(rows.length ? rows.map((r) => relativeRow(r, o)) : [h('p', { class: 'muted' }, 'کسی پیدا نشد.')]));
    };
    filter.addEventListener('input', debounce(draw, 200));
    draw();
    const other = h('button', { class: 'btn sm', type: 'button', onclick: async () => {
      const p = await pickPerson({ title: `تبریک ${o.label} به ...` });
      if (p) compose(p, o.key);
    } }, icon('search'), 'شخص دیگر');
    return [h('section', { class: 'card mt' },
      h('div', { class: 'card-title' }, h('h3', null, `${o.emoji} تبریک ${o.label} به بستگان`), other),
      h('p', { class: 'muted small', style: { marginTop: 0 } }, `بستگان تا درجه ۴ که موبایلشان ثبت شده؛ به هر نفر برای ${o.label} سالی یک بار.`),
      filter,
      list,
    )];
  }

  function relativeRow(r, o) {
    const p = r.person;
    const done = r.greeted?.[o.key];
    const action = done
      ? h('span', { class: 'chip success' }, icon('check'), 'تبریک گفته‌اید')
      : h('button', { class: `btn ${r.can_sms ? 'primary' : ''} sm`, type: 'button', onclick: () => (r.can_sms ? compose(p, o.key, () => { r.greeted = { ...r.greeted, [o.key]: true }; }) : explain({ problem: null })) }, icon('mail'), 'تبریک');
    return h('div', { class: 'bd-row' },
      h('a', { href: `#/person/${p.id}`, class: 'row grow', style: { gap: '10px', minWidth: 0, color: 'inherit', textDecoration: 'none' } },
        avatar(p, 'sm'),
        h('div', { style: { minWidth: 0 } }, h('div', { class: 'bold ellipsis' }, fullName(p)), h('div', { class: 'muted small ellipsis' }, [r.relation, `درجه ${fa(r.degree)}`].filter(Boolean).join(' • '))),
      ),
      action,
    );
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

  // ------------------------------------------------------------ یک ردیف تولد یا سالگرد
  function row(r) {
    const p = r.person;
    const when = r.in_days === 0 ? 'امروز' : r.in_days === -1 ? 'دیروز' : r.in_days === 1 ? 'فردا' : `${fa(r.in_days)} روز دیگر`;
    const what = r.occasion === 'anniversary'
      ? `${fa(r.years)}مین سالگرد ازدواج${r.spouse ? ` با ${fullName(r.spouse)}` : ''}`
      : r.age ? `${fa(r.age)} سالگی` : null;
    const sub = [when, what, r.relation].filter(Boolean).join(' • ');
    let action;
    if (r.is_me) action = h('span', { class: 'chip primary' }, r.occasion === 'anniversary' ? '💍 مبارک باشد!' : '🎉 تولد شما مبارک!');
    else if (r.greeted) action = h('span', { class: 'chip success' }, icon('check'), 'تبریک گفته‌اید');
    else if (r.in_days > 0) action = null;
    else {
      action = h('button', {
        class: `btn ${r.can_sms ? 'primary' : ''} sm`, type: 'button',
        title: r.can_sms ? 'ارسال پیامک تبریک به نام شما' : r.problem || state.eligibility.message || '',
        onclick: () => (r.can_sms ? compose(p, r.occasion, () => { r.greeted = true; }) : explain(r)),
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
  /** انتخاب یکی از متن‌های ثابت مدیر + یادداشت کوتاه اختیاری (نام‌ها و نسبت را سایت می‌نویسد) */
  function templatePicker(templates, selected, onChange) {
    const box = h('div', { class: 'tpl-list', role: 'radiogroup' });
    const draw = (current) => box.replaceChildren(...templates.map((t) => h('button', {
      type: 'button', class: `tpl ${t.id === current ? 'active' : ''}`, role: 'radio', 'aria-checked': t.id === current ? 'true' : 'false',
      onclick: () => { draw(t.id); onChange(t.id); },
    }, h('b', { class: 'small' }, t.title), h('div', { class: 'tpl-text' }, t.display))));
    draw(selected);
    return box;
  }

  function noteInput(value, onInput) {
    const input = h('input', { class: 'input', maxlength: state.note_max, value: value || '', placeholder: 'اختیاری؛ مثلاً «علی کوچولو» یا «با عشق، خانواده رضایی»' });
    input.addEventListener('input', onInput);
    return input;
  }

  function compose(p, occasion, onSent) {
    const o = occ(occasion);
    if (!o?.templates.length) return toast('برای این مناسبت متنی تعیین نشده است.', 'warning');
    let template = occasion === 'birthday' && o.templates.some((t) => t.id === state.auto?.template) ? state.auto.template : o.templates[0].id;
    const previewBox = h('div', { class: 'sms-preview' });
    const counter = h('div', { class: 'muted tiny' });
    const problem = h('div', { class: 'field-error', hidden: true });
    const note = noteInput('', () => refresh());
    const refresh = debounce(async () => {
      try {
        const res = await post('/api/greetings/preview', { person_id: p.id, occasion, template, note: note.value });
        previewBox.textContent = res.text;
        counter.textContent = `${fa(res.length)} نویسه • ${fa(res.parts)} بخش پیامک`;
        problem.hidden = !res.problem;
        problem.textContent = res.problem || '';
      } catch (e) {
        previewBox.textContent = e.message;
        counter.textContent = '';
      }
    }, 300);
    refresh();
    modal({
      title: `تبریک ${o.label} به ${fullName(p)}`,
      body: h('div', { class: 'compose-sms' },
        h('label', { class: 'label' }, 'متن تبریک'),
        templatePicker(o.templates, template, (id) => { template = id; refresh(); }),
        h('label', { class: 'label mt-sm' }, `یادداشت خیلی کوتاه از طرف شما (حداکثر ${fa(state.note_max)} نویسه، بدون عدد و لینک)`),
        note,
        h('label', { class: 'label mt-sm' }, 'پیامکی که ارسال می‌شود'),
        previewBox,
        counter,
        problem,
        h('p', { class: 'muted tiny' }, icon('info'), ' متن را مدیر سایت تعیین کرده؛ نام‌ها (با عنوان دکتر/مهندس) و نسبت فامیلی شما با گیرنده را سایت از روی شجره‌نامه می‌نویسد. شماره گیرنده به شما نشان داده نمی‌شود.'),
      ),
      actions: [
        { label: 'انصراف' },
        { label: 'ارسال پیامک', class: 'primary', icon: 'mail', onClick: async () => {
          try {
            const res = await post('/api/greetings/sms', { person_id: p.id, occasion, template, note: note.value });
            toast(res.message);
            state.eligibility.limits = res.limits;
            onSent?.();
            state.history.unshift({ id: res.data.id, kind: occasion, recipient: p, status: 'sent', auto: false, body: res.data.body, created_at: new Date().toISOString() });
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
    const allowedScopes = Object.entries(SCOPES).filter(([k]) => RANK[k] <= RANK[a.max_scope]);
    const scope = h('select', { class: 'input' }, ...allowedScopes.map(([k, l]) => h('option', { value: k, selected: k === a.scope }, l)));
    const birthdayTemplates = occ('birthday').templates;
    let template = birthdayTemplates.some((t) => t.id === a.template) ? a.template : birthdayTemplates[0]?.id;
    const note = noteInput(a.note, () => {});
    const sw = switchInput('auto', 'روشن', !!a.auto);
    const save = h('button', { class: 'btn primary sm', type: 'button', onclick: () => withLoading(save, async () => {
      try {
        const res = await put('/api/greetings/auto', { auto: sw.querySelector('input').checked, scope: scope.value, template, note: note.value });
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
        h('div', { class: 'field' }, h('label', null, 'یادداشت کوتاه (اختیاری)'), note),
        h('div', { class: 'field full' }, h('label', null, 'متن تبریک'), templatePicker(birthdayTemplates, template, (id) => { template = id; })),
      ),
      h('div', { class: 'row', style: { justifyContent: 'flex-end' } }, save),
    );
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
            m.auto ? h('span', { class: 'chip' }, 'خودکار') : null,
            occ(m.kind) && m.kind !== 'birthday' ? h('span', { class: 'chip' }, `${occ(m.kind).emoji} ${occ(m.kind).label}`) : null),
          h('div', { class: 'muted tiny sms-body' }, m.body),
        ),
        h('span', { class: 'muted tiny' }, timeAgo(m.created_at)),
      ))),
    );
  }
}
