/**
 * «ایجادکننده / نویسنده / آپلودکننده» هر چیز در سایت: @نام‌کاربری (مثل تلگرام) یا اگر نام کاربری ندارد نام کامل؛
 * با زدن روی آن مستقیماً پروفایل همان شخص باز می‌شود.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';

/**
 * @param {{name?: string, username?: string|null, person_id?: string|null}|null} author
 * @param {{label?: string|null, cls?: string, withIcon?: boolean}} [opts]
 */
export function authorLink(author, { label = null, cls = '', withIcon = true } = {}) {
  if (!author || (!author.name && !author.username)) return null;
  const text = author.username ? `@${author.username}` : author.name;
  const title = author.username ? `${author.name || ''} — رفتن به پروفایل` : 'رفتن به پروفایل';
  const inner = author.person_id
    ? h('a', {
      class: `author-link ${cls}`,
      href: `#/person/${encodeURIComponent(author.person_id)}`,
      title,
      onclick: (e) => e.stopPropagation(),
    }, h('bdi', null, text))
    : h('span', { class: `author-link ${cls}` }, h('bdi', null, text));
  if (!label) return inner;
  return h('span', { class: 'author-line' }, withIcon ? icon('user') : null, `${label}: `, inner);
}
