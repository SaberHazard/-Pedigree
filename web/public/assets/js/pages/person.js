/**
 * صفحه پروفایل شخص: درباره (مشخصات کامل + متن‌های رنگی + رزومه)، استوری‌ها، گالری،
 * بستگان، نظرها و امتیازها، تاریخچه تغییرات
 */
import { h, s } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, upload } from '../core/api.js';
import { store, saveLocalPrefs } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, lifespan, formatDate, age, timeAgo, dateTime } from '../core/format.js';
import { tabs, toast, toastError, emptyState, loader, switchInput } from '../core/ui.js';
import { shareLink } from '../core/native.js';
import { avatar } from '../components/avatar.js';
import { gallery } from '../components/gallery.js';
import { openRelativeDialog } from '../components/relative-dialog.js';
import { cropImage } from '../components/cropper.js';
import { actionLabel, FIELDS, MARRIAGE_STATUS, COMPLETENESS, profileOption, countryName } from '../components/labels.js';
import { buildDefs, buildNode } from '../tree/node.js';
import { geometry } from '../tree/layout.js';
import { textSection, legend, authorColor, contributorLabel } from '../components/attributed-text.js';
import { resumeSection } from '../components/resume.js';
import { opinionsTab } from '../components/opinions.js';
import { storiesStrip } from '../components/stories.js';
import { miniMap, directionsLinks, coordText } from '../components/map.js';
import { socialProfiles } from '../components/social.js';

