/**
 * قالب‌بندی متن و تاریخ فارسی.
 *
 * تاریخ‌ها در سرور به صورت «تاریخ جزئی شمسی» هستند: "1305" یا "1305-07" یا "1305-07-12"
 */

export const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

/** اعداد لاتین ← فارسی */
export function fa(value) {
  if (value === null || value === undefined) return '';
  return String(value).replace(/\d/g, (d) => FA_DIGITS[d]);
}

/** اعداد فارسی/عربی ← لاتین */
export function latin(value) {
  return String(value ?? '')
    .replace(/[۰-۹]/g, (d) => String(FA_DIGITS.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
}

/** عدد با جداکننده هزارگان فارسی */
export function num(n) {
  return fa(Number(n || 0).toLocaleString('en-US').replace(/,/g, '٬'));
}

// ------------------------------------------------------------------
// تبدیل تاریخ شمسی ↔ میلادی (الگوریتم jalaali-js)
// ------------------------------------------------------------------
const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
const div = (a, b) => ~~(a / b);
const mod = (a, b) => a - ~~(a / b) * b;

function jalCal(jy) {
  let leapJ = -14;
  let jp = BREAKS[0];
  let jump = 0;
  const gy = jy + 621;
  for (let i = 1; i < BREAKS.length; i++) {
    const jm = BREAKS[i];
    jump = jm - jp;
    if (jy < jm) break;
    leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4);
    jp = jm;
  }
  let n = jy - jp;
  leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
  if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
  const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
  const march = 20 + leapJ - leapG;
  if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
  let leap = mod(mod(n + 1, 33) - 1, 4);
  if (leap === -1) leap = 4;
  return { leap, gy, march };
}

function g2d(gy, gm, gd) {
  let d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
  return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
}

function d2g(jdn) {
  let j = 4 * jdn + 139361631;
  j += div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
  const i = div(mod(j, 1461), 4) * 5 + 308;
  const gd = div(mod(i, 153), 5) + 1;
  const gm = mod(div(i, 153), 12) + 1;
  const gy = div(j, 1461) - 100100 + div(8 - gm, 6);
  return [gy, gm, gd];
}

export function toJalali(gy, gm, gd) {
  const jdn = g2d(gy, gm, gd);
  const g = d2g(jdn)[0];
  let jy = g - 621;
  const r = jalCal(jy);
  let k = jdn - g2d(g, 3, r.march);
  if (k >= 0) {
    if (k <= 185) return [jy, 1 + div(k, 31), mod(k, 31) + 1];
    k -= 186;
  } else {
    jy -= 1;
    k += 179;
    if (r.leap === 1) k += 1;
  }
  return [jy, 7 + div(k, 30), mod(k, 30) + 1];
}

export function toGregorian(jy, jm, jd) {
  const r = jalCal(jy);
  return d2g(g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1);
}

export function isLeapJalali(jy) {
  return jalCal(jy).leap === 0;
}

export function monthLength(jy, jm) {
  if (jm <= 6) return 31;
  if (jm <= 11) return 30;
  return isLeapJalali(jy) ? 30 : 29;
}

export function todayJalali() {
  const d = new Date();
  return toJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
}

// ------------------------------------------------------------------
// تاریخ جزئی
// ------------------------------------------------------------------

/** "1305-07-12" → {y, m, d} */
export function parsePartial(value) {
  if (!value) return null;
  const m = latin(value).match(/^(\d{1,4})(?:[-/.](\d{1,2})(?:[-/.](\d{1,2}))?)?$/);
  if (!m) return null;
  return { y: +m[1], m: m[2] ? +m[2] : null, d: m[3] ? +m[3] : null };
}

/** نمایش تاریخ: «۱۲ مهر ۱۳۰۵» / «مهر ۱۳۰۵» / «۱۳۰۵» */
export function formatDate(value, calendar = 'jalali') {
  const p = parsePartial(value);
  if (!p) return '';
  if (calendar === 'gregorian') {
    if (p.m && p.d) {
      const [gy, gm, gd] = toGregorian(p.y, p.m, p.d);
      return `${gd}/${gm}/${gy}`;
    }
    return `~${p.y + 621}`;
  }
  return fa([p.d, p.m ? MONTHS[p.m - 1] : null, p.y].filter(Boolean).join(' '));
}

/** فقط سال */
export function yearOf(value) {
  const p = parsePartial(value);
  return p ? p.y : null;
}

/** بازه عمر: «۱۳۰۵ – ۱۳۸۲» */
export function lifespan(person, { full = false } = {}) {
  const b = full ? formatDate(person.birth_date) : fa(yearOf(person.birth_date) ?? '');
  const d = full ? formatDate(person.death_date) : fa(yearOf(person.death_date) ?? '');
  if (person.is_deceased) {
    if (b && d) return `${b} - ${d}`;
    if (d) return `وفات ${d}`;
    if (b) return `${b} - ؟`;
    return 'شادروان';
  }
  return b ? `متولد ${b}` : '';
}

/** سن (یا سن هنگام فوت) */
export function age(person) {
  const b = parsePartial(person.birth_date);
  if (!b) return null;
  const end = person.is_deceased ? parsePartial(person.death_date) : { y: todayJalali()[0], m: todayJalali()[1], d: todayJalali()[2] };
  if (!end) return null;
  let years = end.y - b.y;
  if (b.m && end.m && (end.m < b.m || (end.m === b.m && b.d && end.d && end.d < b.d))) years -= 1;
  return years >= 0 ? years : null;
}

// ------------------------------------------------------------------
// اشخاص
// ------------------------------------------------------------------

/**
 * نام کامل با عنوان: «حاج دکتر محمد احمدی».
 * display_title را سرور می‌سازد (عنوان دستی + «دکتر/مهندس» خودکار با ترتیب درست).
 */
export function fullName(p, withTitle = true) {
  if (!p) return '';
  const title = withTitle ? (p.display_title !== undefined ? p.display_title : p.title) : null;
  return [title, p.first_name, p.last_name].filter(Boolean).join(' ');
}

/** نام با عنوان علمی خودکار («دکتر علی احمدی»، «مهندس سارا رضایی») بدون عنوان‌های دیگر */
export function academicName(p) {
  if (!p) return '';
  return [p.honorific, p.first_name, p.last_name].filter(Boolean).join(' ');
}

export function genderLabel(g) {
  return g === 'f' ? 'زن' : 'مرد';
}

// ------------------------------------------------------------------
// زمان نسبی و حجم فایل
// ------------------------------------------------------------------

export function timeAgo(iso) {
  if (!iso) return '';
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return 'لحظاتی پیش';
  if (diff < 3600) return `${fa(Math.floor(diff / 60))} دقیقه پیش`;
  if (diff < 86400) return `${fa(Math.floor(diff / 3600))} ساعت پیش`;
  if (diff < 86400 * 7) return `${fa(Math.floor(diff / 86400))} روز پیش`;
  return dateTime(iso, false);
}

export function dateTime(iso, withTime = true) {
  if (!iso) return '';
  const d = new Date(iso);
  const [jy, jm, jd] = toJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
  const date = `${jd} ${MONTHS[jm - 1]} ${jy}`;
  const time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  return fa(withTime ? `${date} - ${time}` : date);
}

export function fileSize(bytes) {
  if (!bytes) return '';
  const units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
  let i = 0;
  let n = bytes;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return `${fa(n.toFixed(i ? 1 : 0))} ${units[i]}`;
}

export function duration(seconds) {
  if (!seconds) return '';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return fa(`${m}:${String(s).padStart(2, '0')}`);
}

/** نمایش خوانای شماره کارت (۴تا۴تا) و شبا (IRxx xxxx ...) */
export function bankNumber(kind, value) {
  const v = String(value ?? '');
  if (kind === 'card') return v.replace(/(\d{4})(?=\d)/g, '$1 ');
  if (kind === 'sheba') return v.replace(/^(IR\d{2})(\d{4})(\d{4})(\d{4})(\d{4})(\d{4})(\d{2})$/, '$1 $2 $3 $4 $5 $6 $7');
  return v;
}
