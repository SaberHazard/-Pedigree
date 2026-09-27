/**
 * فیلدهای فرم مشخصات شخص (مشترک بین صفحه ویرایش و پنجره افزودن بستگان)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { field, switchInput } from '../core/ui.js';
import { fa } from '../core/format.js';
import { dateInput } from './date-input.js';
import { TITLES } from './labels.js';

let listId = 0;

function text(name, value, attrs = {}) {
  return h('input', { class: 'input', name, value: value ?? '', ...attrs });
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
 * @param {object} p       شخص (برای ویرایش) یا {} برای جدید
 * @param {object} opts    { sensitive: نمایش بخش حساس, gender: نمایش جنسیت, full: همه بخش‌ها, compact }
 */
export function personFields(p = {}, opts = {}) {
  const { sensitive = false, gender = true, full = true } = opts;
  const titles = h('datalist', { id: `titles-${++listId}` }, ...TITLES.map((t) => h('option', { value: t })));

  const deathBox = h('div', { class: 'form-grid full', hidden: !p.is_deceased },
    h('div', { class: 'field' }, h('label', null, 'تاریخ وفات'), dateInput('death_date', p.death_date)),
    field('محل وفات', text('death_place', p.death_place)),
    field('محل دفن (آرامگاه)', text('burial_place', p.burial_place), { full: true }),
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

  parts.push(h('fieldset', null,
    h('legend', null, 'درباره'),
    h('div', { class: 'form-grid' },
      field('شغل', text('occupation', p.occupation)),
      field('تحصیلات', text('education', p.education)),
      field('محل سکونت', text('residence', p.residence), { full: true }),
      field('زندگی‌نامه و خاطرات', h('textarea', { class: 'input', name: 'biography', rows: 5 }, p.biography || ''), { full: true }),
    ),
  ));

  if (sensitive) {
    const pass = h('input', { class: 'input ltr-input', name: 'password', type: 'password', autocomplete: 'new-password', placeholder: p.account?.has_password ? '•••••••• (بدون تغییر)' : '' });
    parts.push(h('fieldset', null,
      h('legend', null, icon('lock'), ' اطلاعات هویتی و ورود (محرمانه)'),
      h('p', { class: 'muted small' }, 'این اطلاعات رمزنگاری‌شده ذخیره می‌شود و فقط برای خود شخص و افراد مجاز قابل مشاهده است.'),
      h('div', { class: 'form-grid' },
        field('کد ملی', text('national_code', p.national_code ? fa(p.national_code) : '', { inputmode: 'numeric', class: 'input ltr-input', maxlength: 12 })),
        field('شماره موبایل', text('phone', p.phone ? fa(p.phone) : '', { type: 'tel', inputmode: 'tel', class: 'input ltr-input' }), { hint: 'برای ورود با پیامک' }),
        field('شماره شناسنامه', text('birth_cert_no', p.birth_cert_no ? fa(p.birth_cert_no) : '', { class: 'input ltr-input' })),
        field('محل صدور شناسنامه', text('birth_cert_place', p.birth_cert_place)),
        field('ایمیل', text('email', p.email, { type: 'email', class: 'input ltr-input' })),
        field('رمز عبور ورود با کد ملی', pass, { hint: 'حداقل ۸ کاراکتر شامل حرف و عدد؛ خالی بگذارید تا تغییر نکند' }),
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
  if (!data.is_deceased) {
    data.death_date = '';
    data.death_place = data.death_place ?? '';
  }
  if (data.password === '') delete data.password;
  return data;
}
