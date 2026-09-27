/**
 * پرسش‌نامه تکمیل پروفایل: یک سؤال در هر صفحه، با زبان ساده.
 *
 * هدف: هر کسی (حتی سالمندی که با فرم‌های طولانی راحت نیست) با جواب دادن به چند سؤال کوتاه
 * پروفایلش را کامل کند؛ حتی چیزهایی که به ذهنش نمی‌رسد (غذای محبوب، ساز، خاطره، آرزو ...).
 *
 * - اول شبکه‌های اجتماعی (اینستاگرام، تلگرام، واتس‌اپ)، بعد مشخصات، تحصیلات، شغل، محل زندگی،
 *   راه‌های ارتباطی، ویژگی‌ها، بیوگرافی و زندگی‌نامه، عکس، و پدر و مادر
 * - پیش‌فرض فقط سؤال‌های بی‌جواب؛ با «همه سؤال‌ها» می‌توان جواب‌های قبلی را هم مرور کرد
 * - هر جواب همان لحظه ذخیره می‌شود (با نام نویسنده، مثل ویرایش معمولی)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, patch, put, post, upload } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, latin } from '../core/format.js';
import { toast, toastError, loader, emptyState, segmented, withLoading } from '../core/ui.js';
import { dateInput } from '../components/date-input.js';
import { socialInput, networks, PRIMARY_NETWORKS } from '../components/social.js';
import { visibilityField } from '../components/kin.js';
import { honorificFor } from '../components/person-form.js';
import { countryName, IRAN_PROVINCES } from '../components/labels.js';
import { pickLocation, coordText } from '../components/map.js';
import { cropImage } from '../components/cropper.js';
import { avatar } from '../components/avatar.js';

/**
 * ویژگی‌هایی که معمولاً به ذهن کسی نمی‌رسد بنویسد (در «ویژگی‌های دیگر» ذخیره می‌شوند)
 * [عنوان، سؤال برای خود شخص، سؤال برای دیگری، مثال، فقط برای زندگان/درگذشتگان/مردان]
 */
