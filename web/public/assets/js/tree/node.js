/**
 * ساخت گره دایره‌ای یک شخص در SVG.
 *
 *            ‿‿ نام (روی قوس بالا) ‿‿
 *          (      عکس دایره‌ای      )
 *            ‿ تاریخ‌ها (قوس پایین، رو به بیننده) ‿
 *
 * مسیر قوس‌ها یک بار در <defs> تعریف می‌شود و همه گره‌ها از آن استفاده می‌کنند
 * (مختصات مسیر در دستگاه مختصات خود گره تفسیر می‌شود) → DOM سبک برای هزاران گره.
 */
import { s } from '../core/dom.js';
import { academicName, fa, fullName, lifespan, yearOf } from '../core/format.js';
import { iconPaths, silhouetteMarkup } from '../core/icons.js';

// ------------------------------------------------------------------ اندازه‌گیری متن
let measureCtx = null;
let measureFont = '';
const widthCache = new Map();
function textWidth(text, size, weight = 700) {
  const key = `${weight}|${size}|${text}`;
  if (widthCache.has(key)) return widthCache.get(key);
  measureCtx ??= document.createElement('canvas').getContext('2d');
  const font = `${weight} ${size}px Vazirmatn, Tahoma, sans-serif`;
  if (font !== measureFont) {
    measureCtx.font = font;
    measureFont = font;
  }
  const w = measureCtx.measureText(text).width;
  if (widthCache.size > 20000) widthCache.clear();
  widthCache.set(key, w);
  return w;
}

/**
 * تطبیق متن با طول قوس: ابتدا کوچک کردن فونت، سپس فشرده‌سازی، و در آخر کوتاه کردن با …
 * @returns {{text:string, size:number, length:number|null}}
 */
export function fitText(text, maxWidth, baseSize, minSize, weight) {
  if (!text) return { text: '', size: baseSize, length: null };
  let size = baseSize;
  let w = textWidth(text, size, weight);
  if (w <= maxWidth) return { text, size, length: null };
  size = Math.max(minSize, Math.floor(baseSize * (maxWidth / w) * 10) / 10);
  w = textWidth(text, size, weight);
  if (w <= maxWidth) return { text, size, length: null };
  if (w <= maxWidth * 1.25) return { text, size, length: maxWidth };
  let t = text;
  while (t.length > 3 && textWidth(t + '…', size, weight) > maxWidth * 1.2) t = t.slice(0, -1);
  return { text: t.trim() + '…', size, length: maxWidth };
}

// ------------------------------------------------------------------ تعاریف مشترک (defs)
export function buildDefs(g, idPrefix = 't') {
  const rt = g.rt; // شعاع خط پایه متن بالا (حروف رو به بیرون)
  const rb = g.rb; // شعاع خط پایه متن پایین (حروف رو به داخل = ایستاده)
  const defs = s('defs');
  defs.innerHTML = `
    <clipPath id="${idPrefix}-clip-photo" clipPathUnits="objectBoundingBox"><circle cx=".5" cy=".5" r=".5"/></clipPath>
    <clipPath id="${idPrefix}-clip-circle"><circle r="${g.R}"/></clipPath>
    <path id="${idPrefix}-arc-top" d="M ${-rt},0 A ${rt},${rt} 0 0 1 ${rt},0" fill="none"/>
    <path id="${idPrefix}-arc-bottom" d="M ${-rb},0 A ${rb},${rb} 0 0 0 ${rb},0" fill="none"/>
    <linearGradient id="${idPrefix}-ring-m" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2563eb"/><stop offset="1" stop-color="#06b6d4"/></linearGradient>
    <linearGradient id="${idPrefix}-ring-f" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#db2777"/><stop offset="1" stop-color="#a855f7"/></linearGradient>
    <linearGradient id="${idPrefix}-ring-md" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1f2937"/><stop offset=".6" stop-color="#475569"/><stop offset="1" stop-color="#b7862c"/></linearGradient>
    <linearGradient id="${idPrefix}-ring-fd" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3b1d2e"/><stop offset=".6" stop-color="#6b5563"/><stop offset="1" stop-color="#b7862c"/></linearGradient>
    <radialGradient id="${idPrefix}-halo"><stop offset=".55" stop-color="#14b8a6" stop-opacity=".35"/><stop offset="1" stop-color="#14b8a6" stop-opacity="0"/></radialGradient>
    <radialGradient id="${idPrefix}-plate"><stop offset=".6" stop-color="var(--plate-1, #fff)" stop-opacity=".92"/><stop offset="1" stop-color="var(--plate-1, #fff)" stop-opacity=".55"/></radialGradient>
    <symbol id="${idPrefix}-sil-m" viewBox="0 0 100 100">${silhouetteMarkup('m')}</symbol>
    <symbol id="${idPrefix}-sil-f" viewBox="0 0 100 100">${silhouetteMarkup('f')}</symbol>
    <radialGradient id="${idPrefix}-shadow"><stop offset=".72" stop-color="#0b1a1d" stop-opacity=".28"/><stop offset="1" stop-color="#0b1a1d" stop-opacity="0"/></radialGradient>
  `;
  return defs;
}

