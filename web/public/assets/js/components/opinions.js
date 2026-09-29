/**
 * نظرسنجی خاندان درباره یک شخص:
 *  - امتیاز ۱ تا ۵ به ویژگی‌ها (شوخ‌طبعی، کاریزما، مهربانی ...) با نمودار راداری
 *  - نظرهای متنی با نام نویسنده؛ مدیریت (مخفی کردن) توسط خود شخص، بستگان درجه یک و مدیر
 */
import { h, s } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, put, patch, del } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, timeAgo, dateTime } from '../core/format.js';
import { toast, toastError, withLoading, confirmDialog, loader, emptyState } from '../core/ui.js';
import { avatar } from './avatar.js';
import { authorLink } from './author.js';

export function opinionsTab(person) {
  const root = h('div', { class: 'opinions' });
  const ratingsBox = h('div', null, loader());
  const commentsBox = h('div', null, loader());
  root.append(...[
    store.config.ratings?.enabled !== false ? ratingsBox : null,
    store.config.comments_enabled !== false ? commentsBox : null,
  ].filter(Boolean));
  if (store.config.ratings?.enabled !== false) loadRatings(person, ratingsBox);
  if (store.config.comments_enabled !== false) loadComments(person, commentsBox);
  return root;
}

// ------------------------------------------------------------------ امتیازها

async function loadRatings(person, box) {
  let data;
  try {
    data = await get(`/api/persons/${person.id}/ratings`);
  } catch (e) {
    return box.replaceChildren(emptyState('alert', e.message));
  }
  renderRatings(person, box, data);
}

function renderRatings(person, box, data) {
  const rated = data.traits.filter((t) => t.count > 0);
  const pending = {}; // تغییرات امتیاز کاربر که هنوز ذخیره نشده
  const saveBtn = h('button', { class: 'btn primary sm', type: 'button', hidden: true }, icon('check'), 'ثبت امتیازهای من');

  // کسی که نمی‌تواند امتیاز بدهد فقط ویژگی‌های امتیازدار را ببیند (بقیه جمع شوند)
  const visible = data.can_rate ? data.traits : rated;
  const hiddenCount = data.traits.length - visible.length;
  const rows = visible.map((t) => {
    const stars = starInput(t.mine, data.can_rate, (value) => {
      pending[t.key] = value;
      saveBtn.hidden = false;
    });
    return h('div', { class: 'trait-row' },
      h('div', { class: 'trait-name' }, t.label),
      h('div', { class: 'trait-bar', title: t.average ? `میانگین ${fa(t.average)} از ۵` : 'بدون امتیاز' },
        h('i', { style: { width: `${((t.average || 0) / 5) * 100}%` } })),
      h('div', { class: 'trait-avg' }, t.average ? fa(t.average.toFixed(1)) : '—', h('span', { class: 'muted tiny' }, t.count ? ` (${fa(t.count)})` : '')),
      data.can_rate ? stars : null,
    );
  });

  saveBtn.addEventListener('click', () => withLoading(saveBtn, async () => {
    try {
      const res = await put(`/api/persons/${person.id}/ratings`, { scores: pending });
      toast('امتیازهای شما ثبت شد.');
      renderRatings(person, box, res);
    } catch (e) {
      toastError(e);
    }
  }));

  const details = data.raters?.length ? h('details', { class: 'raters' },
    h('summary', null, `امتیازدهندگان (${fa(data.raters.length)} نفر)`),
    ...data.raters.map((r) => h('div', { class: 'rater' },
      authorLink(r.user, { cls: 'bold' }),
      h('div', { class: 'row wrap', style: { gap: '4px' } }, ...Object.entries(r.scores).map(([k, v]) => {
        const label = data.traits.find((t) => t.key === k)?.label || k;
        return h('span', { class: 'chip' }, `${label}: ${fa(v)}`);
      })),
    )),
  ) : null;

  box.replaceChildren(h('section', { class: 'card' },
    h('div', { class: 'card-title' },
      h('h3', null, icon('star'), ' ویژگی‌ها از نگاه خاندان'),
      h('span', { class: 'muted small' }, data.raters_count ? `${fa(data.raters_count)} نفر امتیاز داده‌اند` : 'هنوز کسی امتیاز نداده'),
    ),
    rated.length >= 3 ? radarChart(rated) : null,
    data.can_rate ? h('p', { class: 'muted small' }, icon('info'), ' روی ستاره‌ها بزنید تا امتیاز خودتان (۱ تا ۵) را بدهید؛ دوباره زدن همان ستاره امتیاز را پاک می‌کند. نام شما کنار امتیاز ثبت می‌شود.') : null,
    rows.length ? h('div', { class: 'traits' }, ...rows) : h('p', { class: 'muted small' }, 'هنوز کسی به ویژگی‌های این شخص امتیاز نداده است.'),
    hiddenCount && !data.can_rate && rows.length ? h('p', { class: 'muted tiny' }, `${fa(hiddenCount)} ویژگی دیگر هنوز امتیازی ندارند.`) : null,
    h('div', { class: 'row end mt-sm' }, saveBtn),
    details,
  ));
}