const TABS = ['details', 'gallery', 'relatives', 'opinions', 'history'];

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
  const active = TABS.includes(params.tab) ? params.tab : 'details';
  const loggedIn = !!store.user;

  // نویسندگان پروفایل (برای رنگ‌بندی)؛ با هر ذخیره به‌روز می‌شود
  const byId = new Map((person.contributors || []).map((c) => [c.id, c]));
  const legendBox = h('div');
  const setContributors = (list) => {
    byId.clear();
    for (const c of list || []) byId.set(c.id, c);
    person.contributors = list || [];
    legendBox.replaceChildren(legend(person.contributors) || '');
  };
  let colored = localPref('author_colors', true);
  const reload = () => navigate(`/person/${person.id}`, { replace: true });

  page.replaceChildren(
    hero(),
    loggedIn ? storiesStrip(person) : null,
    tabs([
      { value: 'details', label: 'درباره', icon: 'id-card' },
      { value: 'gallery', label: 'عکس‌ها و ویدیوها', icon: 'image' },
      { value: 'relatives', label: 'بستگان', icon: 'users' },
      loggedIn && (store.config.ratings?.enabled !== false || store.config.comments_enabled !== false) ? { value: 'opinions', label: 'نظرها و امتیازها', icon: 'star' } : null,
      perms.history ? { value: 'history', label: 'تاریخچه', icon: 'history' } : null,
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
    const done = person.completeness;
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
            person.city || person.country ? h('span', { class: 'chip' }, icon('pin'), [person.city, person.country && person.country !== 'IR' ? countryName(person.country) : null].filter(Boolean).join('، ') || countryName(person.country)) : null,
            person.is_locked ? h('span', { class: 'chip warning' }, icon('lock'), 'قفل') : null,
            person.account?.active ? h('span', { class: 'chip success' }, icon('check'), 'عضو فعال') : null,
            h('span', { class: 'chip', title: 'کد شناسه یکتا' }, `کد: ${person.code}`),
          ),
          person.education_level || person.occupation ? h('div', { class: 'ph-sub text-2' },
            icon('briefcase'), ' ', [person.occupation, profileOption('education_levels', person.education_level)].filter(Boolean).join(' • ')) : null,
          done && done.percent < 100 ? completeness(done) : null,
        ),
        h('div', { class: 'ph-actions' },
          h('a', { class: 'btn soft', href: `#/tree/${person.id}?mode=hourglass` }, icon('tree'), 'درخت'),
          perms.edit ? h('a', { class: 'btn', href: `#/person/${person.id}/edit` }, icon('edit'), 'ویرایش') : null,
          perms.edit ? h('a', { class: 'btn', href: `#/person/${person.id}/interview`, title: 'تکمیل پروفایل با جواب دادن به سؤال‌های کوتاه' }, icon('sparkles'), 'پرسش‌وپاسخ') : null,
          perms.edit ? h('button', { class: 'btn', type: 'button', onclick: () => openRelativeDialog(person, 'child', { onDone: () => show('relatives') }) }, icon('user-plus'), 'افزودن بستگان') : null,
          h('button', { class: 'btn ghost icon-only', type: 'button', title: 'اشتراک‌گذاری', onclick: share }, icon('share')),
        ),
      ),
    );
  }

  /** نوار «درصد تکمیل پروفایل» برای ویرایشگران */
  function completeness(done) {
    const missing = done.missing.map((k) => COMPLETENESS[k] || k);
    return h('a', { class: 'completeness', href: `#/person/${person.id}/interview`, title: `بخش‌های خالی: ${missing.join('، ')}` },
      h('div', { class: 'row between small' }, h('span', null, `پروفایل ${fa(done.percent)}٪ کامل است`), h('span', { class: 'muted tiny' }, 'تکمیل ←')),
      h('div', { class: 'progress' }, h('i', { style: { width: `${done.percent}%` } })),
      missing.length ? h('div', { class: 'muted tiny ellipsis' }, 'خالی: ', missing.slice(0, 5).join('، '), missing.length > 5 ? ' ...' : '') : null,
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
    else if (tab === 'opinions') tabBody.replaceChildren(opinionsTab(person));
    else if (tab === 'history') tabBody.replaceChildren(await historyTab());
  }

  // ------------------------------------------------------------ درباره
  function details() {
    const ctx = { person, byId, colored: () => colored, onContributors: setContributors };
    const meta = person.field_meta || {};

    /** یک ردیف مشخصه با رنگ آخرین ویرایشگر آن فیلد */
    const row = (ic, label, value, fieldKey) => {
      if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return null;
      const author = fieldKey ? byId.get(meta[fieldKey]?.u) : null;
      return h('div', { class: colored && author ? 'authored' : '', style: colored && author ? { '--c': authorColor(author) } : null, title: author ? `ثبت: ${contributorLabel(author)}` : null },
        h('dt', null, icon(ic), label), h('dd', null, value));
    };
    const group = (title, ic, rows, extra) => {
      const items = rows.filter(Boolean);
      if (!items.length && !extra) return null;
      return h('section', { class: 'card info-group' }, h('h3', null, icon(ic), ' ', title), items.length ? h('dl', { class: 'kv' }, ...items) : null, extra || null);
    };

    const home = person.home_location;
    const grave = person.burial_location;
    const basics = group('مشخصات', 'id-card', [
      row('calendar', 'تاریخ تولد', formatDate(person.birth_date), 'birth_date'),
      row('pin', 'محل تولد', person.birth_place, 'birth_place'),
      person.is_deceased ? row('flame', 'تاریخ وفات', formatDate(person.death_date), 'death_date') : null,
      person.is_deceased ? row('pin', 'محل وفات', person.death_place, 'death_place') : null,
      person.is_deceased ? row('star', 'آرامگاه', person.burial_place, 'burial_place') : null,
      row('users', 'ترتیب تولد', person.birth_order ? `فرزند ${fa(person.birth_order)}` : null, 'birth_order'),
      row('id-card', 'کد ملی', person.national_code ? fa(person.national_code) : null, 'national_code'),
      row('file', 'شماره شناسنامه', person.birth_cert_no ? fa(person.birth_cert_no) + (person.birth_cert_place ? ` (صادره از ${person.birth_cert_place})` : '') : null, 'birth_cert_no'),
    ], grave ? h('div', { class: 'mt-sm' }, h('div', { class: 'muted small mb-sm' }, icon('pin'), ' موقعیت مزار: ', coordText(grave)),
      miniMap([{ ...grave, label: person.burial_place || 'آرامگاه' }], { height: 180 }), h('div', { class: 'mt-sm' }, directionsLinks(grave.lat, grave.lng))) : null);

    const study = group('تحصیلات و شغل', 'graduation', [
      row('graduation', 'مقطع تحصیلی', profileOption('education_levels', person.education_level), 'education_level'),
      row('book', 'رشته', person.education_field, 'education_field'),
      row('home', 'دانشگاه / مدرسه', person.education_institution, 'education_institution'),
      row('crown', 'مرتبه علمی', profileOption('academic_ranks', person.academic_rank), 'academic_rank'),
      row('graduation', 'توضیح تحصیلات', person.education, 'education'),
      row('briefcase', 'شغل', person.occupation, 'occupation'),
      row('home', 'محل کار', person.workplace, 'workplace'),
    ]);

    const place = group('محل زندگی', 'home', [
      row('compass', 'کشور', person.country ? `${flag(person.country)} ${countryName(person.country)}` : null, 'country'),
      row('pin', 'استان', person.province, 'province'),
      row('pin', 'شهر', person.city, 'city'),
      row('pin', 'محله / منطقه', person.residence, 'residence'),
      row('mail', 'نشانی', person.address, 'address'),
      row('file', 'کد پستی', person.postal_code ? fa(person.postal_code) : null, 'postal_code'),
    ], home ? h('div', { class: 'mt-sm' }, miniMap([{ ...home, person, label: fullName(person) }], { height: 200 }), h('div', { class: 'mt-sm' }, directionsLinks(home.lat, home.lng)))
      : person.location_hidden ? h('p', { class: 'muted small' }, icon('lock'), ` نشانی و موقعیت خانه فقط برای ${visibilityText(person.location_visibility)} نمایش داده می‌شود.`) : null);

    const socials = socialProfiles(person, { canEdit: perms.upload, onChange: reload });
    const privacyNote = [];
    if (person.contact_hidden) privacyNote.push(h('p', { class: 'muted small' }, icon('lock'), ` شماره و راه‌های ارتباطی فقط برای ${visibilityText(person.contact_visibility)} نمایش داده می‌شود.`));
    else if (perms.privacy && person.id === store.user?.person?.id) {
      privacyNote.push(h('p', { class: 'muted tiny' }, icon('eye'), ` شماره شما را ${visibilityText(person.contact_visibility)} می‌بینند. `, h('a', { href: `#/person/${person.id}/edit` }, 'تغییر')));
    }
    const contact = group('شبکه‌های اجتماعی و راه‌های ارتباطی', 'phone', [
      row('phone', 'موبایل', person.phone ? h('a', { href: `tel:${person.phone}`, dir: 'ltr' }, fa(person.phone)) : null, 'phone'),
      row('phone', 'تلفن ثابت', person.landline ? h('a', { href: `tel:${person.landline.replace(/[^\d+]/g, '')}`, dir: 'ltr' }, fa(person.landline)) : null, 'landline'),
      row('mail', 'ایمیل', person.email ? h('a', { href: `mailto:${person.email}`, dir: 'ltr' }, person.email) : null, 'email'),
      row('link', 'وب‌سایت', safeUrl(person.website) ? h('a', { href: safeUrl(person.website), target: '_blank', rel: 'noopener nofollow', dir: 'ltr' }, person.website.replace(/^https?:\/\//, '')) : null, 'website'),
    ], socials || privacyNote.length ? h('div', null, socials, ...privacyNote) : null);

    const other = group('ویژگی‌های دیگر', 'sparkles', [
      row('heart', 'گروه خونی', person.blood_type ? h('span', { dir: 'ltr' }, person.blood_type) : null, 'blood_type'),
      row('book', 'زبان‌ها', person.languages, 'languages'),
      row('star', 'علاقه‌مندی‌ها', person.interests, 'interests'),
      ...(person.custom_fields || []).map((f) => row('info', f.label, f.value, 'custom_fields')),
    ]);

    const texts = ['summary', 'description', 'biography', 'resume']
      .filter((f) => store.config.profile?.texts?.[f])
      .filter((f) => perms.edit || person.texts?.[f]?.segments?.length)
      .map((f) => textSection(f, ctx));
    const summary = texts.find((t) => t.classList.contains('text-summary'));

    const colorToggle = h('div', { class: 'row between wrap mt' },
      switchInput('author_colors', 'نمایش رنگ نویسندگان', colored, (v) => {
        colored = v;
        saveLocalPref('author_colors', v);
        tabBody.replaceChildren(details());
      }),
      legendBox,
    );
    setContributors(person.contributors);

    const infoGroups = [basics, study, place, contact, other].filter(Boolean);
    return h('div', { class: 'about' },
      summary || null,
      infoGroups.length ? h('div', { class: 'info-groups' }, ...infoGroups)
        : emptyState('info', 'هنوز مشخصاتی ثبت نشده است.', perms.edit ? h('a', { class: 'btn soft', href: `#/person/${person.id}/edit` }, 'تکمیل مشخصات') : null),
      ...texts.filter((t) => t !== summary),
      (person.resume?.length || perms.edit) ? resumeSection(ctx) : null,
      colorToggle,
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
    const ordinal = ['اول', 'دوم', 'سوم', 'چهارم', 'پنجم', 'ششم'];

    return h('div', null,
      group('پدر و مادر', 'ancestors', [
        r.father ? card(r.father, 'پدر') : addCard('father', 'افزودن پدر'),
        r.mother ? card(r.mother, 'مادر') : addCard('mother', 'افزودن مادر'),
      ]),
      group(r.spouses.length > 1 ? `همسران (${fa(r.spouses.length)})` : 'همسر', 'heart', [
        ...r.spouses.map((sp, i) => card(sp.person, [r.spouses.length > 1 ? `همسر ${ordinal[i] || fa(i + 1)}` : null, MARRIAGE_STATUS[sp.marriage.status], sp.marriage.marriage_date ? `ازدواج ${formatDate(sp.marriage.marriage_date)}` : null].filter(Boolean).join(' - '))),
        addCard('spouse', r.spouses.length ? 'افزودن همسر دیگر' : 'افزودن همسر'),
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

  async function historyTab(pageNo = 1, box = null) {
    let res;
    try {
      res = await get(`/api/persons/${person.id}/history`, { page: pageNo });
    } catch (e) {
      return emptyState('lock', e.message);
    }
    if (!res.data.length && pageNo === 1) return emptyState('history', 'تغییری ثبت نشده است.');
    const timeline = box || h('div', { class: 'timeline' });
    timeline.append(...res.data.map(historyItem));
    const more = res.meta && res.meta.current_page < res.meta.last_page
      ? h('button', { class: 'btn ghost block mt-sm', type: 'button', onclick: async (e) => { e.currentTarget.remove(); await historyTab(pageNo + 1, timeline); } }, 'موارد قدیمی‌تر')
      : null;
    if (box) {
      if (more) box.after(more);
      return box;
    }
    return h('div', { class: 'card' },
      h('p', { class: 'muted small', style: { marginTop: 0 } }, icon('info'), ' هر افزودن، حذف یا ویرایشی در این پروفایل با نام انجام‌دهنده اینجا ثبت می‌شود.'),
      timeline, more);
  }
}

/** یک رویداد تاریخچه با جزئیات خوانا */
function historyItem(log) {
  const p = log.properties || {};
  const changes = p.changes || {};
  const detail = [];
  if (log.action.startsWith('text.')) {
    const label = store.config.profile?.texts?.[p.field]?.label || FIELDS[p.field] || p.field;
    detail.push(h('span', null, `«${label}» `, log.action === 'text.restored' ? `(نسخه ${fa(p.restored_from)})` : h('span', null, h('span', { class: 'added' }, `+${fa(p.added ?? 0)}`), ' ', h('span', { class: 'removed' }, `−${fa(p.removed ?? 0)}`), ' کلمه')));
  } else if (log.action.startsWith('resume.')) {
    detail.push(h('span', null, `«${p.title || ''}»`));
  } else if (log.action.startsWith('comment.') && p.excerpt) {
    detail.push(h('span', { class: 'muted' }, `«${p.excerpt}»`));
  } else if (log.action === 'rating.updated' && p.scores) {
    const traits = store.config.ratings?.traits || {};
    detail.push(h('span', { class: 'muted' }, Object.entries(p.scores).map(([k, v]) => `${traits[k] || k}: ${v ? fa(v) : 'حذف'}`).join('، ')));
  } else if (log.action.startsWith('media.')) {
    detail.push(h('span', { class: 'muted' }, [p.category === 'story' ? 'استوری' : p.type === 'video' ? 'ویدیو' : p.type ? 'عکس' : null, p.caption ? `«${p.caption}»` : null, p.uploaded_by ? `آپلود: ${p.uploaded_by}` : null].filter(Boolean).join(' • ')));
  }
  return h('div', { class: 't-item' },
    // برچسب‌هایی مثل «... برای» در داشبورد با نام شخص کامل می‌شوند؛ اینجا شخص همین پروفایل است
    h('div', null, h('b', null, log.user?.name || 'سیستم'), ' ', actionLabel(log.action).replace(/ برای$/, ''), detail.length ? ' ' : null, ...detail),
    Object.keys(changes).length ? h('ul', { class: 'small text-2', style: { margin: '4px 0', paddingInlineStart: '18px' } },
      ...Object.entries(changes).map(([f, [from, to]]) => h('li', null, `${FIELDS[f] || f}: `, h('span', { class: 'muted' }, fa(fmtField(f, from))), ' ← ', h('b', null, fa(fmtField(f, to)))))) : null,
    h('div', { class: 'muted tiny' }, dateTime(log.created_at)),
  );
}

/** مقدار یک فیلد در تاریخچه (کلیدهای ثابت به برچسب فارسی) */
function fmtField(field, v) {
  const profile = store.config.profile || {};
  if (field.endsWith('_visibility')) return profile.visibility_levels?.[v] || fmtVal(v);
  if (field === 'education_level') return profile.education_levels?.[v] || fmtVal(v);
  if (field === 'academic_rank') return profile.academic_ranks?.[v] || fmtVal(v);
  if (field === 'education_field_group') return profile.education_field_groups?.[v]?.label || fmtVal(v);
  if (field === 'honorific_mode') return v === 'none' ? 'خاموش' : v === 'auto' ? 'خودکار' : fmtVal(v);
  return fmtVal(v);
}

function fmtVal(v) {
  if (v === null || v === undefined || v === '') return '—';
  if (v === true) return 'بله';
  if (v === false) return 'خیر';
  if (Array.isArray(v)) return v.map((x) => (x && typeof x === 'object' ? `${x.label}: ${x.value}` : String(x))).join('، ') || '—';
  if (typeof v === 'object') return Object.entries(v).map(([k, x]) => `${store.config.profile?.social_networks?.[k]?.label || FIELDS[k] || k}: ${x}`).join('، ') || '—';
  return String(v);
}

/** پرچم کشور از کد ISO (ایموجی) */
function flag(code) {
  return /^[A-Z]{2}$/.test(code || '') ? String.fromCodePoint(...[...code].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65)) : '';
}

/** فقط لینک‌های http/https (جلوگیری از javascript: و ...) */
function safeUrl(value) {
  if (!value) return null;
  try {
    const u = new URL(value);
    return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : null;
  } catch {
    return null;
  }
}

/** متن سطح نمایش: «بستگان تا درجه ۲» */
function visibilityText(level) {
  const levels = store.config.profile?.visibility_levels || {};
  if (level === 'all') return 'همه اعضای خاندان';
  if (level === 'self') return 'خود شخص';
  return levels[level] || 'بستگان نزدیک';
}

function localPref(key, fallback) {
  const v = store.prefs[key];
  return v === undefined ? fallback : v;
}

function saveLocalPref(key, value) {
  saveLocalPrefs({ [key]: value });
}
