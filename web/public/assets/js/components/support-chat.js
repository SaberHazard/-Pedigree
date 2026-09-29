/**
 * گفتگو با پشتیبانی (مشترک بین صفحه «پشتیبانی» عضو و تب «پشتیبانی» پنل مدیریت):
 * متن با ایموجی و پیام صوتی؛ هر ۸ ثانیه پیام‌های تازه گرفته می‌شود.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, del, upload } from '../core/api.js';
import { store } from '../core/store.js';
import { toastError, loader, emptyState, confirmDialog } from '../core/ui.js';
import { avatar } from './avatar.js';
import { emojiPanel, insertAtCursor } from './emoji.js';
import { voiceButton, voicePlayer, voiceForm, voiceSupported } from './voice.js';

/**
 * @param {HTMLElement} box
 * @param {{ admin?: boolean, threadId?: number, onBack?: Function }} o
 * @returns {Function} پاک‌سازی (توقف به‌روزرسانی)
 */
export function supportChat(box, { admin = false, threadId = null, onBack = null } = {}) {
  const base = admin ? `/api/admin/support/${threadId}` : '/api/support';
  const sendUrl = admin ? `/api/admin/support/${threadId}/messages` : '/api/support/messages';
  const voiceUrl = admin ? `/api/admin/support/${threadId}/voice` : '/api/support/voice';
  let messages = [];
  let owner = null;
  const players = new Map();
  const list = h('div', { class: 'msg-thread sup-thread', role: 'log', 'aria-live': 'polite' });
  const input = h('textarea', { class: 'input msg-input', rows: 1, maxlength: 2000, placeholder: admin ? 'پاسخ به عضو...' : 'سؤال یا مشکل خود را بنویسید...', 'aria-label': 'متن پیام' });
  const sendBtn = h('button', { class: 'btn primary icon-only', type: 'button', title: 'ارسال', onclick: () => send() }, icon('send'));
  const panel = emojiPanel((e) => insertAtCursor(input, e));
  panel.hidden = true;
  const compose = h('div', { class: 'msg-compose' });
  const micBtn = store.config.voice?.enabled !== false && voiceSupported()
    ? voiceButton({ host: compose, maxSeconds: store.config.voice?.max_seconds || 300, send: sendVoice }) : null;
  const toggle = () => {
    if (!micBtn) return;
    const empty = !input.value.trim();
    micBtn.hidden = !empty;
    sendBtn.hidden = empty;
  };
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
    toggle();
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !matchMedia('(pointer: coarse)').matches) {
      e.preventDefault();
      send();
    }
  });
  compose.append(panel, h('div', { class: 'row compose-row', style: { gap: '6px', alignItems: 'flex-end' } }, ...[
    h('button', { class: 'icon-btn', type: 'button', title: 'ایموجی', onclick: () => { panel.hidden = !panel.hidden; } }, icon('smile')),
    input, sendBtn, micBtn,
  ].filter(Boolean)));
  toggle();

  const head = h('div', { class: 'msg-chat-head' });
  box.replaceChildren(h('div', { class: 'sup-chat card' }, head, list, compose));
  list.append(loader());
  load(true);
  const timer = setInterval(() => !document.hidden && load(false), 8000);

  async function load(first) {
    try {
      const last = messages.length ? messages[messages.length - 1].id : null;
      const res = await get(base, first || !last ? {} : { after: last });
      owner = res.thread?.user || null;
      if (first) messages = res.data;
      else if (res.data.length) {
        const known = new Set(messages.map((m) => m.id));
        messages.push(...res.data.filter((m) => !known.has(m.id)));
      }
      if (first || res.data.length) render(true);
      if (first) {
        renderHead();
        store.refreshCounters?.();
      }
    } catch (e) {
      if (first) list.replaceChildren(emptyState('alert', e.message));
    }
  }

  function renderHead() {
    head.replaceChildren(...[
      onBack ? h('button', { class: 'icon-btn msg-back', type: 'button', title: 'بازگشت', onclick: onBack }, icon('chevron-right')) : null,
      admin && owner ? avatar(owner.person, 'sm') : h('div', { class: 's-icon sup-icon' }, icon('headset')),
      h('div', { class: 'grow', style: { minWidth: 0 } },
        h('b', { class: 'ellipsis' }, admin ? (owner?.name || 'عضو') : `پشتیبانی ${store.config.site_name || ''}`),
        h('div', { class: 'muted tiny' }, admin ? 'گفتگوی پشتیبانی این عضو' : 'پیام شما را مدیران سایت می‌بینند و پاسخ می‌دهند')),
      admin && owner?.person ? h('a', { class: 'btn ghost sm', href: `#/person/${owner.person.id}` }, icon('user'), 'پروفایل') : null,
    ].filter(Boolean));
  }

  function render(scroll) {
    const items = messages.map((m) => {
      const mineSide = admin ? m.from_admin : m.mine;
      return h('div', { class: `msg ${mineSide ? 'mine' : 'theirs'} ${m.deleted ? 'deleted' : ''}` },
        !mineSide || admin ? h('div', { class: 'muted tiny sup-sender' }, m.sender || '') : null,
        h('div', { class: `msg-bubble ${m.voice ? 'has-voice' : ''}`, dir: 'auto' },
          m.deleted ? 'این پیام حذف شد' : m.voice ? playerFor(m, mineSide) : m.body),
        h('div', { class: 'msg-meta' },
          new Date(m.at).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }),
          m.mine && !m.deleted ? h('button', { class: 'msg-del', type: 'button', title: 'حذف پیام', onclick: () => remove(m) }, icon('trash')) : null),
      );
    });
    if (!messages.length) {
      items.push(h('div', { class: 'sup-empty' }, icon('headset'),
        h('p', null, admin ? 'پیامی نیست.' : 'سؤال، مشکل یا پیشنهادی دارید؟ همین‌جا بنویسید یا پیام صوتی بفرستید. تنها راه ارتباط با مدیران سایت همین گفتگوست.')));
    }
    list.replaceChildren(...items);
    if (scroll) list.scrollTop = list.scrollHeight;
  }

  function playerFor(m, mine) {
    if (!players.has(m.id)) players.set(m.id, voicePlayer(m.voice, { mine }));
    return players.get(m.id);
  }

  async function send() {
    const body = input.value.trim();
    if (!body) return;
    sendBtn.disabled = true;
    try {
      const res = await post(sendUrl, { body });
      messages.push(res.data);
      input.value = '';
      input.style.height = 'auto';
      panel.hidden = true;
      toggle();
      render(true);
    } catch (e) {
      toastError(e);
    } finally {
      sendBtn.disabled = false;
    }
  }

  async function sendVoice(blob, waveform) {
    compose.classList.add('uploading');
    try {
      const res = await upload(voiceUrl, voiceForm(blob, waveform));
      messages.push(res.data);
      render(true);
    } catch (e) {
      toastError(e);
      throw e;
    } finally {
      compose.classList.remove('uploading');
    }
  }

  async function remove(m) {
    if (!(await confirmDialog('این پیام حذف شود؟', { danger: true, okLabel: 'حذف' }))) return;
    try {
      const res = await del(`/api/support/messages/${m.id}`);
      Object.assign(m, res.data);
      players.delete(m.id);
      render(false);
    } catch (e) {
      toastError(e);
    }
  }

  return () => clearInterval(timer);
}
