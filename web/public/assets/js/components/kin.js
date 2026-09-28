/**
 * «چه کسانی شماره/نشانی مرا می‌بینند؟»
 *
 * - visibilityField: انتخاب سطح نمایش (همه اعضا / بستگان تا درجه ۴..۱ / فقط خودم) با دکمه «چه کسانی؟»
 * - openKinDialog: فهرست دقیق بستگان هر درجه با نام نسبت (عمو، پسرخاله، پدرزن ...)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, fullName } from '../core/format.js';
import { modal, loader, toastError, tabs } from '../core/ui.js';
import { avatar } from './avatar.js';

const DEGREE_HELP = {
  1: 'پدر، مادر، همسر، فرزندان، خواهر و برادر، و عروس و داماد',
  2: 'پدربزرگ و مادربزرگ، نوه‌ها، عمو، عمه، دایی، خاله، برادرزاده و خواهرزاده؛ و پدر و مادر و خواهر و برادرِ همسر (پدرزن، مادرشوهر ...) و زن‌برادر و شوهرخواهر',
  3: 'فرزندانِ عمو، عمه، دایی و خاله؛ پدربزرگِ پدر و مادر؛ عمو و خاله‌ی پدر و مادر؛ نتیجه‌ها؛ زن‌عمو، شوهرخاله و ...',
  4: 'نوه‌های عمو، عمه، دایی و خاله؛ فرزندانِ عموزاده‌ها و ...',
};

export function levelMax(level) {
  return { d4: 4, d3: 3, d2: 2, d1: 1, self: 0 }[level] ?? null;
}

/**
 * @param {string} name       contact_visibility | location_visibility
 * @param {string} value
 * @param {object} person     برای دکمه «چه کسانی؟» (شخص ذخیره‌شده)
 * @param {string} what       «شماره و راه‌های ارتباطی» یا «نشانی»
 */
export function visibilityField(name, value, person, what) {
  const levels = store.config.profile?.visibility_levels || {};
  const select = h('select', { class: 'input', name },
    ...Object.entries(levels).map(([k, label]) => h('option', { value: k, selected: k === value }, label)));
  const help = h('div', { class: 'hint' });
  const whoBtn = person?.id ? h('button', { class: 'btn ghost sm', type: 'button', onclick: () => openKinDialog(person, levelMax(select.value) || 4, what) }, icon('users'), 'دقیقاً چه کسانی؟') : null;
  const render = () => {
    const max = levelMax(select.value);
    if (select.value === 'all') help.textContent = `همه اعضای شجره‌نامه ${what} را می‌بینند.`;
    else if (max === 0) help.textContent = `فقط خودتان و مدیر سایت ${what} را می‌بینید.`;
    else help.textContent = `درجه ${fa(max)} یعنی: ${DEGREE_HELP[max]}${max > 1 ? ' — و همه درجه‌های نزدیک‌تر.' : '.'}`;
    if (whoBtn) whoBtn.hidden = !max;
  };
  select.addEventListener('change', render);
  render();
  return h('div', { class: 'field full visibility-field' },
    h('label', null, icon('eye'), ` چه کسانی ${what} را ببینند؟`),
    h('div', { class: 'row wrap', style: { gap: '8px', alignItems: 'center' } }, h('div', { class: 'grow', style: { minWidth: '220px' } }, select), whoBtn),
    help,
  );
}

/** فهرست بستگان تا درجه max */
export function openKinDialog(person, max = 4, what = '') {
  const body = h('div', { class: 'kin-dialog' }, loader());
  const dlg = modal({ title: `بستگان ${fullName(person)} تا درجه ${fa(max)}`, body, size: 'wide' });

  get(`/api/persons/${person.id}/kin`, { max }).then((res) => {
    const groups = res.data || [];
    if (!groups.length) {
      body.replaceChildren(h('p', { class: 'muted' }, 'هنوز بستگانی در شجره‌نامه به این شخص وصل نشده است.'));
      return;
    }
    const list = h('div', { class: 'kin-list' });
    const show = (degree) => {
      const g = groups.find((x) => x.degree === degree) || { people: [] };
      list.replaceChildren(
        h('p', { class: 'muted small' }, DEGREE_HELP[degree] || ''),
        ...g.people.map((r) => h('a', { class: 'kin-row', href: `#/person/${r.person.id}`, onclick: () => dlg.close() },
          avatar(r.person, 'sm'),
          h('div', { class: 'grow', style: { minWidth: 0 } },
            h('div', { class: 'bold ellipsis' }, fullName(r.person)),
            h('div', { class: 'muted small' }, r.label, r.inlaw ? ' (سببی)' : ''),
          ),
          r.person.is_deceased ? h('span', { class: 'chip' }, 'درگذشته') : r.has_account ? h('span', { class: 'chip success' }, 'عضو سایت') : h('span', { class: 'chip' }, 'بدون حساب'),
        )),
      );
    };
    const items = groups.map((g) => ({ value: g.degree, label: `درجه ${fa(g.degree)} (${fa(g.count)})` }));
    body.replaceChildren(
      what ? h('p', { class: 'small' }, icon('info'), ` اگر «تا درجه ${fa(max)}» را انتخاب کنید، همه افراد زیر (${fa(res.total)} نفر) ${what} را می‌بینند؛ مدیر سایت هم همیشه می‌بیند.`) : null,
      tabs(items, groups[0].degree, show),
      list,
    );
    show(groups[0].degree);
  }).catch((e) => {
    body.replaceChildren(h('p', { class: 'muted' }, 'بارگذاری ممکن نشد.'));
    toastError(e);
  });
}
