/**
 * شبکه‌های اجتماعی پروفایل:
 *  - socialEditor: فیلدهای فرم (اینستاگرام، تلگرام و واتس‌اپ همیشه اول و پیدا؛ بقیه در «شبکه‌های دیگر»)
 *    با پیش‌نمایش زنده: لینک مستقیم، نام و عکس پروفایل عمومی برای اطمینان از درست بودن شناسه
 *  - socialProfiles: نمایش در پروفایل؛ کلیک روی عکس‌ها ← نمایش یکی‌یکی عکس پروفایل همه شبکه‌ها
 *
 * لینک‌ها را سرور از روی الگوی ثابت هر شبکه می‌سازد (نه از متن کاربر).
 */
import { h, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, upload, post } from '../core/api.js';
import { store } from '../core/store.js';
import { fa } from '../core/format.js';
import { toast, toastError, withLoading } from '../core/ui.js';
import { openLightbox } from './lightbox.js';

/** رنگ و نشان کوتاه هر شبکه */
const STYLE = {
  instagram: ['IG', 'linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7)'], telegram: ['TG', '#229ED9'], whatsapp: ['WA', '#25D366'],
  eitaa: ['ای', '#EF7F1A'], bale: ['بله', '#1BA37F'], rubika: ['رو', '#7A2BF5'], soroush: ['سر', '#1B75BB'],
  linkedin: ['in', '#0A66C2'], x: ['X', '#111'], threads: ['@', '#111'], tiktok: ['TT', '#111'], youtube: ['YT', '#FF0000'],
  aparat: ['آپ', '#ED145B'], facebook: ['f', '#1877F2'], snapchat: ['SC', '#F7D000'], pinterest: ['P', '#E60023'],
  github: ['GH', '#24292F'], virasty: ['وی', '#1DA1F2'], bluesky: ['BS', '#0085FF'],
};

/** سه شبکه اصلی که همیشه در فرم پیداست */
export const PRIMARY_NETWORKS = ['instagram', 'telegram', 'whatsapp'];

export function networks() {
  return store.config.profile?.social_networks || {};
}

