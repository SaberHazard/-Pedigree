/**
 * خروجی گرفتن از درخت: SVG، PNG، PDF (تک‌صفحه یا چندصفحه‌ای پوستری) و چاپ.
 *
 * مراحل:
 *  ۱. ساخت یک SVG مستقل (بدون وابستگی به صفحه): فونت‌ها و عکس‌ها داخلش جاسازی می‌شوند
 *  ۲. SVG: مستقیم ذخیره می‌شود (برداری، مناسب چاپ بزرگ)
 *  ۳. PNG/PDF: SVG روی canvas با وضوح بالا رسم و به تصویر تبدیل می‌شود
 *  ۴. چاپ: SVG در صفحه چاپ قرار می‌گیرد و پنجره چاپ مرورگر باز می‌شود
 *     (در پنجره چاپ می‌توان «ذخیره به PDF» را هم انتخاب کرد: خروجی کاملاً برداری)
 */
import { s } from '../core/dom.js';
import { fa, dateTime } from '../core/format.js';
import { buildDefs, buildNode } from './node.js';
import { TREE_CSS } from './renderer.js';
import { PdfWriter, PAPER, mmToPt } from './pdf.js';
import { printPage, saveFile } from '../core/native.js';

const HEADER_H = 120;
const FOOTER_H = 50;
const MARGIN = 50;

/** رنگ‌های ثابت خروجی (همیشه روشن و مناسب چاپ) */
const EXPORT_VARS = `
svg{--t-text:#1d2a2f;--t-text-2:#4b5a5f;--t-halo:#ffffff;--t-link:#a58c66;--t-marriage:#c9a24a;--t-hl:#14a39a;--t-mark-bg:#fff;--plate-1:#ffffff;--plate-border:#e6ded0;--surface:#fff}
.gray image, .gray use{filter:grayscale(1)}
`;

// ------------------------------------------------------------------ جاسازی منابع
let fontCss = null;

async function toDataUrl(url) {
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error('fetch failed');
  const blob = await res.blob();
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = reject;
    r.readAsDataURL(blob);
  });
}

async function embeddedFonts() {
  if (fontCss) return fontCss;
  const base = document.querySelector('link[rel="stylesheet"][href*="app.css"]')?.href.replace(/css\/app\.css.*$/, 'fonts/') || '/assets/fonts/';
  const faces = [
    [400, 'Vazirmatn-UI-FD-Regular.woff2'],
    [500, 'Vazirmatn-UI-FD-Medium.woff2'],
    [700, 'Vazirmatn-UI-FD-Bold.woff2'],
  ];
  const parts = await Promise.all(faces.map(async ([weight, file]) => {
    try {
      const data = await toDataUrl(base + file);
      return `@font-face{font-family:Vazirmatn;font-weight:${weight};src:url(${data}) format("woff2")}`;
    } catch {
      return '';
    }
  }));
  fontCss = parts.join('');
  return fontCss;
}

// ------------------------------------------------------------------ ساخت SVG مستقل
/**
 * @param {object} layout خروجی layout.js
 * @param {object} opts   title, subtitle, prefs, photos(bool), grayscale(bool), siteName
 * @returns {Promise<{svg:string, width:number, height:number, box:object}>}
 */
