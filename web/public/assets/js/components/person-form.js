/**
 * فیلدهای فرم مشخصات شخص (مشترک بین صفحه ویرایش و پنجره افزودن بستگان)
 *
 * بخش‌ها: مشخصات اصلی، تولد و وفات (+ موقعیت مزار)، تحصیلات و شغل، محل زندگی (+ نقشه)،
 * راه‌های ارتباطی، ویژگی‌های دیگر (+ ویژگی‌های دلخواه)، اطلاعات هویتی و ورود.
 * همه فیلدها جز نام و جنسیت اختیاری‌اند.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import { field, switchInput } from '../core/ui.js';
import { fa, latin } from '../core/format.js';
import { dateInput } from './date-input.js';
import { TITLES, IRAN_PROVINCES, countryName } from './labels.js';
import { pickLocation, coordText } from './map.js';

let listId = 0;

function text(name, value, attrs = {}) {
  return h('input', { class: 'input', name, value: value ?? '', ...attrs });
}

function select(name, options, value, placeholder = '— انتخاب کنید —') {
  return h('select', { class: 'input', name },
    h('option', { value: '' }, placeholder),
    ...options.map(([v, label]) => h('option', { value: v, selected: v === value }, label)),
  );
}

/** انتخاب جنسیت */
export function genderPicker(value = 'm', onChange) {
  const input = h('input', { type: 'hidden', name: 'gender', value });
  const el = h('div', { class: 'segmented', style: { width: '100%' } });
  const render = () => el.replaceChildren(input, ...[['m', 'مرد'], ['f', 'زن']].map(([v, l]) => h('button', {
    type: 'button',
    class: input.value === v ? 'active' : '',
    style: { flex: 1 },
    onclick: () => {
      input.value = v;
      render();
      onChange?.(v);
    },
  }, l)));
  render();
  return el;
}

/**
 * دکمه انتخاب موقعیت روی نقشه؛ مقدار در دو input مخفی (lat/lng) نگه داشته می‌شود
 */
function locationField(label, latName, lngName, value, hint) {
  const lat = h('input', { type: 'hidden', name: latName, value: value?.lat ?? '' });
  const lng = h('input', { type: 'hidden', name: lngName, value: value?.lng ?? '' });
  const summary = h('span', { class: 'muted small' });
  const render = () => {
    const p = lat.value !== '' ? { lat: +lat.value, lng: +lng.value } : null;
    summary.textContent = p ? coordText(p) : 'انتخاب نشده';
    btn.replaceChildren(icon('pin'), p ? 'تغییر موقعیت' : 'انتخاب روی نقشه');
  };
  const btn = h('button', { class: 'btn soft sm', type: 'button', onclick: async () => {
    const current = lat.value !== '' ? { lat: +lat.value, lng: +lng.value } : null;
    const picked = await pickLocation({ title: label, value: current });
    if (picked === undefined) return;
    lat.value = picked ? picked.lat.toFixed(7) : '';
    lng.value = picked ? picked.lng.toFixed(7) : '';
    render();
  } });
  render();
  return h('div', { class: 'field' }, h('label', null, label), h('div', { class: 'row wrap', style: { gap: '8px', alignItems: 'center' } }, btn, summary), lat, lng,
    hint ? h('div', { class: 'hint' }, hint) : null);
}

/** ردیف‌های «ویژگی دلخواه» (عنوان + مقدار) با امکان افزودن و حذف */
function customFields(rows = []) {
  const max = store.config.profile?.max_attributes || 40;
  const list = h('div', { class: 'custom-fields' });
  const addRow = (row = { label: '', value: '' }) => {
    if (list.children.length >= max) return;
    const item = h('div', { class: 'custom-field-row' },
      h('input', { class: 'input', 'data-cf': 'label', value: row.label, placeholder: 'عنوان (مثلاً غذای محبوب)', maxlength: 60 }),
      h('input', { class: 'input', 'data-cf': 'value', value: row.value, placeholder: 'مقدار (مثلاً قورمه‌سبزی)', maxlength: 300 }),
      h('button', { class: 'icon-btn', type: 'button', title: 'حذف', onclick: () => item.remove() }, icon('trash')),
    );
    list.append(item);
  };
  rows.forEach(addRow);
  return h('div', { class: 'field full' },
    h('label', null, 'ویژگی‌های دیگر (هر چیزی که دوست دارید ثبت شود)'),
    list,
    h('button', { class: 'btn ghost sm', type: 'button', onclick: () => addRow() }, icon('plus'), 'افزودن ویژگی'),
  );
}

