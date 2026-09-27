/**
 * آواتار دایره‌ای یک شخص (عکس یا سیلوئت)
 */
import { h } from '../core/dom.js';
import { silhouette } from '../core/icons.js';
import { fullName } from '../core/format.js';

export function avatar(person, size = '', { ring = true } = {}) {
  const p = person || {};
  const src = size === 'xl' || size === 'lg' ? p.avatar_medium || p.avatar : p.avatar;
  const el = h('span', { class: ['avatar', size, ring ? 'ring' : '', p.gender === 'f' ? 'f' : 'm', p.is_deceased ? 'dead' : ''] });
  if (src) {
    const img = h('img', { src, alt: fullName(p), loading: 'lazy', decoding: 'async' });
    img.onerror = () => img.replaceWith(silhouette(p.gender));
    el.append(img);
  } else {
    el.append(silhouette(p.gender));
  }
  return el;
}
