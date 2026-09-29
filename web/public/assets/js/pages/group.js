/**
 * «گروه خاطرات خاندان»: گفتگوی همه اعضا برای زنده کردن خاطرات، با عکس و فیلم قدیمی.
 *
 * - متن با ایموجی (پیامِ فقط ایموجی پذیرفته نمی‌شود)، پاسخ، واکنش، گزارش
 * - عکس/فیلم با توضیح، سال تقریبی و نام کسانی که در عکس هستند؛ در پروفایل فرستنده هم می‌ماند
 * - «سؤال روز» برای شروع گفتگو؛ مدیران: سنجاق، حذف، سکوت
 * - همه متن‌ها با گره متنی نمایش داده می‌شوند (هیچ HTML یا لینک فعالی از پیام‌ها ساخته نمی‌شود)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, del, upload } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, timeAgo, formatDate } from '../core/format.js';
import { toast, toastError, loader, emptyState, confirmDialog, dropdown, modal } from '../core/ui.js';
import { avatar } from '../components/avatar.js';
import { emojiPanel, insertAtCursor } from '../components/emoji.js';
import { openLightbox } from '../components/lightbox.js';
import { searchBox } from '../components/person-search.js';
import { shrinkImage } from '../core/shrink.js';
import { voiceButton, voicePlayer, voiceForm, voiceSupported } from '../components/voice.js';

const POLL = 4000;

export default async function groupPage(container, { query }) {
  const page = h('div', { class: 'page group-page' }, loader());
  container.append(page);

  let info;
  try {
    info = (await get('/api/group')).data;
  } catch (e) {
    page.replaceChildren(emptyState('users', e.message));
    return;
  }
  document.title = `${info.name} | ${store.config.site_name}`;

  let messages = [];
  let hasMore = false;
  let serverTime = null;
  let replyTo = null;
  let pollTimer = null;
  const byId = new Map();

  const thread = h('div', { class: 'grp-thread', role: 'log', 'aria-live': 'polite' });
  const pinnedBar = h('div', { class: 'grp-pinned', hidden: true });
  const replyBar = h('div', { class: 'grp-reply-bar', hidden: true });
  const input = h('textarea', { class: 'input grp-input', rows: 1, maxlength: info.max_length, placeholder: 'خاطره‌ای بنویسید...', 'aria-label': 'پیام به گروه' });
  const sendBtn = h('button', { class: 'btn primary icon-only', type: 'button', title: 'ارسال', onclick: () => send() }, icon('send'));
  const panel = emojiPanel((e) => insertAtCursor(input, e));
  panel.hidden = true;
  const fileInput = h('input', { type: 'file', hidden: true, accept: store.config.media?.accept || 'image/*,video/*', onchange: () => { const f = fileInput.files[0]; fileInput.value = ''; if (f) mediaDialog(f); } });

  // مثل تلگرام: بدون متن دکمه میکروفون، با متن دکمه ارسال
  const composeBox = h('div', { class: 'grp-compose' });
  const voiceOn = store.config.voice?.enabled !== false && voiceSupported();
  const micBtn = voiceOn && info.can_post ? voiceButton({ host: composeBox, maxSeconds: store.config.voice?.max_seconds || 300, send: sendVoice, title: 'خاطره صوتی' }) : null;
  const toggleButtons = () => {
    if (!micBtn) return;
    const empty = !input.value.trim();
    micBtn.hidden = !empty;
    sendBtn.hidden = empty;
  };
  toggleButtons();
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 150)}px`;
    toggleButtons();
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !matchMedia('(pointer: coarse)').matches) {
      e.preventDefault();
      send();
    }
  });

  if (info.can_post) {
    composeBox.append(
      replyBar,
      panel,
      h('div', { class: 'row compose-row', style: { gap: '6px', alignItems: 'flex-end' } }, ...[
        h('button', { class: 'icon-btn', type: 'button', title: 'ایموجی', onclick: () => { panel.hidden = !panel.hidden; } }, icon('smile')),
        h('button', { class: 'icon-btn', type: 'button', title: 'عکس یا فیلم قدیمی', onclick: () => fileInput.click() }, icon('image')),
        input, sendBtn, micBtn, fileInput,
      ].filter(Boolean)),
    );
  }
  const composer = info.can_post
    ? composeBox
    : h('div', { class: 'grp-compose muted small center' }, icon('lock'), ' ', info.problem || 'امکان پیام دادن نیست.');

  // پخش‌کننده هر خاطره صوتی یک بار ساخته می‌شود تا با به‌روزرسانی گروه، پخش قطع نشود
  const players = new Map();
  const playerFor = (m) => {
    if (!players.has(m.id)) players.set(m.id, voicePlayer(m.voice, { mine: m.mine }));
    return players.get(m.id);
  };

  page.replaceChildren(h('div', { class: 'grp-shell card' },
    h('div', { class: 'grp-head' },
      h('div', { class: 'grp-avatar' }, icon('users')),
      h('div', { class: 'grow', style: { minWidth: 0 } },
        h('b', null, info.name),
        h('div', { class: 'muted tiny' }, `${fa(info.members)} عضو • فقط متن، عکس و فیلم خاطره‌ها`),
      ),
      h('button', { class: 'icon-btn', type: 'button', title: 'راهنما', onclick: help }, icon('info')),
    ),
    pinnedBar,
    thread,
    composer,
  ));
  renderPinned();

  if (query?.m) await loadAround(Number(query.m));
  else await loadLatest();
  pollTimer = setInterval(() => !document.hidden && poll(), POLL);

  // ------------------------------------------------------------ بارگذاری
  async function loadLatest() {
    thread.replaceChildren(loader());
    try {
      const res = await get('/api/group/messages');
      messages = res.data;
      hasMore = res.has_more;
      serverTime = res.server_time;
      index();
      render(true);
      markRead();
    } catch (e) {
      thread.replaceChildren(emptyState('alert', e.message));
    }
  }

  async function loadAround(id) {
    try {
      const res = await get('/api/group/messages', { around: id });
      messages = res.data;
      hasMore = true;
      serverTime = res.server_time;
      index();
      render(false);
      highlight(id);
    } catch {
      loadLatest();
    }
  }

  async function loadOlder() {
    const first = messages[0]?.id;
    if (!first) return;
    try {
      const res = await get('/api/group/messages', { before: first });
      const height = thread.scrollHeight;
      messages = [...res.data, ...messages];
      hasMore = res.has_more;
      index();
      render(false);
      thread.scrollTop = thread.scrollHeight - height;
    } catch (e) {
      toastError(e);
    }
  }

  async function poll() {
    const last = messages[messages.length - 1]?.id || 0;
    try {
      const res = await get('/api/group/messages', { after: last, since: serverTime });
      serverTime = res.server_time;
      let changed = false;
      for (const m of res.updated || []) {
        if (byId.has(m.id)) {
          Object.assign(byId.get(m.id), m);
          changed = true;
        }
      }
      const fresh = res.data.filter((m) => !byId.has(m.id));
      if (fresh.length) {
        messages.push(...fresh);
        changed = true;
      }
      index();
      if (changed) render(false);
      if (fresh.length) markRead();
    } catch {
      /* شبکه موقتاً قطع است */
    }
  }

  function index() {
    byId.clear();
    for (const m of messages) byId.set(m.id, m);
  }

  function markRead() {
    const last = messages[messages.length - 1]?.id;
    if (!last) return;
    post('/api/group/read', { up_to: last }).then((res) => {
      if (store.user?.counters) {
        store.user.counters.group = res.unread;
        store.emit('counters', store.user.counters);
      }
    }).catch(() => {});
  }

  // ------------------------------------------------------------ نمایش
  function render(scrollToEnd) {
    const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 120;
    const items = [];
    if (hasMore) items.push(h('button', { class: 'btn ghost sm grp-more', type: 'button', onclick: loadOlder }, 'پیام‌های قدیمی‌تر'));
    if (!messages.length) {
      items.push(h('div', { class: 'grp-empty' }, emptyState('users', 'هنوز پیامی نیست. اولین خاطره را شما تعریف کنید؛ یک عکس قدیمی هم بگذارید!')));
    }
    let lastDay = '';
    for (const m of messages) {
      const day = new Date(m.at).toDateString();
      if (day !== lastDay) {
        lastDay = day;
        items.push(h('div', { class: 'msg-day' }, dayLabel(m.at)));
      }
      items.push(m.kind === 'prompt' ? promptCard(m) : bubble(m));
    }
    thread.replaceChildren(...items);
    if (scrollToEnd || atBottom) thread.scrollTop = thread.scrollHeight;
  }

  function promptCard(m) {
    return h('div', { class: 'grp-prompt', 'data-id': m.id },
      h('div', { class: 'grp-prompt-body', dir: 'auto' }, m.body),
      info.can_post ? h('button', { class: 'btn soft sm', type: 'button', onclick: () => setReply(m) }, icon('chat'), 'پاسخ به این سؤال') : null,
      reactionsRow(m),
    );
  }

  function bubble(m) {
    const mine = m.mine;
    const name = m.user?.name || '—';
    const body = [];
    if (m.reply_to) {
      body.push(h('button', { class: 'grp-quote', type: 'button', onclick: () => jump(m.reply_to.id) },
        h('b', null, m.reply_to.name), h('span', { class: 'ellipsis' }, m.reply_to.text || '')));
    }
    if (m.deleted) {
      body.push(h('div', { class: 'grp-text deleted' }, 'این پیام حذف شد'));
    } else {
      if (m.media) body.push(mediaBlock(m));
      if (m.voice) body.push(playerFor(m));
      if (m.body) body.push(h('div', { class: 'grp-text', dir: 'auto' }, m.body));
    }
    const menuBtn = m.deleted ? null : h('button', { class: 'grp-menu', type: 'button', title: 'گزینه‌ها', onclick: () => menu(m, menuBtn) }, icon('more'));
    return h('div', { class: `grp-msg ${mine ? 'mine' : 'theirs'} ${m.pinned ? 'pinned' : ''}`, 'data-id': m.id,
      // دو بار زدن = ❤️ (مثل اینستاگرام)
      ondblclick: (e) => { if (!m.deleted && !e.target.closest('button,a')) react(m, m.reactions?.some((r) => r.mine && r.emoji === '❤️') ? null : '❤️'); } },
      mine ? null : h('a', { href: m.user?.person ? `#/person/${m.user.person.id}` : '#/group', class: 'grp-ava' }, avatar(m.user?.person, 'sm')),
      h('div', { class: 'grp-col' },
        h('div', { class: 'grp-bubble' },
          mine ? null : h('div', { class: 'grp-name' }, name),
          ...body,
          h('div', { class: 'grp-meta' },
            m.pinned ? icon('pin') : null,
            new Date(m.at).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }),
            menuBtn,
          ),
        ),
        reactionsRow(m),
      ),
    );
  }

  function mediaBlock(m) {
    const media = m.media;
    const meta = [
      media.taken_at ? h('span', { class: 'chip' }, icon('calendar'), ` ${formatDate(media.taken_at)}`) : null,
      ...(media.tags || []).map((t) => h('a', { class: 'chip', href: `#/person/${t.id}` }, icon('user'), ` ${t.name}`)),
    ].filter(Boolean);
    let view;
    if (!media.visible || !media.urls) {
      view = h('div', { class: 'grp-media-wait' }, icon('clock'), ' در انتظار تأیید');
    } else if (media.type === 'video' && media.processing !== 'ready') {
      view = h('div', { class: 'grp-media-wait' }, h('div', { class: 'spinner sm' }), media.processing === 'failed' ? ' تبدیل این فیلم ممکن نشد' : ' فیلم در حال فشرده‌سازی است...');
    } else {
      const src = media.type === 'video' ? media.urls.poster || media.urls.thumb : media.urls.medium || media.urls.thumb;
      const ratio = media.width && media.height ? `${media.width} / ${media.height}` : '4 / 3';
      view = h('button', { class: 'grp-media', type: 'button', style: { aspectRatio: ratio }, title: 'بزرگ‌نمایی', onclick: () => openLightbox([{ ...media, caption: m.body }], 0) },
        src ? h('img', { src, alt: m.body || '', loading: 'lazy' }) : null,
        media.type === 'video' ? h('span', { class: 'grp-play' }, icon('play')) : null,
      );
    }
    return h('div', { class: 'grp-media-wrap' }, view, meta.length ? h('div', { class: 'row wrap grp-tags' }, ...meta) : null);
  }

  function reactionsRow(m) {
    if (!m.reactions?.length || m.deleted) return null;
    return h('div', { class: 'grp-reactions' }, ...m.reactions.map((r) => h('button', {
      type: 'button', class: `grp-react ${r.mine ? 'mine' : ''}`, onclick: () => react(m, r.mine ? null : r.emoji),
    }, r.emoji, ' ', fa(r.count))));
  }

  function renderPinned() {
    const pins = info.pinned || [];
    pinnedBar.hidden = !pins.length;
    if (!pins.length) return;
    const p = pins[0];
    pinnedBar.replaceChildren(...[
      icon('pin'),
      h('button', { class: 'grow ellipsis', type: 'button', onclick: () => jump(p.id) }, h('b', null, 'سنجاق‌شده: '), p.body || (p.media ? 'عکس خاطره' : '')),
      pins.length > 1 ? h('span', { class: 'chip' }, fa(pins.length)) : null,
    ].filter(Boolean));
  }

  function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    if (d.toDateString() === today.toDateString()) return 'امروز';
    if (d.toDateString() === new Date(Date.now() - 86400000).toDateString()) return 'دیروز';
    return d.toLocaleDateString('fa-IR', { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
  }

  async function jump(id) {
    if (!byId.has(id)) await loadAround(id);
    else highlight(id);
  }

  function highlight(id) {
    const el = thread.querySelector(`[data-id="${Number(id)}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('flash');
    setTimeout(() => el.classList.remove('flash'), 1600);
  }

  // ------------------------------------------------------------ کارها
  function menu(m, anchor) {
    const items = [
      info.can_post ? { label: 'پاسخ', icon: 'chat', onClick: () => setReply(m) } : null,
      { label: 'واکنش', icon: 'smile', onClick: () => setTimeout(() => reactPicker(m, anchor), 0) },
      m.body ? { label: 'کپی متن', icon: 'copy', onClick: () => navigator.clipboard?.writeText(m.body).then(() => toast('کپی شد.')) } : null,
      m.user?.person ? { label: 'پروفایل فرستنده', icon: 'user', onClick: () => navigate(`/person/${m.user.person.id}`) } : null,
      info.is_admin ? { label: m.pinned ? 'برداشتن سنجاق' : 'سنجاق کردن', icon: 'pin', onClick: () => pin(m) } : null,
      !m.mine && m.user ? { label: 'گزارش به مدیر', icon: 'flag', onClick: () => report(m) } : null,
      m.mine || info.is_admin ? { label: 'حذف', icon: 'trash', danger: true, onClick: () => remove(m) } : null,
    ].filter(Boolean);
    dropdown(anchor, items);
  }

  /** ردیف کوچک واکنش‌ها کنار پیام */
  function reactPicker(m, anchor) {
    document.querySelector('.grp-react-pop')?.remove();
    const pop = h('div', { class: 'grp-react-pop', role: 'menu' }, ...info.reactions.map((e) => h('button', {
      type: 'button', 'aria-label': e, onclick: () => { pop.remove(); react(m, e); },
    }, e)));
    document.body.append(pop);
    const r = anchor.getBoundingClientRect();
    const w = pop.offsetWidth;
    pop.style.top = `${Math.max(8, r.top - pop.offsetHeight - 6)}px`;
    pop.style.left = `${Math.min(Math.max(8, r.left + r.width / 2 - w / 2), innerWidth - w - 8)}px`;
    const off = (e) => {
      if (!pop.contains(e.target)) {
        pop.remove();
        document.removeEventListener('pointerdown', off, true);
      }
    };
    setTimeout(() => document.addEventListener('pointerdown', off, true), 0);
  }

  async function react(m, emoji) {
    try {
      const res = await post(`/api/group/messages/${m.id}/react`, { emoji });
      Object.assign(m, res.data);
      render(false);
    } catch (e) {
      toastError(e);
    }
  }

  function setReply(m) {
    replyTo = m;
    replyBar.hidden = false;
    replyBar.replaceChildren(
      icon('chat'),
      h('div', { class: 'grow ellipsis small' }, h('b', null, m.user?.person?.first_name || m.user?.name || 'سؤال روز'), ': ', m.body || 'عکس'),
      h('button', { class: 'icon-btn', type: 'button', title: 'لغو پاسخ', onclick: clearReply }, icon('x')),
    );
    input.focus();
  }

  function clearReply() {
    replyTo = null;
    replyBar.hidden = true;
  }

  async function send() {
    const body = input.value.trim();
    if (!body) return;
    if (!/[\p{L}\p{N}]/u.test(body)) return toast('فقط ایموجی نمی‌شود فرستاد؛ باید متن یا کلمه‌ای هم اضافه شود.', 'warning');
    sendBtn.disabled = true;
    try {
      const res = await post('/api/group/messages', { body, reply_to: replyTo?.id || null });
      messages.push(res.data);
      index();
      input.value = '';
      input.style.height = 'auto';
      panel.hidden = true;
      toggleButtons();
      clearReply();
      render(true);
    } catch (e) {
      toastError(e);
    } finally {
      sendBtn.disabled = false;
    }
  }

  async function sendVoice(blob, waveform) {
    composeBox.classList.add('uploading');
    try {
      const res = await upload('/api/group/voice', voiceForm(blob, waveform, { reply_to: replyTo?.id || null }));
      messages.push(res.data);
      index();
      clearReply();
      render(true);
    } catch (e) {
      toastError(e);
      throw e;
    } finally {
      composeBox.classList.remove('uploading');
    }
  }

  async function pin(m) {
    try {
      const res = await post(`/api/admin/group/messages/${m.id}/pin`, { pinned: !m.pinned });
      toast(res.message);
      m.pinned = !m.pinned;
      info = (await get('/api/group')).data;
      renderPinned();
      render(false);
    } catch (e) {
      toastError(e);
    }
  }

  async function report(m) {
    const reason = h('input', { class: 'input', maxlength: 200, placeholder: 'اختیاری؛ مثلاً «توهین» یا «تبلیغ»' });
    modal({
      title: 'گزارش پیام به مدیران',
      body: h('div', null, h('p', { class: 'small muted' }, 'مدیران سایت پیام را بررسی می‌کنند و در صورت لزوم آن را حذف یا فرستنده را محدود می‌کنند.'), reason),
      actions: [{ label: 'انصراف' }, { label: 'گزارش', class: 'danger', icon: 'flag', onClick: async () => {
        try {
          const res = await post(`/api/group/messages/${m.id}/report`, { reason: reason.value.trim() || null });
          toast(res.message);
          return true;
        } catch (e) {
          toastError(e);
          return false;
        }
      } }],
    });
  }

  async function remove(m) {
    if (!(await confirmDialog(m.media ? 'این پیام از گروه حذف شود؟ (عکس/فیلم در پروفایل فرستنده می‌ماند)' : 'این پیام حذف شود؟', { danger: true, okLabel: 'حذف' }))) return;
    try {
      const res = await del(`/api/group/messages/${m.id}`);
      Object.assign(m, res.data);
      render(false);
    } catch (e) {
      toastError(e);
    }
  }

  function help() {
    modal({
      title: info.name,
      body: h('div', { class: 'small', style: { lineHeight: 2 } },
        h('p', null, 'اینجا همه اعضای خاندان با هم گفتگو می‌کنند تا خاطره‌های قدیمی زنده شود.'),
        h('ul', null,
          h('li', null, 'متن با ایموجی بفرستید؛ پیامی که فقط ایموجی باشد پذیرفته نمی‌شود.'),
          h('li', null, 'با دکمه ', icon('image'), ' عکس یا فیلم قدیمی بگذارید؛ با توضیح، سال تقریبی و نام کسانی که در آن هستند. هر عکس و فیلم در پروفایل خود شما هم می‌ماند و همه می‌توانند مرورش کنند.'),
          h('li', null, 'عکس‌ها خودکار به JPEG و فیلم‌ها به MP4 کم‌حجم تبدیل می‌شوند.'),
          info.allow_links ? null : h('li', null, 'برای جلوگیری از تبلیغ، فرستادن لینک ممکن نیست.'),
          h('li', null, 'هر روز یک «سؤال روز» برای شروع گفتگو گذاشته می‌شود.'),
          h('li', null, 'پیام نامناسب را از منوی «...» به مدیران گزارش دهید.'),
        ),
      ),
      actions: [{ label: 'باشه' }],
    });
  }

  // ------------------------------------------------------------ عکس و فیلم خاطره
  async function mediaDialog(file) {
    const isVideo = file.type.startsWith('video/') || /\.(mkv|avi|wmv|flv|3gp|mts|m2ts|ts|mov)$/i.test(file.name);
    if (isVideo && !info.video_enabled) return toast('گذاشتن فیلم فعلاً ممکن نیست.', 'warning');
    const cfg = store.config.media || {};
    const maxKb = isVideo ? cfg.video_max_kb || 512000 : cfg.image_max_kb || 51200;
    const ready = isVideo ? file : await shrinkImage(file);
    if (ready.size > maxKb * 1024) return toast('حجم فایل بیش از حد مجاز است.', 'warning');

    const previewUrl = URL.createObjectURL(ready);
    const preview = isVideo
      ? h('video', { src: previewUrl, controls: true, playsinline: true, class: 'grp-upload-preview' })
      : h('img', { src: previewUrl, class: 'grp-upload-preview', alt: '' });
    const caption = h('textarea', { class: 'input', rows: 2, maxlength: 300, placeholder: 'این خاطره را توضیح دهید؛ مثلاً «عروسی عمو حسن در باغ پدربزرگ»' });
    const year = h('input', { class: 'input', inputmode: 'numeric', maxlength: 10, placeholder: 'مثلاً ۱۳۵۸' });
    const tags = [];
    const tagBox = h('div', { class: 'row wrap', style: { gap: '6px' } });
    const drawTags = () => tagBox.replaceChildren(...tags.map((p, i) => h('span', { class: 'chip' }, fullName(p),
      h('button', { class: 'icon-btn xs', type: 'button', title: 'حذف', onclick: () => { tags.splice(i, 1); drawTags(); } }, icon('x')))));
    const search = searchBox({ placeholder: 'نام کسانی که در عکس هستند...', onSelect: (p) => { if (!tags.some((t) => t.id === p.id) && tags.length < 15) tags.push(p); drawTags(); } });
    const progress = h('div', { class: 'progress', hidden: true }, h('i', { style: { width: '0%' } }));

    modal({
      title: isVideo ? 'فیلم خاطره' : 'عکس خاطره',
      onClose: () => URL.revokeObjectURL(previewUrl),
      body: h('div', { class: 'grp-upload' },
        preview,
        h('label', { class: 'label mt-sm' }, 'توضیح (لازم)'),
        caption,
        h('div', { class: 'form-grid mt-sm' },
          h('div', { class: 'field' }, h('label', null, 'سال تقریبی (اختیاری)'), year),
          h('div', { class: 'field' }, h('label', null, 'چه کسانی در آن هستند؟'), search),
        ),
        tagBox,
        progress,
        h('p', { class: 'muted tiny' }, icon('info'), ' در پروفایل شما هم به عنوان «خاطره» می‌ماند. ', isVideo ? 'فیلم پس از آپلود به MP4 کم‌حجم تبدیل می‌شود.' : 'عکس به JPEG کم‌حجم تبدیل می‌شود.'),
      ),
      actions: [{ label: 'انصراف' }, { label: 'فرستادن به گروه', class: 'primary', icon: 'send', onClick: async () => {
        const text = caption.value.trim();
        if (!/[\p{L}\p{N}]/u.test(text)) {
          toast('برای خاطره باید متن یا کلمه‌ای هم اضافه شود (فقط ایموجی کافی نیست).', 'warning');
          return false;
        }
        const form = new FormData();
        form.append('file', ready, ready.name || file.name);
        form.append('caption', text);
        if (year.value.trim()) form.append('taken_at', year.value.trim().replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)));
        tags.forEach((t) => form.append('tags[]', t.id));
        if (replyTo) form.append('reply_to', replyTo.id);
        progress.hidden = false;
        try {
          const res = await upload('/api/group/media', form, (p) => { progress.firstChild.style.width = `${p}%`; });
          messages.push(res.data);
          index();
          clearReply();
          render(true);
          toast('خاطره در گروه و پروفایل شما گذاشته شد.');
          return true;
        } catch (e) {
          progress.hidden = true;
          toastError(e);
          return false;
        }
      } }],
    });
  }

  return () => clearInterval(pollTimer);
}
