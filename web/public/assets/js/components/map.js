/**
 * نقشه (Leaflet) - فقط وقتی لازم شود بارگذاری می‌شود تا سایت سبک بماند.
 *
 * - pickLocation(): انتخاب موقعیت خانه یا مزار با کلیک/کشیدن نشانگر یا «موقعیت من»
 * - miniMap(): نقشه کوچک فقط‌خواندنی در پروفایل
 * - mountMap(): نقشه کامل با نشانگرهای عکس‌دار (صفحه نقشه خاندان)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { url } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, latin } from '../core/format.js';
import { modal, toast } from '../core/ui.js';

let loading = null;

/** بارگذاری تنبل کتابخانه نقشه از فایل‌های خود سایت (بدون CDN خارجی) */
export function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  loading ??= new Promise((resolve, reject) => {
    document.head.append(h('link', { rel: 'stylesheet', href: url('/assets/vendor/leaflet/leaflet.css') }));
    const script = h('script', { src: url('/assets/vendor/leaflet/leaflet.js') });
    script.onload = () => resolve(window.L);
    script.onerror = () => {
      loading = null;
      reject(new Error('بارگذاری نقشه ممکن نشد.'));
    };
    document.head.append(script);
  });
  return loading;
}

const config = () => store.config.map || {};

function tiles(L, map) {
  const c = config();
  L.tileLayer(c.tiles || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: c.max_zoom || 19,
    attribution: c.attribution || '© OpenStreetMap',
  }).addTo(map);
}

function baseMap(L, el, center, zoom) {
  el.setAttribute('dir', 'ltr'); // کنترل‌های نقشه در صفحه راست‌به‌چپ به هم نریزند
  const map = L.map(el, { zoomControl: true, attributionControl: true }).setView(center, zoom);
  tiles(L, map);
  // وقتی نقشه داخل مودال/تب باز می‌شود اندازه‌اش را دوباره حساب کند
  setTimeout(() => map.invalidateSize(), 80);
  return map;
}

