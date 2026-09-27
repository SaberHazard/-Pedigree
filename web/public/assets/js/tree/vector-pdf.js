/**
 * خروجی PDF کاملاً برداری از درخت: هر اندازه‌ای (A4 تا بنر ۱۰×۵ متری) بدون افت کیفیت.
 *
 * - خطوط، حلقه‌های رنگی، سیلوئت‌ها و متن‌ها برداری‌اند؛ متن فارسی با فونت وزیرمتن
 *   جاسازی می‌شود (حروف چسبان و راست‌به‌چپ) و در PDF قابل جستجو و کپی است.
 * - عکس‌ها JPEG با وضوح اصلی خود (بریده به شکل دایره).
 * - کل درخت یک بار به صورت «فرم» ساخته می‌شود؛ حالت تک‌صفحه آن را در صفحه‌ای با اندازه
 *   دلخواه جا می‌دهد و حالت چندبرگی همان فرم را با برش‌های مختلف روی برگه‌های A4/A3 می‌چیند.
 * - صفحه‌های بزرگ‌تر از ۵ متر (محدودیت PDF) با UserUnit ساخته می‌شوند.
 */
import { fa, dateTime } from '../core/format.js';
import { url as assetUrl } from '../core/api.js';
import { arcTexts } from './node.js';
import { TrueTypeFont } from './pdf/ttf.js';
import { layoutText } from './pdf/shaping.js';
import { PdfDocument, num } from './pdf/document.js';

export const PAPER = {
  A4: [210, 297],
  A3: [297, 420],
  A2: [420, 594],
  A1: [594, 841],
  A0: [841, 1189],
  Letter: [216, 279],
};

const MM = 72 / 25.4; // point در هر میلی‌متر
const MAX_PAGE_PT = 14400; // سقف ابعاد صفحه در PDF (۲۰۰ اینچ)
const HEADER_H = 120;
const FOOTER_H = 50;
const MARGIN = 50;
const K = 0.5522847498; // ضریب تقریب دایره با منحنی بزیه

const COLORS = {
  text: [0.114, 0.165, 0.184],
  text2: [0.337, 0.4, 0.42],
  link: [0.647, 0.549, 0.4],
  marriage: [0.788, 0.635, 0.29],
  plateBorder: [0.902, 0.871, 0.816],
  heart: [0.882, 0.114, 0.282],
  heartGray: [0.58, 0.639, 0.722],
  title: [0.059, 0.361, 0.333],
  subtitle: [0.478, 0.416, 0.31],
  frame: [0.89, 0.827, 0.69],
  frame2: [0.937, 0.894, 0.8],
};
const RING = {
  m: [[0, '#2563eb'], [1, '#06b6d4']],
  f: [[0, '#db2777'], [1, '#a855f7']],
  md: [[0, '#1f2937'], [0.6, '#475569'], [1, '#b7862c']],
  fd: [[0, '#3b1d2e'], [0.6, '#6b5563'], [1, '#b7862c']],
};
const SILHOUETTE = {
  m: { bg: '#dde9fb', parts: [['#9ebfee', 'M33 39a17 17 0 1 0 34 0a17 17 0 1 0 -34 0z'], ['#9ebfee', 'M16 100c2-21 15-33 34-33s32 12 34 33z']] },
  f: {
    bg: '#fbe3ef',
    parts: [
      ['#e7a6c6', 'M29 46c0-17 9-28 21-28s21 11 21 28c0 10-2 17-6 21H35c-4-4-6-11-6-21z'],
      ['#f5c9dd', 'M36 41a14 14 0 1 0 28 0a14 14 0 1 0 -28 0z'],
      ['#e7a6c6', 'M18 100c2-20 14-32 32-32s30 12 32 32z'],
    ],
  },
};
const HEART = 'M0,4.6 C-6.4,0.2 -5.2,-5.2 -2.2,-5 C-1,-4.9 -0.3,-4.2 0,-3.4 C0.3,-4.2 1,-4.9 2.2,-5 C5.2,-5.2 6.4,0.2 0,4.6 Z';

const hexRgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
const rgb = (c, stroke = false) => `${c.map(num).join(' ')} ${stroke ? 'RG' : 'rg'}`;

// ------------------------------------------------------------------ مسیرهای SVG → PDF

/**
 * تبدیل رشته مسیر SVG (M L H V C S Q T Z و نسخه نسبی) به دستورهای PDF
 * @param {(x:number,y:number)=>[number,number]} tf تبدیل مختصات
 */
export function svgPathToPdf(d, tf) {
  const tokens = d.match(/[a-zA-Z]|-?(?:\d*\.\d+|\d+\.?)(?:e[-+]?\d+)?/gi) || [];
  const out = [];
  let i = 0;
  let cmd = '';
  let x = 0;
  let y = 0;
  let sx = 0;
  let sy = 0;
  let cx = 0; // آخرین نقطه کنترل (برای S و T)
  let cy = 0;
  let prev = '';
  const n = () => parseFloat(tokens[i++]);
  const P = (px, py) => tf(px, py).map(num).join(' ');
  // در قوس‌ها (a) فقط دایره کامل با دو نیم‌قوس پشتیبانی می‌شود (برای سیلوئت‌ها)
  const arc = (rx, x2, y2) => {
    // نیم‌دایره از (x,y) تا (x2,y2) با دو منحنی بزیه
    const mx = (x + x2) / 2;
    const my = (y + y2) / 2;
    const dx = (x2 - x) / 2;
    const dy = (y2 - y) / 2;
    // نقطه وسط قوس (عمود بر وتر، سمت ساعت‌گرد در مختصات SVG)
    const px = mx - dy;
    const py = my + dx;
    const seg = (ax, ay, bx, by, ox, oy) => {
      out.push(`${P(ax + (ox - ax) * K, ay + (oy - ay) * K)} ${P(bx + (ox - bx) * K, by + (oy - by) * K)} ${P(bx, by)} c`);
    };
    // گوشه‌های مربع محیطی برای تقریب
    seg(x, y, px, py, x - dy, y + dx);
    seg(px, py, x2, y2, x2 - dy, y2 + dx);
    x = x2;
    y = y2;
    void rx;
  };
  while (i < tokens.length) {
    if (/[a-zA-Z]/.test(tokens[i])) cmd = tokens[i++];
    const rel = cmd === cmd.toLowerCase();
    const bx = rel ? x : 0;
    const by = rel ? y : 0;
    switch (cmd.toUpperCase()) {
      case 'M':
        x = bx + n();
        y = by + n();
        sx = x;
        sy = y;
        out.push(`${P(x, y)} m`);
        cmd = rel ? 'l' : 'L';
        break;
      case 'L':
        x = bx + n();
        y = by + n();
        out.push(`${P(x, y)} l`);
        break;
      case 'H':
        x = bx + n();
        out.push(`${P(x, y)} l`);
        break;
      case 'V':
        y = by + n();
        out.push(`${P(x, y)} l`);
        break;
      case 'C': {
        const x1 = bx + n(); const y1 = by + n();
        const x2 = bx + n(); const y2 = by + n();
        x = bx + n();
        y = by + n();
        out.push(`${P(x1, y1)} ${P(x2, y2)} ${P(x, y)} c`);
        cx = x2;
        cy = y2;
        break;
      }
      case 'S': {
        const x1 = /[CS]/i.test(prev) ? 2 * x - cx : x;
        const y1 = /[CS]/i.test(prev) ? 2 * y - cy : y;
        const x2 = bx + n(); const y2 = by + n();
        x = bx + n();
        y = by + n();
        out.push(`${P(x1, y1)} ${P(x2, y2)} ${P(x, y)} c`);
        cx = x2;
        cy = y2;
        break;
      }
      case 'Q': {
        const qx = bx + n(); const qy = by + n();
        const ex = bx + n(); const ey = by + n();
        // تبدیل منحنی درجه دو به درجه سه
        out.push(`${P(x + (2 / 3) * (qx - x), y + (2 / 3) * (qy - y))} ${P(ex + (2 / 3) * (qx - ex), ey + (2 / 3) * (qy - ey))} ${P(ex, ey)} c`);
        cx = qx;
        cy = qy;
        x = ex;
        y = ey;
        break;
      }
      case 'T': {
        const qx = /[QT]/i.test(prev) ? 2 * x - cx : x;
        const qy = /[QT]/i.test(prev) ? 2 * y - cy : y;
        const ex = bx + n(); const ey = by + n();
        out.push(`${P(x + (2 / 3) * (qx - x), y + (2 / 3) * (qy - y))} ${P(ex + (2 / 3) * (qx - ex), ey + (2 / 3) * (qy - ey))} ${P(ex, ey)} c`);
        cx = qx;
        cy = qy;
        x = ex;
        y = ey;
        break;
      }
      case 'A': {
        const rx = n(); n(); n(); n(); n();
        const x2 = bx + n(); const y2 = by + n();
        arc(rx, x2, y2);
        break;
      }
      case 'Z':
        out.push('h');
        x = sx;
        y = sy;
        break;
      default:
        i++; // نویسه ناشناخته
    }
    prev = cmd;
  }
  return out.join('\n');
}