/**
 * @param {object} p       شخص (برای ویرایش) یا {} برای جدید
 * @param {object} opts    { sensitive: نمایش بخش حساس, gender: نمایش جنسیت, full: همه بخش‌ها }
 */
export function personFields(p = {}, opts = {}) {
  const { sensitive = false, gender = true, full = true } = opts;
  const profile = store.config.profile || {};
  const titles = h('datalist', { id: `titles-${++listId}` }, ...TITLES.map((t) => h('option', { value: t })));

  const deathBox = h('div', { class: 'form-grid full', hidden: !p.is_deceased },
    h('div', { class: 'field' }, h('label', null, 'تاریخ وفات'), dateInput('death_date', p.death_date)),
    field('محل وفات', text('death_place', p.death_place)),
    field('محل دفن (آرامگاه)', text('burial_place', p.burial_place, { placeholder: 'مثلاً بهشت زهرا، قطعه ۲۴' })),
    full ? locationField('موقعیت مزار روی نقشه', 'burial_lat', 'burial_lng', p.burial_location, 'برای پیدا کردن مزار توسط نسل‌های بعد') : null,
  );

  const main = h('fieldset', null,
    h('legend', null, 'مشخصات اصلی'),
    h('div', { class: 'form-grid' },
      field('نام', text('first_name', p.first_name, { required: true, autocomplete: 'off' })),
      field('نام خانوادگی', text('last_name', p.last_name, { autocomplete: 'off' })),
      field('عنوان (اختیاری)', h('div', null, text('title', p.title, { list: titles.id, placeholder: 'حاج، سید، دکتر ...' }), titles)),
      field('شهرت / لقب', text('nickname', p.nickname)),
      gender ? h('div', { class: 'field' }, h('label', null, 'جنسیت'), genderPicker(p.gender || 'm')) : null,
      field('ترتیب تولد بین خواهر و برادرها', text('birth_order', p.birth_order ? fa(p.birth_order) : '', { inputmode: 'numeric', placeholder: 'مثلاً ۱ برای فرزند اول' })),
    ),
  );

  const life = h('fieldset', null,
    h('legend', null, 'تولد و وفات'),
    h('div', { class: 'form-grid' },
      h('div', { class: 'field' }, h('label', null, 'تاریخ تولد'), dateInput('birth_date', p.birth_date), h('div', { class: 'hint' }, 'اگر فقط سال را می‌دانید، فقط سال را وارد کنید.')),
      field('محل تولد', text('birth_place', p.birth_place)),
      h('div', { class: 'field full' }, switchInput('is_deceased', 'این شخص درگذشته است', !!p.is_deceased, (v) => { deathBox.hidden = !v; })),
      deathBox,
    ),
  );

  const parts = [main, life];
  if (!full) return h('div', null, ...parts);

  // ---------------------------------------------------------------- تحصیلات و شغل
  const levels = Object.entries(profile.education_levels || {});
  const ranks = Object.entries(profile.academic_ranks || {});
  parts.push(h('fieldset', null,
    h('legend', null, icon('graduation'), ' تحصیلات و شغل'),
    h('div', { class: 'form-grid' },
      field('مقطع تحصیلی (بالاترین مدرک)', select('education_level', levels, p.education_level)),
      field('رشته تحصیلی', text('education_field', p.education_field, { placeholder: 'مثلاً پزشکی، ادبیات فارسی' })),
      field('دانشگاه / مدرسه / حوزه', text('education_institution', p.education_institution)),
      field('مرتبه علمی (برای اعضای هیئت علمی)', select('academic_rank', ranks, p.academic_rank, '— ندارد —')),
      field('شغل / سمت', text('occupation', p.occupation, { placeholder: 'مثلاً معلم، کشاورز، مهندس عمران' })),
      field('محل کار', text('workplace', p.workplace)),
      p.education ? field('توضیح تحصیلات (قدیمی)', text('education', p.education), { full: true }) : null,
    ),
  ));

  // ---------------------------------------------------------------- محل زندگی
  const provinces = h('datalist', { id: `prov-${++listId}` });
  const fillProvinces = (code) => provinces.replaceChildren(...(code === 'IR' ? IRAN_PROVINCES : []).map((x) => h('option', { value: x })));
  const countries = (profile.countries || []).map((c) => [c, countryName(c)]).sort((a, b) => a[1].localeCompare(b[1], 'fa'));
  const countrySel = select('country', countries, p.country || (p.id ? '' : 'IR'), '— کشور —');
  countrySel.addEventListener('change', () => fillProvinces(countrySel.value));
  fillProvinces(countrySel.value);
  parts.push(h('fieldset', null,
    h('legend', null, icon('home'), ' محل زندگی'),
    h('div', { class: 'form-grid' },
      field('کشور', countrySel),
      field('استان / ایالت', h('div', null, text('province', p.province, { list: provinces.id }), provinces)),
      field('شهر / روستا', text('city', p.city)),
      field('محله / منطقه', text('residence', p.residence)),
      field('نشانی دقیق', h('textarea', { class: 'input', name: 'address', rows: 2 }, p.address || ''), { full: true }),
      field('کد پستی', text('postal_code', p.postal_code ? fa(p.postal_code) : '', { class: 'input ltr-input', inputmode: 'numeric', maxlength: 20 })),
      locationField('موقعیت خانه روی نقشه', 'home_lat', 'home_lng', p.home_location),
      h('div', { class: 'field full' }, switchInput('share_location', 'نشانی و موقعیت خانه برای همه اعضای خاندان نمایش داده شود', !!p.share_location),
        h('div', { class: 'hint' }, 'در غیر این صورت فقط خود شخص و بستگان درجه یک (و مدیر) نشانی را می‌بینند. نشانی رمزنگاری‌شده ذخیره می‌شود.')),
    ),
  ));

  // ---------------------------------------------------------------- راه‌های ارتباطی
  const networks = Object.entries(profile.social_networks || {});
  parts.push(h('fieldset', null,
    h('legend', null, icon('phone'), ' راه‌های ارتباطی'),
    h('div', { class: 'form-grid' },
      field('تلفن ثابت', text('landline', p.landline ? fa(p.landline) : '', { class: 'input ltr-input', type: 'tel', inputmode: 'tel' })),
      field('وب‌سایت', text('website', p.website, { class: 'input ltr-input', type: 'url', placeholder: 'https://' })),
      h('details', { class: 'field full social-fields', open: Object.keys(p.social || {}).length > 0 },
        h('summary', null, icon('share'), ' شبکه‌های اجتماعی', Object.keys(p.social || {}).length ? ` (${fa(Object.keys(p.social).length)})` : ''),
        h('div', { class: 'form-grid mt-sm' }, ...networks.map(([key, label]) => field(label, text(`social.${key}`, p.social?.[key], { class: 'input ltr-input', placeholder: key === 'whatsapp' ? '+98912...' : '@username' })))),
      ),
      h('div', { class: 'field full' }, switchInput('share_contact', 'موبایل، ایمیل و تلفن برای همه اعضای خاندان نمایش داده شود', !!p.share_contact)),
    ),
  ));

  // ---------------------------------------------------------------- ویژگی‌های دیگر
  parts.push(h('fieldset', null,
    h('legend', null, icon('sparkles'), ' ویژگی‌های دیگر'),
    h('div', { class: 'form-grid' },
      field('گروه خونی', select('blood_type', (profile.blood_types || []).map((b) => [b, b]), p.blood_type)),
      field('زبان‌ها', text('languages', p.languages, { placeholder: 'فارسی، ترکی، انگلیسی' })),
      field('علاقه‌مندی‌ها و سرگرمی‌ها', text('interests', p.interests), { full: true }),
      customFields(p.custom_fields || []),
    ),
    h('p', { class: 'muted small', style: { margin: '4px 0 0' } }, icon('info'), ' چکیده، زندگی‌نامه، توضیحات و رزومه در صفحه پروفایل (بخش «درباره») نوشته می‌شوند تا نام نویسنده هر بخش ثبت شود.'),
  ));

  if (sensitive) {
    const pass = h('input', { class: 'input ltr-input', name: 'password', type: 'password', autocomplete: 'new-password', placeholder: p.account?.has_password ? '•••••••• (بدون تغییر)' : '' });
    parts.push(h('fieldset', null,
      h('legend', null, icon('lock'), ' اطلاعات هویتی و ورود (محرمانه)'),
      h('p', { class: 'muted small' }, 'این اطلاعات رمزنگاری‌شده ذخیره می‌شود و فقط برای خود شخص و افراد مجاز قابل مشاهده است. هیچ‌کدام اجباری نیست؛ برای سالمندی که کد ملی یا موبایل ندارد، نام کاربری و رمز تعیین کنید.'),
      h('div', { class: 'form-grid' },
        field('کد ملی', text('national_code', p.national_code ? fa(p.national_code) : '', { inputmode: 'numeric', class: 'input ltr-input', maxlength: 12 })),
        field('شماره موبایل', text('phone', p.phone ? fa(p.phone) : '', { type: 'tel', inputmode: 'tel', class: 'input ltr-input' }), { hint: 'برای ورود با پیامک' }),
        field('شماره شناسنامه', text('birth_cert_no', p.birth_cert_no ? fa(p.birth_cert_no) : '', { class: 'input ltr-input' })),
        field('محل صدور شناسنامه', text('birth_cert_place', p.birth_cert_place)),
        field('ایمیل', text('email', p.email, { type: 'email', class: 'input ltr-input' })),
        field('نام کاربری', text('username', p.account?.username, { class: 'input ltr-input', autocomplete: 'off', autocapitalize: 'none', spellcheck: 'false', placeholder: 'tahereh.k' }), { hint: 'مثلاً tahereh.k — حروف انگلیسی کوچک، عدد، نقطه یا خط تیره' }),
        field('رمز عبور', pass, { hint: 'برای ورود با کد ملی یا نام کاربری؛ حداقل ۸ کاراکتر شامل حرف و عدد. خالی = بدون تغییر' }),
        h('div', { class: 'field full' }, switchInput('is_locked', 'قفل پروفایل (فقط خود شخص و مدیر بتوانند ویرایش کنند)', !!p.is_locked)),
      ),
    ));
  }

  return h('div', null, ...parts);
}

