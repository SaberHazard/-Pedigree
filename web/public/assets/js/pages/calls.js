/**
 * تماس‌ها:
 *  - تماس مستقیم صوتی/تصویری داخل سایت (دونفره یا گروهی تا سقف تعیین‌شده مدیر)
 *  - دعوت بستگان به تماس گروهی بزرگ با لینک سرویس‌های بیرونی (لینک تماس واتس‌اپ، گوگل‌میت، جیتسی، اسکای‌روم ...)
 *  - تماس‌های اخیر و دعوت‌ها
 */
import { h, fill, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, del } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, timeAgo, duration } from '../core/format.js';
import { loader, emptyState, toast, toastError, withLoading, field, confirmDialog } from '../core/ui.js';
import { avatar } from '../components/avatar.js';
import { startCall, callsAvailable, answerFromLink } from '../core/calls.js';

const JITSI_CHARS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function jitsiRoom() {
  const bytes = crypto.getRandomValues(new Uint8Array(18));
  return `https://meet.jit.si/Shajare-${Array.from(bytes, (b) => JITSI_CHARS[b % JITSI_CHARS.length]).join('')}`;
}

export default async function callsPage(container, { query }) {
  const page = h('div', { class: 'page narrow calls-page' },
    h('div', { class: 'page-head' }, h('h1', null, icon('call'), ' تماس‌ها')));
  const body = h('div', null, loader());
  page.append(body);
  container.append(page);
  if (query.answer) answerFromLink(query.answer);

  let data;
  try {
    data = await get('/api/calls');
  } catch (e) {
    fill(body, emptyState('alert', e.message));
    return undefined;
  }
  const cfg = data.config;
  const max = Math.max(1, (cfg.max_participants || 4) - 1);

  // ------------------------------------------------------------ تماس مستقیم
  const group = new Map();
  const results = h('div', { class: 'call-people' });
  const chips = h('div', { class: 'call-group row wrap' });
  const search = h('input', { class: 'input', type: 'search', placeholder: 'نام یکی از اعضا را بنویسید...', 'aria-label': 'جستجوی اعضا' });
  const load = debounce(async () => {
    try {
      const res = await get('/api/calls/people', { q: search.value.trim() }, { quiet: true });
      fill(results, res.data.length ? res.data.map(personRow) : h('p', { class: 'muted small' }, 'عضو فعالی با این نام پیدا نشد.'));
    } catch (e) {
      fill(results, h('p', { class: 'muted small' }, e.message));
    }
  }, 300);
  search.addEventListener('input', load);

  function personRow(p) {
    return h('div', { class: 'call-person' },
      avatar(p, 'sm'),
      h('a', { class: 'grow', href: `#/person/${p.id}` }, p.name || [p.first_name, p.last_name].filter(Boolean).join(' ')),
      h('button', { class: 'icon-btn', type: 'button', title: 'تماس صوتی', 'aria-label': 'تماس صوتی', onclick: () => startCall([p.id], 'audio') }, icon('call')),
      h('button', { class: 'icon-btn', type: 'button', title: 'تماس تصویری', 'aria-label': 'تماس تصویری', onclick: () => startCall([p.id], 'video') }, icon('video')),
      max > 1 ? h('button', { class: 'icon-btn', type: 'button', title: 'افزودن به تماس گروهی', 'aria-label': 'افزودن به تماس گروهی', onclick: () => addToGroup(p) }, icon('user-plus')) : null);
  }

  function addToGroup(p) {
    if (group.has(p.id)) return;
    if (group.size >= max) return toast(`در تماس گروهی حداکثر ${fa(max)} نفر را می‌توانید اضافه کنید.`, 'warning');
    group.set(p.id, p);
    drawGroup();
    return undefined;
  }

  function drawGroup() {
    fill(chips, group.size ? [
      ...[...group.values()].map((p) => h('span', { class: 'chip' }, p.first_name || p.name,
        h('button', { class: 'chip-x', type: 'button', 'aria-label': 'حذف', onclick: () => { group.delete(p.id); drawGroup(); } }, icon('x')))),
      h('button', { class: 'btn primary sm', type: 'button', onclick: () => startCall([...group.keys()], 'audio') }, icon('call'), 'تماس گروهی'),
      h('button', { class: 'btn soft sm', type: 'button', onclick: () => startCall([...group.keys()], 'video') }, icon('video'), 'تصویری'),
    ] : null);
  }

  const direct = cfg.enabled ? h('section', { class: 'card' },
    h('h3', { style: { marginTop: 0 } }, 'تماس مستقیم با اعضا'),
    h('p', { class: 'muted small' }, `صوتی یا تصویری، دونفره یا گروهی تا ${fa(cfg.max_participants)} نفر. صدا و تصویر رمزگذاری‌شده و مستقیم بین گوشی‌ها می‌رود و از سرور سایت نمی‌گذرد. طرف مقابل باید سایت یا اپ را باز داشته باشد یا اعلان گوشی‌اش روشن باشد.`),
    callsAvailable() ? null : h('p', { class: 'chip warning' }, 'این مرورگر تماس مستقیم را پشتیبانی نمی‌کند؛ مرورگر را به‌روز کنید.'),
    search, chips, results) : null;

  // ------------------------------------------------------------ دعوت با لینک
  let linkBox = null;
  if (cfg.links) {
    const url = h('input', { class: 'input ltr-input', type: 'url', dir: 'ltr', placeholder: 'https://call.whatsapp.com/voice/...', maxlength: 300 });
    const title = h('input', { class: 'input', maxlength: 120, placeholder: 'مثلاً: دورهمی آنلاین شب یلدا' });
    const recipients = new Map();
    const recChips = h('div', { class: 'row wrap', style: { gap: '6px' } });
    const recSearch = h('input', { class: 'input', type: 'search', placeholder: 'افزودن دعوت‌شده‌ها (نام)...' });
    const recResults = h('div', { class: 'call-people compact' });
    const drawRecipients = () => fill(recChips, [...recipients.values()].map((p) => h('span', { class: 'chip' }, p.first_name || p.name,
      h('button', { class: 'chip-x', type: 'button', 'aria-label': 'حذف', onclick: () => { recipients.delete(p.id); drawRecipients(); } }, icon('x')))));
    const findRecipients = debounce(async () => {
      const q = recSearch.value.trim();
      if (!q) return fill(recResults);
      try {
        const res = await get('/api/calls/people', { q }, { quiet: true });
        fill(recResults, res.data.slice(0, 8).map((p) => h('button', { class: 'call-person pick', type: 'button', onclick: () => {
          if (recipients.size >= 60) return toast('حداکثر ۶۰ نفر.', 'warning');
          recipients.set(p.id, p);
          drawRecipients();
          recSearch.value = '';
          fill(recResults);
          return undefined;
        } }, avatar(p, 'sm'), h('span', { class: 'grow' }, [p.first_name, p.last_name].filter(Boolean).join(' ')), icon('plus'))));
      } catch {
        fill(recResults);
      }
      return undefined;
    }, 300);
    recSearch.addEventListener('input', findRecipients);
    const addAll = h('button', { class: 'btn ghost xs', type: 'button', onclick: async () => {
      try {
        const res = await get('/api/calls/people', {}, { quiet: true });
        res.data.slice(0, 60).forEach((p) => recipients.set(p.id, p));
        drawRecipients();
      } catch (e) {
        toastError(e);
      }
    } }, icon('users'), 'اعضای فعال اخیر');
    const send = h('button', { class: 'btn primary', type: 'button' }, icon('send'), 'فرستادن دعوت');
    send.addEventListener('click', () => withLoading(send, async () => {
      if (!url.value.trim()) return toast('لینک تماس را بچسبانید یا «اتاق جیتسی» بسازید.', 'warning');
      if (!recipients.size) return toast('دعوت‌شده‌ها را انتخاب کنید.', 'warning');
      try {
        const res = await post('/api/call-invites', { url: url.value.trim(), title: title.value.trim() || 'تماس گروهی خانوادگی', person_ids: [...recipients.keys()] });
        toast(res.message);
        url.value = '';
        title.value = '';
        recipients.clear();
        drawRecipients();
        reload();
      } catch (e) {
        toastError(e);
      }
      return undefined;
    }));
    linkBox = h('section', { class: 'card mt' },
      h('h3', { style: { marginTop: 0 } }, 'تماس گروهی با لینک (واتس‌اپ، گوگل‌میت، جیتسی ...)'),
      h('details', { class: 'set-guide' }, h('summary', null, icon('info'), ' لینک تماس واتس‌اپ چطور ساخته می‌شود؟'),
        h('ol', null,
          h('li', null, 'در واتس‌اپ به بخش «تماس‌ها» (Calls) بروید و «ساخت لینک تماس» (Create call link) را بزنید.'),
          h('li', null, 'نوع تماس (صوتی یا تصویری) را انتخاب و لینک را کپی کنید، سپس اینجا بچسبانید.'),
          h('li', null, 'هر کس لینک را بزند به تماس می‌پیوندد (تا ۳۲ نفر). واتس‌اپ در حال گسترش ورود «مهمان» از مرورگر برای کسانی است که واتس‌اپ ندارند.'),
          h('li', null, 'بدون هیچ برنامه‌ای: «ساخت اتاق جیتسی» یک اتاق تماس تصویری در مرورگر می‌سازد (ممکن است میزبان یک بار با حساب گوگل یا گیت‌هاب وارد شود).'),
          h('li', null, 'گوگل‌میت، اسکای‌روم، زوم و ویدیوچت تلگرام هم پذیرفته می‌شوند.'))),
      field('لینک تماس', h('div', { class: 'row', style: { gap: '6px' } }, url,
        h('button', { class: 'btn soft sm', type: 'button', onclick: () => { url.value = jitsiRoom(); } }, icon('video'), 'اتاق جیتسی'))),
      field('عنوان', title),
      h('div', { class: 'field' }, h('label', null, 'دعوت‌شده‌ها ', addAll), recSearch, recResults, recChips),
      send);
  }

  // ------------------------------------------------------------ دعوت‌ها و تماس‌های اخیر
  const invites = h('div');
  const history = h('div');

  function drawInvites(list) {
    fill(invites, list.length ? h('section', { class: 'card mt' },
      h('h3', { style: { marginTop: 0 } }, 'دعوت‌ها'),
      h('div', { class: 'call-list' }, ...list.map((i) => h('div', { class: 'call-row' },
        h('span', { class: 'call-row-icon invite' }, icon('link')),
        h('div', { class: 'grow' },
          h('b', null, i.title),
          h('div', { class: 'tiny muted' }, [i.label, i.mine ? `شما ← ${fa(i.recipients)} نفر` : `از ${i.from.name}`, timeAgo(i.at)].join(' • '))),
        h('a', { class: 'btn primary sm', href: i.url, target: '_blank', rel: 'noopener noreferrer' }, icon('external'), 'پیوستن'),
        i.mine ? h('button', { class: 'icon-btn', type: 'button', title: 'حذف دعوت', 'aria-label': 'حذف دعوت', onclick: async () => {
          if (!(await confirmDialog('این دعوت حذف شود؟'))) return;
          try {
            await del(`/api/call-invites/${i.id}`);
            reload();
          } catch (e) {
            toastError(e);
          }
        } }, icon('trash')) : null)))) : null);
  }

  function drawHistory(list) {
    fill(history, h('section', { class: 'card mt' },
      h('h3', { style: { marginTop: 0 } }, 'تماس‌های اخیر'),
      list.length ? h('div', { class: 'call-list' }, ...list.map((c) => {
        const missed = !c.outgoing && ['missed', 'declined'].includes(c.state);
        const label = c.outgoing ? (c.answered ? 'تماس خروجی' : 'بی‌پاسخ') : missed ? (c.state === 'declined' ? 'رد شده' : 'از دست رفته') : 'تماس ورودی';
        const ids = c.people.map((p) => p.person?.id).filter(Boolean);
        return h('div', { class: `call-row${missed ? ' missed' : ''}` },
          h('span', { class: 'call-row-icon' }, icon(c.kind === 'video' ? 'video' : 'call')),
          h('div', { class: 'grow' },
            h('b', null, c.people.map((p) => p.name).join('، ') || 'عضو'),
            h('div', { class: 'tiny muted' }, [label, c.duration ? duration(c.duration) : null, timeAgo(c.at)].filter(Boolean).join(' • '))),
          ids.length && cfg.enabled ? h('button', { class: 'icon-btn', type: 'button', title: 'تماس دوباره', 'aria-label': 'تماس دوباره', onclick: () => startCall(ids, c.kind) }, icon(c.kind === 'video' ? 'video' : 'call')) : null);
      })) : h('p', { class: 'muted small' }, 'هنوز تماسی نداشته‌اید.')));
  }

  async function reload() {
    try {
      const res = await get('/api/calls', null, { quiet: true });
      drawInvites(res.invites);
      drawHistory(res.data);
    } catch {
      /* بعداً */
    }
  }

  fill(body, direct, linkBox, invites, history);
  drawInvites(data.invites);
  drawHistory(data.data);
  if (cfg.enabled) load();
  const off = store.on?.('call-ended', () => reload());
  return () => off?.();
}