export async function buildExportSvg(layout, opts = {}) {
  const g = layout.geometry;
  const b = layout.bounds;
  const width = Math.ceil(b.width + MARGIN * 2);
  const height = Math.ceil(b.height + MARGIN * 2 + HEADER_H + FOOTER_H);
  const ox = MARGIN - b.minX;
  const oy = MARGIN + HEADER_H - b.minY;

  // عکس‌ها → data URL
  const photoMap = new Map();
  if (opts.photos !== false) {
    const urls = [...new Set(layout.nodes.map((n) => n.person.avatar).filter(Boolean))];
    let i = 0;
    const worker = async () => {
      while (i < urls.length) {
        const u = urls[i++];
        try {
          photoMap.set(u, await toDataUrl(u));
        } catch {
          /* عکس در دسترس نیست؛ سیلوئت نمایش داده می‌شود */
        }
      }
    };
    await Promise.all(Array.from({ length: 6 }, worker));
  }

  // xmlns توسط XMLSerializer خودکار اضافه می‌شود
  const svg = s('svg', { width, height, viewBox: `0 0 ${width} ${height}`, direction: 'rtl' });
  svg.append(s('style', null, (await embeddedFonts()) + TREE_CSS + EXPORT_VARS));
  svg.append(buildDefs(g, 'x'));

  // پس‌زمینه و قاب تزئینی
  svg.append(
    s('rect', { width, height, fill: '#ffffff' }),
    s('rect', { x: 14, y: 14, width: width - 28, height: height - 28, rx: 18, fill: 'none', stroke: '#e3d3b0', 'stroke-width': 2 }),
    s('rect', { x: 22, y: 22, width: width - 44, height: height - 44, rx: 14, fill: 'none', stroke: '#efe4cc', 'stroke-width': 1 }),
  );

  // عنوان
  const cx = width / 2;
  svg.append(s('text', { x: cx, y: 70, 'text-anchor': 'middle', 'font-family': 'Vazirmatn', 'font-weight': 700, 'font-size': 34, fill: '#0f5c55' }, opts.title || 'شجره‌نامه'));
  if (opts.subtitle) {
    svg.append(s('text', { x: cx, y: 104, 'text-anchor': 'middle', 'font-family': 'Vazirmatn', 'font-size': 17, fill: '#7a6a4f' }, opts.subtitle));
  }
  svg.append(s('path', { d: `M${cx - 120},122 H${cx - 14} M${cx + 14},122 H${cx + 120}`, stroke: '#c9a24a', 'stroke-width': 1.5 }));
  svg.append(s('circle', { cx, cy: 122, r: 4, fill: '#c9a24a' }));

  // محتوای درخت
  const world = s('g', { transform: `translate(${ox},${oy})`, class: opts.grayscale ? 'gray' : '' });
  const links = s('g');
  for (const l of layout.links) {
    links.append(s('path', { class: ['t-link', l.type, l.divorced ? 'divorced' : '', l.implied ? 'implied' : ''].filter(Boolean).join(' '), d: l.d }));
  }
  world.append(links);
  for (const l of layout.links) {
    if (l.type !== 'marriage' || l.implied) continue;
    world.append(s('g', { class: `t-mark ${l.divorced ? 'divorced' : ''}`, transform: `translate(${l.mid.x},${l.mid.y})` },
      s('circle', { r: 9.5 }),
      s('path', { d: 'M0,4.6 C-6.4,0.2 -5.2,-5.2 -2.2,-5 C-1,-4.9 -0.3,-4.2 0,-3.4 C0.3,-4.2 1,-4.9 2.2,-5 C5.2,-5.2 6.4,0.2 0,4.6 Z' }),
    ));
  }
  for (const node of layout.nodes) {
    const el = buildNode(node, g, {
      prefs: { ...(opts.prefs || {}), show_photos: opts.photos !== false },
      idPrefix: 'x',
      interactive: false,
      photoHref: (p) => (p.avatar ? photoMap.get(p.avatar) || null : null),
    });
    el.setAttribute('transform', `translate(${node.x},${node.y})`);
    world.append(el);
  }
  svg.append(world);

  // پانویس
  const footer = [opts.siteName, `تاریخ تهیه: ${dateTime(new Date().toISOString(), false)}`, `${fa(layout.nodes.length)} نفر`].filter(Boolean).join('   •   ');
  svg.append(s('text', { x: cx, y: height - 30, 'text-anchor': 'middle', 'font-family': 'Vazirmatn', 'font-size': 13, fill: '#8b7d63' }, footer));

  const markup = new XMLSerializer().serializeToString(svg);
  return { svg: markup, width, height };
}

// ------------------------------------------------------------------ رسم روی canvas
function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('SVG render failed'));
    img.src = src;
  });
}

/** بیشترین تعداد پیکسل امن canvas (گوشی‌ها محدودیت شدیدتری دارند) */
function maxPixels() {
  return /iPhone|iPad|Android/i.test(navigator.userAgent) ? 16e6 : 40e6;
}

