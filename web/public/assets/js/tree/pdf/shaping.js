/**
 * چیدمان متن فارسی برای PDF برداری (بدون موتور متن مرورگر):
 *
 *  ۱. اتصال حروف: هر حرف با توجه به حروف قبل و بعد به شکل «تنها / پایانی / آغازی / میانی»
 *     تبدیل و با نویسه‌های «Presentation Forms» یونیکد جایگزین می‌شود (+ لام‌الف).
 *  ۲. جهت متن (نسخه ساده الگوریتم دوجهتی یونیکد): متن راست‌به‌چپ است ولی اعداد و
 *     کلمات لاتین چپ‌به‌راست می‌مانند؛ پرانتزها در متن راست‌به‌چپ قرینه می‌شوند.
 *
 * خروجی: فهرست گلیف‌ها به ترتیب دیداری (از چپ به راست) برای رسم.
 */

// [تنها، پایانی، آغازی، میانی] ؛ حروف راست‌چسب فقط دو شکل اول را دارند
const FORMS = {
  0x0621: [0xfe80],
  0x0622: [0xfe81, 0xfe82],
  0x0623: [0xfe83, 0xfe84],
  0x0624: [0xfe85, 0xfe86],
  0x0625: [0xfe87, 0xfe88],
  0x0626: [0xfe89, 0xfe8a, 0xfe8b, 0xfe8c],
  0x0627: [0xfe8d, 0xfe8e],
  0x0628: [0xfe8f, 0xfe90, 0xfe91, 0xfe92],
  0x0629: [0xfe93, 0xfe94],
  0x062a: [0xfe95, 0xfe96, 0xfe97, 0xfe98],
  0x062b: [0xfe99, 0xfe9a, 0xfe9b, 0xfe9c],
  0x062c: [0xfe9d, 0xfe9e, 0xfe9f, 0xfea0],
  0x062d: [0xfea1, 0xfea2, 0xfea3, 0xfea4],
  0x062e: [0xfea5, 0xfea6, 0xfea7, 0xfea8],
  0x062f: [0xfea9, 0xfeaa],
  0x0630: [0xfeab, 0xfeac],
  0x0631: [0xfead, 0xfeae],
  0x0632: [0xfeaf, 0xfeb0],
  0x0633: [0xfeb1, 0xfeb2, 0xfeb3, 0xfeb4],
  0x0634: [0xfeb5, 0xfeb6, 0xfeb7, 0xfeb8],
  0x0635: [0xfeb9, 0xfeba, 0xfebb, 0xfebc],
  0x0636: [0xfebd, 0xfebe, 0xfebf, 0xfec0],
  0x0637: [0xfec1, 0xfec2, 0xfec3, 0xfec4],
  0x0638: [0xfec5, 0xfec6, 0xfec7, 0xfec8],
  0x0639: [0xfec9, 0xfeca, 0xfecb, 0xfecc],
  0x063a: [0xfecd, 0xfece, 0xfecf, 0xfed0],
  0x0641: [0xfed1, 0xfed2, 0xfed3, 0xfed4],
  0x0642: [0xfed5, 0xfed6, 0xfed7, 0xfed8],
  0x0643: [0xfed9, 0xfeda, 0xfedb, 0xfedc],
  0x0644: [0xfedd, 0xfede, 0xfedf, 0xfee0],
  0x0645: [0xfee1, 0xfee2, 0xfee3, 0xfee4],
  0x0646: [0xfee5, 0xfee6, 0xfee7, 0xfee8],
  0x0647: [0xfee9, 0xfeea, 0xfeeb, 0xfeec],
  0x0648: [0xfeed, 0xfeee],
  0x0649: [0xfeef, 0xfef0],
  0x064a: [0xfef1, 0xfef2, 0xfef3, 0xfef4],
  // حروف فارسی (Presentation Forms-A)
  0x067e: [0xfb56, 0xfb57, 0xfb58, 0xfb59], // پ
  0x0686: [0xfb7a, 0xfb7b, 0xfb7c, 0xfb7d], // چ
  0x0698: [0xfb8a, 0xfb8b], // ژ
  0x06a9: [0xfb8e, 0xfb8f, 0xfb90, 0xfb91], // ک
  0x06af: [0xfb92, 0xfb93, 0xfb94, 0xfb95], // گ
  0x06cc: [0xfbfc, 0xfbfd, 0xfbfe, 0xfbff], // ی
  0x06c0: [0xfba4, 0xfba5], // ۀ
};