export function socialBadge(network, size = 22) {
  const [glyph, bg] = STYLE[network] || [network.slice(0, 2).toUpperCase(), '#666'];
  return h('span', { class: 'social-badge', style: { background: bg, width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.42)}px`, color: network === 'snapchat' ? '#111' : '#fff' }, 'aria-hidden': 'true' }, glyph);
}

// ------------------------------------------------------------------ فرم

/** یک فیلد شبکه اجتماعی با پیش‌نمایش زنده (برای فرم و پرسش‌نامه) */
export function socialInput(key, value, opts = {}) {
  const def = networks()[key];
  const isPhone = def.kind === 'phone';
  const input = h('input', {
    class: 'input ltr-input', name: `social.${key}`, value: value ?? '', autocomplete: 'off', autocapitalize: 'none', spellcheck: 'false',
    inputmode: isPhone ? 'tel' : 'text', type: isPhone ? 'tel' : 'text',
    placeholder: isPhone ? '09121234567' : key === 'telegram' ? '@username / 0912...' : '@username',
    'aria-label': def.label,
  });
  const preview = h('div', { class: 'social-preview', hidden: true });
  let seq = 0;
  const check = async () => {
    const value = input.value.trim();
    const mine = ++seq;
    if (!value) {
      preview.hidden = true;
      return;
    }
    preview.hidden = false;
    preview.replaceChildren(h('span', { class: 'muted small' }, h('span', { class: 'spinner sm' }), ' در حال بررسی...'));
    try {
      const { data } = await get('/api/social/preview', { network: key, value });
      if (mine !== seq) return;
      // شکل استاندارد (مثلاً لینک کامل ← شناسه)
      if (data.value && data.value !== value && document.activeElement !== input) input.value = data.value;
      preview.replaceChildren(previewCard(key, data));
    } catch (e) {
      if (mine !== seq) return;
      preview.replaceChildren(h('span', { class: 'error' }, icon('alert'), ' ', e.message || 'نامعتبر'));
    }
  };
  const later = debounce(check, 900);
  input.addEventListener('input', later);
  input.addEventListener('change', check);
  if (input.value) setTimeout(check, 50);

  const extra = [];
  if (isPhone && opts.phone) {
    extra.push(h('button', { class: 'btn ghost xs', type: 'button', onclick: () => { input.value = opts.phone; check(); } }, icon('phone'), 'همان شماره موبایل'));
  }
  return h('div', { class: 'field social-field' },
    h('label', null, socialBadge(key, 18), ' ', def.label),
    input,
    ...extra,
    preview,
  );
}

/**
 * @param {object} p        شخص
 * @param {object} opts     { phone: شماره موبایل برای دکمه «همان موبایل» }
 */
export function socialEditor(p = {}, opts = {}) {
  const defs = networks();
  const all = Object.keys(defs);
  const primary = PRIMARY_NETWORKS.filter((k) => defs[k]);
  const others = all.filter((k) => !primary.includes(k));
  const filledOthers = others.filter((k) => p.social?.[k]);

  const row = (key) => socialInput(key, p.social?.[key], opts);

  return h('div', { class: 'field full social-fields' },
    h('div', { class: 'form-grid' }, ...primary.map(row)),
    others.length ? h('details', { class: 'mt-sm', open: filledOthers.length > 0 },
      h('summary', null, icon('share'), ' شبکه‌های دیگر', filledOthers.length ? ` (${fa(filledOthers.length)})` : '', h('span', { class: 'muted small' }, ' — ایتا، بله، روبیکا، لینکدین، ایکس، یوتیوب، تیک‌تاک ...')),
      h('div', { class: 'form-grid mt-sm' }, ...others.map(row)),
    ) : null,
    h('p', { class: 'hint' }, icon('info'), ' می‌توانید لینک صفحه را هم بچسبانید؛ شناسه خودکار جدا می‌شود. عکس پروفایل عمومی (اگر شبکه اجازه دهد) پس از ذخیره دریافت و — اگر عکس دیگری در سایت ندارید — عکس پروفایل شما می‌شود (اولویت: اینستاگرام، واتس‌اپ، تلگرام).'),
  );
}

function previewCard(network, data) {
  const def = networks()[network] || {};
  const photo = data.image ? h('img', { class: 'sp-photo', src: data.image, alt: '' }) : socialBadge(network, 40);
  const lines = [
    h('div', { class: 'bold ellipsis', dir: 'auto' }, data.name || data.display),
    data.name ? h('div', { class: 'muted small ltr-input', dir: 'ltr' }, data.display) : null,
  ];
  let note = null;
  if (data.error) note = h('div', { class: 'muted tiny' }, data.error);
  else if (data.fetchable && !data.image) note = h('div', { class: 'muted tiny' }, 'عکس عمومی پیدا نشد؛ بعداً می‌توانید عکس را دستی آپلود کنید.');
  else if (network === 'whatsapp') note = h('div', { class: 'muted tiny' }, 'واتس‌اپ عکس پروفایل را عمومی نمی‌کند؛ در صورت تمایل پس از ذخیره آن را دستی آپلود کنید.');
  return h('div', { class: 'sp-card' },
    photo,
    h('div', { class: 'grow', style: { minWidth: 0 } }, ...lines, note),
    data.url ? h('a', { class: 'btn soft xs', href: data.url, target: '_blank', rel: 'noopener noreferrer nofollow' }, icon('link'), network === 'whatsapp' ? 'باز کردن گفتگو' : `دیدن در ${def.label || ''}`) : null,
  );
}

// ------------------------------------------------------------------ نمایش در پروفایل

/**
 * @param {object} person
 * @param {object} opts { canEdit, onChange }
 */
export function socialProfiles(person, opts = {}) {
  const list = person.social_profiles || [];
  if (!list.length) return null;
  const photos = list.filter((s) => s.photo);

  const showPhotos = (network) => {
    const items = photos.map((s) => ({
      type: 'image',
      urls: { medium: s.photo.medium, original: s.photo.medium },
      caption: `${s.label} ${s.display}`,
    }));
    const start = Math.max(0, photos.findIndex((s) => s.network === network));
    openLightbox(items, start);
  };

  const chips = list.map((s) => {
    const face = s.photo
      ? h('button', { class: 'sp-face', type: 'button', title: 'دیدن عکس‌های پروفایل', onclick: () => showPhotos(s.network) }, h('img', { src: s.photo.thumb, alt: '' }), h('span', { class: 'sp-mini' }, socialBadge(s.network, 14)))
      : socialBadge(s.network, 26);
    return h('div', { class: 'social-chip' },
      face,
      h('a', { href: s.url, target: '_blank', rel: 'noopener noreferrer nofollow', class: 'sp-link' },
        h('span', { class: 'small muted' }, s.label),
        h('span', { class: 'ltr-input', dir: 'ltr' }, s.network === 'whatsapp' || s.display.startsWith('+') ? fa(s.display) : s.display),
      ),
      opts.canEdit ? photoMenu(person, s, opts.onChange) : null,
    );
  });

  return h('div', { class: 'social-list' },
    photos.length > 1 ? h('button', { class: 'btn ghost xs', type: 'button', onclick: () => showPhotos(photos[0].network) }, icon('image'), `عکس‌های پروفایل (${fa(photos.length)})`) : null,
    ...chips,
  );
}

/** دریافت دوباره عکس از شبکه یا آپلود دستی عکس آن شبکه (مثلاً واتس‌اپ) */
function photoMenu(person, s, onChange) {
  const def = networks()[s.network] || {};
  const file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: true });
  const uploadBtn = h('button', { class: 'icon-btn sm', type: 'button', title: `آپلود عکس پروفایل ${def.label}`, onclick: () => file.click() }, icon('upload'));
  file.addEventListener('change', async () => {
    const f = file.files?.[0];
    if (!f) return;
    const form = new FormData();
    form.append('network', s.network);
    form.append('file', f);
    try {
      const res = await upload(`/api/persons/${person.id}/social-avatar`, form);
      toast(res.message || 'عکس ذخیره شد.');
      onChange?.();
    } catch (e) {
      toastError(e);
    }
    file.value = '';
  });
  const fetchBtn = def.fetch && !s.display.startsWith('+')
    ? h('button', { class: 'icon-btn sm', type: 'button', title: `دریافت عکس از ${def.label}`, onclick: (e) => withLoading(e.currentTarget, async () => {
      try {
        const res = await post(`/api/persons/${person.id}/social-avatar`, { network: s.network });
        toast(res.message || 'عکس دریافت شد.');
        onChange?.();
      } catch (err) {
        toastError(err);
      }
    }) }, icon('refresh'))
    : null;
  return h('span', { class: 'sp-actions' }, fetchBtn, uploadBtn, file);
}