/** دایره کامل (مختصات PDF) */
function circle(cx, cy, r) {
  const k = r * K;
  return `${num(cx + r)} ${num(cy)} m ${num(cx + r)} ${num(cy + k)} ${num(cx + k)} ${num(cy + r)} ${num(cx)} ${num(cy + r)} c `
    + `${num(cx - k)} ${num(cy + r)} ${num(cx - r)} ${num(cy + k)} ${num(cx - r)} ${num(cy)} c `
    + `${num(cx - r)} ${num(cy - k)} ${num(cx - k)} ${num(cy - r)} ${num(cx)} ${num(cy - r)} c `
    + `${num(cx + k)} ${num(cy - r)} ${num(cx + r)} ${num(cy - k)} ${num(cx + r)} ${num(cy)} c h`;
}

// ------------------------------------------------------------------ متن

/** کوتاه/کوچک کردن متن تا در طول مشخص جا شود */
function fitLine(text, font, maxWidth, size, minSize) {
  let line = layoutText(text, font);
  let s = size;
  if (line.width * s > maxWidth) s = Math.max(minSize, (maxWidth / line.width) * 0.99);
  let hscale = 1;
  if (line.width * s > maxWidth) {
    if (line.width * s <= maxWidth * 1.25) hscale = maxWidth / (line.width * s);
    else {
      let t = text;
      while (t.length > 3 && layoutText(t + '…', font).width * s > maxWidth * 1.2) t = t.slice(0, -1);
      line = layoutText(t.trim() + '…', font);
      if (line.width * s > maxWidth) hscale = maxWidth / (line.width * s);
    }
  }
  return { line, size: s, hscale };
}

/** رسم گلیف‌ها روی قوس دایره (مرکز cx,cy در مختصات PDF) */
function arcGlyphs(pdfFont, fit, cx, cy, r, top) {
  const { line, size, hscale } = fit;
  const total = line.width * size * hscale;
  let s = 0;
  const ops = [];
  for (const g of line.glyphs) {
    pdfFont.used.set(g.gid, g.src);
    const w = g.width * size * hscale;
    const c = s + w / 2; // مرکز گلیف روی قوس
    s += w;
    if (g.gid === 0) continue;
    let theta;
    let tx;
    let ty;
    if (top) {
      // بالای دایره، خواندن از چپ به راست = ساعت‌گرد
      theta = Math.PI / 2 + (total / 2 - c) / r;
      tx = Math.sin(theta);
      ty = -Math.cos(theta);
    } else {
      // پایین دایره، حروف ایستاده = پادساعت‌گرد
      theta = (3 * Math.PI) / 2 - (total / 2 - c) / r;
      tx = -Math.sin(theta);
      ty = Math.cos(theta);
    }
    const px = cx + r * Math.cos(theta) - (tx * w) / 2;
    const py = cy + r * Math.sin(theta) - (ty * w) / 2;
    // ماتریس متن: محور x در جهت مماس (با فشردگی افقی)، محور y عمود بر آن
    ops.push(`${num(tx * hscale)} ${num(ty * hscale)} ${num(-ty)} ${num(tx)} ${num(px)} ${num(py)} Tm <${g.gid.toString(16).padStart(4, '0')}> Tj`);
  }
  return ops.join('\n');
}

