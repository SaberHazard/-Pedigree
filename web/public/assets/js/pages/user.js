/**
 * لینک‌های #/@نام‌کاربری (مثل تلگرام): پروفایل صاحب نام کاربری را باز می‌کند
 */
import { h } from '../core/dom.js';
import { get } from '../core/api.js';
import { navigate } from '../core/router.js';
import { loader, emptyState } from '../core/ui.js';

export default async function userPage(container, { params } = {}) {
  const page = h('div', { class: 'page narrow' }, loader());
  container.append(page);
  try {
    const { data } = await get(`/api/u/${encodeURIComponent(params.username)}`);
    navigate(`/person/${data.person_id}`, { replace: true });
  } catch (e) {
    page.replaceChildren(h('div', { class: 'card' }, emptyState('user', e.message)));
  }
}