/** نام کوتاه مقطع تحصیلی برای جا شدن روی قوس کوچک */
const EDUCATION_SHORT = {
  illiterate: 'بی‌سواد', literate: 'سواد قدیمی', primary: 'ابتدایی', middle: 'سیکل', high_school: 'دیپلم',
  associate: 'فوق‌دیپلم', bachelor: 'لیسانس', master: 'فوق‌لیسانس', professional_doctorate: 'دکترای حرفه‌ای',
  phd: 'دکترا', specialist: 'متخصص', subspecialist: 'فوق‌تخصص', fellowship: 'فلوشیپ', postdoc: 'پسادکترا',
  hawza_1: 'حوزوی ۱', hawza_2: 'حوزوی ۲', hawza_3: 'حوزوی ۳', hawza_4: 'حوزوی ۴',
};

// ------------------------------------------------------------------ متن‌های دور دایره
export function arcTexts(person, prefs = {}) {
  const topMode = prefs.arc_top || 'name';
  const bottomMode = prefs.arc_bottom || 'dates';
  let top = '';
  // «دکتر» و «مهندس» خودکار همیشه همراه نام است (روی دایره و در چاپ)
  if (topMode === 'name') top = academicName(person);
  else if (topMode === 'fullname') top = fullName(person);
  else if (topMode === 'nickname') top = person.nickname || person.first_name;

  let bottom = '';
  if (bottomMode === 'dates' || bottomMode === 'years') bottom = lifespan(person);
  else if (bottomMode === 'place') bottom = person.birth_place || '';
  else if (bottomMode === 'occupation') bottom = person.occupation || '';
  else if (bottomMode === 'education') bottom = EDUCATION_SHORT[person.education_level] || '';
  else if (bottomMode === 'city') bottom = person.city || '';

  return { top: fa(top), bottom: fa(bottom) };
}

// ------------------------------------------------------------------ گره
/**
 * @param {object} node  خروجی layout (x, y, person, kind, ...)
 * @param {object} g     geometry
 * @param {object} opts  { prefs, idPrefix, interactive, photoHref(person) }
 */
export function buildNode(node, g, opts = {}) {
  const p = node.person;
  const idp = opts.idPrefix || 't';
  const prefs = opts.prefs || {};
  const female = p.gender === 'f';
  const dead = !!p.is_deceased;
  const ringId = `${idp}-ring-${female ? 'f' : 'm'}${dead ? 'd' : ''}`;
  const classes = ['t-node', female ? 'female' : 'male', dead ? 'dead' : '', `kind-${node.kind}`, node.isRoot ? 'root' : ''];

  const el = s('g', { class: classes.filter(Boolean).join(' '), 'data-key': node.key, 'data-id': node.id });
  const inner = s('g', { class: 't-inner' });
  el.append(inner);

  const rt = g.rt;
  const rb = g.rb;

  if (opts.interactive !== false) {
    inner.append(
      s('circle', { class: 't-halo', r: g.outer + 10, fill: `url(#${idp}-halo)` }),
      s('circle', { class: 't-orbit', r: g.outer + 3, fill: 'none', stroke: 'var(--primary-2)', 'stroke-width': 1.6, 'stroke-dasharray': '3 7', 'stroke-linecap': 'round' }),
    );
  }

  // صفحه پشت متن‌ها (مدال)
  inner.append(s('circle', { class: 't-plate', r: g.outer - 1, fill: `url(#${idp}-plate)`, stroke: 'var(--plate-border, rgba(0,0,0,.06))', 'stroke-width': 1 }));
  if (node.isRoot) {
    inner.append(s('circle', { r: g.outer + 1, fill: 'none', stroke: '#c9a24a', 'stroke-width': 1.5, 'stroke-dasharray': node.kind === 'alias' ? '4 4' : null }));
  }

  // عکس یا سیلوئت
  // سایه نرم با گرادیان (به‌جای فیلتر SVG که روی هزاران گره بسیار کند است)
  const photoGroup = s('g', { class: 't-photo-wrap' });
  photoGroup.append(
    s('circle', { class: 't-shadow', r: g.R + g.ring + 7, cy: 4, fill: `url(#${idp}-shadow)` }),
    s('circle', { r: g.R + g.ring, fill: 'var(--surface, #fff)' }),
  );
  const href = prefs.show_photos === false ? null : (opts.photoHref ? opts.photoHref(p) : p.avatar);
  if (href) {
    photoGroup.append(s('image', {
      class: 't-photo',
      href,
      x: -g.R,
      y: -g.R,
      width: g.R * 2,
      height: g.R * 2,
      preserveAspectRatio: 'xMidYMid slice',
      'clip-path': `url(#${idp}-clip-photo)`,
      style: dead ? 'filter: grayscale(.45)' : null,
    }));
  } else {
    // برش دایره روی گروه اعمال می‌شود (نه خود use) تا مختصات برش با مرکز گره یکی باشد
    photoGroup.append(s('g', { 'clip-path': `url(#${idp}-clip-circle)` },
      s('use', { href: `#${idp}-sil-${female ? 'f' : 'm'}`, x: -g.R, y: -g.R, width: g.R * 2, height: g.R * 2 })));
  }
  // روبان مشکی سوگواری برای درگذشتگان
  if (dead) {
    photoGroup.append(s('path', {
      d: `M ${-g.R},${-g.R * 0.22} L ${-g.R * 0.22},${-g.R}`,
      stroke: '#111',
      'stroke-width': g.R * 0.2,
      'clip-path': `url(#${idp}-clip-circle)`,
      opacity: 0.92,
    }));
  }
  // حلقه رنگی
  photoGroup.append(s('circle', {
    class: 't-ring',
    r: g.R + g.ring / 2,
    fill: 'none',
    stroke: `url(#${ringId})`,
    'stroke-width': g.ring,
    'stroke-dasharray': node.kind === 'alias' ? '6 4' : null,
  }));
  inner.append(photoGroup);

  // متن‌های روی قوس
  const texts = arcTexts(p, prefs);
  const maxTop = Math.PI * rt * 0.8;
  const maxBottom = Math.PI * rb * 0.74;

  if (texts.top) {
    const fit = fitText(texts.top, maxTop, g.topSize, g.topSize * 0.78, 700);
    inner.append(arcText(`#${idp}-arc-top`, fit, 't-arc t-arc-top', 700));
  }
  if (texts.bottom) {
    const fit = fitText(texts.bottom, maxBottom, g.bottomSize, g.bottomSize * 0.8, 500);
    inner.append(arcText(`#${idp}-arc-bottom`, fit, 't-arc t-arc-bottom', 500));
  }

  if (opts.interactive !== false) addButtons(el, node, g, idp);
  return el;
}

