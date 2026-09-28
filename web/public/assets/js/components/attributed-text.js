/**
 * متن‌های رنگی پروفایل: هر تکه با رنگ نویسنده‌اش نمایش داده می‌شود.
 *
 * رنگ‌ها: خود شخص = رنگ اصلی سایت، مدیر = مشکی، بقیه = پالت رنگی (قرمز، سبز، آبی ...)
 * در انتهای بخش «درباره» راهنمای کوچک رنگ‌ها (legend) نمایش داده می‌شود.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, put, post } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, timeAgo, dateTime } from '../core/format.js';
import { modal, toast, toastError, withLoading, confirmDialog, loader, emptyState } from '../core/ui.js';

const PALETTE_SIZE = 10;

/** متغیر CSS رنگ یک نویسنده */
export function authorColor(contributor) {
  if (!contributor) return 'var(--author-unknown)';
  if (contributor.color === 'owner') return 'var(--author-owner)';
  if (contributor.color === 'admin') return 'var(--author-admin)';
  return `var(--author-${Number(contributor.color) % PALETTE_SIZE})`;
}

export function contributorLabel(c) {
  if (!c) return 'نامشخص';
  const role = c.color === 'admin' ? 'مدیر' : c.relation;
  return role && role !== 'خود شخص' ? `${c.name} (${role})` : c.name + (role ? ' (خود شخص)' : '');
}

/** نمایش تکه‌ها با رنگ نویسنده */
export function renderSegments(segments, byId, { colored = true } = {}) {
  const box = h('div', { class: 'attributed' });
  for (const [author, text] of segments) {
    const c = byId.get(author);
    box.append(h('span', {
      class: colored ? 'seg' : '',
      style: colored ? { '--c': authorColor(c) } : null,
      title: colored ? `نوشته ${contributorLabel(c)}` : null,
    }, text));
  }
  return box;
}

/** راهنمای رنگ‌ها: «■ علی (پسر)  ■ مریم (دختر)  ■ مدیر» */
export function legend(contributors, usedIds = null) {
  const list = contributors.filter((c) => !usedIds || usedIds.has(c.id));
  if (!list.length) return null;
  return h('div', { class: 'author-legend', 'aria-label': 'راهنمای رنگ نویسندگان' },
    h('span', { class: 'muted' }, icon('palette'), ' نویسندگان:'),
    ...list.map((c) => h('span', { class: 'legend-item' }, h('i', { style: { background: authorColor(c) } }), contributorLabel(c))),
  );
}

/**
 * یک بخش متنی قابل ویرایش (بیوگرافی، توضیحات، زندگی‌نامه، رزومه متنی)
 *
 * @param {object} ctx  { person, byId: Map, colored: () => bool, onContributors: (list) => void, onUsed: (ids) => void }
 */
export function textSection(field, ctx) {
  const { person } = ctx;
  const meta = store.config.profile?.texts?.[field] || { label: field, max: 20000 };
  const canEdit = !!person.permissions?.edit;
  let state = person.texts?.[field] || { segments: [], revision: 0 };

  const body = h('div');
  const head = h('div', { class: 'card-title' },
    h('h3', null, icon(field === 'summary' ? 'sparkles' : field === 'resume' ? 'briefcase' : 'book'), ' ', meta.label),
    h('div', { class: 'row', style: { gap: '4px' } },
      state.revision ? h('button', { class: 'btn ghost sm', type: 'button', title: 'نسخه‌های قبلی و نویسندگان', onclick: () => openRevisions(field, meta.label, ctx, (next) => { state = next; show(); }) }, icon('history'), h('span', { class: 'hide-mobile' }, 'نسخه‌ها')) : null,
      canEdit ? h('button', { class: 'btn soft sm', type: 'button', onclick: () => edit() }, icon('edit'), state.segments.length ? 'ویرایش' : 'نوشتن') : null,
    ),
  );
  const card = h('section', { class: `card text-card text-${field}` }, head, body);

  function show() {
    ctx.onUsed?.(state.segments.map((s) => s[0]));
    if (!state.segments.length) {
      body.replaceChildren(h('p', { class: 'muted small', style: { margin: 0 } }, canEdit ? placeholder(field) : 'هنوز چیزی نوشته نشده است.'));
      card.classList.toggle('empty-text', true);
      return;
    }
    card.classList.toggle('empty-text', false);
    body.replaceChildren(...[
      renderSegments(state.segments, ctx.byId, { colored: ctx.colored() }),
      state.updated_at ? h('div', { class: 'muted tiny mt-sm' }, `آخرین تغییر ${timeAgo(state.updated_at)}`) : null,
    ].filter(Boolean));
  }

  function edit(draft = null) {
    const plain = state.segments.map((s) => s[1]).join('');
    const area = h('textarea', { class: 'input', rows: field === 'summary' ? 4 : 10, maxlength: meta.max, dir: 'auto' }, draft ?? plain);
    const counter = h('span', { class: 'muted tiny' });
    const updateCounter = () => { counter.textContent = `${fa(area.value.length)} / ${fa(meta.max)}`; };
    area.addEventListener('input', updateCounter);
    updateCounter();
    const save = h('button', { class: 'btn primary sm', type: 'button' }, icon('check'), 'ذخیره');
    save.addEventListener('click', () => withLoading(save, async () => {
      try {
        const res = await put(`/api/persons/${person.id}/texts/${field}`, { text: area.value, base_revision: state.revision });
        state = res.data;
        ctx.onContributors?.(res.contributors);
        toast('ذخیره شد. کلمه‌هایی که شما نوشتید یا تغییر دادید به نام شما ثبت شد.');
        show();
      } catch (e) {
        if (e.status === 409) return conflict(area.value);
        toastError(e);
      }
    }));
    body.replaceChildren(
      area,
      h('div', { class: 'row between mt-sm' },
        h('div', { class: 'muted tiny' }, icon('info'), ' فقط کلمه‌هایی که تغییر دهید به نام شما ثبت می‌شود؛ بقیه به نام نویسنده قبلی می‌ماند.'),
        h('div', { class: 'row', style: { gap: '6px' } }, counter, h('button', { class: 'btn ghost sm', type: 'button', onclick: show }, 'انصراف'), save),
      ),
    );
    area.focus();
  }

  /** کس دیگری هم‌زمان متن را تغییر داده: متن تازه بارگذاری و پیش‌نویس کاربر حفظ می‌شود */
  async function conflict(draft) {
    try {
      const fresh = (await get(`/api/persons/${person.id}`)).data;
      state = fresh.texts?.[field] || { segments: [], revision: 0 };
      ctx.onContributors?.(fresh.contributors);
    } catch (e) {
      return toastError(e);
    }
    modal({
      title: 'متن هم‌زمان تغییر کرده است',
      body: h('div', null,
        h('p', null, 'در همین فاصله یکی دیگر از بستگان این متن را ویرایش کرده است. متن تازه نمایش داده شد تا تغییرات او از بین نرود. نوشته شما اینجاست؛ آن را کپی و دوباره اعمال کنید:'),
        h('textarea', { class: 'input', rows: 8, readonly: true }, draft),
      ),
      actions: [{ label: 'ویرایش متن تازه', class: 'primary', onClick: () => edit() }],
    });
    show();
  }

  show();
  return card;
}

