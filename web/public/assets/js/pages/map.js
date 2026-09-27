/**
 * نقشه خاندان: محل زندگی اعضا (با اجازه خودشان یا بستگان درجه یک بیننده) و آرامگاه درگذشتگان
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, fullName, lifespan } from '../core/format.js';
import { emptyState, loader, toastError } from '../core/ui.js';
import { mountMap, avatarIcon } from '../components/map.js';
import { countryName } from '../components/labels.js';

export default async function mapPage(container) {
  document.title = `نقشه خاندان | ${store.config.site_name}`;
  const mapEl = h('div', { class: 'family-map' });
  const panel = h('div', { class: 'map-panel card glass' }, loader());
  const page = h('div', { class: 'map-page' }, mapEl, panel);
  container.append(page);

  let points;
  let ctx;
  try {
    [points, ctx] = await Promise.all([get('/api/map').then((r) => r.data), mountMap(mapEl)]);
  } catch (e) {
    toastError(e);
    panel.replaceChildren(emptyState('alert', e.message));
    return;
  }
  const { L, map } = ctx;
  const layers = { home: L.layerGroup().addTo(map), burial: L.layerGroup().addTo(map) };
  const bounds = [];

  for (const p of points) {
    const person = p.person;
    const place = p.kind === 'home'
      ? [p.city, p.country && p.country !== 'IR' ? countryName(p.country) : null].filter(Boolean).join('، ')
      : p.place;
    const popup = h('div', { class: 'map-popup' },
      h('b', null, fullName(person)),
      h('div', { class: 'tiny' }, [p.kind === 'burial' ? 'آرامگاه' : 'محل زندگی', place, lifespan(person)].filter(Boolean).join(' • ')),
      h('div', { class: 'row', style: { gap: '6px', marginTop: '6px' } },
        h('a', { class: 'btn soft sm', href: `#/person/${person.id}` }, 'پروفایل'),
        h('a', { class: 'btn ghost sm', target: '_blank', rel: 'noopener', href: `https://www.google.com/maps/dir/?api=1&destination=${p.lat},${p.lng}` }, 'مسیریابی'),
      ),
    );
    L.marker([p.lat, p.lng], { icon: avatarIcon(L, person, p.kind), title: fullName(person) })
      .bindPopup(popup)
      .addTo(layers[p.kind]);
    bounds.push([p.lat, p.lng]);
  }
  if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });

  const homes = points.filter((p) => p.kind === 'home').length;
  const graves = points.length - homes;
  const toggle = (kind, label, count) => {
    const input = h('input', { type: 'checkbox', checked: true, onchange: (e) => (e.target.checked ? layers[kind].addTo(map) : layers[kind].remove()) });
    return h('label', { class: 'switch' }, input, h('span', { class: 'track' }), h('span', null, `${label} (${fa(count)})`));
  };
  panel.replaceChildren(
    h('h2', null, icon('pin'), ' نقشه خاندان'),
    points.length
      ? h('div', { class: 'stack' }, toggle('home', 'محل زندگی اعضا', homes), toggle('burial', 'آرامگاه درگذشتگان', graves))
      : h('p', { class: 'muted small' }, 'هنوز موقعیتی ثبت نشده است. از صفحه ویرایش پروفایل، موقعیت خانه یا مزار را روی نقشه انتخاب کنید.'),
    h('p', { class: 'muted tiny', style: { marginBottom: 0 } }, icon('lock'), ' خانه هر کس فقط با اجازه خودش (یا برای بستگان درجه یک) نمایش داده می‌شود.'),
  );
}