/** متن افقی وسط‌چین */
function centeredText(pdfFont, text, font, cx, baseline, size) {
  const line = layoutText(text, font);
  let x = cx - (line.width * size) / 2;
  const ops = [];
  for (const g of line.glyphs) {
    pdfFont.used.set(g.gid, g.src);
    if (g.gid) ops.push(`1 0 0 1 ${num(x)} ${num(baseline)} Tm <${g.gid.toString(16).padStart(4, '0')}> Tj`);
    x += g.width * size;
  }
  return ops.join('\n');
}

// ------------------------------------------------------------------ منابع

const fontCache = new Map();
async function loadFont(file) {
  if (!fontCache.has(file)) {
    fontCache.set(file, (async () => {
      const res = await fetch(assetUrl(`/assets/fonts/ttf/${file}`), { credentials: 'same-origin' });
      if (!res.ok) throw new Error('بارگذاری فونت خروجی PDF ممکن نشد.');
      return new TrueTypeFont(await res.arrayBuffer());
    })());
  }
  return fontCache.get(file);
}

/** عکس (WebP/PNG/JPEG) ← JPEG مربعی برای جاسازی؛ درگذشتگان کمی خاکستری */
async function photoJpeg(src, { gray = 0, maxSize = 480 } = {}) {
  const res = await fetch(src, { credentials: 'same-origin' });
  if (!res.ok) throw new Error('photo');
  const bitmap = await createImageBitmap(await res.blob());
  const side = Math.min(bitmap.width, bitmap.height);
  const size = Math.min(maxSize, side);
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, size, size);
  if (gray) ctx.filter = `grayscale(${gray})`;
  ctx.drawImage(bitmap, (bitmap.width - side) / 2, (bitmap.height - side) / 2, side, side, 0, 0, size, size);
  bitmap.close?.();
  const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.9));
  return { bytes: new Uint8Array(await blob.arrayBuffer()), size };
}

// ------------------------------------------------------------------ ساخت PDF

/**
 * @param {object} layout خروجی layout.js
 * @param {object} opts
 *   title, subtitle, siteName, prefs, photos (bool), grayscale (bool)
 *   mode: 'fit' (تک‌صفحه با اندازه page) | 'tiles' (چند برگه با اندازه نهایی final)
 *   page: {w, h} میلی‌متر (حالت fit)؛ final: {w, h} میلی‌متر اندازه نهایی (حالت tiles)؛ sheet: 'A4' | 'A3' ...
 *   orientation: auto | portrait | landscape
 *   onProgress(0..1)
 * @returns {Promise<Blob>}
 */