function placeholder(field) {
  return {
    summary: 'بیوگرافی کوتاه (چند خط) درباره این شخص بنویسید: چه کسی بود/هست، چه کرد و به چه شناخته می‌شود.',
    description: 'بستگان درجه یک هر توضیحی درباره این شخص دارند اینجا بنویسند؛ نام نویسنده هر بخش با رنگ مشخص می‌شود.',
    biography: 'زندگی‌نامه، خاطرات و داستان‌های این شخص را بنویسید.',
    resume: 'رزومه متنی (در کنار سوابق ساختاریافته پایین صفحه).',
  }[field] || 'بنویسید...';
}

/** پنجره نسخه‌ها: چه کسی، کی، چند کلمه اضافه/حذف کرد + پیش‌نمایش رنگی و بازگردانی */
async function openRevisions(field, label, ctx, onRestored) {
  const { person } = ctx;
  const list = h('div', null, loader());
  const preview = h('div', { class: 'revision-preview' });
  const dialog = modal({ title: `نسخه‌های «${label}»`, size: 'wide', body: h('div', { class: 'revisions' }, list, preview) });

  let rows;
  try {
    rows = (await get(`/api/persons/${person.id}/texts/${field}/revisions`)).data;
  } catch (e) {
    return list.replaceChildren(emptyState('alert', e.message));
  }
  list.replaceChildren(h('div', { class: 'timeline' }, ...rows.map((r) => h('div', { class: 't-item' },
    h('div', null,
      h('b', null, r.user?.name || 'نامشخص'),
      ' ', r.restored_from ? `نسخه ${fa(r.restored_from)} را بازگرداند` : `نسخه ${fa(r.revision)}`,
      r.current ? h('span', { class: 'chip primary', style: { marginInlineStart: '6px' } }, 'فعلی') : null,
    ),
    h('div', { class: 'muted tiny' }, dateTime(r.created_at), ' • ',
      h('span', { class: 'added' }, `+${fa(r.added)} کلمه`), ' ', h('span', { class: 'removed' }, `−${fa(r.removed)} کلمه`)),
    h('div', { class: 'row', style: { gap: '6px', marginTop: '4px' } },
      h('button', { class: 'btn ghost sm', type: 'button', onclick: () => showRevision(r.revision) }, icon('eye'), 'مشاهده'),
      !r.current && person.permissions?.edit ? h('button', { class: 'btn ghost sm', type: 'button', onclick: (e) => restore(r.revision, e.currentTarget) }, icon('refresh'), 'بازگردانی') : null,
    ),
  ))));

  async function showRevision(revision) {
    preview.replaceChildren(loader());
    try {
      const res = await get(`/api/persons/${person.id}/texts/${field}/revisions/${revision}`);
      const byId = new Map(res.contributors.map((c) => [c.id, c]));
      preview.replaceChildren(
        h('h4', null, `متن نسخه ${fa(revision)}`),
        renderSegments(res.segments, byId),
        legend(res.contributors),
      );
      preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
      preview.replaceChildren(emptyState('alert', e.message));
    }
  }

  async function restore(revision, button) {
    if (!(await confirmDialog(`متن به نسخه ${fa(revision)} برگردد؟ (نسخه فعلی هم در تاریخچه می‌ماند)`))) return;
    await withLoading(button, async () => {
      try {
        const res = await post(`/api/persons/${person.id}/texts/${field}/revisions/${revision}/restore`);
        ctx.onContributors?.(res.contributors);
        onRestored(res.data);
        toast('نسخه قبلی بازگردانده شد.');
        dialog.close();
      } catch (e) {
        toastError(e);
      }
    });
  }
}
