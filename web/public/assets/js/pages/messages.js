/**
 * پیام‌رسان داخلی اعضا: فقط متن و ایموجی.
 *
 * - دسکتاپ: فهرست گفتگوها کنار پنجره گفتگو؛ موبایل: یکی پس از دیگری
 * - پیام‌های تازه هر چند ثانیه (فقط وقتی صفحه دیده می‌شود) گرفته می‌شوند
 * - متن‌ها همیشه به صورت متن ساده نمایش داده می‌شوند (هیچ HTML یا لینک فعالی)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, del, upload } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, timeAgo } from '../core/format.js';
import { toast, toastError, loader, emptyState, confirmDialog, dropdown } from '../core/ui.js';
import { avatar } from '../components/avatar.js';
import { emojiPanel, insertAtCursor } from '../components/emoji.js';
import { voiceButton, voicePlayer, voiceForm, voiceSupported } from '../components/voice.js';

const LIST_POLL = 20000;
const CHAT_POLL = 4000;

export default async function messagesPage(container, { params }) {
  const listBox = h('aside', { class: 'msg-list' });
  const chatBox = h('section', { class: 'msg-chat' });
  const page = h('div', { class: `page messenger ${params.id ? 'has-chat' : ''}` }, listBox, chatBox);
  container.append(page);
  document.title = `پیام‌ها | ${store.config.site_name}`;

  let conversations = [];
  let current = null; // { id, messages, lastId, other, ... }
  const timers = [];

  await loadList();
  if (params.id) openChat(Number(params.id));
  else chatBox.replaceChildren(emptyState('chat', 'یک گفتگو را انتخاب کنید یا از صفحه پروفایل هر عضو، دکمه «پیام» را بزنید.'));

  timers.push(setInterval(() => !document.hidden && loadList(), LIST_POLL));
  timers.push(setInterval(() => !document.hidden && current && poll(), CHAT_POLL));

  // ------------------------------------------------------------ فهرست گفتگوها
  async function loadList() {
    try {
      const res = await get('/api/messages');
      conversations = res.data;
      renderList();
      if (store.user?.counters) {
        store.user.counters.messages = res.unread_total;
        store.emit('counters', store.user.counters);
      }
    } catch (e) {
      if (!conversations.length) listBox.replaceChildren(emptyState('alert', e.message));
    }
  }

  function renderList() {
    const groupUnread = store.user?.counters?.group || 0;
    const groupItem = store.config.group?.enabled !== false
      ? h('a', { class: `msg-item group ${groupUnread ? 'unread' : ''}`, href: '#/group' },
        h('span', { class: 'grp-avatar sm' }, icon('users')),
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('b', { class: 'ellipsis' }, store.config.group?.name || 'گروه خاندان'),
          h('div', { class: 'muted small ellipsis' }, 'گفتگوی همه اعضا و خاطره‌های قدیمی')),
        groupUnread ? h('span', { class: 'badge' }, fa(groupUnread > 99 ? '99+' : groupUnread)) : null)
      : null;
    listBox.replaceChildren(
      h('div', { class: 'msg-list-head' }, h('h2', null, icon('chat'), ' پیام‌ها')),
      groupItem,
      conversations.length
        ? h('div', { class: 'msg-items' }, ...conversations.map((c) => h('a', {
          class: `msg-item ${current?.id === c.id ? 'active' : ''} ${c.unread ? 'unread' : ''}`,
          href: `#/messages/${c.id}`,
          onclick: (e) => { e.preventDefault(); history.replaceState(null, '', `#/messages/${c.id}`); openChat(c.id); },
        },
        avatar(c.other?.person, 'sm'),
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', { class: 'row between' }, h('b', { class: 'ellipsis' }, c.other?.person ? fullName(c.other.person) : c.other?.name || '—'), h('span', { class: 'muted tiny nowrap' }, c.last ? timeAgo(c.last.at) : '')),
          h('div', { class: 'row between' },
            h('span', { class: 'muted small ellipsis' }, c.last ? (c.last.deleted ? 'پیام حذف شد' : `${c.last.mine ? 'شما: ' : ''}${c.last.text}`) : ''),
            c.unread ? h('span', { class: 'badge' }, fa(c.unread)) : null),
        )),
        ))
        : h('p', { class: 'muted small', style: { padding: '12px' } }, 'هنوز گفتگویی ندارید. از صفحه پروفایل هر عضو «پیام» بدهید.'),
    );
  }

  // ------------------------------------------------------------ گفتگو
  async function openChat(id) {
    page.classList.add('has-chat');
    chatBox.replaceChildren(loader());
    try {
      const res = await get(`/api/messages/${id}`);
      current = { id, ...res.conversation, messages: res.data, hasMore: res.has_more };
      renderChat();
      renderList();
      loadList();
    } catch (e) {
      current = null;
      chatBox.replaceChildren(emptyState('alert', e.message));
    }
  }

  function lastId() {
    return current.messages.length ? current.messages[current.messages.length - 1].id : 0;
  }

  async function poll() {
    try {
      const res = await get(`/api/messages/${current.id}`, { after: lastId() });
      current.read_up_to = res.conversation.read_up_to;
      current.can_send = res.conversation.can_send;
      if (res.data.length) {
        const known = new Set(current.messages.map((m) => m.id));
        current.messages.push(...res.data.filter((m) => !known.has(m.id)));
        renderMessages(true);
      } else {
        renderMessages(false);
      }
    } catch {
      /* شبکه موقتاً قطع است */
    }
  }

  let messagesEl;
  let input;
  function renderChat() {
    const other = current.other;
    messagesEl = h('div', { class: 'msg-thread', role: 'log', 'aria-live': 'polite' });
    input = h('textarea', { class: 'input msg-input', rows: 1, maxlength: 2000, placeholder: current.can_send ? 'پیام بنویسید...' : 'ارسال پیام ممکن نیست', disabled: !current.can_send, 'aria-label': 'متن پیام' });
    const sendBtn = h('button', { class: 'btn primary icon-only msg-send', type: 'button', title: 'ارسال', disabled: !current.can_send, onclick: send }, icon('send'));
    const panel = emojiPanel((e) => insertAtCursor(input, e));
    panel.hidden = true;
    const emojiBtn = h('button', { class: 'icon-btn', type: 'button', title: 'ایموجی', disabled: !current.can_send, onclick: () => { panel.hidden = !panel.hidden; } }, icon('smile'));

    const compose = h('div', { class: 'msg-compose' });
    // مثل تلگرام: وقتی متنی نیست دکمه میکروفون، وقتی متن هست دکمه ارسال
    const voiceOn = store.config.voice?.enabled !== false && voiceSupported();
    const micBtn = voiceOn ? voiceButton({ host: compose, maxSeconds: store.config.voice?.max_seconds || 300, send: sendVoice }) : null;
    if (micBtn) micBtn.disabled = !current.can_send;
    const toggleButtons = () => {
      if (!micBtn) return;
      const empty = !input.value.trim();
      micBtn.hidden = !empty;
      sendBtn.hidden = empty;
    };
    toggleButtons();
    input.addEventListener('input', () => {
      input.style.height = 'auto';
      input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
      toggleButtons();
    });
    input.addEventListener('keydown', (e) => {
      // دسکتاپ: Enter ارسال، Shift+Enter خط جدید
      if (e.key === 'Enter' && !e.shiftKey && !matchMedia('(pointer: coarse)').matches) {
        e.preventDefault();
        send();
      }
    });

    const menuBtn = h('button', { class: 'icon-btn', type: 'button', title: 'بیشتر', onclick: () => dropdown(menuBtn, [
      other?.person ? { label: 'دیدن پروفایل', icon: 'user', onClick: () => navigate(`/person/${other.person.id}`) } : null,
      { label: current.blocked_by_me ? 'رفع مسدودی' : 'مسدود کردن', icon: 'ban', danger: !current.blocked_by_me, onClick: toggleBlock },
    ].filter(Boolean)) }, icon('more'));

    chatBox.replaceChildren(...[
      h('div', { class: 'msg-chat-head' },
        h('button', { class: 'icon-btn msg-back', type: 'button', title: 'بازگشت', onclick: () => { page.classList.remove('has-chat'); current = null; history.replaceState(null, '', '#/messages'); renderList(); } }, icon('chevron-right')),
        avatar(other?.person, 'sm'),
        h('div', { class: 'grow', style: { minWidth: 0 } }, h('b', { class: 'ellipsis' }, other?.person ? fullName(other.person) : other?.name || '—')),
        menuBtn,
      ),
      messagesEl,
      current.blocked_by_me ? h('div', { class: 'msg-note' }, icon('ban'), ' این شخص را مسدود کرده‌اید.') : null,
      compose,
    ].filter(Boolean));
    compose.append(panel, h('div', { class: 'row compose-row', style: { gap: '6px', alignItems: 'flex-end' } }, ...[emojiBtn, input, sendBtn, micBtn].filter(Boolean)));
    renderMessages(true);
    if (!matchMedia('(pointer: coarse)').matches) input.focus();

    async function send() {
      const body = input.value.trim();
      if (!body || !current.can_send) return;
      sendBtn.disabled = true;
      try {
        const res = await post(`/api/messages/${current.id}`, { body });
        current.messages.push(res.data);
        input.value = '';
        input.style.height = 'auto';
        panel.hidden = true;
        toggleButtons();
        renderMessages(true);
        loadList();
      } catch (e) {
        toastError(e);
      } finally {
        sendBtn.disabled = false;
      }
    }

    async function sendVoice(blob, waveform) {
      if (!current.can_send) return;
      compose.classList.add('uploading');
      try {
        const res = await upload(`/api/messages/${current.id}/voice`, voiceForm(blob, waveform));
        current.messages.push(res.data);
        renderMessages(true);
        loadList();
      } catch (e) {
        toastError(e);
        throw e;
      } finally {
        compose.classList.remove('uploading');
      }
    }
  }

  // پخش‌کننده هر پیام صوتی یک بار ساخته می‌شود تا با به‌روزرسانی گفتگو، پخش قطع نشود
  const players = new Map();
  function playerFor(m) {
    if (!players.has(m.id)) players.set(m.id, voicePlayer(m.voice, { mine: m.mine }));
    return players.get(m.id);
  }

  async function toggleBlock() {
    const block = !current.blocked_by_me;
    if (block && !(await confirmDialog('این شخص مسدود شود؟ دیگر نمی‌تواند به شما پیام بدهد (و شما هم به او).', { danger: true, okLabel: 'مسدود کن' }))) return;
    try {
      const res = await post(`/api/messages/${current.id}/block`, { blocked: block });
      toast(res.message);
      openChat(current.id);
    } catch (e) {
      toastError(e);
    }
  }

  function renderMessages(scroll) {
    if (!messagesEl) return;
    const atBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 80;
    const items = [];
    if (current.hasMore) {
      items.push(h('button', { class: 'btn ghost sm msg-more', type: 'button', onclick: loadOlder }, 'پیام‌های قدیمی‌تر'));
    }
    let lastDay = '';
    for (const m of current.messages) {
      const day = new Date(m.at).toDateString();
      if (day !== lastDay) {
        lastDay = day;
        items.push(h('div', { class: 'msg-day' }, dayLabel(m.at)));
      }
      const read = m.mine && m.id <= (current.read_up_to || 0);
      items.push(h('div', { class: `msg ${m.mine ? 'mine' : 'theirs'} ${m.deleted ? 'deleted' : ''}` },
        h('div', { class: `msg-bubble ${m.voice ? 'has-voice' : ''}`, dir: 'auto' }, m.deleted ? 'این پیام حذف شد' : m.voice ? playerFor(m) : m.body),
        h('div', { class: 'msg-meta' },
          new Date(m.at).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }),
          m.mine && !m.deleted ? h('span', { class: `tick ${read ? 'read' : ''}`, title: read ? 'خوانده شد' : 'ارسال شد' }, icon(read ? 'check-double' : 'check')) : null,
          m.mine && !m.deleted ? h('button', { class: 'msg-del', type: 'button', title: 'حذف پیام', onclick: () => removeMessage(m) }, icon('trash')) : null,
        ),
      ));
    }
    if (!current.messages.length) items.push(h('p', { class: 'muted small center', style: { margin: 'auto' } }, 'اولین پیام را بفرستید 👋'));
    messagesEl.replaceChildren(...items);
    if (scroll || atBottom) messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  async function loadOlder() {
    try {
      const first = current.messages[0]?.id;
      const res = await get(`/api/messages/${current.id}`, first ? { before: first } : {});
      const height = messagesEl.scrollHeight;
      current.messages.unshift(...res.data);
      current.hasMore = res.has_more;
      renderMessages(false);
      messagesEl.scrollTop = messagesEl.scrollHeight - height;
    } catch (e) {
      toastError(e);
    }
  }

  async function removeMessage(m) {
    if (!(await confirmDialog('این پیام برای هر دو طرف حذف شود؟', { danger: true, okLabel: 'حذف' }))) return;
    try {
      const res = await del(`/api/direct-messages/${m.id}`);
      Object.assign(m, res.data);
      renderMessages(false);
    } catch (e) {
      toastError(e);
    }
  }

  return () => timers.forEach(clearInterval);
}

/** برچسب روز در گفتگو: امروز، دیروز یا تاریخ شمسی */
function dayLabel(iso) {
  const d = new Date(iso);
  const today = new Date();
  const yesterday = new Date(Date.now() - 86400000);
  if (d.toDateString() === today.toDateString()) return 'امروز';
  if (d.toDateString() === yesterday.toDateString()) return 'دیروز';
  return d.toLocaleDateString('fa-IR', { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
}

/** شروع گفتگو با صاحب یک پروفایل (دکمه «پیام» در پروفایل و درخت) */
export async function startConversation(person) {
  try {
    const res = await post('/api/messages/start', { person_id: person.id });
    navigate(`/messages/${res.data.id}`);
  } catch (e) {
    toastError(e);
  }
}