const PROMPTS = [
  ['غذای محبوب', 'غذای محبوبتان چیست؟', 'غذای محبوبش چه بود/هست؟', 'قورمه‌سبزی، آبگوشت، ماکارونی'],
  ['رنگ محبوب', 'رنگ محبوبتان؟', 'رنگ محبوبش؟', 'آبی فیروزه‌ای'],
  ['شاعر یا نویسنده محبوب', 'شاعر یا نویسنده محبوبتان کیست؟', 'شاعر یا نویسنده محبوبش؟', 'حافظ، سعدی، شهریار'],
  ['کتاب محبوب', 'کتابی که خیلی دوست دارید؟', 'کتاب محبوبش؟', 'دیوان حافظ، کلیدر'],
  ['فیلم یا سریال محبوب', 'فیلم یا سریال محبوبتان؟', 'فیلم یا سریال محبوبش؟', 'هزاردستان، مختارنامه'],
  ['خواننده یا آهنگ محبوب', 'خواننده یا آهنگی که دوست دارید؟', 'خواننده یا آهنگ محبوبش؟', 'شجریان، مرغ سحر'],
  ['ورزش', 'چه ورزشی انجام می‌دهید یا دوست دارید؟', 'چه ورزشی انجام می‌داد/می‌دهد؟', 'کوهنوردی، فوتبال، کشتی'],
  ['تیم محبوب', 'طرفدار کدام تیم هستید؟', 'طرفدار کدام تیم بود/هست؟', 'پرسپولیس، استقلال، تراکتور'],
  ['ساز یا هنر', 'سازی می‌زنید یا هنری دارید؟', 'سازی می‌زد یا هنری داشت؟', 'تار، خوشنویسی، نقاشی، قالی‌بافی'],
  ['مهارت خاص', 'چه مهارت خاصی دارید که کمتر کسی می‌داند؟', 'چه مهارت خاصی داشت/دارد؟', 'آشپزی، خیاطی، نجاری، تعمیر ماشین'],
  ['لقب دوران کودکی', 'در بچگی شما را چه صدا می‌زدند؟', 'در بچگی او را چه صدا می‌زدند؟', 'ممد کوچیکه'],
  ['مدرسه دوران کودکی', 'دبستانتان کجا بود؟', 'در کدام مدرسه درس خواند؟', 'دبستان فردوسی، محله سرچشمه'],
  ['خدمت سربازی', 'سربازی را کجا گذراندید؟', 'سربازی را کجا گذراند؟', 'پادگان ۰۱ تهران، ۱۳۶۵', { gender: 'm' }],
  ['اولین شغل', 'اولین کارتان چه بود؟', 'اولین کارش چه بود؟', 'شاگرد مغازه عمو'],
  ['سفرهای به‌یادماندنی', 'به‌یادماندنی‌ترین سفرتان؟', 'به‌یادماندنی‌ترین سفرش؟', 'مشهد با قطار، ۱۳۵۵'],
  ['جایی که دوست دارد برود', 'دوست دارید کجا را ببینید؟', 'دوست داشت کجا را ببیند؟', 'کربلا، ژاپن، شمال', { alive: true }],
  ['حیوان خانگی', 'حیوان خانگی داشته‌اید؟', 'حیوان خانگی داشت؟', 'گربه‌ای به اسم ملوس'],
  ['خصلت بارز', 'دیگران شما را با چه خصلتی می‌شناسند؟', 'او را با چه خصلتی می‌شناختند؟', 'مهمان‌نواز، شوخ‌طبع، دست‌ودل‌باز'],
  ['جمله یا شعار محبوب', 'جمله یا ضرب‌المثلی که همیشه می‌گویید؟', 'جمله‌ای که همیشه می‌گفت؟', '«از تو حرکت، از خدا برکت»'],
  ['شیرین‌ترین خاطره', 'شیرین‌ترین خاطره زندگیتان؟', 'شیرین‌ترین خاطره‌ای که از او دارید؟', 'روز تولد اولین نوه'],
  ['آرزو', 'بزرگ‌ترین آرزویتان چیست؟', 'بزرگ‌ترین آرزویش چه بود؟', 'سلامتی خانواده، ساختن مدرسه در روستا'],
  ['توصیه به نسل‌های بعد', 'چه توصیه‌ای برای نوه‌ها و نسل‌های بعد دارید؟', 'چه توصیه‌ای به نسل‌های بعد می‌کرد؟', 'درس بخوانید و به هم کمک کنید'],
  ['علت درگذشت', null, 'علت درگذشت (اختیاری)', 'بیماری قلبی، تصادف', { dead: true }],
];