/** ستاره‌های ۱ تا ۵ برای امتیاز دادن */
function starInput(value, enabled, onChange) {
  let current = value || 0;
  const el = h('div', { class: 'stars', role: 'radiogroup', 'aria-label': 'امتیاز شما' });
  const render = () => el.replaceChildren(...[1, 2, 3, 4, 5].map((n) => h('button', {
    type: 'button',
    class: n <= current ? 'on' : '',
    disabled: !enabled,
    title: `${fa(n)} از ۵`,
    'aria-label': `${fa(n)} ستاره`,
    onclick: () => {
      current = current === n ? 0 : n;
      render();
      onChange(current || null);
    },
  }, icon('star'))));
  render();
  return el;
}

/** نمودار راداری میانگین امتیازها (SVG ساده بدون کتابخانه) */
function radarChart(traits) {
  const size = 320;
  const c = size / 2;
  const r = c - 62;
  const n = traits.length;
  const point = (i, v) => {
    const a = -Math.PI / 2 + (i * 2 * Math.PI) / n;
    return [c + Math.cos(a) * r * v, c + Math.sin(a) * r * v];
  };
  const svg = s('svg', { class: 'radar', viewBox: `0 0 ${size} ${size}`, role: 'img', 'aria-label': 'نمودار ویژگی‌ها' });
  for (const level of [0.2, 0.4, 0.6, 0.8, 1]) {
    svg.append(s('polygon', { class: 'radar-grid', points: traits.map((_, i) => point(i, level).join(',')).join(' ') }));
  }
  traits.forEach((t, i) => {
    const [x, y] = point(i, 1);
    svg.append(s('line', { class: 'radar-axis', x1: c, y1: c, x2: x, y2: y }));
    const [lx, ly] = point(i, 1.18);
    svg.append(s('text', { class: 'radar-label', x: lx, y: ly, 'text-anchor': 'middle', 'dominant-baseline': 'middle' }, t.label));
  });
  svg.append(s('polygon', { class: 'radar-area', points: traits.map((t, i) => point(i, t.average / 5).join(',')).join(' ') }));
  traits.forEach((t, i) => {
    const [x, y] = point(i, t.average / 5);
    svg.append(s('circle', { class: 'radar-dot', cx: x, cy: y, r: 3.5 }, s('title', null, `${t.label}: ${fa(t.average)}`)));
  });
  return h('div', { class: 'radar-wrap' }, svg);
}

// ------------------------------------------------------------------ نظرها

async function loadComments(person, box, page = 1, existing = []) {
  let res;
  try {
    res = await get(`/api/persons/${person.id}/comments`, { page });
  } catch (e) {
    return box.replaceChildren(emptyState('alert', e.message));
  }
  const all = [...existing, ...res.data];
  const list = h('div', { class: 'comments' });
  const draw = () => list.replaceChildren(...(all.length ? all.map((c) => commentItem(c, all, draw)) : [h('p', { class: 'muted small' }, 'هنوز نظری ثبت نشده است. اولین نفر باشید!')]));
  draw();

  const composer = res.can?.write ? commentComposer(person, (c) => {
    all.unshift(c);
    draw();
  }) : null;

  box.replaceChildren(h('section', { class: 'card mt' },
    h('div', { class: 'card-title' }, h('h3', null, icon('mail'), ' نظرها و خاطره‌ها'), h('span', { class: 'muted small' }, `${fa(res.meta.total)} نظر`)),
    composer,
    list,
    res.meta.current_page < res.meta.last_page
      ? h('button', { class: 'btn ghost block mt-sm', type: 'button', onclick: () => loadComments(person, box, page + 1, all) }, 'نظرهای بیشتر')
      : null,
  ));
}