/** نشانگر دایره‌ای با عکس شخص */
export function avatarIcon(L, person, kind = 'home') {
  const img = person.avatar
    ? `<img src="${encodeURI(person.avatar)}" alt="">`
    : `<span>${escapeHtml((person.first_name || '?').slice(0, 1))}</span>`;
  return L.divIcon({
    className: `map-pin ${kind} ${person.gender === 'f' ? 'female' : 'male'}`,
    html: `<div class="map-pin-inner">${img}</div>`,
    iconSize: [44, 52],
    iconAnchor: [22, 50],
    popupAnchor: [0, -46],
  });
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * پنجره انتخاب موقعیت روی نقشه.
 * @returns {Promise<{lat:number,lng:number}|null|undefined>} null = حذف موقعیت، undefined = انصراف
 */
export function pickLocation({ title = 'انتخاب موقعیت روی نقشه', value = null } = {}) {
  return new Promise((resolve) => {
    let point = value && Number.isFinite(+value.lat) ? { lat: +value.lat, lng: +value.lng } : null;
    let result;
    const mapEl = h('div', { class: 'map-box tall' });
    const latIn = h('input', { class: 'input ltr-input', inputmode: 'decimal', placeholder: 'عرض (lat)', style: { width: '150px' } });
    const lngIn = h('input', { class: 'input ltr-input', inputmode: 'decimal', placeholder: 'طول (lng)', style: { width: '150px' } });
    const status = h('div', { class: 'muted small' });
    let marker = null;
    let map = null;

    const sync = () => {
      latIn.value = point ? point.lat.toFixed(6) : '';
      lngIn.value = point ? point.lng.toFixed(6) : '';
      status.textContent = point ? '' : 'روی نقشه کلیک کنید یا دکمه «موقعیت من» را بزنید.';
    };
    const place = (latlng, pan = false) => {
      point = { lat: +latlng.lat, lng: +latlng.lng };
      if (!marker) {
        marker = window.L.marker(point, { draggable: true, autoPan: true }).addTo(map);
        marker.on('dragend', () => place(marker.getLatLng()));
      } else marker.setLatLng(point);
      if (pan) map.setView(point, Math.max(map.getZoom(), 16));
      sync();
    };
    const fromInputs = () => {
      const lat = parseFloat(latin(latIn.value));
      const lng = parseFloat(latin(lngIn.value));
      if (Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) place({ lat, lng }, true);
    };
    latIn.addEventListener('change', fromInputs);
    lngIn.addEventListener('change', fromInputs);

    const locate = h('button', { class: 'btn soft sm', type: 'button', onclick: () => {
      if (!navigator.geolocation) return toast('مرورگر شما موقعیت‌یابی را پشتیبانی نمی‌کند.', 'warning');
      status.textContent = 'در حال پیدا کردن موقعیت شما...';
      navigator.geolocation.getCurrentPosition(
        (pos) => place({ lat: pos.coords.latitude, lng: pos.coords.longitude }, true),
        () => { status.textContent = 'دسترسی به موقعیت داده نشد؛ روی نقشه کلیک کنید.'; },
        { enableHighAccuracy: true, timeout: 15000 },
      );
    } }, icon('crosshair'), 'موقعیت من');

    const m = modal({
      title,
      size: 'wide',
      body: h('div', null,
        mapEl,
        h('div', { class: 'row wrap mt', style: { gap: '8px' } }, locate, h('div', { class: 'grow' }), latIn, lngIn),
        status,
      ),
      actions: [
        value ? { label: 'حذف موقعیت', class: 'ghost danger-text', onClick: () => { result = null; } } : null,
        { label: 'انصراف', class: 'ghost', onClick: () => { result = undefined; } },
        { label: 'تأیید موقعیت', class: 'primary', icon: 'check', onClick: () => {
          if (!point) {
            toast('ابتدا نقطه‌ای را روی نقشه انتخاب کنید.', 'warning');
            return false;
          }
          result = point;
        } },
      ].filter(Boolean),
      onClose: () => {
        map?.remove();
        resolve(result);
      },
    });
    sync();

    loadLeaflet().then((L) => {
      const c = config();
      map = baseMap(L, mapEl, point || c.center || [32.4, 53.7], point ? 16 : c.zoom || 5);
      if (point) place(point);
      map.on('click', (e) => place(e.latlng));
    }).catch((e) => {
      mapEl.replaceChildren(h('div', { class: 'empty' }, e.message, h('div', { class: 'muted small' }, 'می‌توانید مختصات را دستی وارد کنید.')));
    });
    m.el.querySelector('.modal-body')?.classList.add('no-pad-map');
  });
}

/** نقشه کوچک فقط‌خواندنی با یک یا چند نقطه */
export function miniMap(points, { height = 220 } = {}) {
  const el = h('div', { class: 'map-box', style: { height: `${height}px` } });
  loadLeaflet().then((L) => {
    const first = points[0];
    const map = baseMap(L, el, [first.lat, first.lng], 15);
    map.scrollWheelZoom.disable();
    const bounds = [];
    for (const p of points) {
      L.marker([p.lat, p.lng], p.person ? { icon: avatarIcon(L, p.person, p.kind) } : {}).addTo(map).bindTooltip(p.label || '', { direction: 'top' });
      bounds.push([p.lat, p.lng]);
    }
    if (bounds.length > 1) map.fitBounds(bounds, { padding: [30, 30] });
  }).catch(() => el.replaceChildren(h('div', { class: 'empty small' }, 'نقشه بارگذاری نشد.')));
  return el;
}

/** نشانی قابل باز شدن در برنامه‌های مسیریابی */
export function directionsLinks(lat, lng) {
  const q = `${lat},${lng}`;
  return h('div', { class: 'row wrap', style: { gap: '6px' } },
    h('a', { class: 'btn ghost sm', target: '_blank', rel: 'noopener', href: `https://www.google.com/maps/search/?api=1&query=${q}` }, icon('external'), 'گوگل‌مپ'),
    h('a', { class: 'btn ghost sm', target: '_blank', rel: 'noopener', href: `https://neshan.org/maps/@${q},16z` }, icon('external'), 'نشان'),
    h('a', { class: 'btn ghost sm', target: '_blank', rel: 'noopener', href: `https://balad.ir/location?latitude=${lat}&longitude=${lng}&zoom=16` }, icon('external'), 'بلد'),
    h('a', { class: 'btn ghost sm', target: '_blank', rel: 'noopener', href: `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=16/${q}` }, icon('external'), 'OSM'),
  );
}

/** نمایش مختصات به فارسی */
export function coordText(p) {
  return p ? `${fa(p.lat.toFixed(5))}، ${fa(p.lng.toFixed(5))}` : '';
}

/** نقشه بزرگ روی یک المنت (برای صفحه نقشه خاندان) */
export async function mountMap(el) {
  const L = await loadLeaflet();
  const c = config();
  return { L, map: baseMap(L, el, c.center || [32.4, 53.7], c.zoom || 5) };
}
