// تست‌های واحد ماژول قالب‌بندی (بدون مرورگر): node --test tests/js/
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  fa, latin, toJalali, toGregorian, isLeapJalali, monthLength, parsePartial, formatDate, yearOf, bankNumber,
} from '../../public/assets/js/core/format.js';

test('اعداد فارسی و لاتین به هم تبدیل می‌شوند', () => {
  assert.equal(fa('1403-01-05'), '۱۴۰۳-۰۱-۰۵');
  assert.equal(latin('۱۴۰۳/۰۱/۰۵'), '1403/01/05');
  assert.equal(latin('٠١٢٣٤٥٦٧٨٩'), '0123456789');
  assert.equal(fa(null), '');
});

test('تبدیل تاریخ‌های شناخته‌شده شمسی و میلادی', () => {
  const known = [
    [[2024, 3, 20], [1403, 1, 1]],
    [[2025, 3, 20], [1403, 12, 30]], // ۱۴۰۳ کبیسه است
    [[2025, 3, 21], [1404, 1, 1]],
    [[2021, 3, 20], [1399, 12, 30]], // ۱۳۹۹ کبیسه است
    [[1979, 2, 11], [1357, 11, 22]],
    [[2000, 1, 1], [1378, 10, 11]],
  ];
  for (const [g, j] of known) {
    assert.deepEqual(toJalali(...g), j, `toJalali(${g})`);
    assert.deepEqual(toGregorian(...j), g, `toGregorian(${j})`);
  }
});

test('رفت‌وبرگشت تبدیل برای هر روز از ۱۲۰۰ تا ۱۵۰۰ سازگار است', () => {
  for (let jy = 1200; jy <= 1500; jy += 7) {
    for (let jm = 1; jm <= 12; jm++) {
      for (let jd = 1; jd <= monthLength(jy, jm); jd++) {
        const g = toGregorian(jy, jm, jd);
        assert.deepEqual(toJalali(...g), [jy, jm, jd]);
      }
    }
  }
});

test('سال کبیسه و طول ماه‌ها', () => {
  assert.equal(isLeapJalali(1403), true);
  assert.equal(isLeapJalali(1404), false);
  assert.equal(monthLength(1404, 12), 29);
  assert.equal(monthLength(1403, 12), 30);
  assert.equal(monthLength(1404, 6), 31);
  assert.equal(monthLength(1404, 7), 30);
});

test('تاریخ جزئی', () => {
  assert.deepEqual(parsePartial('1305'), { y: 1305, m: null, d: null });
  assert.deepEqual(parsePartial('۱۳۰۵/۷'), { y: 1305, m: 7, d: null });
  assert.deepEqual(parsePartial('1305-07-12'), { y: 1305, m: 7, d: 12 });
  assert.equal(parsePartial('abc'), null);
  assert.equal(formatDate('1305-07-12'), '۱۲ مهر ۱۳۰۵');
  assert.equal(formatDate('1305-07'), 'مهر ۱۳۰۵');
  assert.equal(yearOf('1305-07-12'), 1305);
});

test('شماره کارت و شبا خوانا نمایش داده می‌شوند', () => {
  assert.equal(bankNumber('card', '6037991199500590'), '6037 9911 9950 0590');
  assert.equal(bankNumber('sheba', 'IR820540102680020817909002'), 'IR82 0540 1026 8002 0817 9090 02');
  assert.equal(bankNumber('account', '0101-123'), '0101-123');
  assert.equal(bankNumber('link', null), '');
});

test('سال رویداد تاریخی از یادداشت تقویم رسمی', async () => {
  const { eventYear } = await import('../../public/assets/js/core/format.js');
  assert.equal(eventYear('۱۳ دی 1338'), 'سال ۱۳۳۸');
  assert.equal(eventYear('January 3 1892'), '۱۸۹۲ میلادی');
  assert.equal(eventYear('March 21 1900'), '');
  assert.equal(eventYear('۱۳ رجب'), '');
  assert.equal(eventYear(''), '');
  assert.equal(eventYear(null), '');
});