export async function buildVectorPdf(layout, opts = {}) {
  const g = layout.geometry;
  const b = layout.bounds;
  const W = Math.ceil(b.width + MARGIN * 2);
  const H = Math.ceil(b.height + MARGIN * 2 + HEADER_H + FOOTER_H);
  const ox = MARGIN - b.minX;
  const oy = MARGIN + HEADER_H - b.minY;
  // مختصات چیدمان (y به پایین) ← مختصات PDF فرم (y به بالا)
  const tf = (x, y) => [x + ox, H - (y + oy)];
  const progress = (v) => opts.onProgress?.(v);

  const doc = new PdfDocument({ title: opts.title || 'شجره‌نامه' });
  const [bold, medium] = await Promise.all([loadFont('Vazirmatn-UI-FD-Bold.ttf'), loadFont('Vazirmatn-UI-FD-Medium.ttf')]);
  const fBold = doc.addFont(bold);
  const fMed = doc.addFont(medium);
  progress(0.08);

  // عکس‌ها
  const photos = new Map();
  if (opts.photos !== false) {
    const jobs = [];
    for (const n of layout.nodes) {
      const src = n.person.avatar;
      if (!src) continue;
      const gray = opts.grayscale ? 1 : n.person.is_deceased ? 0.45 : 0;
      const key = `${src}|${gray}`;
      if (!photos.has(key)) {
        photos.set(key, null);
        jobs.push({ key, src, gray });
      }
    }
    let done = 0;
    let next = 0;
    const worker = async () => {
      while (next < jobs.length) {
        const job = jobs[next++];
        try {
          const img = await photoJpeg(job.src, { gray: job.gray });
          photos.set(job.key, doc.addImage(img.bytes, img.size, img.size));
        } catch {
          /* عکس در دسترس نیست؛ سیلوئت رسم می‌شود */
        }
        done++;
        progress(0.08 + 0.5 * (done / jobs.length));
      }
    };
    await Promise.all(Array.from({ length: 6 }, worker));
  }
  progress(0.6);

  // گرادیان حلقه‌ها (یکی برای هر نوع؛ مختصات نسبت به مرکز گره)
  const rr = g.R + g.ring;
  const rings = {};
  for (const [key, stops] of Object.entries(RING)) {
    const s = opts.grayscale ? stops.map(([p, c]) => [p, hexRgb(c).map(() => hexRgb(c).reduce((a, v) => a + v, 0) / 3)]) : stops.map(([p, c]) => [p, hexRgb(c)]);
    rings[key] = doc.addShading(s, [-rr, rr, rr, -rr]);
  }
  const shadowAlpha = doc.alpha(0.12);
  const ribbonAlpha = doc.alpha(0.92);

  const ops = [];
  const push = (s) => ops.push(s);

  // پس‌زمینه و قاب
  push(`1 1 1 rg 0 0 ${num(W)} ${num(H)} re f`);
  push(`${rgb(COLORS.frame, true)} 2 w ${roundRect(14, 14, W - 28, H - 28, 18)} S`);
  push(`${rgb(COLORS.frame2, true)} 1 w ${roundRect(22, 22, W - 44, H - 44, 14)} S`);

  // عنوان
  push(`BT 0 Tr ${rgb(COLORS.title)} /${fBold.name} 34 Tf ${centeredText(fBold, opts.title || 'شجره‌نامه', bold, W / 2, H - 70, 34)} ET`);
  if (opts.subtitle) push(`BT ${rgb(COLORS.subtitle)} /${fMed.name} 17 Tf ${centeredText(fMed, opts.subtitle, medium, W / 2, H - 104, 17)} ET`);
  push(`${rgb(COLORS.marriage, true)} 1.5 w ${num(W / 2 - 120)} ${num(H - 122)} m ${num(W / 2 - 14)} ${num(H - 122)} l ${num(W / 2 + 14)} ${num(H - 122)} m ${num(W / 2 + 120)} ${num(H - 122)} l S`);
  push(`${rgb(COLORS.marriage)} ${circle(W / 2, H - 122, 4)} f`);

  // خطوط
  push('1 J 1 j');
  for (const l of layout.links) {
    const marriage = l.type === 'marriage';
    const color = marriage ? COLORS.marriage : COLORS.link;
    const dash = l.divorced ? '[7 6] 0 d' : l.implied ? '[2 6] 0 d' : '[] 0 d';
    push(`${rgb(color, true)} ${marriage ? 2.6 : 2} w ${dash}\n${svgPathToPdf(l.d, tf)}\nS`);
  }
  push('[] 0 d');
  for (const l of layout.links) {
    if (l.type !== 'marriage' || l.implied) continue;
    const [mx, my] = tf(l.mid.x, l.mid.y);
    push(`1 1 1 rg ${rgb(COLORS.marriage, true)} 1.5 w ${circle(mx, my, 9.5)} B`);
    push(`${rgb(l.divorced ? COLORS.heartGray : COLORS.heart)} ${svgPathToPdf(HEART, (x, y) => [mx + x, my - y])} f`);
  }
  progress(0.66);

  // گره‌ها
  const prefs = opts.prefs || {};
  const maxTop = Math.PI * g.rt * 0.8;
  const maxBottom = Math.PI * g.rb * 0.74;
  for (const node of layout.nodes) {
    const [cx, cy] = tf(node.x, node.y);
    const p = node.person;
    const female = p.gender === 'f';
    const dead = !!p.is_deceased;

    // صفحه پشت (مدال)، حاشیه طلایی ریشه و سایه
    push(`1 1 1 rg ${rgb(COLORS.plateBorder, true)} 1 w ${circle(cx, cy, g.outer - 1)} B`);
    if (node.isRoot) push(`${rgb(COLORS.marriage, true)} 1.5 w ${circle(cx, cy, g.outer + 1)} S`);
    push(`q /${shadowAlpha} gs 0.043 0.102 0.114 rg ${circle(cx, cy - 3, rr + 3)} f Q`);
    push(`1 1 1 rg ${circle(cx, cy, rr)} f`);

    // عکس یا سیلوئت (بریده در دایره)
    const photo = opts.photos !== false && p.avatar ? photos.get(`${p.avatar}|${opts.grayscale ? 1 : dead ? 0.45 : 0}`) : null;
    push(`q ${circle(cx, cy, g.R)} W n`);
    if (photo) {
      push(`${num(g.R * 2)} 0 0 ${num(g.R * 2)} ${num(cx - g.R)} ${num(cy - g.R)} cm /${photo} Do`);
    } else {
      const sil = SILHOUETTE[female ? 'f' : 'm'];
      const k = (g.R * 2) / 100;
      const stf = (x, y) => [cx - g.R + x * k, cy + g.R - y * k];
      const toColor = (hex) => (opts.grayscale ? hexRgb(hex).map(() => hexRgb(hex).reduce((a, v) => a + v, 0) / 3) : hexRgb(hex));
      push(`${rgb(toColor(sil.bg))} ${num(cx - g.R)} ${num(cy - g.R)} ${num(g.R * 2)} ${num(g.R * 2)} re f`);
      for (const [color, d] of sil.parts) push(`${rgb(toColor(color))} ${svgPathToPdf(d, stf)} f`);
    }
    if (dead) {
      // روبان مشکی سوگواری
      const [x1, y1] = [cx - g.R, cy + g.R * 0.22];
      const [x2, y2] = [cx - g.R * 0.22, cy + g.R];
      push(`/${ribbonAlpha} gs 0.067 0.067 0.067 RG ${num(g.R * 0.2)} w ${num(x1)} ${num(y1)} m ${num(x2)} ${num(y2)} l S`);
    }
    push('Q');

    // حلقه رنگی (گرادیان در حلقه بین R و R+ring)
    const ringKey = `${female ? 'f' : 'm'}${dead ? 'd' : ''}`;
    if (node.kind === 'alias') {
      const c0 = hexRgb(RING[ringKey][0][1]);
      push(`${rgb(opts.grayscale ? c0.map(() => 0.4) : c0, true)} ${num(g.ring)} w [6 4] 0 d ${circle(cx, cy, g.R + g.ring / 2)} S [] 0 d`);
    } else {
      push(`q ${circle(cx, cy, rr)} ${circle(cx, cy, g.R)} W* n 1 0 0 1 ${num(cx)} ${num(cy)} cm /${rings[ringKey]} sh Q`);
    }

    // متن‌های روی قوس (ابتدا هاله سفید، سپس رنگ متن)
    const texts = arcTexts(p, prefs);
    const parts = [];
    if (texts.top) parts.push([fBold, bold, fitLine(texts.top, bold, maxTop, g.topSize, g.topSize * 0.78), g.rt, true, COLORS.text]);
    if (texts.bottom) parts.push([fMed, medium, fitLine(texts.bottom, medium, maxBottom, g.bottomSize, g.bottomSize * 0.8), g.rb, false, COLORS.text2]);
    for (const [pf, , fit, r, top, color] of parts) {
      const glyphs = arcGlyphs(pf, fit, cx, cy, r, top);
      push(`BT /${pf.name} ${num(fit.size)} Tf 1 1 1 RG 2.4 w 1 j 1 Tr\n${glyphs}\nET`);
      push(`BT /${pf.name} ${num(fit.size)} Tf ${rgb(color)} 0 Tr\n${glyphs}\nET`);
    }
  }
  progress(0.85);

  // پانویس
  const footer = [opts.siteName, `تاریخ تهیه: ${dateTime(new Date().toISOString(), false)}`, `${fa(layout.nodes.length)} نفر`].filter(Boolean).join('   •   ');
  push(`BT ${rgb([0.545, 0.49, 0.388])} /${fMed.name} 13 Tf ${centeredText(fMed, footer, medium, W / 2, 30, 13)} ET`);

  const form = doc.addForm(ops.join('\n'), [0, 0, W, H]);

  // ---------------------------------------------------------------- صفحه‌ها
  const orient = (w, h, content) => {
    const land = opts.orientation === 'landscape' || (opts.orientation !== 'portrait' && content.w > content.h);
    return land ? [Math.max(w, h), Math.min(w, h)] : [Math.min(w, h), Math.max(w, h)];
  };

  if ((opts.mode || 'fit') === 'fit') {
    const page = opts.page || { w: PAPER.A3[0], h: PAPER.A3[1] };
    const [pw, ph] = orient(page.w, page.h, { w: W, h: H });
    const ptW = pw * MM;
    const ptH = ph * MM;
    const margin = Math.max(8, Math.min(pw, ph) * 0.02) * MM;
    const s = Math.min((ptW - margin * 2) / W, (ptH - margin * 2) / H);
    const unit = Math.max(1, Math.ceil(Math.max(ptW, ptH) / MAX_PAGE_PT));
    const tx = (ptW - W * s) / 2;
    const ty = (ptH - H * s) / 2;
    doc.addPage(ptW, ptH, `q ${num(s / unit)} 0 0 ${num(s / unit)} ${num(tx / unit)} ${num(ty / unit)} cm /${form} Do Q`, unit);
  } else {
    // چند برگه با اندازه نهایی مشخص (مثلاً ۱۰×۵ متر روی برگه‌های A3) + ۶ میلی‌متر هم‌پوشانی برای چسباندن
    const final = opts.final || { w: 1000, h: 500 };
    const s = Math.min((final.w * MM) / W, (final.h * MM) / H); // point در هر واحد درخت
    const sheet = PAPER[opts.sheet || 'A3'];
    const [pw, ph] = orient(sheet[0], sheet[1], { w: W, h: H });
    const margin = 8 * MM;
    const overlap = 6 * MM;
    const areaW = pw * MM - margin * 2;
    const areaH = ph * MM - margin * 2;
    const totalW = W * s;
    const totalH = H * s;
    const cols = Math.max(1, Math.ceil((totalW - overlap) / (areaW - overlap)));
    const rows = Math.max(1, Math.ceil((totalH - overlap) / (areaH - overlap)));
    for (let r = 0; r < rows; r++) {
      // ترتیب برگه‌ها از بالا-راست (مطابق خواندن فارسی)
      for (let c = cols - 1; c >= 0; c--) {
        const offX = c * (areaW - overlap);
        const offY = r * (areaH - overlap);
        // مختصات فرم: y به بالا؛ برگه ردیف اول بالای درخت را نشان می‌دهد
        const tx = margin - offX;
        const ty = margin + areaH - (totalH - offY);
        const label = `${fa(r + 1)} - ${fa(cols - c)}`;
        const content = [
          `q ${num(margin)} ${num(margin)} ${num(areaW)} ${num(areaH)} re W n`,
          `${num(s)} 0 0 ${num(s)} ${num(tx)} ${num(ty)} cm /${form} Do Q`,
          cropMarks(margin, areaW, areaH),
          `BT ${rgb([0.647, 0.549, 0.4])} /${fMed.name} 9 Tf ${centeredText(fMed, `ردیف و ستون ${label}  (از ${fa(rows)}×${fa(cols)})`, medium, pw * MM / 2, margin / 3, 9)} ET`,
        ].join('\n');
        doc.addPage(pw * MM, ph * MM, content);
      }
    }
  }

  progress(0.9);
  const blob = await doc.build();
  progress(1);
  return blob;
}

