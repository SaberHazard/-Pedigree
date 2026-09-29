/**
 * «قالب‌های پیامک تبریک» در پنل مدیریت (فقط مدیر کل)
 *
 * متن ثابتی که همه اعضا با آن تبریک می‌فرستند؛ با متغیرهای آماده (نام، نام خانوادگی، نسبت، سن ...)
 * که با کلیک در متن گذاشته می‌شوند، ایموجی، پیش‌نمایش زنده و شمارش بخش‌های پیامک.
 */
import { h, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, put, del } from '../core/api.js';
import { fa, fullName } from '../core/format.js';
import { modal, toast, toastError, loader, emptyState, confirmDialog, segmented, switchInput, withLoading } from '../core/ui.js';
import { emojiPanel, insertAtCursor } from './emoji.js';
import { pickPerson } from './person-search.js';

export async function adminTemplates(body) {
  body.replaceChildren(loader());
  let state;
  let occasion = 'birthday';
  try {
    state = await get('/api/admin/sms-templates');
  } catch (e) {
    body.replaceChildren(emptyState('alert', e.message));
    return;
  }

  const listBox = h('div');
  const tabBar = segmented(state.occasions.map((o) => ({ value: o.key, label: `${o.emoji} ${o.label}${o.enabled ? '' : ' (خاموش)'}` })), occasion, (v) => { occasion = v; renderList(); });

  body.replaceChildren(
    h('div', { class: 'card' },
      h('div', { class: 'card-title' },
        h('h3', null, icon('mail'), ' قالب‌های پیامک تبریک'),
        h('button', { class: 'btn ghost sm', type: 'button', onclick: restoreDefaults }, icon('refresh'), 'بازگردانی پیش‌فرض‌ها'),
      ),
      h('p', { class: 'muted small', style: { marginTop: 0 } },
        'اعضا فقط می‌توانند یکی از همین متن‌ها را انتخاب کنند (به‌علاوه یادداشتی خیلی کوتاه). متغیرهایی مثل ',
        h('code', null, '{نام_گیرنده}'), ' یا ', h('code', null, '{نسبت}'), ' هنگام ارسال با مشخصات هر نفر پر می‌شوند؛ اگر همه متغیرهای یک سطر خالی باشند آن سطر حذف می‌شود. دست‌کم یک قالب فعال تولد همیشه می‌ماند.'),
      h('div', { class: 'scroll-x', 'data-scroll-x': '' }, tabBar),
    ),
    listBox,
  );
  renderList();

  function current() {
    return state.data.filter((t) => t.occasion === occasion).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
  }

  function renderList() {
    const o = state.occasions.find((x) => x.key === occasion);
    const items = current();
    listBox.replaceChildren(
      h('div', { class: 'row between mt', style: { flexWrap: 'wrap', gap: '8px' } },
        h('span', { class: 'muted small' }, icon('calendar'), ` زمان ارسال: ${o.window}`, o.next ? ` — امسال: ${o.next}` : '',
          o.enabled ? null : h('span', { class: 'chip warning', style: { marginInlineStart: '6px' } }, 'این مناسبت خاموش است؛ از «تنظیمات ← مناسبت‌های تبریک» روشن کنید')),
        h('button', { class: 'btn primary sm', type: 'button', disabled: items.length >= state.limits.per_occasion, onclick: () => edit(null) }, icon('plus'), 'افزودن قالب'),
      ),
      items.length
        ? h('div', { class: 'tpl-admin-list mt-sm' }, ...items.map((t, i) => card(t, i, items)))
        : h('div', { class: 'card mt-sm' }, emptyState('mail', `برای ${o.label} قالبی نیست؛ تا وقتی قالب فعالی نباشد، اعضا نمی‌توانند برای این مناسبت پیامک بفرستند.`)),
    );
  }

  function card(t, i, items) {
    return h('div', { class: `card tpl-admin ${t.active ? '' : 'inactive'}` },
      h('div', { class: 'row between', style: { gap: '8px', flexWrap: 'wrap' } },
        h('div', { class: 'row', style: { gap: '8px' } },
          h('b', null, t.title),
          t.active ? h('span', { class: 'chip success' }, 'فعال') : h('span', { class: 'chip' }, 'غیرفعال'),
          t.default ? h('span', { class: 'chip' }, 'پیش‌فرض') : null,
        ),
        h('div', { class: 'row', style: { gap: '4px' } },
          h('button', { class: 'icon-btn', type: 'button', title: 'بالاتر', disabled: i === 0, onclick: () => move(items, i, -1) }, icon('chevron-up')),
          h('button', { class: 'icon-btn', type: 'button', title: 'پایین‌تر', disabled: i === items.length - 1, onclick: () => move(items, i, 1) }, icon('chevron-down')),
          h('button', { class: 'btn soft sm', type: 'button', onclick: () => edit(t) }, icon('edit'), 'ویرایش'),
          h('button', { class: 'icon-btn danger', type: 'button', title: 'حذف', onclick: () => remove(t) }, icon('trash')),
        ),
      ),
      h('pre', { class: 'tpl-body', dir: 'rtl' }, highlight(t.body)),
    );
  }

  /** متغیرها به صورت برچسب رنگی (فقط گره متنی) */
  function highlight(text) {
    const labels = Object.fromEntries(state.variables.flatMap((v) => [[v.key, v.label], [v.token, v.label]]));
    const nodes = [];
    let last = 0;
    for (const m of text.matchAll(/\{([^{}\n]{1,40})\}/g)) {
      if (m.index > last) nodes.push(text.slice(last, m.index));
      nodes.push(h('span', { class: 'tpl-var', title: m[0] }, labels[m[1]] || m[0]));
      last = m.index + m[0].length;
    }
    if (last < text.length) nodes.push(text.slice(last));
    return nodes;
  }

  async function move(items, i, dir) {
    const ids = items.map((t) => t.id);
    [ids[i], ids[i + dir]] = [ids[i + dir], ids[i]];
    try {
      await post('/api/admin/sms-templates/reorder', { occasion, ids });
      ids.forEach((id, index) => { state.data.find((t) => t.id === id).sort_order = index; });
      renderList();
    } catch (e) {
      toastError(e);
    }
  }

  async function remove(t) {
    if (!(await confirmDialog(`قالب «${t.title}» حذف شود؟`, { danger: true, okLabel: 'حذف' }))) return;
    try {
      await del(`/api/admin/sms-templates/${t.id}`);
      state.data = state.data.filter((x) => x.id !== t.id);
      renderList();
      toast('حذف شد.');
    } catch (e) {
      toastError(e);
    }
  }

  async function restoreDefaults() {
    if (!(await confirmDialog('قالب‌های پیش‌فرض سایت به متن اولیه برگردند؟ قالب‌هایی که خودتان ساخته‌اید دست نمی‌خورند.'))) return;
    try {
      await post('/api/admin/sms-templates/defaults');
      state = await get('/api/admin/sms-templates');
      renderList();
      toast('بازگردانی شد.');
    } catch (e) {
      toastError(e);
    }
  }

  // ------------------------------------------------------------ ویرایشگر
  function edit(t) {
    const title = h('input', { class: 'input', maxlength: state.limits.title_max, value: t?.title || '', placeholder: 'مثلاً «گرم و صمیمی»' });
    const text = h('textarea', { class: 'input tpl-editor', rows: 6, maxlength: state.limits.body_max, dir: 'rtl' });
    text.value = t?.body || '🎉 {نام_کامل_گیرنده} عزیز، \n{از_طرف}\n{یادداشت}\n{نام_سایت}';
    const active = switchInput('active', 'فعال (اعضا بتوانند انتخابش کنند)', t ? t.active : true);
    let occ = t?.occasion || occasion;
    const occSelect = h('select', { class: 'input' }, ...state.occasions.map((o) => h('option', { value: o.key, selected: o.key === occ }, `${o.emoji} ${o.label}`)));
    const error = h('div', { class: 'field-error', hidden: true });
    const preview = h('div', { class: 'sms-preview' });
    const counter = h('div', { class: 'muted tiny' });
    const varsBox = h('div', { class: 'tpl-vars' });
    let sample = null;
    const sampleLabel = h('span', { class: 'muted small' }, 'با مقادیر نمونه');

    const panel = emojiPanel((e) => insertAtCursor(text, e));
    panel.hidden = true;

    const drawVars = () => {
      const groups = {};
      for (const v of state.variables) {
        if (v.occasions && !v.occasions.includes(occ)) continue;
        (groups[v.group] ||= []).push(v);
      }
      varsBox.replaceChildren(...Object.entries(groups).map(([group, vars]) => h('div', { class: 'tpl-var-group' },
        h('span', { class: 'muted tiny' }, group),
        h('div', { class: 'row wrap', style: { gap: '4px' } }, ...vars.map((v) => h('button', {
          type: 'button', class: 'chip tpl-chip', title: `{${v.token}} — نمونه: ${v.sample}`,
          onclick: () => insertAtCursor(text, `{${v.token}}`),
        }, v.label))),
      )));
    };
    drawVars();

    const refresh = debounce(async () => {
      try {
        const res = await post('/api/admin/sms-templates/preview', { occasion: occ, body: text.value, person_id: sample?.id || null });
        preview.textContent = res.text;
        counter.textContent = `${fa(res.length)} نویسه • ${fa(res.parts)} بخش پیامک (هر بخش فارسی ۷۰ نویسه)`;
        error.hidden = true;
      } catch (e) {
        error.textContent = e.errors?.body?.[0] || e.message;
        error.hidden = false;
      }
    }, 350);
    text.addEventListener('input', refresh);
    occSelect.addEventListener('change', () => { occ = occSelect.value; drawVars(); refresh(); });
    refresh();

    const chooseSample = async () => {
      const p = await pickPerson({ title: 'پیش‌نمایش برای کدام شخص؟', hint: 'شما فرستنده فرض می‌شوید.' });
      if (!p) return;
      sample = p;
      sampleLabel.textContent = `برای ${fullName(p)} (از طرف شما)`;
      refresh();
    };

    modal({
      title: t ? `ویرایش قالب «${t.title}»` : 'قالب تازه',
      size: 'wide',
      body: h('div', { class: 'tpl-edit' },
        h('div', { class: 'form-grid' },
          h('div', { class: 'field' }, h('label', null, 'عنوان (برای انتخاب اعضا)'), title),
          h('div', { class: 'field' }, h('label', null, 'مناسبت'), occSelect),
        ),
        h('div', { class: 'row between mt-sm', style: { flexWrap: 'wrap', gap: '6px' } },
          h('label', { class: 'label', style: { margin: 0 } }, 'متن پیامک'),
          h('button', { class: 'btn ghost sm', type: 'button', onclick: () => { panel.hidden = !panel.hidden; } }, icon('smile'), 'ایموجی'),
        ),
        panel,
        text,
        error,
        h('div', { class: 'label mt-sm' }, 'متغیرها (بزنید تا در جای مکان‌نما گذاشته شود)'),
        varsBox,
        h('div', { class: 'row between mt', style: { flexWrap: 'wrap', gap: '6px' } },
          h('div', { class: 'label', style: { margin: 0 } }, 'پیش‌نمایش ', sampleLabel),
          h('button', { class: 'btn ghost sm', type: 'button', onclick: chooseSample }, icon('user'), 'پیش‌نمایش با مشخصات واقعی'),
        ),
        preview,
        counter,
        h('div', { class: 'mt-sm' }, active),
      ),
      actions: [
        { label: 'انصراف' },
        { label: 'ذخیره', class: 'primary', icon: 'check', onClick: async () => {
          const payload = { occasion: occ, title: title.value.trim(), body: text.value, active: active.querySelector('input').checked };
          try {
            const res = t ? await put(`/api/admin/sms-templates/${t.id}`, payload) : await post('/api/admin/sms-templates', payload);
            state.data = [...state.data.filter((x) => x.id !== res.data.id), res.data];
            occasion = res.data.occasion;
            tabBar.setValue(occasion);
            renderList();
            toast(res.message);
            return true;
          } catch (e) {
            error.textContent = Object.keys(e.errors || {}).length ? Object.values(e.errors).flat().join(' ') : e.message;
            error.hidden = false;
            return false;
          }
        } },
      ],
    });
  }
}