function arcText(href, fit, cls, weight) {
  const tp = s('textPath', {
    href,
    startOffset: '50%',
    'text-anchor': 'middle',
    textLength: fit.length ? Math.round(fit.length) : null,
    lengthAdjust: fit.length ? 'spacingAndGlyphs' : null,
  }, fit.text);
  return s('text', { class: cls, 'font-size': fit.size, 'font-weight': weight, direction: 'rtl' }, tp);
}

// ------------------------------------------------------------------ دکمه‌های کوچک روی گره
function button(action, x, y, content, title, variant = '') {
  const b = s('g', { class: `t-btn ${variant}`, 'data-action': action, transform: `translate(${x},${y})` },
    s('title', null, title),
    s('circle', { class: 't-btn-bg', r: 12.5 }),
  );
  if (typeof content === 'string' && content.startsWith('<')) {
    const icon = s('g', { transform: 'translate(-7.5,-7.5) scale(.625)', class: 't-btn-icon' });
    icon.innerHTML = content;
    b.append(icon);
  } else {
    b.append(s('text', { class: 't-btn-label', 'text-anchor': 'middle', dy: '0.36em' }, content));
  }
  return b;
}

function addButtons(el, node, g, idp) {
  const p = node.person;
  const by = g.outer + 17;

  // جمع/باز کردن فرزندان
  if (node.kind === 'blood' || node.kind === 'ancestor' || node.isRoot) {
    if (node.childCount > 0 && node.collapsed) {
      el.append(button('toggle', 0, by, '+' + fa(node.childCount), 'نمایش فرزندان', 'expand'));
    } else if (node.childCount > 0 && node.kind === 'blood') {
      el.append(button('toggle', 0, by, '<path d="M5 12h14" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>', 'بستن شاخه'));
    } else if (node.expandable) {
      el.append(button('load-children', 0, by, '<path d="M5 12h14M12 5v14" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>', 'بارگذاری ادامه درخت', 'expand dashed'));
    }
  }

  // نمایش نیاکان بیشتر
  if (node.expandUp) {
    el.append(button('load-parents', 0, -by, '<path d="m6 15 6-6 6 6" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>', 'نمایش والدین', 'expand dashed'));
  } else if (node.canCollapseUp && !node.isRoot) {
    el.append(button('toggle-up', 0, -by, '<path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>', 'بستن نیاکان'));
  }

  // درخت خانوادگی همسر
  if ((node.kind === 'spouse') && p.has_parents) {
    const d = g.outer * 0.72;
    el.append(button('spouse-tree', -d, -d, iconPaths('ancestors').replace(/<(path|circle)/g, '<$1 fill="none" stroke="currentColor" stroke-width="2.4"'), 'مشاهده درخت خانواده همسر', 'family'));
  }
  if (node.kind === 'alias') {
    const d = g.outer * 0.72;
    el.append(button('goto', -d, -d, iconPaths('link').replace(/<path/g, '<path fill="none" stroke="currentColor" stroke-width="2.4"'), 'رفتن به جایگاه اصلی در درخت', 'family'));
  }
}

/** متن ساده برای عنوان (tooltip) */
export function nodeTitle(p) {
  const y = yearOf(p.birth_date);
  return fullName(p) + (y ? ` (${fa(y)})` : '');
}