/**
 * رسم یک ناحیه از SVG روی canvas
 * @param {string} svgMarkup
 * @param {{x:number,y:number,w:number,h:number}} region ناحیه به واحد SVG
 */
async function rasterize(svgMarkup, region, pxW, pxH, background = '#fff') {
  const withView = svgMarkup.replace(/^<svg([^>]*?)\swidth="[^"]*"\sheight="[^"]*"\sviewBox="[^"]*"/,
    `<svg$1 width="${pxW}" height="${pxH}" viewBox="${region.x} ${region.y} ${region.w} ${region.h}"`);
  const blob = new Blob([withView], { type: 'image/svg+xml;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  try {
    const img = await loadImage(url);
    const canvas = document.createElement('canvas');
    canvas.width = pxW;
    canvas.height = pxH;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = background;
    ctx.fillRect(0, 0, pxW, pxH);
    ctx.drawImage(img, 0, 0, pxW, pxH);
    return canvas;
  } finally {
    URL.revokeObjectURL(url);
  }
}

function canvasBlob(canvas, type, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

// ------------------------------------------------------------------ خروجی‌ها
export async function exportSvg(built, filename) {
  await saveFile(new Blob([built.svg], { type: 'image/svg+xml' }), filename);
}

export async function exportPng(built, filename, scale = 2) {
  const limit = maxPixels();
  let k = scale;
  if (built.width * built.height * k * k > limit) k = Math.sqrt(limit / (built.width * built.height));
  const pxW = Math.max(1, Math.floor(built.width * k));
  const pxH = Math.max(1, Math.floor(built.height * k));
  const canvas = await rasterize(built.svg, { x: 0, y: 0, w: built.width, h: built.height }, pxW, pxH);
  await saveFile(await canvasBlob(canvas, 'image/png'), filename);
}

/**
 * خروجی PDF
 * @param {object} opts paper (A4..A0), orientation (auto|portrait|landscape), mode (fit|tiles), tileScale (درصد اندازه واقعی), dpi, onProgress
 */
export async function exportPdf(built, filename, opts = {}) {
  const [pw, ph] = PAPER[opts.paper || 'A3'];
  const landscape = opts.orientation === 'landscape' || (opts.orientation !== 'portrait' && built.width > built.height);
  const pageW = landscape ? Math.max(pw, ph) : Math.min(pw, ph); // میلی‌متر
  const pageH = landscape ? Math.min(pw, ph) : Math.max(pw, ph);
  const marginMm = 8;
  const printableW = pageW - marginMm * 2;
  const printableH = pageH - marginMm * 2;
  const pdf = new PdfWriter(opts.title || 'شجره‌نامه');
  const dpi = opts.dpi || 170;

  if ((opts.mode || 'fit') === 'fit') {
    const scaleMm = Math.min(printableW / built.width, printableH / built.height); // میلی‌متر به ازای هر واحد SVG
    const wMm = built.width * scaleMm;
    const hMm = built.height * scaleMm;
    let pxW = Math.round((wMm / 25.4) * dpi);
    let pxH = Math.round((hMm / 25.4) * dpi);
    const limit = maxPixels();
    if (pxW * pxH > limit) {
      const f = Math.sqrt(limit / (pxW * pxH));
      pxW = Math.floor(pxW * f);
      pxH = Math.floor(pxH * f);
    }
    opts.onProgress?.(0.3);
    const canvas = await rasterize(built.svg, { x: 0, y: 0, w: built.width, h: built.height }, pxW, pxH);
    const jpeg = new Uint8Array(await (await canvasBlob(canvas, 'image/jpeg', 0.92)).arrayBuffer());
    pdf.addPage(jpeg, pxW, pxH, mmToPt(pageW), mmToPt(pageH), {
      x: mmToPt((pageW - wMm) / 2),
      y: mmToPt((pageH - hMm) / 2),
      w: mmToPt(wMm),
      h: mmToPt(hMm),
    });
  } else {
    // حالت پوستری: درخت در اندازه واقعی روی چند صفحه تقسیم می‌شود
    const unitMm = (25.4 / 96) * ((opts.tileScale || 100) / 100); // هر واحد SVG ≈ یک پیکسل صفحه
    const overlapMm = 6;
    const cols = Math.max(1, Math.ceil((built.width * unitMm - overlapMm) / (printableW - overlapMm)));
    const rows = Math.max(1, Math.ceil((built.height * unitMm - overlapMm) / (printableH - overlapMm)));
    const regionW = printableW / unitMm;
    const regionH = printableH / unitMm;
    const stepW = (printableW - overlapMm) / unitMm;
    const stepH = (printableH - overlapMm) / unitMm;
    const pxW = Math.round((printableW / 25.4) * dpi);
    const pxH = Math.round((printableH / 25.4) * dpi);
    const total = cols * rows;
    let done = 0;
    // ترتیب صفحات: از بالا-راست (مطابق خواندن فارسی)
    for (let r = 0; r < rows; r++) {
      for (let c = cols - 1; c >= 0; c--) {
        const region = { x: c * stepW, y: r * stepH, w: regionW, h: regionH };
        const canvas = await rasterize(built.svg, region, pxW, pxH);
        const ctx = canvas.getContext('2d');
        ctx.font = '600 22px Vazirmatn, Tahoma';
        ctx.fillStyle = '#a58c66';
        ctx.textAlign = 'left';
        ctx.fillText(`${fa(r + 1)}/${fa(cols - c)}`, 18, pxH - 18);
        const jpeg = new Uint8Array(await (await canvasBlob(canvas, 'image/jpeg', 0.9)).arrayBuffer());
        pdf.addPage(jpeg, pxW, pxH, mmToPt(pageW), mmToPt(pageH), {
          x: mmToPt(marginMm), y: mmToPt(marginMm), w: mmToPt(printableW), h: mmToPt(printableH),
        });
        done++;
        opts.onProgress?.(done / total);
      }
    }
  }

  await saveFile(pdf.build(), filename);
}

/** تعداد صفحات حالت پوستری (برای نمایش در فرم) */
export function tileCount(built, opts) {
  const [pw, ph] = PAPER[opts.paper || 'A3'];
  const landscape = opts.orientation === 'landscape' || (opts.orientation !== 'portrait' && built.width > built.height);
  const pageW = (landscape ? Math.max(pw, ph) : Math.min(pw, ph)) - 16;
  const pageH = (landscape ? Math.min(pw, ph) : Math.max(pw, ph)) - 16;
  const unitMm = (25.4 / 96) * ((opts.tileScale || 100) / 100);
  const cols = Math.max(1, Math.ceil((built.width * unitMm - 6) / (pageW - 6)));
  const rows = Math.max(1, Math.ceil((built.height * unitMm - 6) / (pageH - 6)));
  return { cols, rows, total: cols * rows };
}

/** چاپ مستقیم (برداری) */
export function printSvg(built, { paper = 'A3', orientation = 'auto' } = {}) {
  const landscape = orientation === 'landscape' || (orientation !== 'portrait' && built.width > built.height);
  const root = document.getElementById('print-root');
  root.innerHTML = '';
  // تجزیه به عنوان سند SVG (نه HTML) و انتقال به صفحه؛ داده کاربر فقط به صورت متن است
  const parsed = new DOMParser().parseFromString(built.svg, 'image/svg+xml').documentElement;
  if (parsed.nodeName !== 'svg') return;
  const svg = document.importNode(parsed, true);
  svg.setAttribute('width', '100%');
  svg.setAttribute('height', '100%');
  svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
  svg.style.height = 'calc(100vh - 2px)';
  root.append(svg);

  const style = document.createElement('style');
  style.id = 'print-page-style';
  style.textContent = `@page { size: ${paper} ${landscape ? 'landscape' : 'portrait'}; margin: 8mm; }`;
  document.head.append(style);
  document.body.classList.add('printing');

  const cleanup = () => {
    document.body.classList.remove('printing');
    style.remove();
    root.innerHTML = '';
    window.removeEventListener('afterprint', cleanup);
  };
  window.addEventListener('afterprint', cleanup);
  setTimeout(() => printPage(), 150);
  setTimeout(cleanup, 120000);
}
