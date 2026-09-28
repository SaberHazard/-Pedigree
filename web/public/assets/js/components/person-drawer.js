/**
 * کشوی کناری جزئیات شخص (در صفحه درخت)
 */
import { h, fill } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, lifespan, formatDate, age } from '../core/format.js';
import { avatar } from './avatar.js';
import { openRelativeDialog } from './relative-dialog.js';
import { openLightbox } from './lightbox.js';

export function createDrawer(host, { onAction } = {}) {
  const scroll = h('div', { class: 'drawer-scroll' });
  const el = h('aside', { class: 'drawer', 'aria-label': 'جزئیات شخص' },
    h('div', { class: 'row between', style: { padding: '10px 12px 0' } },
      h('span'),
      h('button', { class: 'icon-btn', type: 'button', title: 'بستن', onclick: () => close() }, icon('x')),
    ),
    scroll,
  );
  host.append(el);
  let token = 0;

  function close() {
    el.classList.remove('open');
    onAction?.('drawer-closed');
  }

  async function open(personId, node) {
    const my = ++token;
    el.classList.add('open');
    const p0 = node?.person || { id: personId };
    scroll.replaceChildren(header(p0), h('div', { class: 'skeleton', style: { height: '160px', marginTop: '14px' } }));

    let person;
    try {
      person = (await get(`/api/persons/${personId}`)).data;
    } catch (e) {
      scroll.replaceChildren(h('p', { class: 'muted' }, e.message));
      return;
    }
    if (my !== token) return;

    const me = store.user?.person;
    const facts = [
      ['calendar', 'تولد', [formatDate(person.birth_date), person.birth_place].filter(Boolean).join(' - ')],
      person.is_deceased ? ['flame', 'وفات', [formatDate(person.death_date), person.death_place].filter(Boolean).join(' - ')] : null,
      person.burial_place ? ['pin', 'آرامگاه', person.burial_place] : null,
      ['briefcase', 'شغل', person.occupation],
      ['graduation', 'تحصیلات', person.education],
      ['home', 'سکونت', person.residence],
    ].filter((f) => f && f[2]);

    const relationEl = h('div');
    const galleryEl = h('div');
    const canEdit = person.permissions?.edit;

    fill(scroll,
      header(person),
      relationEl,
      facts.length ? h('dl', { class: 'kv', style: { gridTemplateColumns: '1fr', marginTop: '14px' } },
        ...facts.map(([ic, k, v]) => h('div', null, h('dt', null, icon(ic), k), h('dd', null, fa(v)))),
      ) : null,
      person.biography ? h('p', { class: 'small text-2', style: { marginTop: '12px', whiteSpace: 'pre-line' } }, person.biography.length > 280 ? person.biography.slice(0, 280) + '…' : person.biography) : null,
      h('div', { class: 'grid grid-2', style: { gap: '8px', marginTop: '14px' } },
        h('a', { class: 'btn soft sm', href: `#/person/${person.id}` }, icon('user'), 'پروفایل کامل'),
        store.user && person.account?.active && store.user.person?.id !== person.id ? h('button', { class: 'btn sm', type: 'button', onclick: () => import('../pages/messages.js').then((m) => m.startConversation(person)) }, icon('chat'), 'پیام') : null,
        canEdit ? h('a', { class: 'btn sm', href: `#/person/${person.id}/edit` }, icon('edit'), 'ویرایش') : null,
        canEdit ? h('button', { class: 'btn sm', type: 'button', onclick: () => openRelativeDialog(person, 'child', { onDone: () => onAction?.('reload') }) }, icon('user-plus'), 'افزودن بستگان') : null,
        h('button', { class: 'btn sm', type: 'button', onclick: () => navigate(`/tree/${person.id}?mode=descendants`) }, icon('tree'), 'نوادگان'),
        h('button', { class: 'btn sm', type: 'button', onclick: () => navigate(`/tree/${person.id}?mode=ancestors`) }, icon('ancestors'), 'نیاکان'),
        h('button', { class: 'btn sm', type: 'button', onclick: () => navigate(`/tree/${person.id}?mode=hourglass`) }, icon('hourglass'), 'ساعت شنی'),
        h('button', { class: 'btn sm', type: 'button', onclick: () => onAction?.('lineage-to', person.id) }, icon('route'), 'مسیر از ریشه'),
        h('button', { class: 'btn sm', type: 'button', title: 'چاپ یا PDF از این شخص به پایین (یا نیاکانش)', onclick: () => onAction?.('export', person.id) }, icon('printer'), 'چاپ از این شخص'),
      ),
      galleryEl,
    );

    // نسبت با کاربر
    if (me && me.id !== person.id) {
      get(`/api/persons/${me.id}/relationship/${person.id}`).then((r) => {
        if (my !== token || !r.found) return;
        relationEl.replaceChildren(h('div', { class: 'chip accent', style: { marginTop: '10px' } }, icon('link'), `نسبت با شما: ${r.label}`));
      }).catch(() => {});
    }

    // گالری کوچک
    get(`/api/persons/${person.id}/media`).then((res) => {
      if (my !== token) return;
      const items = res.data.filter((m) => m.status === 'approved');
      if (!items.length) return;
      galleryEl.replaceChildren(
        h('div', { class: 'section-title', style: { margin: '18px 0 10px' } }, icon('image'), `عکس‌ها و ویدیوها (${fa(items.length)})`),
        h('div', { class: 'gallery', style: { gridTemplateColumns: 'repeat(3, 1fr)' } },
          ...items.slice(0, 6).map((m, i) => h('div', { class: 'g-item', onclick: () => openLightbox(items, i) },
            m.urls?.thumb ? h('img', { src: m.urls.thumb, alt: m.caption || '', loading: 'lazy' }) : icon('video'),
            m.type === 'video' ? h('div', { class: 'g-play' }, icon('play')) : null,
          )),
        ),
      );
    }).catch(() => {});
  }

  function header(p) {
    const a = age(p);
    return h('div', { class: 'center', style: { paddingTop: '4px' } },
      h('div', { style: { display: 'inline-block', position: 'relative' } }, avatar(p, 'xl')),
      h('h2', { style: { margin: '12px 0 2px' } }, fullName(p) || '...'),
      p.nickname ? h('div', { class: 'muted small' }, `(${p.nickname})`) : null,
      h('div', { class: 'row wrap', style: { justifyContent: 'center', marginTop: '6px' } },
        p.gender ? h('span', { class: `chip ${p.gender === 'f' ? 'female' : 'male'}` }, p.gender === 'f' ? 'زن' : 'مرد') : null,
        p.is_deceased ? h('span', { class: 'chip' }, icon('ribbon'), 'شادروان') : null,
        lifespan(p) ? h('span', { class: 'chip primary' }, lifespan(p)) : null,
        a !== null && a !== undefined ? h('span', { class: 'chip' }, p.is_deceased ? `${fa(a)} سال عمر` : `${fa(a)} ساله`) : null,
      ),
    );
  }

  return { el, open, close, isOpen: () => el.classList.contains('open') };
}
