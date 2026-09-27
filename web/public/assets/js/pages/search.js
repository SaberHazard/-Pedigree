/**
 * جستجو (مخصوص موبایل؛ در دسکتاپ جستجو در هدر است)
 */
import { h } from '../core/dom.js';
import { navigate } from '../core/router.js';
import { searchBox } from '../components/person-search.js';

export default function searchPage(container) {
  container.append(h('div', { class: 'page narrow' },
    h('h1', null, 'جستجو در شجره‌نامه'),
    h('div', { class: 'card' }, searchBox({ inline: true, autofocus: true, placeholder: 'نام، نام خانوادگی یا کد شخص...', onSelect: (p) => navigate(`/person/${p.id}`) })),
  ));
}
