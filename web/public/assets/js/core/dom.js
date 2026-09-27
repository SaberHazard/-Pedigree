/**
 * ابزارهای ساخت DOM بدون فریم‌ورک.
 *
 * h('div', {class: 'card', onclick: fn}, 'متن', child)  → یک المنت
 * متن‌ها همیشه به صورت textNode اضافه می‌شوند، پس داده کاربر هرگز به HTML
 * تبدیل نمی‌شود (جلوگیری از XSS). فقط کلید «html» برای قالب‌های ثابت است.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

function apply(el, attrs, isSvg) {
  if (!attrs) return;
  for (const [key, value] of Object.entries(attrs)) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class' || key === 'className') {
      const cls = Array.isArray(value) ? value.filter(Boolean).join(' ') : value;
      if (isSvg) el.setAttribute('class', cls);
      else el.className = cls;
    } else if (key === 'style' && typeof value === 'object') {
      for (const [prop, v] of Object.entries(value)) {
        if (v === null || v === undefined) continue;
        if (prop.startsWith('--')) el.style.setProperty(prop, v);
        else el.style[prop] = v;
      }
    } else if (key === 'dataset') {
      Object.assign(el.dataset, value);
    } else if (key === 'html') {
      el.innerHTML = value; // فقط برای رشته‌های ثابت داخل کد
    } else if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key === 'ref' && typeof value === 'function') {
      value(el);
    } else if (!isSvg && (key === 'value' || key === 'checked' || key === 'selected' || key === 'disabled')) {
      el[key] = value;
    } else if (value === true) {
      el.setAttribute(key, '');
    } else {
      el.setAttribute(key, String(value));
    }
  }
}

function append(el, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false || child === true) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
}

/** ساخت المنت HTML */
export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  if (attrs instanceof Node || typeof attrs !== 'object' || Array.isArray(attrs)) {
    children.unshift(attrs);
    attrs = null;
  }
  apply(el, attrs, false);
  append(el, children);
  return el;
}

/** ساخت المنت SVG */
export function s(tag, attrs, ...children) {
  const el = document.createElementNS(SVG_NS, tag);
  apply(el, attrs, true);
  append(el, children);
  return el;
}

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

/**
 * جایگزینی فرزندان یک المنت (مثل replaceChildren ولی مقادیر null/false نادیده گرفته می‌شوند؛
 * replaceChildren بومی مرورگر null را به متن «null» تبدیل می‌کند)
 */
export function fill(el, ...children) {
  el.replaceChildren();
  append(el, children);
  return el;
}

export function clear(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
  return el;
}

export function debounce(fn, ms = 250) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
}

export function throttle(fn, ms = 100) {
  let last = 0;
  let timer;
  return (...args) => {
    const now = Date.now();
    clearTimeout(timer);
    if (now - last >= ms) {
      last = now;
      fn(...args);
    } else {
      timer = setTimeout(() => {
        last = Date.now();
        fn(...args);
      }, ms - (now - last));
    }
  };
}

/** اجرای یک تابع بعد از ورود المنت به صفحه قابل دیدن (برای انیمیشن شمارنده‌ها و ...) */
export function onVisible(el, fn) {
  if (!('IntersectionObserver' in window)) return fn();
  const io = new IntersectionObserver((entries) => {
    if (entries.some((e) => e.isIntersecting)) {
      io.disconnect();
      fn();
    }
  });
  io.observe(el);
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** شناسه یکتای کوتاه برای المنت‌ها */
let uid = 0;
export const nextId = (prefix = 'u') => `${prefix}${++uid}`;
