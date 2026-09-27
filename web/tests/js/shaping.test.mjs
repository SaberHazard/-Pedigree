// تست اتصال حروف فارسی و ترتیب دیداری متن برای PDF برداری
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { shapeArabic, reorderRtl, layoutText } from '../../public/assets/js/tree/pdf/shaping.js';
import { TrueTypeFont } from '../../public/assets/js/tree/pdf/ttf.js';

const hex = (items) => items.map((i) => i.cp.toString(16)).join(' ');
const font = new TrueTypeFont(readFileSync(new URL('../../public/assets/fonts/ttf/Vazirmatn-UI-FD-Bold.ttf', import.meta.url)));

test('خواندن فونت TrueType', () => {
  assert.equal(font.unitsPerEm, 2048);
  assert.ok(font.numGlyphs > 1000);
  assert.ok(font.glyphId(0x0627) > 0, 'alef');
  assert.ok(font.glyphId(0xfb58) > 0, 'initial peh');
  assert.ok(font.advance(font.glyphId(0x0627)) > 0.1);
  assert.match(font.name, /Vazirmatn/);
});

test('شکل حروف: آغازی، میانی، پایانی، تنها', () => {
  // «علی»: ع آغازی، ل میانی، ی پایانی
  assert.equal(hex(shapeArabic('علی')), 'fecb fee0 fbfd');
  // «محمد»: م آغازی، ح میانی، م میانی، د پایانی
  assert.equal(hex(shapeArabic('محمد')), 'fee3 fea4 fee4 feaa');
  // «دارا»: حروف راست‌چسب (د، ا، ر) به حرف بعدی نمی‌چسبند؛ همه تنها
  assert.equal(hex(shapeArabic('دارا')), 'fea9 fe8d fead fe8d');
  // «بابا»: ب آغازی و الف پایانی؛ ب دوم بعد از الف دوباره آغازی
  assert.equal(hex(shapeArabic('بابا')), 'fe91 fe8e fe91 fe8e');
  // «پگاه» با حروف فارسی
  assert.equal(hex(shapeArabic('پگاه')), 'fb58 fb95 fe8e fee9');
});

test('لام‌الف و نیم‌فاصله', () => {
  assert.equal(hex(shapeArabic('لا')), 'fefb');
  assert.equal(hex(shapeArabic('سلام')), 'feb3 fefc fee1');
  // نیم‌فاصله اتصال را قطع می‌کند و خودش رسم نمی‌شود: «می‌کرد»
  // «می» با ی پایانی تمام می‌شود، «کرد» از نو با ک آغازی؛ د بعد از ر تنها است
  assert.equal(hex(shapeArabic('می‌کرد')), 'fee3 fbfd fb90 feae fea9');
});

test('ترتیب دیداری: متن فارسی وارونه، اعداد چپ‌به‌راست', () => {
  const vis = (s) => reorderRtl(Array.from(s, (c) => ({ cp: c.codePointAt(0) }))).map((i) => String.fromCodePoint(i.cp)).join('');
  assert.equal(vis('ابج'), 'جبا');
  assert.equal(vis('متولد ۱۳۶۸'), '۱۳۶۸ دلوتم');
  // بازه عمر: هر عدد چپ‌به‌راست، ترتیب اعداد راست‌به‌چپ
  assert.equal(vis('۱۳۰۵ - ۱۳۸۸'), '۱۳۸۸ - ۱۳۰۵');
  // تاریخ کامل یک عدد واحد است
  assert.equal(vis('۱۳۰۵/۰۷/۱۲'), '۱۳۰۵/۰۷/۱۲');
  // پرانتز قرینه می‌شود؛ کلمه لاتین چپ‌به‌راست
  assert.equal(vis('علی (Ali)'), '(Ali) یلع');
});

test('چیدمان متن با فونت', () => {
  const r = layoutText('محمدرضا احمدی', font);
  assert.equal(r.glyphs.length, 13); // ۱۲ حرف + فاصله
  assert.ok(r.glyphs.every((g) => g.gid > 0), 'all glyphs exist in the font');
  assert.ok(r.width > 4 && r.width < 9, `width ${r.width}em`);
  // اولین گلیف دیداری (چپ) آخرین حرف منطقی است: «ی» پایانی
  assert.equal(r.glyphs[0].src, 'ی');
});