// لام + الف ← یک گلیف [تنها، پایانی]
const LAM_ALEF = {
  0x0622: [0xfef5, 0xfef6],
  0x0623: [0xfef7, 0xfef8],
  0x0625: [0xfef9, 0xfefa],
  0x0627: [0xfefb, 0xfefc],
};

const ZWNJ = 0x200c;
const ZWJ = 0x200d;
const TATWEEL = 0x0640;

/** نوع اتصال: D دوطرفه، R فقط به قبل، U بدون اتصال، T شفاف (اعراب)، C اتصال‌دهنده */
function joining(cp) {
  if (cp === ZWJ || cp === TATWEEL) return 'C';
  if ((cp >= 0x064b && cp <= 0x065f) || cp === 0x0670) return 'T';
  const forms = FORMS[cp];
  if (!forms) return 'U';
  return forms.length === 4 ? 'D' : forms.length === 2 ? 'R' : 'U';
}

/**
 * اتصال حروف (روی ترتیب منطقی)
 * @returns {Array<{cp:number, src:string}>} نویسه‌های نهایی + متن اصلی (برای جستجوپذیری)
 */
export function shapeArabic(text) {
  const cps = Array.from(text, (c) => c.codePointAt(0));
  const types = cps.map(joining);
  const out = [];

  const prevIndex = (i) => {
    for (let k = i - 1; k >= 0; k--) if (types[k] !== 'T') return k;
    return -1;
  };
  const nextIndex = (i) => {
    for (let k = i + 1; k < cps.length; k++) if (types[k] !== 'T') return k;
    return -1;
  };
  const joinsLeft = (t) => t === 'D' || t === 'C';
  const joinsRight = (t) => t === 'D' || t === 'R' || t === 'C';

  for (let i = 0; i < cps.length; i++) {
    const cp = cps[i];
    if (cp === ZWNJ || cp === ZWJ) continue; // نامرئی
    const t = types[i];
    if (t === 'U' || t === 'T' || t === 'C') {
      out.push({ cp, src: String.fromCodePoint(cp) });
      continue;
    }
    const p = prevIndex(i);
    const n = nextIndex(i);
    const fromPrev = p >= 0 && joinsLeft(types[p]);

    // لام‌الف
    if (cp === 0x0644 && n >= 0 && LAM_ALEF[cps[n]]) {
      out.push({ cp: LAM_ALEF[cps[n]][fromPrev ? 1 : 0], src: String.fromCodePoint(cp, cps[n]) });
      // اعراب بین لام و الف (نادر) نادیده گرفته می‌شود
      i = n;
      continue;
    }

    const toNext = t === 'D' && n >= 0 && joinsRight(types[n]);
    const forms = FORMS[cp];
    let form = 0;
    if (fromPrev && toNext) form = 3;
    else if (fromPrev) form = 1;
    else if (toNext) form = 2;
    out.push({ cp: forms[form] ?? forms[0], src: String.fromCodePoint(cp) });
  }
  return out;
}

// ------------------------------------------------------------------ جهت متن

function bidiClass(cp) {
  if ((cp >= 0x0600 && cp <= 0x06ff && !(cp >= 0x06f0 && cp <= 0x06f9) && !(cp >= 0x0660 && cp <= 0x0669))
    || (cp >= 0xfb50 && cp <= 0xfdff) || (cp >= 0xfe70 && cp <= 0xfeff)) return 'R';
  if ((cp >= 0x30 && cp <= 0x39) || (cp >= 0x06f0 && cp <= 0x06f9)) return 'EN';
  if (cp >= 0x0660 && cp <= 0x0669) return 'AN';
  if (cp === 0x2b || cp === 0x2d || cp === 0x2212) return 'ES';
  if (cp === 0x2c || cp === 0x2e || cp === 0x2f || cp === 0x3a || cp === 0x060c) return 'CS';
  if (cp === 0x25 || cp === 0x066a || cp === 0x23 || cp === 0x24) return 'ET';
  if ((cp >= 0x41 && cp <= 0x5a) || (cp >= 0x61 && cp <= 0x7a) || (cp >= 0xc0 && cp <= 0x24f)) return 'L';
  return 'N';
}