/** تعداد برگه‌های حالت چندبرگی (برای نمایش در فرم) */
export function sheetCount(layout, opts) {
  const b = layout.bounds;
  const W = b.width + MARGIN * 2;
  const H = b.height + MARGIN * 2 + HEADER_H + FOOTER_H;
  const final = opts.final || { w: 1000, h: 500 };
  const s = Math.min((final.w * MM) / W, (final.h * MM) / H);
  const sheet = PAPER[opts.sheet || 'A3'];
  const land = opts.orientation === 'landscape' || (opts.orientation !== 'portrait' && W > H);
  const pw = land ? Math.max(...sheet) : Math.min(...sheet);
  const ph = land ? Math.min(...sheet) : Math.max(...sheet);
  const areaW = (pw - 16) * MM;
  const areaH = (ph - 16) * MM;
  const overlap = 6 * MM;
  const cols = Math.max(1, Math.ceil((W * s - overlap) / (areaW - overlap)));
  const rows = Math.max(1, Math.ceil((H * s - overlap) / (areaH - overlap)));
  // اندازه واقعی درخت چاپی (میلی‌متر) با حفظ تناسب
  return { cols, rows, total: cols * rows, widthMm: (W * s) / MM, heightMm: (H * s) / MM };
}

/** اندازه قطر عکس هر نفر در چاپ نهایی (میلی‌متر) — برای راهنمایی کاربر */
export function photoDiameterMm(layout, finalMm) {
  const b = layout.bounds;
  const W = b.width + MARGIN * 2;
  const H = b.height + MARGIN * 2 + HEADER_H + FOOTER_H;
  const s = Math.min(finalMm.w / W, finalMm.h / H);
  return layout.geometry.R * 2 * s;
}

