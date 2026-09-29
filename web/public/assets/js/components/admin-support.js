/**
 * پنل مدیریت ← «پشتیبانی»: گفتگوهای اعضا با پشتیبانی (نخوانده‌ها اول) و پاسخ با متن یا صدا
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { fa, fullName, timeAgo } from '../core/format.js';
import { loader, emptyState } from '../core/ui.js';
import { avatar } from './avatar.js';
import { supportChat } from './support-chat.js';

export async function adminSupport(body, { threadId = null } = {}) {
  let stop = null;
  const listBox = h('div', { class: 'sup-list card' });
  const chatBox = h('div', { class: 'sup-chat-box' });
  const layout = h('div', { class: `sup-admin ${threadId ? 'has-chat' : ''}` }, listBox, chatBox);
  body.replaceChildren(layout);
  await loadList();
  if (threadId) open(Number(threadId));
  else chatBox.replaceChildren(h('div', { class: 'card' }, emptyState('headset', 'یک گفتگو را انتخاب کنید.')));

  async function loadList() {
    listBox.replaceChildren(loader());
    try {
      const res = await get('/api/admin/support');
      listBox.replaceChildren(
        h('div', { class: 'msg-list-head' }, h('h3', { style: { margin: 0 } }, icon('headset'), ' گفتگوهای پشتیبانی'),
          res.unread_threads ? h('span', { class: 'chip warning' }, `${fa(res.unread_threads)} نخوانده`) : null),
        res.data.length ? h('div', { class: 'msg-items' }, ...res.data.map((t) => h('button', {
          class: `msg-item ${t.unread ? 'unread' : ''}`, type: 'button', onclick: () => open(t.id),
        },
        avatar(t.user?.person, 'sm'),
        h('div', { class: 'grow', style: { minWidth: 0, textAlign: 'start' } },
          h('div', { class: 'row between' }, h('b', { class: 'ellipsis' }, t.user?.person ? fullName(t.user.person) : t.user?.name || '—'), h('span', { class: 'muted tiny nowrap' }, t.last ? timeAgo(t.last.at) : '')),
          h('div', { class: 'row between' }, h('span', { class: 'muted small ellipsis' }, t.last ? `${t.last.from_admin ? 'پشتیبانی: ' : ''}${t.last.text}` : ''),
            t.unread ? h('span', { class: 'badge' }, fa(t.unread)) : null)),
        ))) : emptyState('headset', 'هنوز گفتگویی با پشتیبانی نیست.'),
      );
    } catch (e) {
      listBox.replaceChildren(emptyState('alert', e.message));
    }
  }

  function open(id) {
    stop?.();
    layout.classList.add('has-chat');
    history.replaceState(null, '', `#/admin/support?thread=${id}`);
    stop = supportChat(chatBox, { admin: true, threadId: id, onBack: () => { stop?.(); layout.classList.remove('has-chat'); loadList(); } });
    setTimeout(loadList, 1200);
  }

  return () => stop?.();
}
