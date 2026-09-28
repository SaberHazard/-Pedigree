/**
 * اعلان‌ها
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { timeAgo } from '../core/format.js';
import { emptyState, loader } from '../core/ui.js';

const ICONS = { birthday: 'cake', media_vote: 'thumbs-up', media_decided: 'image', profile_changed: 'edit', link_request: 'link', link_decided: 'link' };

export default async function notificationsPage(container) {
  const list = h('div', { class: 'card', style: { padding: '6px' } }, loader());
  const page = h('div', { class: 'page narrow' },
    h('div', { class: 'page-head' },
      h('h1', null, 'اعلان‌ها'),
      h('button', { class: 'btn ghost sm', type: 'button', onclick: markAll }, icon('check'), 'همه خوانده شد'),
    ),
    list,
  );
  container.append(page);

  let res;
  try {
    res = await get('/api/notifications');
  } catch (e) {
    list.replaceChildren(emptyState('alert', e.message));
    return;
  }
  if (!res.data.length) {
    list.replaceChildren(emptyState('bell', 'اعلانی ندارید.'));
    return;
  }
  list.replaceChildren(...res.data.map((n) => h('div', {
    class: 'person-row',
    style: { alignItems: 'flex-start', background: n.read ? null : 'var(--primary-soft)', marginBottom: '4px' },
    onclick: async () => {
      if (!n.read) post(`/api/notifications/${n.id}/read`).then(() => store.refreshCounters?.());
      if (n.link) navigate(n.link.replace(/^#/, ''));
    },
  },
  h('div', { class: 'stat' }, h('div', { class: 's-icon', style: { width: '40px', height: '40px' } }, icon(ICONS[n.kind] || 'bell'))),
  h('div', { class: 'grow' },
    h('div', { class: 'bold' }, n.title),
    h('div', { class: 'small text-2' }, n.body),
    h('div', { class: 'tiny muted' }, timeAgo(n.created_at)),
  ),
  )));

  async function markAll() {
    await post('/api/notifications/read-all');
    store.refreshCounters?.();
    list.querySelectorAll('.person-row').forEach((r) => (r.style.background = ''));
  }
}
