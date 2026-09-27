/**
 * ارتباط با API سرور.
 *
 * - وب: احراز هویت با کوکی سشن امن + توکن CSRF (کوکی XSRF-TOKEN)
 * - خطاها به صورت ApiError با پیام فارسی سرور پرتاب می‌شوند
 * - آپلود با نمایش درصد پیشرفت (XHR)
 */
import { store } from './store.js';

const BASE = (document.querySelector('meta[name="base-url"]')?.content || '').replace(/\/$/, '');
let inflight = 0;

export class ApiError extends Error {
  constructor(status, data) {
    super(data?.message || (status === 0 ? 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید.' : 'خطایی رخ داد.'));
    this.status = status;
    this.data = data || {};
    this.errors = data?.errors || {};
  }

  /** اولین پیام خطای یک فیلد */
  field(name) {
    const e = this.errors[name];
    return Array.isArray(e) ? e[0] : e;
  }
}

export function url(path) {
  if (/^https?:/i.test(path)) return path;
  return BASE + (path.startsWith('/') ? path : '/' + path);
}

function cookie(name) {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[-.]/g, '\\$&') + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : null;
}

async function ensureCsrf() {
  if (!cookie('XSRF-TOKEN')) {
    await fetch(url('/sanctum/csrf-cookie'), { credentials: 'same-origin' });
  }
}

function headers(extra = {}) {
  const h = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...extra,
  };
  const xsrf = cookie('XSRF-TOKEN');
  if (xsrf) h['X-XSRF-TOKEN'] = xsrf;
  return h;
}

function busy(delta) {
  inflight = Math.max(0, inflight + delta);
  document.getElementById('top-progress')?.classList.toggle('active', inflight > 0);
}

function query(params) {
  if (!params) return '';
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') q.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v);
  }
  const s = q.toString();
  return s ? '?' + s : '';
}

/**
 * درخواست عمومی
 * @returns {Promise<any>} بدنه JSON پاسخ
 */
export async function request(method, path, body, { raw = false, signal } = {}) {
  if (method !== 'GET') await ensureCsrf();
  busy(1);
  let response;
  try {
    const isForm = body instanceof FormData;
    response = await fetch(url(path), {
      method,
      credentials: 'same-origin',
      headers: headers(isForm || body === undefined ? {} : { 'Content-Type': 'application/json' }),
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
      signal,
    });
  } catch (e) {
    busy(-1);
    if (e.name === 'AbortError') throw e;
    throw new ApiError(0, null);
  }
  busy(-1);

  if (raw && response.ok) return response;

  let data = null;
  const type = response.headers.get('Content-Type') || '';
  if (type.includes('json')) data = await response.json().catch(() => null);

  if (!response.ok) {
    if (response.status === 401) store.emit('unauthorized');
    if (response.status === 419) {
      // توکن CSRF منقضی شده؛ یک بار دیگر تلاش کن
      document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
      if (!request._retry) {
        request._retry = true;
        try {
          return await request(method, path, body, { raw, signal });
        } finally {
          request._retry = false;
        }
      }
    }
    throw new ApiError(response.status, data);
  }

  return data;
}

export const get = (path, params, opts) => request('GET', path + query(params), undefined, opts);
export const post = (path, body, opts) => request('POST', path, body ?? {}, opts);
export const put = (path, body, opts) => request('PUT', path, body ?? {}, opts);
export const patch = (path, body, opts) => request('PATCH', path, body ?? {}, opts);
export const del = (path, body, opts) => request('DELETE', path, body, opts);

/** دانلود فایل از API (مثلاً GEDCOM) */
export async function download(path, params) {
  const res = await request('GET', path + query(params), undefined, { raw: true });
  const blob = await res.blob();
  const disposition = res.headers.get('Content-Disposition') || '';
  const name = (disposition.match(/filename="?([^";]+)"?/) || [])[1] || 'download';
  return { blob, name };
}

/**
 * آپلود فایل با درصد پیشرفت
 * @param {(percent:number)=>void} onProgress
 */
export async function upload(path, formData, onProgress) {
  await ensureCsrf();
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url(path));
    xhr.withCredentials = true;
    for (const [k, v] of Object.entries(headers())) xhr.setRequestHeader(k, v);
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && onProgress) onProgress(Math.round((e.loaded / e.total) * 100));
    };
    xhr.onload = () => {
      let data = null;
      try {
        data = JSON.parse(xhr.responseText);
      } catch {
        /* ignore */
      }
      if (xhr.status >= 200 && xhr.status < 300) resolve(data);
      else {
        if (xhr.status === 413) data = { message: 'حجم فایل بیش از حد مجاز سرور است.' };
        reject(new ApiError(xhr.status, data));
      }
    };
    xhr.onerror = () => reject(new ApiError(0, null));
    xhr.send(formData);
  });
}