export default async function interviewPage(container, { params, query }) {
  const page = h('div', { class: 'page narrow interview' }, loader());
  container.append(page);

  let person;
  try {
    person = (await get(`/api/persons/${params.id}`)).data;
  } catch (e) {
    page.replaceChildren(emptyState('alert', e.message));
    return;
  }
  if (!person.permissions?.edit) {
    page.replaceChildren(emptyState('lock', 'شما اجازه تکمیل این پروفایل را ندارید.', h('a', { class: 'btn', href: `#/person/${person.id}` }, 'بازگشت به پروفایل')));
    return;
  }
  document.title = `پرسش‌نامه ${fullName(person)} | ${store.config.site_name}`;

  const self = store.user?.person?.id === person.id;
  const name = person.first_name;
  const ask = (mine, other) => (self ? mine : other.replaceAll('{name}', name));
  const profile = store.config.profile || {};
  let mode = query?.all ? 'all' : 'missing';
  let steps = [];
  let index = 0;

  // ------------------------------------------------------------ تعریف سؤال‌ها
  function buildSteps() {
    const p = person;
    const list = [];
    const add = (step) => list.push(step);
    const has = (v) => v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && !v.length);

    // شبکه‌های اجتماعی (اول اینستاگرام)
    for (const key of PRIMARY_NETWORKS) {
      const def = networks()[key];
      if (!def) continue;
      if (key === 'whatsapp' && p.contact_hidden) continue;
      add({
        section: 'شبکه‌های اجتماعی', key: `social.${key}`, answered: has(p.social?.[key]),
        q: key === 'whatsapp' ? ask('شماره واتس‌اپ شما چیست؟', 'شماره واتس‌اپ {name} چیست؟') : ask(`آیدی ${def.label} شما چیست؟`, `آیدی ${def.label} {name} چیست؟`),
        hint: key === 'whatsapp' ? 'اگر همان شماره موبایل است، دکمه «همان شماره موبایل» را بزنید.' : 'مثلاً \u2066@ali.rezaei\u2069 — می‌توانید لینک صفحه را هم کپی کنید. اگر ندارید «رد شدن» را بزنید.',
        render: () => socialInput(key, p.social?.[key], { phone: p.phone }),
        collect: (box) => ({ social: { ...(p.social || {}), [key]: box.querySelector('input').value.trim() || null } }),
      });
    }
    const otherNetworks = Object.keys(networks()).filter((k) => !PRIMARY_NETWORKS.includes(k));
    add({
      section: 'شبکه‌های اجتماعی', key: 'social.others', answered: otherNetworks.some((k) => has(p.social?.[k])),
      q: ask('در شبکه‌های دیگر هم حساب دارید؟', 'آیا {name} در شبکه‌های دیگر هم حساب دارد؟'),
      hint: 'ایتا، بله، روبیکا، لینکدین، ایکس، یوتیوب، تیک‌تاک ... فقط هر کدام را که دارید پر کنید.',
      render: () => h('div', { class: 'form-grid' }, ...otherNetworks.map((k) => socialInput(k, p.social?.[k]))),
      collect: (box) => {
        const social = { ...(p.social || {}) };
        box.querySelectorAll('input[name^="social."]').forEach((i) => { social[i.name.slice(7)] = i.value.trim() || null; });
        return { social };
      },
    });

    // مشخصات
    add(simple('birth_date', 'مشخصات', ask('تاریخ تولدتان چیست؟', 'تاریخ تولد {name}؟'), () => dateInput('birth_date', p.birth_date), 'اگر فقط سال را می‌دانید، فقط سال را بنویسید.'));
    add(simple('birth_place', 'مشخصات', ask('کجا به دنیا آمدید؟', '{name} کجا به دنیا آمد؟'), () => textInput('birth_place', p.birth_place, 'شهر یا روستا')));
    add(simple('birth_order', 'مشخصات', ask('فرزند چندم خانواده‌تان هستید؟', '{name} فرزند چندم خانواده بود/است؟'), () => textInput('birth_order', p.birth_order ? fa(p.birth_order) : '', 'مثلاً ۲', { inputmode: 'numeric' })));
    add(simple('nickname', 'مشخصات', ask('در خانواده شما را با لقب یا اسم دیگری صدا می‌زنند؟', 'آیا {name} در خانواده لقب یا اسم دیگری داشت؟'), () => textInput('nickname', p.nickname, 'مثلاً آقاجون، ممدآقا')));
    if (p.is_deceased) {
      add(simple('death_date', 'درگذشت', 'تاریخ درگذشت؟', () => dateInput('death_date', p.death_date)));
      add(simple('death_place', 'درگذشت', 'محل درگذشت؟', () => textInput('death_place', p.death_place)));
      add(simple('burial_place', 'درگذشت', 'آرامگاه کجاست؟', () => textInput('burial_place', p.burial_place, 'مثلاً بهشت زهرا، قطعه ۲۴')));
    }

    // تحصیلات و شغل
    add({
      section: 'تحصیلات', key: 'education_level', answered: has(p.education_level),
      q: ask('آخرین مدرک تحصیلی شما چیست؟', 'آخرین مدرک تحصیلی {name}؟'),
      hint: '«دکتر» برای دکترا و بالاتر و «مهندس» برای لیسانس و فوق‌لیسانس رشته‌های فنی خودکار پیش از نام می‌آید.',
      render: () => {
        const level = selectInput('education_level', Object.entries(profile.education_levels || {}), p.education_level);
        const group = selectInput('education_field_group', Object.entries(profile.education_field_groups || {}).map(([k, g]) => [k, g.label]), p.education_field_group, '— گروه رشته —');
        const fieldI = textInput('education_field', p.education_field, 'رشته، مثلاً مهندسی برق یا پرستاری');
        const inst = textInput('education_institution', p.education_institution, 'دانشگاه / مدرسه / حوزه');
        const rank = selectInput('academic_rank', Object.entries(profile.academic_ranks || {}), p.academic_rank, '— مرتبه علمی (اگر عضو هیئت علمی است) —');
        const preview = h('div', { class: 'honorific-preview' });
        const render = () => {
          const t = honorificFor({ education_level: level.value, education_field_group: group.value, education_field: fieldI.value, academic_rank: rank.value, title: p.title, honorific_mode: p.honorific_mode });
          preview.replaceChildren(t ? h('span', null, icon('crown'), ` در درخت و چاپ: «${t} ${fullName({ first_name: p.first_name, last_name: p.last_name })}»`) : '');
        };
        [level, group, fieldI, rank].forEach((el) => { el.addEventListener('input', render); el.addEventListener('change', render); });
        render();
        return h('div', { class: 'stack' }, level, group, fieldI, inst, rank, preview);
      },
      collect: fieldsOf(['education_level', 'education_field_group', 'education_field', 'education_institution', 'academic_rank']),
    });
    add({
      section: 'شغل', key: 'occupation', answered: has(p.occupation) || has(p.workplace),
      q: ask('شغلتان چیست و کجا کار می‌کنید؟', 'شغل {name} چه بود/هست؟'),
      render: () => h('div', { class: 'stack' }, textInput('occupation', p.occupation, 'شغل یا سمت، مثلاً معلم، کشاورز، بازنشسته'), textInput('workplace', p.workplace, 'محل کار')),
      collect: fieldsOf(['occupation', 'workplace']),
    });

    // محل زندگی
    if (!p.is_deceased) {
      add({
        section: 'محل زندگی', key: 'city', answered: has(p.city) || has(p.country),
        q: ask('کجا زندگی می‌کنید؟', '{name} کجا زندگی می‌کند؟'),
        render: () => {
          const countries = (profile.countries || []).map((c) => [c, countryName(c)]).sort((a, b) => a[1].localeCompare(b[1], 'fa'));
          const provinces = h('datalist', { id: 'iv-prov' }, ...IRAN_PROVINCES.map((x) => h('option', { value: x })));
          return h('div', { class: 'stack' },
            selectInput('country', countries, p.country || 'IR', '— کشور —'),
            textInput('province', p.province, 'استان / ایالت', { list: 'iv-prov' }), provinces,
            textInput('city', p.city, 'شهر یا روستا'),
            textInput('residence', p.residence, 'محله / منطقه'));
        },
        collect: fieldsOf(['country', 'province', 'city', 'residence']),
      });
      if (!p.location_hidden) {
        add({
          section: 'محل زندگی', key: 'address', answered: has(p.address) || !!p.home_location,
          q: ask('نشانی دقیق و موقعیت خانه‌تان؟', 'نشانی و موقعیت خانه {name}؟'),
          hint: 'نشانی رمزنگاری‌شده ذخیره می‌شود و فقط برای کسانی که خودتان تعیین می‌کنید نمایش داده می‌شود (پیش‌فرض: بستگان درجه یک).',
          render: () => {
            const lat = h('input', { type: 'hidden', name: 'home_lat', value: p.home_location?.lat ?? '' });
            const lng = h('input', { type: 'hidden', name: 'home_lng', value: p.home_location?.lng ?? '' });
            const label = h('span', { class: 'muted small' }, p.home_location ? coordText(p.home_location) : 'انتخاب نشده');
            const btn = h('button', { class: 'btn soft', type: 'button', onclick: async () => {
              const picked = await pickLocation({ title: 'موقعیت خانه', value: lat.value ? { lat: +lat.value, lng: +lng.value } : null });
              if (picked === undefined) return;
              lat.value = picked ? picked.lat.toFixed(7) : '';
              lng.value = picked ? picked.lng.toFixed(7) : '';
              label.textContent = picked ? coordText(picked) : 'انتخاب نشده';
            } }, icon('pin'), 'انتخاب روی نقشه');
            return h('div', { class: 'stack' },
              h('textarea', { class: 'input', name: 'address', rows: 3, placeholder: 'خیابان، کوچه، پلاک، واحد' }, p.address || ''),
              textInput('postal_code', p.postal_code ? fa(p.postal_code) : '', 'کد پستی', { inputmode: 'numeric', class: 'input ltr-input' }),
              h('div', { class: 'row wrap', style: { gap: '8px', alignItems: 'center' } }, btn, label), lat, lng);
          },
          collect: (box) => {
            const data = fieldsOf(['address', 'postal_code', 'home_lat', 'home_lng'])(box);
            for (const k of ['postal_code', 'home_lat', 'home_lng']) if (typeof data[k] === 'string') data[k] = latin(data[k]);
            return data;
          },
        });
      }
      if (p.permissions?.privacy) {
        add({
          section: 'حریم خصوصی', key: 'location_visibility', answered: false, always: true,
          q: ask('چه کسانی نشانی خانه شما را ببینند؟', 'چه کسانی نشانی {name} را ببینند؟'),
          render: () => visibilityField('location_visibility', p.location_visibility || 'd1', p, 'نشانی و موقعیت خانه'),
          collect: fieldsOf(['location_visibility']),
        });
      }

      // راه‌های ارتباطی
      if (!p.contact_hidden) {
        add({
          section: 'راه‌های ارتباطی', key: 'landline', answered: has(p.landline) || has(p.website) || (p.permissions?.sensitive && has(p.email)),
          q: ask('تلفن ثابت، ایمیل یا وب‌سایت دارید؟', 'تلفن ثابت یا ایمیل {name}؟'),
          render: () => h('div', { class: 'stack' },
            textInput('landline', p.landline ? fa(p.landline) : '', 'تلفن ثابت با کد شهر', { type: 'tel', inputmode: 'tel', class: 'input ltr-input' }),
            p.permissions?.sensitive ? textInput('email', p.email, 'ایمیل', { type: 'email', class: 'input ltr-input' }) : null,
            textInput('website', p.website, 'وب‌سایت یا وبلاگ', { type: 'url', class: 'input ltr-input' })),
          collect: (box) => {
            const data = fieldsOf(['landline', 'email', 'website'])(box);
            if (typeof data.landline === 'string') data.landline = latin(data.landline);
            return data;
          },
        });
      }
      if (p.permissions?.privacy) {
        add({
          section: 'حریم خصوصی', key: 'contact_visibility', answered: false, always: true,
          q: ask('چه کسانی شماره موبایل و واتس‌اپ شما را ببینند؟', 'چه کسانی شماره {name} را ببینند؟'),
          hint: 'پیش‌فرض: همه اعضای شجره‌نامه. می‌توانید به بستگان نزدیک محدود کنید و ببینید دقیقاً چه کسانی هستند.',
          render: () => visibilityField('contact_visibility', p.contact_visibility || 'all', p, 'شماره موبایل، واتس‌اپ، ایمیل و تلفن'),
          collect: fieldsOf(['contact_visibility']),
        });
      }
    }

    // ویژگی‌ها
    add(simple('blood_type', 'ویژگی‌ها', ask('گروه خونی‌تان؟', 'گروه خونی {name}؟'), () => selectInput('blood_type', (profile.blood_types || []).map((b) => [b, b]), p.blood_type), 'در مواقع اضطراری به درد بستگان می‌خورد.'));
    add(simple('languages', 'ویژگی‌ها', ask('به چه زبان‌ها یا گویش‌هایی صحبت می‌کنید؟', '{name} به چه زبان‌هایی صحبت می‌کرد/می‌کند؟'), () => textInput('languages', p.languages, 'فارسی، ترکی، کردی، انگلیسی ...')));
    add(simple('interests', 'ویژگی‌ها', ask('سرگرمی‌ها و علاقه‌مندی‌هایتان؟', 'سرگرمی‌ها و علاقه‌مندی‌های {name}؟'), () => textInput('interests', p.interests, 'کتاب‌خوانی، باغبانی، ماهیگیری ...')));
    for (const [label, mine, other, example, cond = {}] of PROMPTS) {
      if (cond.gender && p.gender !== cond.gender) continue;
      if (cond.alive && p.is_deceased) continue;
      if (cond.dead && !p.is_deceased) continue;
      const existing = (p.custom_fields || []).find((f) => f.label === label);
      add({
        section: 'چیزهایی که شاید به ذهنتان نرسد', key: `cf.${label}`, answered: !!existing,
        q: mine && self ? mine : other.replaceAll('{name}', name),
        render: () => h('textarea', { class: 'input', name: 'cf', rows: 2, placeholder: `مثلاً ${example}`, maxlength: 300 }, existing?.value || ''),
        collect: (box) => {
          const value = box.querySelector('textarea').value.trim();
          const rows = (p.custom_fields || []).filter((f) => f.label !== label);
          if (value) rows.push({ label, value });
          return { custom_fields: rows };
        },
      });
    }

    // بیوگرافی و زندگی‌نامه (متن‌های رنگی با نام نویسنده)
    for (const [field, q, rows, hint] of [
      ['summary', ask('در چند جمله خودتان را معرفی کنید (بیوگرافی).', 'در چند جمله {name} را معرفی کنید (بیوگرافی).'), 4, 'چه کسی هستید، چه کرده‌اید و به چه شناخته می‌شوید.'],
      ['biography', ask('داستان زندگی‌تان را بنویسید (زندگی‌نامه و خاطرات).', 'داستان زندگی {name} و خاطراتتان از او را بنویسید.'), 9, 'کودکی، ازدواج، مهاجرت، سختی‌ها، موفقیت‌ها، خاطره‌ها ... هر چقدر که دوست دارید.'],
    ]) {
      if (!profile.texts?.[field]) continue;
      const current = person.texts?.[field];
      const plain = (current?.segments || []).map((seg) => seg[1] ?? '').join('');
      add({
        section: 'بیوگرافی', key: `text.${field}`, answered: plain.trim() !== '', q, hint,
        render: () => h('textarea', { class: 'input', name: field, rows, maxlength: profile.texts[field].max }, plain),
        save: async (box) => {
          const text = box.querySelector('textarea').value;
          if (text.trim() === plain.trim()) return null;
          await put(`/api/persons/${person.id}/texts/${field}`, { text, base_revision: current?.revision ?? 0 });
          return true;
        },
      });
    }

    // عکس
    if (person.permissions?.upload) {
      add({
        section: 'عکس', key: 'avatar', answered: !!p.avatar,
        q: ask('یک عکس خوب از خودتان بگذارید.', 'یک عکس از {name} بگذارید.'),
        hint: 'عکسی که صورت واضح باشد؛ بعد از انتخاب می‌توانید آن را برش بزنید. اگر عکسی در سایت نباشد، عکس پروفایل اینستاگرام، واتس‌اپ یا تلگرام نمایش داده می‌شود.',
        render: () => {
          const wrap = h('div', { class: 'row wrap', style: { gap: '14px', alignItems: 'center' } });
          const file = h('input', { type: 'file', accept: 'image/*', hidden: true });
          const draw = () => wrap.replaceChildren(avatar(person, 'xl'), h('button', { class: 'btn soft', type: 'button', onclick: () => file.click() }, icon('camera'), person.avatar ? 'عوض کردن عکس' : 'انتخاب عکس'), file);
          file.addEventListener('change', async () => {
            const f = file.files[0];
            file.value = '';
            if (!f) return;
            const blob = await cropImage(f);
            if (!blob) return;
            const form = new FormData();
            form.append('file', blob, 'avatar.jpg');
            try {
              const res = await upload(`/api/persons/${person.id}/avatar`, form);
              toast(res.message, res.data.status === 'approved' ? 'success' : 'info', 6000);
              await refresh();
              draw();
            } catch (e) {
              toastError(e);
            }
          });
          draw();
          return wrap;
        },
        save: async () => null,
      });
    }

    // پدر و مادر
    for (const [type, label] of [['father', 'پدر'], ['mother', 'مادر']]) {
      if (p[`${type}_id`]) continue;
      add({
        section: 'خانواده', key: type, answered: false,
        q: ask(`نام ${label}تان چیست؟`, `نام ${label} {name}؟`),
        hint: `${label} هنوز در شجره‌نامه ثبت نشده. اگر ${label} از قبل در شجره‌نامه هست، از صفحه پروفایل «افزودن بستگان ← انتخاب از افراد موجود» را بزنید تا تکراری ساخته نشود.`,
        render: () => h('div', { class: 'stack' }, textInput('first_name', '', `نام ${label}`), textInput('last_name', type === 'father' ? p.last_name : '', 'نام خانوادگی')),
        save: async (box) => {
          const first = box.querySelector('[name=first_name]').value.trim();
          if (!first) return null;
          await post(`/api/persons/${person.id}/relatives`, { type, first_name: first, last_name: box.querySelector('[name=last_name]').value.trim(), gender: type === 'father' ? 'm' : 'f' });
          return true;
        },
      });
    }

    return list;

    function simple(key, section, q, render, hint) {
      return { section, key, answered: has(p[key]), q, hint, render, collect: fieldsOf([key]) };
    }
  }

  // ------------------------------------------------------------ نمایش
  const body = h('div', { class: 'iv-body' });
  const modeSwitch = segmented([
    { value: 'missing', label: 'فقط سؤال‌های بی‌جواب' },
    { value: 'all', label: 'همه سؤال‌ها' },
  ], mode, (v) => { mode = v; restart(); }, { compact: false });

  page.replaceChildren(
    h('div', { class: 'page-head' },
      h('div', null,
        h('h1', null, icon('sparkles'), ' ', self ? 'چند سؤال درباره شما' : `چند سؤال درباره ${fullName(person)}`),
        h('p', { class: 'muted', style: { margin: 0 } }, 'به هر سؤال که دوست دارید جواب دهید؛ بقیه را «رد شدن» بزنید. هر جواب همان لحظه ذخیره می‌شود.'),
      ),
    ),
    modeSwitch,
    body,
  );
  restart();

  function restart() {
    steps = buildSteps().filter((s) => mode === 'all' || !s.answered || s.always);
    index = 0;
    render();
  }

  async function refresh() {
    person = (await get(`/api/persons/${person.id}`)).data;
  }

  function render() {
    if (index >= steps.length) {
      finish();
      return;
    }
    const step = steps[index];
    const box = h('div', { class: 'iv-answer' }, step.render());
    const next = h('button', { class: 'btn primary lg', type: 'submit' }, index === steps.length - 1 ? 'ذخیره و پایان' : 'ذخیره و بعدی', icon('chevron-left'));
    const form = h('form', { class: 'card iv-card', novalidate: true },
      h('div', { class: 'iv-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': steps.length, 'aria-valuenow': index + 1 },
        h('div', { style: { width: `${Math.round(((index + 1) / steps.length) * 100)}%` } })),
      h('div', { class: 'row between small muted' }, h('span', null, step.section), h('span', null, `${fa(index + 1)} از ${fa(steps.length)}`)),
      h('h2', { class: 'iv-q' }, step.q),
      step.hint ? h('p', { class: 'muted small' }, step.hint) : null,
      box,
      h('div', { class: 'iv-actions' },
        h('button', { class: 'btn ghost', type: 'button', title: 'قبلی', disabled: index === 0, onclick: () => { index--; render(); } }, icon('chevron-right'), h('span', { class: 'hide-mobile' }, 'قبلی')),
        h('button', { class: 'btn', type: 'button', onclick: () => { index++; render(); } }, 'رد شدن'),
        next,
      ),
    );
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      await withLoading(next, async () => {
        try {
          let changed = null;
          if (step.save) changed = await step.save(box);
          else {
            const data = step.collect(box);
            if (data && Object.keys(data).length) {
              person = (await patch(`/api/persons/${person.id}`, data)).data;
              changed = true;
            }
          }
          if (changed && step.save) await refresh();
          index++;
          render();
        } catch (err) {
          const first = err?.errors ? Object.values(err.errors)[0] : null;
          toastError(first ? { message: Array.isArray(first) ? first[0] : first } : err);
        }
      });
    });
    body.replaceChildren(form);
    setTimeout(() => form.querySelector('input:not([type=hidden]),textarea,select')?.focus({ preventScroll: true }), 50);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function finish() {
    const done = person.completeness;
    body.replaceChildren(h('div', { class: 'card iv-card center' },
      h('div', { style: { fontSize: '3rem' } }, '🌳'),
      h('h2', null, 'ممنون!'),
      h('p', null, steps.length ? 'جواب‌ها ذخیره شد.' : 'به همه سؤال‌ها جواب داده شده است.', done ? ` پروفایل ${fa(done.percent)}٪ کامل است.` : ''),
      h('div', { class: 'row wrap', style: { justifyContent: 'center', gap: '8px' } },
        h('a', { class: 'btn primary', href: `#/person/${person.id}` }, icon('user'), 'دیدن پروفایل'),
        mode === 'missing' ? h('button', { class: 'btn', type: 'button', onclick: () => { mode = 'all'; modeSwitch.setValue?.('all'); restart(); } }, 'مرور همه سؤال‌ها') : null,
        h('a', { class: 'btn ghost', href: `#/person/${person.id}/edit` }, icon('edit'), 'فرم کامل'),
      ),
    ));
  }

  // ------------------------------------------------------------ ابزارها
  function fieldsOf(keys) {
    return (box) => {
      const data = {};
      for (const k of keys) {
        const el = box.querySelector(`[name="${k}"]`);
        if (!el) continue;
        data[k] = el.value.trim();
        if (k === 'birth_order') data[k] = latin(data[k]);
      }
      // بدون تغییر ← چیزی ارسال نمی‌شود
      const changed = Object.entries(data).some(([k, v]) => String(person[k] ?? '') !== v && !(k.startsWith('home_') && person.home_location && String(person.home_location[k === 'home_lat' ? 'lat' : 'lng']) === v));
      return changed ? data : null;
    };
  }
}

function textInput(name, value, placeholder = '', attrs = {}) {
  return h('input', { class: 'input', name, value: value ?? '', placeholder, autocomplete: 'off', ...attrs });
}

function selectInput(name, options, value, placeholder = '— انتخاب کنید —') {
  return h('select', { class: 'input', name },
    h('option', { value: '' }, placeholder),
    ...options.map(([v, label]) => h('option', { value: v, selected: v === value }, label)));
}
