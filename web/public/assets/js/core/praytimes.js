/**
 * اوقات شرعی (روش مؤسسه ژئوفیزیک دانشگاه تهران): اذان صبح ۱۷٫۷ درجه، اذان مغرب ۴٫۵ درجه پس از غروب، نیمه‌شب شرعی
 * میانه غروب تا اذان صبح. کاملاً در مرورگر محاسبه می‌شود (بدون بار روی سرور و بدون اینترنت).
 * فرمول‌ها: موقعیت خورشید از روز ژولیانی (معادله زمان و میل خورشید)، طلوع/غروب با ۰٫۸۳۳ درجه (شکست نور و قرص خورشید).
 */
const DEG = Math.PI / 180;
const sin = (d) => Math.sin(d * DEG);
const cos = (d) => Math.cos(d * DEG);
const asin = (x) => Math.asin(Math.max(-1, Math.min(1, x))) / DEG;
const acos = (x) => Math.acos(Math.max(-1, Math.min(1, x))) / DEG;
const atan2 = (y, x) => Math.atan2(y, x) / DEG;
const fix = (a, b) => { const r = a - b * Math.floor(a / b); return r < 0 ? r + b : r; };

function julian(y, m, d) {
  if (m <= 2) { y -= 1; m += 12; }
  const A = Math.floor(y / 100);
  const B = 2 - A + Math.floor(A / 4);
  return Math.floor(365.25 * (y + 4716)) + Math.floor(30.6001 * (m + 1)) + d + B - 1524.5;
}

function sun(jd) {
  const D = jd - 2451545.0;
  const g = fix(357.529 + 0.98560028 * D, 360);
  const q = fix(280.459 + 0.98564736 * D, 360);
  const L = fix(q + 1.915 * sin(g) + 0.020 * sin(2 * g), 360);
  const e = 23.439 - 0.00000036 * D;
  const RA = atan2(cos(e) * sin(L), cos(L)) / 15;
  return { decl: asin(sin(e) * sin(L)), eqt: q / 15 - fix(RA, 24) };
}

export const TEHRAN_METHOD = { fajr: 17.7, maghrib: 4.5 };

/** شهرهای مرکز استان‌ها */
export const CITIES = [
  ['تهران', 35.6892, 51.3890], ['مشهد', 36.2605, 59.6168], ['اصفهان', 32.6546, 51.6680], ['شیراز', 29.5918, 52.5837],
  ['تبریز', 38.0962, 46.2738], ['کرج', 35.8400, 50.9391], ['اهواز', 31.3183, 48.6706], ['قم', 34.6399, 50.8759],
  ['کرمانشاه', 34.3142, 47.0650], ['ارومیه', 37.5527, 45.0760], ['رشت', 37.2808, 49.5832], ['زاهدان', 29.4963, 60.8629],
  ['همدان', 34.7983, 48.5148], ['کرمان', 30.2839, 57.0834], ['یزد', 31.8974, 54.3569], ['اردبیل', 38.2498, 48.2933],
  ['بندرعباس', 27.1832, 56.2666], ['اراک', 34.0954, 49.7013], ['زنجان', 36.6736, 48.4787], ['سنندج', 35.3219, 46.9862],
  ['قزوین', 36.2688, 50.0041], ['خرم‌آباد', 33.4878, 48.3558], ['گرگان', 36.8456, 54.4393], ['ساری', 36.5633, 53.0601],
  ['بوشهر', 28.9234, 50.8203], ['بیرجند', 32.8649, 59.2262], ['بجنورد', 37.4747, 57.3290], ['ایلام', 33.6374, 46.4227],
  ['شهرکرد', 32.3256, 50.8644], ['سمنان', 35.5769, 53.3950], ['یاسوج', 30.6682, 51.5880],
];

/**
 * اوقات شرعی یک روز (میلادی) برای یک مکان
 *
 * @returns {{fajr:number, sunrise:number, dhuhr:number, sunset:number, maghrib:number, midnight:number}} زمان‌ها به میلی‌ثانیه (UTC)
 */
export function prayerTimes(gy, gm, gd, lat, lng, method = TEHRAN_METHOD) {
  const jDate = julian(gy, gm, gd) - lng / (15 * 24);
  const midDay = (t) => fix(12 - sun(jDate + t).eqt, 24);
  const angleTime = (angle, t, ccw) => {
    const { decl } = sun(jDate + t);
    const noon = midDay(t);
    const T = acos((-sin(angle) - sin(decl) * sin(lat)) / (cos(decl) * cos(lat))) / 15;
    return noon + (ccw ? -T : T);
  };
  // دو دور محاسبه با حدس اولیه برای دقت بیشتر
  let t = { fajr: 5, sunrise: 6, dhuhr: 12, sunset: 18, maghrib: 18 };
  for (let i = 0; i < 2; i++) {
    const p = Object.fromEntries(Object.entries(t).map(([k, v]) => [k, v / 24]));
    t = {
      fajr: angleTime(method.fajr, p.fajr, true),
      sunrise: angleTime(0.833, p.sunrise, true),
      dhuhr: midDay(p.dhuhr),
      sunset: angleTime(0.833, p.sunset, false),
      maghrib: angleTime(method.maghrib, p.maghrib, false),
    };
  }
  // ساعت محلی خورشیدی ← UTC
  const utc = Object.fromEntries(Object.entries(t).map(([k, v]) => [k, v - lng / 15]));
  utc.midnight = utc.sunset + fix(utc.fajr - utc.sunset, 24) / 2;
  const base = Date.UTC(gy, gm - 1, gd);
  return Object.fromEntries(Object.entries(utc).map(([k, v]) => [k, Number.isFinite(v) ? base + Math.round(v * 3600) * 1000 : null]));
}

export const PRAYER_LABELS = {
  fajr: 'اذان صبح', sunrise: 'طلوع آفتاب', dhuhr: 'اذان ظهر', sunset: 'غروب آفتاب', maghrib: 'اذان مغرب', midnight: 'نیمه‌شب شرعی',
};
