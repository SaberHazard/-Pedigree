/**
 * صفحه پروفایل شخص: مشخصات، گالری، بستگان، تاریخچه تغییرات
 */
import { h, s } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, upload } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, lifespan, formatDate, age, timeAgo, dateTime } from '../core/format.js';
import { tabs, toast, toastError, emptyState, loader } from '../core/ui.js';
import { shareLink } from '../core/native.js';
import { avatar } from '../components/avatar.js';
import { gallery } from '../components/gallery.js';
import { openRelativeDialog } from '../components/relative-dialog.js';
import { cropImage } from '../components/cropper.js';
import { actionLabel, FIELDS, MARRIAGE_STATUS } from '../components/labels.js';
import { buildDefs, buildNode } from '../tree/node.js';
import { geometry } from '../tree/layout.js';

export default async function personPage(container, { params }) {
  const page = h('div', { class: 'page' }, loader());
  container.append(page);

  let person;
  try {
    person = (await get(`/api/persons/${params.id}`)).data;
  } catch (e) {
    page.replaceChildren(emptyState('alert', e.message, h('a', { class: 'btn', href: '#/' }, 'بازگشت')));
    return;
  }
  document.title = `${fullName(person)} | ${store.config.site_name}`;

  const perms = person.permissions || {};
  const tabBody = h('div');
  const active = ['details', 'gallery', 'relatives', 'history'].includes(params.tab) ? params.tab : 'details';

  page.replaceChildren(
    hero(),
    tabs([
      { value: 'details', label: 'مشخصات', icon: 'id-card' },
      { value: 'gallery', label: 'عکس‌ها و ویدیوها', icon: 'image' },
      { value: 'relatives', label: 'بستگان', icon: 'users' },
      perms.edit || store.user?.person?.id === person.id ? { value: 'history', label: 'تاریخچه', icon: 'history' } : null,
    ].filter(Boolean), active, (tab) => {
      history.replaceState(null, '', `#/person/${person.id}${tab === 'details' ? '' : '/' + tab}`);
      show(tab);
    }),
    tabBody,
  );
  show(active);

  // ------------------------------------------------------------ سربرگ
  function hero() {
    const g = geometry(false);
    const svg = s('svg', { width: 200, height: 200, viewBox: '-100 -100 200 200' });
    svg.append(buildDefs(g, 'pp'));
    const node = buildNode({ key: person.id, id: person.id, person: { ...person, avatar: person.avatar_medium || person.avatar }, kind: 'blood', isRoot: false }, g, {
      idPrefix: 'pp', interactive: false, prefs: { ...store.prefs, arc_bottom: 'dates' },
    });
    node.setAttribute('transform', 'scale(1.3)');
    svg.append(node);

    const avatarWrap = h('div', { class: 'ph-avatar' }, svg);
    if (perms.upload) {
      const fileInput = h('input', { type: 'file', accept: 'image/*', hidden: true });
      fileInput.addEventListener('change', async () => {
        const file = fileInput.files[0];
        fileInput.value = '';
        if (!file) return;
        const blob = await cropImage(file);
        if (!blob) return;
        const form = new FormData();
        form.append('file', blob, 'avatar.jpg');
        try {
          const res = await upload(`/api/persons/${person.id}/avatar`, form);
          toast(res.message, res.data.status === 'approved' ? 'success' : 'info', 6000);
          if (res.data.status === 'approved') navigate(`/person/${person.id}`, { replace: true });
        } catch (e) {
          toastError(e);
        }
      });
      avatarWrap.append(fileInput, h('button', {
        class: 'btn primary sm icon-only', type: 'button', title: 'تغییر عکس پروفایل',
        style: { position: 'absolute', bottom: '26px', insetInlineStart: '26px', borderRadius: '50%' },
        onclick: () => fileInput.click(),
      }, icon('camera')));
    }

    const a = age(person);
    return h('div', { class: `profile-hero ${person.gender === 'f' ? 'female' : ''} ${person.is_deceased ? 'dead' : ''}` },
      h('div', { class: 'cover' }, pattern()),
      h('div', { class: 'ph-body' },
        avatarWrap,
        h('div', { class: 'ph-info' },
          h('h1', null, fullName(person)),
          h('div', { class: 'row wrap', style: { marginTop: '6px' } },
            person.nickname ? h('span', { class: 'chip' }, `«${person.nickname}»`) : null,
            h('span', { class: `chip ${person.gender === 'f' ? 'female' : 'male'}` }, person.gender === 'f' ? 'زن' : 'مرد'),
            person.is_deceased ? h('span', { class: 'chip' }, icon('ribbon'), 'شادروان') : null,
            lifespan(person) ? h('span', { class: 'chip primary' }, lifespan(person, { full: true })) : null,
            a !== null ? h('span', { class: 'chip' }, person.is_deceased ? `${fa(a)} سال عمر` : `${fa(a)} ساله`) : null,
            person.is_locked ? h('span', { class: 'chip warning' }, icon('lock'), 'قفل') : null,
            person.account?.active ? h('span', { class: 'chip success' }, icon('check'), 'عضو فعال') : null,
            h('span', { class: 'chip', title: 'کد شناسه یکتا' }, `کد: ${person.code}`),
          ),
        ),
        h('div', { class: 'ph-actions' },
          h('a', { class: 'btn soft', href: `#/tree/${person.id}?mode=hourglass` }, icon('tree'), 'درخت'),
          perms.edit ? h('a', { class: 'btn', href: `#/person/${person.id}/edit` }, icon('edit'), 'ویرایش') : null,
          perms.edit ? h('button', { class: 'btn', type: 'button', onclick: () => openRelativeDialog(person, 'child', { onDone: () => show('relatives') }) }, icon('user-plus'), 'افزودن بستگان') : null,
          h('button', { class: 'btn ghost icon-only', type: 'button', title: 'اشتراک‌گذاری', onclick: share }, icon('share')),
        ),
      ),
    );
  }

  async function share() {
    const url = location.href.split('#')[0] + `#/person/${person.id}`;
    const r = await shareLink(fullName(person), url);
    if (r === 'copied') toast('لینک کپی شد.');
  }

  function pattern() {
    const svg = s('svg', { class: 'pattern', 'aria-hidden': 'true' });
    svg.innerHTML = '<defs><pattern id="pp-pat" width="46" height="46" patternUnits="userSpaceOnUse"><path d="M23 0 L29 17 L46 23 L29 29 L23 46 L17 29 L0 23 L17 17 Z" fill="none" stroke="#fff" stroke-width="1"/><circle cx="23" cy="23" r="4" fill="none" stroke="#fff"/></pattern></defs><rect width="100%" height="100%" fill="url(#pp-pat)"/>';
    return svg;
  }

  // ------------------------------------------------------------ تب‌ها
  async function show(tab) {
    tabBody.replaceChildren(loader());
    if (tab === 'details') tabBody.replaceChildren(details());
    else if (tab === 'gallery') tabBody.replaceChildren(gallery(person, { onChange: () => navigate(`/person/${person.id}/gallery`, { replace: true }) }));
    else if (tab === 'relatives') tabBody.replaceChildren(await relatives());
    else if (tab === 'history') tabBody.replaceChildren(await historyTab());
  }

  function details() {
    const rows = [
      ['calendar', 'تاریخ تولد', formatDate(person.birth_date)],
      ['pin', 'محل تولد', person.birth_place],
      person.is_deceased ? ['flame', 'تاریخ وفات', formatDate(person.death_date)] : null,
      person.is_deceased ? ['pin', 'محل وفات', person.death_place] : null,
      person.is_deceased ? ['star', 'آرامگاه', person.burial_place] : null,
      ['briefcase', 'شغل', person.occupation],
      ['graduation', 'تحصیلات', person.education],
      ['home', 'محل سکونت', person.residence],
      ['users', 'ترتیب تولد', person.birth_order ? `فرزند ${fa(person.birth_order)}` : null],
      person.national_code ? ['id-card', 'کد ملی', fa(person.national_code)] : null,
      person.birth_cert_no ? ['file', 'شماره شناسنامه', fa(person.birth_cert_no) + (person.birth_cert_place ? ` (صادره از ${person.birth_cert_place})` : '')] : null,
      person.phone ? ['phone', 'موبایل', fa(person.phone)] : null,
      person.email ? ['mail', 'ایمیل', person.email] : null,
    ].filter((r) => r && r[2]);

    return h('div', null,
      rows.length
        ? h('dl', { class: 'kv' }, ...rows.map(([ic, k, v]) => h('div', null, h('dt', null, icon(ic), k), h('dd', null, v))))
        : emptyState('info', 'هنوز مشخصاتی ثبت نشده است.', perms.edit ? h('a', { class: 'btn soft', href: `#/person/${person.id}/edit` }, 'تکمیل مشخصات') : null),
      person.biography ? h('div', { class: 'card mt' }, h('h3', null, icon('book'), ' زندگی‌نامه و خاطرات'), h('p', { style: { whiteSpace: 'pre-line', margin: 0 } }, person.biography)) : null,
      h('p', { class: 'muted tiny mt' }, [person.created_by ? `ثبت توسط ${person.created_by}` : null, person.updated_at ? `آخرین به‌روزرسانی ${timeAgo(person.updated_at)}` : null].filter(Boolean).join(' • ')),
    );
  }

  async function relatives() {
    let r;
    try {
      r = await get(`/api/persons/${person.id}/relatives`);
    } catch (e) {
      return emptyState('alert', e.message);
    }
    const card = (p, extra) => h('div', { class: 'person-card', onclick: () => navigate(`/person/${p.id}`) },
      avatar(p, 'sm'),
      h('div', { class: 'grow', style: { minWidth: 0 } },
        h('div', { class: 'bold ellipsis' }, fullName(p)),
        h('div', { class: 'muted tiny ellipsis' }, [extra, lifespan(p)].filter(Boolean).join(' • ')),
      ),
    );
    const addCard = (type, label) => (perms.edit ? h('div', { class: 'person-card add', onclick: () => openRelativeDialog(person, type, { onDone: () => show('relatives') }) }, icon('plus'), label) : null);
    const group = (title, ic, items) => h('div', { class: 'relatives-group' }, h('h3', null, icon(ic), title), h('div', { class: 'person-cards' }, ...items.filter(Boolean)));

    return h('div', null,
      group('پدر و مادر', 'ancestors', [
        r.father ? card(r.father, 'پدر') : addCard('father', 'افزودن پدر'),
        r.mother ? card(r.mother, 'مادر') : addCard('mother', 'افزودن مادر'),
      ]),
      group('همسر', 'heart', [
        ...r.spouses.map((s) => card(s.person, [MARRIAGE_STATUS[s.marriage.status], s.marriage.marriage_date ? `ازدواج ${formatDate(s.marriage.marriage_date)}` : null].filter(Boolean).join(' - '))),
        addCard('spouse', 'افزودن همسر'),
      ]),
      group(`فرزندان${r.children.length ? ` (${fa(r.children.length)})` : ''}`, 'baby', [
        ...r.children.map((c) => card(c, c.gender === 'f' ? 'دختر' : 'پسر')),
        addCard('child', 'افزودن فرزند'),
      ]),
      group(`خواهران و برادران${r.siblings.length ? ` (${fa(r.siblings.length)})` : ''}`, 'users', [
        ...r.siblings.map((sb) => card(sb, (sb.gender === 'f' ? 'خواهر' : 'برادر') + (sb.half ? ' ناتنی' : ''))),
        r.father || r.mother ? addCard('sibling', 'افزودن خواهر/برادر') : null,
      ]),
    );
  }

  async function historyTab() {
    let res;
    try {
      res = await get(`/api/persons/${person.id}/history`);
    } catch (e) {
      return emptyState('lock', e.message);
    }
    if (!res.data.length) return emptyState('history', 'تغییری ثبت نشده است.');
    return h('div', { class: 'card' }, h('div', { class: 'timeline' }, ...res.data.map((log) => {
      const changes = log.properties?.changes || {};
      return h('div', { class: 't-item' },
        h('div', null, h('b', null, log.user?.name || 'سیستم'), ' ', actionLabel(log.action)),
        Object.keys(changes).length ? h('ul', { class: 'small text-2', style: { margin: '4px 0', paddingInlineStart: '18px' } },
          ...Object.entries(changes).map(([f, [from, to]]) => h('li', null, `${FIELDS[f] || f}: `, h('span', { class: 'muted' }, fa(fmtVal(from))), ' ← ', h('b', null, fa(fmtVal(to)))))) : null,
        h('div', { class: 'muted tiny' }, dateTime(log.created_at)),
      );
    })));
  }
}

function fmtVal(v) {
  if (v === null || v === undefined || v === '') return '—';
  if (v === true) return 'بله';
  if (v === false) return 'خیر';
  return String(v);
}