const MIRROR = { '(': ')', ')': '(', '[': ']', ']': '[', '{': '}', '}': '{', '<': '>', '>': '<', '«': '»', '»': '«' };

/**
 * ترتیب دیداری نویسه‌ها در یک خط با جهت پایه راست‌به‌چپ.
 * @param {Array<{cp:number}>} items  نویسه‌ها به ترتیب منطقی
 */
export function reorderRtl(items) {
  const n = items.length;
  const cls = items.map((it) => bidiClass(it.cp));

  // W4: جداکننده تنها بین دو عدد جزو عدد است (۱۳۰۵/۰۷/۱۲ یا ۱۲٫۵)
  for (let i = 1; i < n - 1; i++) {
    if ((cls[i] === 'ES' || cls[i] === 'CS') && cls[i - 1] === 'EN' && cls[i + 1] === 'EN') cls[i] = 'EN';
  }
  // W5: علامت‌های کنار عدد (مثل ٪) جزو عدد
  for (let i = 0; i < n; i++) {
    if (cls[i] === 'ET' && ((i > 0 && cls[i - 1] === 'EN') || (i + 1 < n && cls[i + 1] === 'EN'))) cls[i] = 'EN';
  }

  // سطح هر نویسه: راست‌به‌چپ = ۱ ، لاتین و عدد = ۲
  const strong = (c) => (c === 'L' ? 'L' : c === 'R' ? 'R' : c === 'EN' || c === 'AN' ? 'R' : null);
  const levels = new Array(n);
  for (let i = 0; i < n; i++) {
    const c = cls[i];
    if (c === 'R') levels[i] = 1;
    else if (c === 'L' || c === 'EN' || c === 'AN') levels[i] = 2;
    else levels[i] = null;
  }
  // N1/N2: نویسه‌های خنثی بین دو نویسه لاتین، لاتین؛ در غیر این صورت راست‌به‌چپ
  for (let i = 0; i < n; i++) {
    if (levels[i] !== null) continue;
    let j = i;
    while (j < n && levels[j] === null) j++;
    const before = i > 0 ? strong(cls[i - 1]) : 'R';
    const after = j < n ? strong(cls[j]) : 'R';
    const level = before === 'L' && after === 'L' ? 2 : 1;
    for (let k = i; k < j; k++) levels[k] = level;
    i = j - 1;
  }

  // L2: وارونه کردن زنجیره‌های سطح ۲ و سپس کل خط
  const order = [...Array(n).keys()];
  for (let i = 0; i < n; i++) {
    if (levels[i] < 2) continue;
    let j = i;
    while (j < n && levels[j] >= 2) j++;
    order.splice(i, j - i, ...order.slice(i, j).reverse());
    i = j - 1;
  }
  order.reverse();

  return order.map((idx) => {
    const it = items[idx];
    if (levels[idx] === 1) {
      const ch = String.fromCodePoint(it.cp);
      if (MIRROR[ch]) return { ...it, cp: MIRROR[ch].codePointAt(0) };
    }
    return it;
  });
}

/**
 * متن ← گلیف‌ها به ترتیب دیداری (چپ به راست) با پهنای هر کدام
 * @param {string} text
 * @param {import('./ttf.js').TrueTypeFont} font
 * @returns {{glyphs: Array<{gid:number, src:string, width:number}>, width:number}} پهنا به واحد em
 */
export function layoutText(text, font) {
  const shaped = shapeArabic(String(text ?? '').replace(/[‎‏‪-‮⁦-⁩]/g, ''));
  // اگر فونت شکل خاصی را نداشت، خود حرف پایه استفاده شود
  for (const s of shaped) {
    if (!font.has(s.cp) && s.src.length === 1) s.cp = s.src.codePointAt(0);
  }
  const visual = reorderRtl(shaped);
  let width = 0;
  const glyphs = visual.map((s) => {
    const gid = font.glyphId(s.cp);
    const w = font.advance(gid);
    width += w;
    return { gid, src: s.src, width: w };
  });
  return { glyphs, width };
}