function roundRect(x, y, w, h, r) {
  const k = r * K;
  return `${num(x + r)} ${num(y)} m ${num(x + w - r)} ${num(y)} l ${num(x + w - r + k)} ${num(y)} ${num(x + w)} ${num(y + r - k)} ${num(x + w)} ${num(y + r)} c `
    + `${num(x + w)} ${num(y + h - r)} l ${num(x + w)} ${num(y + h - r + k)} ${num(x + w - r + k)} ${num(y + h)} ${num(x + w - r)} ${num(y + h)} c `
    + `${num(x + r)} ${num(y + h)} l ${num(x + r - k)} ${num(y + h)} ${num(x)} ${num(y + h - r + k)} ${num(x)} ${num(y + h - r)} c `
    + `${num(x)} ${num(y + r)} l ${num(x)} ${num(y + r - k)} ${num(x + r - k)} ${num(y)} ${num(x + r)} ${num(y)} c h`;
}

/** علامت‌های برش گوشه‌ها برای چسباندن برگه‌ها */
function cropMarks(m, w, h) {
  const L = 10;
  const pts = [[m, m], [m + w, m], [m, m + h], [m + w, m + h]];
  return `0.6 0.6 0.6 RG 0.4 w ${pts.map(([x, y]) => `${num(x - L)} ${num(y)} m ${num(x + L)} ${num(y)} l ${num(x)} ${num(y - L)} m ${num(x)} ${num(y + L)} l`).join(' ')} S`;
}