/** جمع‌آوری مقادیر فرم شخص برای ارسال به API */
export function collectPerson(form) {
  const data = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled) continue;
    if (el.type === 'checkbox') data[el.name] = el.checked;
    else if (el.type === 'radio') {
      if (el.checked) data[el.name] = el.value;
    } else data[el.name] = el.value.trim();
  }
  // شبکه‌های اجتماعی: social.telegram → social: {telegram}
  const social = {};
  for (const key of Object.keys(data)) {
    if (key.startsWith('social.')) {
      if (data[key]) social[key.slice(7)] = data[key];
      delete data[key];
    }
  }
  if ('share_contact' in data) data.social = social;

  // ویژگی‌های دلخواه
  const rows = form.querySelectorAll('.custom-field-row');
  if (form.querySelector('.custom-fields')) {
    data.custom_fields = [...rows].map((r) => ({
      label: r.querySelector('[data-cf=label]').value.trim(),
      value: r.querySelector('[data-cf=value]').value.trim(),
    })).filter((r) => r.label && r.value);
  }

  for (const key of ['postal_code', 'landline', 'home_lat', 'home_lng', 'burial_lat', 'burial_lng']) {
    if (typeof data[key] === 'string') data[key] = latin(data[key]);
  }
  if (!data.is_deceased) {
    data.death_date = '';
    data.death_place = data.death_place ?? '';
  }
  if (data.password === '') delete data.password;
  return data;
}
