// تست سازنده PDF برداری (بدون مرورگر)
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { PdfDocument, num } from '../../public/assets/js/tree/pdf/document.js';
import { TrueTypeFont } from '../../public/assets/js/tree/pdf/ttf.js';
import { layoutText } from '../../public/assets/js/tree/pdf/shaping.js';

// vector-pdf.js به DOM وابسته است؛ مبدل مسیر با import پویا بعد از تعریف document ساختگی خوانده می‌شود
globalThis.document ??= { querySelector: () => null };
const { svgPathToPdf } = await import('../../public/assets/js/tree/vector-pdf.js');

test('اعداد کوتاه PDF', () => {
  assert.equal(num(1), '1');
  assert.equal(num(1.2345), '1.23');
  assert.equal(num(-0.5), '-0.5');
  assert.equal(num(NaN), '0');
});

test('تبدیل مسیر SVG به دستورهای PDF', () => {
  const id = (x, y) => [x, -y];
  assert.equal(svgPathToPdf('M10,20 H30 V40', id), '10 -20 m\n30 -20 l\n30 -40 l');
  // مسیر نسبی با اعداد فشرده و منحنی
  const rel = svgPathToPdf('M29 46c0-17 9-28 21-28s21 11 21 28z', id);
  assert.match(rel, /^29 -46 m\n29 -29 38 -18 50 -18 c\n/);
  assert.ok(rel.endsWith('h'));
  // منحنی درجه دو به درجه سه تبدیل می‌شود
  assert.match(svgPathToPdf('M0,0 Q10,10 20,0', id), / c$/);
  // دایره با دو نیم‌قوس
  const arc = svgPathToPdf('M33 39a17 17 0 1 0 34 0a17 17 0 1 0 -34 0z', id);
  assert.equal(arc.split(' c').length - 1, 4);
});

test('ساخت فایل PDF معتبر با فونت فارسی جاسازی‌شده', async () => {
  const font = new TrueTypeFont(readFileSync(new URL('../../public/assets/fonts/ttf/Vazirmatn-UI-FD-Bold.ttf', import.meta.url)));
  const doc = new PdfDocument({ title: 'آزمون' });
  const f = doc.addFont(font);
  const line = layoutText('شجره‌نامه خاندان', font);
  let x = 50;
  const ops = [];
  for (const g of line.glyphs) {
    f.used.set(g.gid, g.src);
    ops.push(`1 0 0 1 ${num(x)} 100 Tm <${g.gid.toString(16).padStart(4, '0')}> Tj`);
    x += g.width * 24;
  }
  const shading = doc.addShading([[0, [1, 0, 0]], [0.5, [0, 1, 0]], [1, [0, 0, 1]]], [0, 0, 10, 10]);
  const form = doc.addForm(`BT /${f.name} 24 Tf ${ops.join(' ')} ET q 0 0 10 10 re W n /${shading} sh Q`, [0, 0, 500, 200]);
  doc.addPage(595, 842, `/${form} Do`);
  // صفحه بسیار بزرگ (۱۰ متر) با UserUnit
  doc.addPage(28346, 14173, `/${form} Do`, 2);

  const bytes = new Uint8Array(await (await doc.build()).arrayBuffer());
  const text = new TextDecoder('latin1').decode(bytes);
  assert.ok(text.startsWith('%PDF-1.6'));
  assert.ok(text.trimEnd().endsWith('%%EOF'));
  assert.match(text, /\/UserUnit 2/);
  assert.match(text, /\/MediaBox \[0 0 14173 7086.5\]/);
  assert.match(text, /\/Subtype \/CIDFontType2/);
  assert.match(text, /\/FontFile2 \d+ 0 R/);
  assert.match(text, /\/ToUnicode \d+ 0 R/);

  // جدول xref: هر آفست دقیقاً به شروع «N 0 obj» اشاره کند
  const xrefPos = Number(text.match(/startxref\n(\d+)/)[1]);
  assert.equal(text.slice(xrefPos, xrefPos + 4), 'xref');
  const [, count] = text.slice(xrefPos).match(/xref\n0 (\d+)/);
  const entries = text.slice(xrefPos).split('\n').slice(3, 3 + Number(count) - 1);
  entries.forEach((line, i) => {
    const offset = Number(line.slice(0, 10));
    assert.ok(text.startsWith(`${i + 1} 0 obj`, offset), `object ${i + 1} offset`);
  });
});
