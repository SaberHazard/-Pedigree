/**
 * دستیار هوش مصنوعی
 *
 * - گفتگو فقط در همین زبانه مرورگر (sessionStorage) نگه داشته می‌شود؛ سرور چیزی ذخیره نمی‌کند
 * - پاسخ‌ها با گره‌های متنی ساخته می‌شوند (نه innerHTML)؛ فقط **پررنگ**، فهرست و بلوک کد شکل می‌گیرد
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, upload, request } from '../core/api.js';
import { store } from '../core/store.js';
import { fa } from '../core/format.js';
import { toast, toastError, loader, emptyState, confirmDialog } from '../core/ui.js';
import { voiceButton, voiceForm, voiceSupported } from '../components/voice.js';
import { openAiCall, callSupported } from '../components/ai-call.js';

const MAX_KEEP = 40;
const STARTERS = [
  ['edit', 'کمک در نوشتن زندگی‌نامه', 'می‌خواهم زندگی‌نامه کوتاه و گرمی برای پدربزرگم بنویسم. چه سؤال‌هایی از خانواده بپرسم و متن را چطور شروع کنم؟'],
  ['cake', 'متن تبریک تولد', 'یک متن تبریک تولد کوتاه، صمیمی و فارسی برای دخترخاله‌ام بنویس.'],
  ['users', 'ایده برای دورهمی خانوادگی', 'برای یک دورهمی خانوادگی با حدود ۴۰ نفر از سه نسل، چند ایده سرگرمی و برنامه پیشنهاد بده.'],
  ['search', 'ریشه نام خانوادگی', 'نام‌های خانوادگی ایرانی معمولاً از چه ریشه‌هایی آمده‌اند؟ چطور درباره ریشه نام خانوادگی خودمان تحقیق کنم؟'],
  ['ribbon', 'متن یادبود', 'یک متن یادبود محترمانه و کوتاه برای سالگرد درگذشت مادربزرگم بنویس.'],
  ['tree', 'پژوهش در تاریخچه خانواده', 'برای کامل کردن شجره‌نامه خانواده از چه منابع و اسنادی (مثل شناسنامه قدیمی) می‌توانم استفاده کنم؟'],
];

export default async function assistantPage(container, { query } = {}) {
  document.title = `دستیار هوشمند | ${store.config.site_name}`;
  const page = h('div', { class: 'page assistant' });
  container.append(page);
  page.append(loader());

  let info;
  try {
    info = (await get('/api/assistant')).data;
  } catch (e) {
    page.replaceChildren(emptyState('alert', e.message));
    return;
  }
  if (!info.enabled) {
    page.replaceChildren(h('div', { class: 'card', style: { maxWidth: '640px', margin: '40px auto' } },
      emptyState('bot', 'دستیار هوش مصنوعی هنوز راه‌اندازی نشده است.',
        store.user?.role === 'super_admin' ? h('a', { class: 'btn primary', href: '#/admin/settings' }, icon('settings'), 'راه‌اندازی در تنظیمات') : h('p', { class: 'muted small' }, 'مدیر سایت باید کلید یکی از سرویس‌ها را وارد کند.'))));
    return;
  }

  const modes = info.modes || [{ key: 'chat', label: 'گفتگو', emoji: '💬' }];
  let mode = modes.some((m) => m.key === query?.mode) ? query.mode : 'chat';
  const keyFor = (m) => `ai-chat:${store.user?.id}:${m}`;
  let storeKey = keyFor(mode);
  let history = load();
  let busy = false;

  const remainingEl = h('span', { class: 'muted tiny' });
  const thread = h('div', { class: 'ai-thread', role: 'log', 'aria-live': 'polite' });
  const input = h('textarea', { class: 'input ai-input', rows: 1, maxlength: info.max_chars, placeholder: 'پیامتان را بنویسید...', 'aria-label': 'پیام به دستیار' });
  const sendBtn = h('button', { class: 'btn primary icon-only', type: 'button', title: 'ارسال', onclick: () => send() }, icon('send'));

  // صدا: پیام صوتی فارسی (تبدیل به متن)، خواندن پاسخ و تماس صوتی زنده
  const voice = info.voice || {};
  const compose = h('div', { class: 'ai-compose' });
  const micBtn = voice.stt && voiceSupported()
    ? voiceButton({ host: compose, maxSeconds: voice.max_seconds || 120, send: sendVoice, title: 'پیام صوتی به دستیار' }) : null;
  const toggleButtons = () => {
    if (!micBtn) return;
    const empty = !input.value.trim();
    micBtn.hidden = !empty;
    sendBtn.hidden = empty;
  };
  toggleButtons();
  const callBtn = voice.live && callSupported()
    ? h('button', { class: 'btn soft sm call-start', type: 'button', title: 'گفتگوی صوتی زنده با دستیار', onclick: () => {
      const m = modes.find((x) => x.key === mode);
      openAiCall({ mode, modeLabel: m ? `${m.emoji} ${m.label}` : '' });
    } }, icon('phone-call'), h('span', { class: 'hide-mobile' }, 'تماس صوتی'))
    : null;
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 180)}px`;
    toggleButtons();
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !matchMedia('(pointer: coarse)').matches) {
      e.preventDefault();
      send();
    }
  });

  // حالت‌ها: گفتگو، زنده کردن خاطره و بازی‌ها (هر حالت گفتگوی جدای خودش را دارد)
  const modeBar = h('div', { class: 'ai-modes', role: 'tablist' });
  const drawModes = () => modeBar.replaceChildren(...modes.map((m) => h('button', {
    type: 'button', role: 'tab', class: `ai-mode ${m.key === mode ? 'active' : ''}`, 'aria-selected': m.key === mode ? 'true' : 'false',
    onclick: () => switchMode(m.key),
  }, m.emoji, ' ', m.label)));
  drawModes();

  page.replaceChildren(
    h('div', { class: 'ai-shell card' },
      h('div', { class: 'ai-head' },
        h('div', { class: 'ai-avatar' }, icon('bot')),
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('b', null, 'دستیار هوشمند'),
          h('div', { class: 'muted tiny ellipsis' }, `با ${info.provider}`, ' • ', remainingEl)),
        callBtn,
        h('button', { class: 'btn ghost sm', type: 'button', title: 'پاک کردن گفتگو و شروع دوباره', onclick: reset }, icon('refresh'), h('span', { class: 'hide-mobile' }, 'گفتگوی تازه')),
      ),
      modeBar,
      thread,
      compose,
    ),
  );
  const privacy = h('p', { class: 'muted tiny', style: { margin: '6px 2px 0' } });
  const drawPrivacy = () => privacy.replaceChildren(icon('lock'), mode === 'family_quiz'
    ? ' در مسابقه خاندان، نام، نسبت، سال تولد، زادگاه، شغل و تحصیلات تعدادی از بستگانتان برای سرویس هوش مصنوعی فرستاده می‌شود (هرگز شماره، نشانی یا کد ملی).'
    : ' گفتگو در سرور ذخیره نمی‌شود و فقط همین متن‌ها (و پیام صوتی شما) برای سرویس هوش مصنوعی فرستاده می‌شود؛ کد ملی، رمز یا اطلاعات خصوصی ننویسید. پاسخ‌ها ممکن است اشتباه داشته باشند.');
  drawPrivacy();
  compose.append(h('div', { class: 'row compose-row', style: { gap: '6px', alignItems: 'flex-end' } }, ...[input, sendBtn, micBtn].filter(Boolean)), privacy);
  setRemaining(info.remaining);
  render();
  if (!matchMedia('(pointer: coarse)').matches) input.focus();

  function switchMode(key) {
    if (busy || key === mode) return;
    mode = key;
    storeKey = keyFor(mode);
    history = load();
    drawModes();
    drawPrivacy();
    history_replace();
    render();
    const m = modes.find((x) => x.key === mode);
    // بازی‌ها خودشان شروع می‌شوند
    if (!history.length && m?.starter) send(m.starter);
  }

  function history_replace() {
    try {
      window.history.replaceState(null, '', mode === 'chat' ? '#/assistant' : `#/assistant?mode=${mode}`);
    } catch {
      /* ignore */
    }
  }

  function setRemaining(n) {
    remainingEl.textContent = `${fa(n)} پیام دیگر تا پایان امروز`;
  }

  function load() {
    try {
      const data = JSON.parse(sessionStorage.getItem(storeKey) || '[]');
      return Array.isArray(data) ? data.filter((m) => m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string').slice(-MAX_KEEP) : [];
    } catch {
      return [];
    }
  }

  function save() {
    try {
      sessionStorage.setItem(storeKey, JSON.stringify(history.slice(-MAX_KEEP)));
    } catch {
      /* حافظه مرورگر در دسترس نیست */
    }
  }

  async function reset() {
    if (!history.length) return;
    if (!(await confirmDialog('گفتگوی فعلی پاک شود؟', { okLabel: 'پاک کن' }))) return;
    history = [];
    save();
    render();
  }

  function render(pending = false) {
    const items = [];
    const current = modes.find((x) => x.key === mode);
    if (!history.length && mode !== 'chat') {
      items.push(h('div', { class: 'ai-welcome' },
        h('div', { class: 'ai-avatar lg' }, h('span', { style: { fontSize: '2rem' } }, current?.emoji || '🎲')),
        h('h2', null, current?.label || ''),
        h('button', { class: 'btn primary', type: 'button', onclick: () => send(current?.starter || 'شروع کن') }, icon('play'), 'شروع'),
      ));
    } else if (!history.length) {
      items.push(h('div', { class: 'ai-welcome' },
        h('div', { class: 'ai-avatar lg' }, icon('bot')),
        h('h2', null, 'سلام! چطور کمکتان کنم؟'),
        h('p', { class: 'muted small' }, 'در نوشتن زندگی‌نامه و خاطره، متن تبریک و یادبود، ایده برای دورهمی، ریشه نام‌ها و هر پرسش دیگری کمک می‌کنم.'),
        h('div', { class: 'ai-starters' }, ...STARTERS.map(([ic, label, prompt]) => h('button', { class: 'ai-starter', type: 'button', onclick: () => send(prompt) }, icon(ic), label))),
      ));
    }
    for (const m of history) {
      items.push(h('div', { class: `ai-msg ${m.role}` },
        m.role === 'assistant' ? h('div', { class: 'ai-avatar sm' }, icon('bot')) : null,
        h('div', { class: 'ai-bubble', dir: 'auto' }, ...(m.role === 'assistant' ? rich(m.content) : [m.content])),
        m.role === 'assistant' ? h('div', { class: 'ai-tools' },
          voice.tts ? h('button', { class: 'icon-btn ai-copy', type: 'button', title: 'خواندن با صدا', 'aria-label': 'خواندن با صدا', onclick: (e) => speak(m.content, e.currentTarget) }, icon('volume')) : null,
          h('button', { class: 'icon-btn ai-copy', type: 'button', title: 'کپی متن', onclick: () => copy(m.content) }, icon('copy'))) : null,
      ));
    }
    if (pending) items.push(h('div', { class: 'ai-msg assistant' }, h('div', { class: 'ai-avatar sm' }, icon('bot')), h('div', { class: 'ai-bubble typing' }, h('i'), h('i'), h('i'))));
    thread.replaceChildren(...items);
    thread.scrollTop = thread.scrollHeight;
  }

  async function send(text) {
    const content = (text ?? input.value).trim();
    if (!content || busy) return;
    if (content.length > info.max_chars) return toast(`پیام حداکثر ${fa(info.max_chars)} نویسه باشد.`, 'warning');
    busy = true;
    sendBtn.disabled = true;
    history.push({ role: 'user', content });
    if (text === undefined) {
      input.value = '';
      input.style.height = 'auto';
      toggleButtons();
    }
    render(true);
    try {
      const res = await post('/api/assistant/chat', { messages: history.slice(-20), mode });
      history.push({ role: 'assistant', content: res.data.reply });
      setRemaining(res.data.remaining);
      save();
      render();
    } catch (e) {
      // پیام فرستاده‌نشده به کادر برمی‌گردد
      history.pop();
      if (!input.value) input.value = content;
      render();
      toastError(e);
    } finally {
      busy = false;
      sendBtn.disabled = false;
    }
  }

  /** پیام صوتی ← متن ← ارسال مثل پیام نوشتاری */
  async function sendVoice(blob) {
    if (busy) return;
    compose.classList.add('uploading');
    try {
      const res = await upload('/api/assistant/transcribe', voiceForm(blob, []));
      setRemaining(res.data.remaining);
      compose.classList.remove('uploading');
      await send(res.data.text);
    } catch (e) {
      toastError(e);
      throw e;
    } finally {
      compose.classList.remove('uploading');
    }
  }

  // خواندن پاسخ: با صدای سرور (Gemini/OpenAI) یا صدای فارسی خود گوشی
  let speaking = null;
  async function speak(textValue, btn) {
    if (speaking) {
      speaking.stop();
      speaking = null;
      return;
    }
    if (voice.tts === 'browser') {
      const synth = window.speechSynthesis;
      const faVoice = synth?.getVoices().find((v) => /^fa/i.test(v.lang));
      if (!synth || !faVoice) {
        toast('صدای فارسی روی این دستگاه نصب نیست؛ مدیر سایت می‌تواند خواندن با صدای Gemini را روشن کند.', 'info', 6000);
        return;
      }
      const u = new SpeechSynthesisUtterance(textValue.replace(/[*#`>_]+/g, ''));
      u.voice = faVoice;
      u.lang = faVoice.lang;
      u.onend = () => { speaking = null; btn.classList.remove('active'); };
      synth.cancel();
      synth.speak(u);
      btn.classList.add('active');
      speaking = { stop: () => { synth.cancel(); btn.classList.remove('active'); } };
      return;
    }
    btn.disabled = true;
    try {
      const res = await request('POST', '/api/assistant/speak', { text: textValue.slice(0, 1200) }, { raw: true });
      const blobUrl = URL.createObjectURL(await res.blob());
      const audio = new Audio(blobUrl);
      audio.onended = () => { URL.revokeObjectURL(blobUrl); speaking = null; btn.classList.remove('active'); };
      await audio.play();
      btn.classList.add('active');
      speaking = { stop: () => { audio.pause(); URL.revokeObjectURL(blobUrl); btn.classList.remove('active'); } };
    } catch (e) {
      toastError(e);
    } finally {
      btn.disabled = false;
    }
  }

  async function copy(textValue) {
    try {
      await navigator.clipboard.writeText(textValue);
      toast('متن کپی شد.');
    } catch {
      toast('کپی ممکن نشد.', 'warning');
    }
  }
}

/**
 * قالب‌بندی امن پاسخ: فقط گره متنی و چند تگ ثابت (هیچ HTML یا لینکی از پاسخ اجرا نمی‌شود)
 * @returns {Node[]}
 */
export function rich(text) {
  const out = [];
  const parts = String(text).split(/```[^\n]*\n?/);
  parts.forEach((part, index) => {
    if (index % 2 === 1) {
      out.push(h('pre', { class: 'ai-code', dir: 'ltr' }, part.replace(/\n$/, '')));
      return;
    }
    let list = null;
    for (const raw of part.split('\n')) {
      const line = raw.trimEnd();
      const bullet = line.match(/^\s*(?:[-*•]|\d+[.)]|[۰-۹]+[.)])\s+(.*)$/);
      if (bullet) {
        const ordered = /^\s*[\d۰-۹]/.test(line);
        if (!list || list.ordered !== ordered) {
          list = { ordered, el: h(ordered ? 'ol' : 'ul') };
          out.push(list.el);
        }
        list.el.append(h('li', null, ...inline(bullet[1])));
        continue;
      }
      list = null;
      if (!line.trim()) continue;
      const heading = line.match(/^#{1,4}\s+(.*)$/);
      out.push(heading ? h('p', { class: 'ai-h' }, ...inline(heading[1])) : h('p', null, ...inline(line)));
    }
  });
  return out;
}

/** **پررنگ** و `کد` داخل خط */
function inline(line) {
  const nodes = [];
  const re = /\*\*(.+?)\*\*|`([^`]+)`/g;
  let last = 0;
  let m;
  while ((m = re.exec(line))) {
    if (m.index > last) nodes.push(line.slice(last, m.index));
    nodes.push(m[1] !== undefined ? h('b', null, m[1]) : h('code', null, m[2]));
    last = re.lastIndex;
  }
  if (last < line.length) nodes.push(line.slice(last));
  return nodes;
}