function commentComposer(person, onAdded) {
  const max = store.config.comment_max || 3000;
  const area = h('textarea', { class: 'input', rows: 3, maxlength: max, placeholder: `نظر، خاطره یا ویژگی‌ای از ${person.first_name} بنویسید... (نام شما کنار نظر نمایش داده می‌شود)` });
  const send = h('button', { class: 'btn primary sm', type: 'button' }, icon('check'), 'ارسال نظر');
  send.addEventListener('click', () => withLoading(send, async () => {
    if (!area.value.trim()) return;
    try {
      const res = await post(`/api/persons/${person.id}/comments`, { body: area.value });
      area.value = '';
      onAdded(res.data);
    } catch (e) {
      toastError(e);
    }
  }));
  return h('div', { class: 'composer' }, area, h('div', { class: 'row end mt-sm' }, send));
}

function commentItem(c, all, redraw) {
  const bodyEl = h('div', { class: 'c-body' }, c.body);
  const actions = h('div', { class: 'row', style: { gap: '2px' } },
    c.can.edit ? h('button', { class: 'icon-btn', type: 'button', title: 'ویرایش', onclick: () => edit() }, icon('edit')) : null,
    c.can.hide ? h('button', { class: 'icon-btn', type: 'button', title: c.hidden ? 'آشکار کردن' : 'مخفی کردن', onclick: () => toggleHidden() }, icon(c.hidden ? 'eye' : 'eye-off')) : null,
    c.can.delete ? h('button', { class: 'icon-btn', type: 'button', title: 'حذف', onclick: () => remove() }, icon('trash')) : null,
  );
  const item = h('article', { class: `comment ${c.hidden ? 'is-hidden' : ''}` },
    avatar({ first_name: c.author?.name, avatar: c.author?.avatar, gender: c.author?.gender }, 'sm'),
    h('div', { class: 'grow', style: { minWidth: 0 } },
      h('div', { class: 'row between' },
        h('div', null,
          c.author ? h('span', { class: 'comment-author' }, authorLink(c.author, { cls: 'bold' }), c.author.username ? h('span', { class: 'muted tiny' }, ` ${c.author.name}`) : null) : h('b', null, 'کاربر حذف‌شده'),
          ' ', h('span', { class: 'muted tiny', title: dateTime(c.created_at) }, timeAgo(c.created_at)),
          c.edited_at ? h('span', { class: 'muted tiny', title: dateTime(c.edited_at) }, ' • ویرایش‌شده') : null,
          c.hidden ? h('span', { class: 'chip warning', style: { marginInlineStart: '6px' } }, 'مخفی') : null,
        ),
        actions,
      ),
      bodyEl,
    ),
  );

  function edit() {
    const area = h('textarea', { class: 'input', rows: 3 }, c.body);
    const save = h('button', { class: 'btn primary sm', type: 'button' }, 'ذخیره');
    save.addEventListener('click', () => withLoading(save, async () => {
      try {
        const res = await patch(`/api/comments/${c.id}`, { body: area.value });
        Object.assign(c, res.data);
        redraw();
      } catch (e) {
        toastError(e);
      }
    }));
    bodyEl.replaceChildren(area, h('div', { class: 'row end mt-sm', style: { gap: '6px' } }, h('button', { class: 'btn ghost sm', type: 'button', onclick: redraw }, 'انصراف'), save));
  }

  async function toggleHidden() {
    try {
      const res = await post(`/api/comments/${c.id}/hide`, { hidden: !c.hidden });
      Object.assign(c, res.data);
      redraw();
      toast(c.hidden ? 'نظر مخفی شد (فقط نویسنده و ویرایشگران آن را می‌بینند).' : 'نظر دوباره نمایش داده می‌شود.');
    } catch (e) {
      toastError(e);
    }
  }

  async function remove() {
    if (!(await confirmDialog('این نظر حذف شود؟', { danger: true, okLabel: 'حذف' }))) return;
    try {
      await del(`/api/comments/${c.id}`);
      all.splice(all.indexOf(c), 1);
      redraw();
    } catch (e) {
      toastError(e);
    }
  }

  return item;
}
