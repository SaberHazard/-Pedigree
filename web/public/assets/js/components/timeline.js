/**
 * «زندگی در گذر زمان»: خط زمان زندگی یک شخص با رویدادهای خانوادگی (تولد، ازدواج، فرزندان، نوه‌ها، درگذشت بستگان)
 * و رویدادهای مهم تاریخی ایران و جهان، همراه با سن شخص در آن زمان.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { fa, formatDate } from '../core/format.js';
import { loader, emptyState } from '../core/ui.js';

const KIND_ICON = {
  birth: 'baby', death: 'flame', marriage: 'heart', divorce: 'unlink', child: 'baby', grandchild: 'sparkles',
  sibling: 'users', parent_death: 'flame', spouse_death: 'flame', child_death: 'flame', sibling_death: 'flame', child_marriage: 'heart',
};
const CATEGORY_ICON = { iran: 'flag', world: 'compass', science: 'sparkles', disaster: 'alert' };

export function timelineTab(person) {
  const box = h('div', { class: 'timeline-tab' }, loader());
  load();

  async function load() {
    let data;
    try {
      data = (await get(`/api/persons/${person.id}/timeline`)).data;
    } catch (e) {
      box.replaceChildren(emptyState('alert', e.message));
      return;
    }
    const filters = { family: true, iran: true, world: true, science: true, disaster: true };
    const list = h('ol', { class: 'tl-list' });
    const chips = h('div', { class: 'tl-filters', role: 'group', 'aria-label': 'فیلتر رویدادها' });
    const labels = { family: 'خانواده', ...data.categories };
    const counts = data.events.reduce((acc, e) => {
      const k = e.kind === 'history' ? e.category : 'family';
      acc[k] = (acc[k] || 0) + 1;
      return acc;
    }, {});

    const drawChips = () => chips.replaceChildren(...Object.keys(labels).filter((k) => counts[k]).map((k) => h('button', {
      type: 'button',
      class: `chip tl-chip ${k}${filters[k] ? ' on' : ''}`,
      'aria-pressed': String(filters[k]),
      onclick: () => { filters[k] = !filters[k]; drawChips(); draw(); },
    }, icon(k === 'family' ? 'users' : CATEGORY_ICON[k] || 'star'), `${labels[k]} (${fa(counts[k])})`)));

    function draw() {
      const events = data.events.filter((e) => filters[e.kind === 'history' ? e.category : 'family']);
      list.replaceChildren(...events.map((e) => {
        const history = e.kind === 'history';
        return h('li', { class: `tl-item ${history ? `history ${e.category}` : `family ${e.kind}`}` },
          h('div', { class: 'tl-when' },
            h('b', null, fa(e.year)),
            e.age !== null ? h('span', { class: 'tl-age' }, e.age === 0 && e.kind !== 'birth' ? 'نوزاد' : e.kind === 'birth' ? '' : `${e.approx ? 'حدود ' : ''}${fa(e.age)} سالگی`) : null),
          h('span', { class: 'tl-dot', 'aria-hidden': 'true' }, icon(history ? CATEGORY_ICON[e.category] || 'star' : KIND_ICON[e.kind] || 'star')),
          h('div', { class: 'tl-body' },
            e.person ? h('a', { href: `#/person/${e.person.id}`, class: 'tl-title' }, e.title) : h('div', { class: 'tl-title' }, e.title),
            h('div', { class: 'tiny muted' }, e.date.length > 4 ? formatDate(e.date) : `سال ${fa(e.year)}`, history ? ` • ${data.categories[e.category] || ''}` : '')),
        );
      }));
      if (!events.length) list.replaceChildren(h('li', null, emptyState('calendar', 'رویدادی با این فیلتر نیست.')));
    }

    const family = counts.family || 0;
    const historyCount = data.events.length - family;
    box.replaceChildren(
      h('div', { class: 'card tl-head' },
        h('div', null,
          h('h3', { style: { margin: 0 } }, 'زندگی در گذر زمان'),
          h('p', { class: 'muted small', style: { margin: '4px 0 0' } }, data.has_birth
            ? `${fa(family)} رویداد خانوادگی و ${fa(historyCount)} رویداد تاریخی که ${person.is_deceased ? 'در طول زندگی‌اش رخ داد' : 'تا امروز شاهدش بوده است'}.`
            : 'برای دیدن رویدادهای تاریخی هم‌زمان با زندگی، تاریخ تولد را در پروفایل ثبت کنید.')),
        chips),
      data.events.length ? h('div', { class: 'card tl-card' }, list) : emptyState('calendar', 'هنوز رویداد تاریخ‌داری برای این شخص ثبت نشده است.'),
    );
    drawChips();
    draw();
  }

  return box;
}
