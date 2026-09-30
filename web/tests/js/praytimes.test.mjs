// اوقات شرعی (روش مؤسسه ژئوفیزیک): مقایسه با جدول رسمی تهران و درستی ترتیب زمان‌ها
import test from 'node:test';
import assert from 'node:assert/strict';
import { prayerTimes, CITIES, PRAYER_LABELS } from '../../public/assets/js/core/praytimes.js';

const hm = (ms) => new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Tehran', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(ms);
const minutes = (ms) => { const [h, m] = hm(ms).split(':').map(Number); return h * 60 + m; };
const near = (ms, expected, tolerance = 2) => {
  const [h, m] = expected.split(':').map(Number);
  assert.ok(Math.abs(minutes(ms) - (h * 60 + m)) <= tolerance, `${hm(ms)} ≠ ${expected}`);
};

test('تهران، ۱ فروردین ۱۴۰۵ نزدیک جدول رسمی', () => {
  const t = prayerTimes(2026, 3, 21, 35.6892, 51.3890);
  near(t.fajr, '04:44');
  near(t.sunrise, '06:08');
  near(t.dhuhr, '12:11');
  near(t.sunset, '18:16');
  near(t.maghrib, '18:35');
  near(t.midnight, '23:29');
});

test('ترتیب زمان‌ها و طول روز در انقلاب تابستانی و زمستانی', () => {
  for (const [gy, gm, gd] of [[2026, 6, 21], [2026, 12, 21], [2027, 3, 1]]) {
    for (const [, lat, lng] of CITIES) {
      const t = prayerTimes(gy, gm, gd, lat, lng);
      const order = ['fajr', 'sunrise', 'dhuhr', 'sunset', 'maghrib', 'midnight'].map((k) => t[k]);
      order.forEach((v) => assert.ok(Number.isFinite(v)));
      for (let i = 1; i < order.length; i++) assert.ok(order[i] > order[i - 1], `${lat},${lng} ${gy}-${gm}-${gd}`);
      const maghribGap = (t.maghrib - t.sunset) / 60000;
      assert.ok(maghribGap > 14 && maghribGap < 26, `maghrib gap ${maghribGap}`);
    }
  }
  const summer = prayerTimes(2026, 6, 21, 35.6892, 51.3890);
  const winter = prayerTimes(2026, 12, 21, 35.6892, 51.3890);
  assert.ok(summer.sunset - summer.sunrise > winter.sunset - winter.sunrise + 4 * 3600000);
});

test('همه ۳۱ مرکز استان و برچسب‌ها', () => {
  assert.equal(CITIES.length, 31);
  for (const [name, lat, lng] of CITIES) {
    assert.ok(name && lat > 25 && lat < 40 && lng > 44 && lng < 64, name);
  }
  assert.deepEqual(Object.keys(PRAYER_LABELS), ['fajr', 'sunrise', 'dhuhr', 'sunset', 'maghrib', 'midnight']);
});

test('عرض‌های بالا بدون خطا (زمان نامعلوم = null یا عدد)', () => {
  const t = prayerTimes(2026, 6, 21, 69.65, 18.95);
  for (const v of Object.values(t)) assert.ok(v === null || Number.isFinite(v));
});
