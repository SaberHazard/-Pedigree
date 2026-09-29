/**
 * پیام صوتی مثل تلگرام: ضبط با یک لمس (نمایش زنده موج صدا و زمان، لغو یا ارسال)
 * و پخش با موج صدا، جلو/عقب بردن با لمس روی موج و سرعت ۱، ۱٫۵ و ۲ برابر.
 *
 * ضبط در خود مرورگر/اپ با کیفیت پایین (Opus یا AAC حدود ۳۲ کیلوبیت) انجام می‌شود تا آپلود سبک باشد؛
 * سرور دوباره به AAC فشرده و قابل پخش روی همه گوشی‌ها تبدیل می‌کند.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { fa } from '../core/format.js';
import { toast } from '../core/ui.js';

const MIME_CANDIDATES = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/aac'];
const BARS = 48;

export function voiceSupported() {
  return !!(navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
}

const clock = (sec) => {
  const s = Math.max(0, Math.round(sec));
  return fa(`${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`);
};

/**
 * دکمه میکروفون برای یک جعبه نوشتن پیام
 * @param {object} o  host (عنصر جعبه نوشتن؛ نوار ضبط روی آن قرار می‌گیرد)، maxSeconds، send(blob, waveform) → Promise
 */
export function voiceButton({ host, maxSeconds = 300, send, title = 'پیام صوتی' }) {
  const btn = h('button', { class: 'btn icon-only voice-btn', type: 'button', title, 'aria-label': title }, icon('mic'));
  if (!voiceSupported()) {
    btn.hidden = true;
    return btn;
  }
  btn.addEventListener('click', () => record());

  async function record() {
    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } });
    } catch {
      toast('اجازه دسترسی به میکروفون داده نشد. از تنظیمات مرورگر یا گوشی اجازه میکروفون را بدهید.', 'warning', 6000);
      return;
    }
    const mimeType = MIME_CANDIDATES.find((m) => window.MediaRecorder.isTypeSupported?.(m)) || '';
    let rec;
    try {
      rec = new MediaRecorder(stream, { ...(mimeType ? { mimeType } : {}), audioBitsPerSecond: 32000 });
    } catch {
      rec = new MediaRecorder(stream);
    }
    const chunks = [];
    const levels = [];
    rec.ondataavailable = (e) => { if (e.data?.size) chunks.push(e.data); };

    // موج زنده صدا
    let ctx = null;
    let analyser = null;
    try {
      ctx = new (window.AudioContext || window.webkitAudioContext)();
      analyser = ctx.createAnalyser();
      analyser.fftSize = 512;
      ctx.createMediaStreamSource(stream).connect(analyser);
    } catch {
      analyser = null;
    }
    const buf = analyser ? new Uint8Array(analyser.fftSize) : null;

    const live = h('div', { class: 'rec-wave', 'aria-hidden': 'true' });
    const time = h('span', { class: 'rec-time' }, clock(0));
    const cancelBtn = h('button', { class: 'icon-btn rec-cancel', type: 'button', title: 'لغو', 'aria-label': 'لغو ضبط' }, icon('trash'));
    const sendBtn = h('button', { class: 'btn primary icon-only rec-send', type: 'button', title: 'ارسال', 'aria-label': 'ارسال پیام صوتی' }, icon('send'));
    const bar = h('div', { class: 'rec-bar', role: 'status', 'aria-live': 'polite' },
      cancelBtn, h('span', { class: 'rec-dot' }), time, live, sendBtn);
    host.classList.add('recording');
    host.append(bar);

    const started = performance.now();
    let finished = false;
    const tick = setInterval(() => {
      const sec = (performance.now() - started) / 1000;
      time.textContent = clock(sec);
      if (analyser) {
        analyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) sum += ((buf[i] - 128) / 128) ** 2;
        levels.push(Math.min(1, Math.sqrt(sum / buf.length) * 3.2));
        const recent = levels.slice(-36);
        live.replaceChildren(...recent.map((v) => h('i', { style: { height: `${Math.max(3, Math.round(v * 26))}px` } })));
      }
      if (sec >= maxSeconds) finish(true);
    }, 90);

    const cleanup = () => {
      clearInterval(tick);
      stream.getTracks().forEach((t) => t.stop());
      ctx?.close?.().catch(() => {});
      bar.remove();
      host.classList.remove('recording');
      document.removeEventListener('visibilitychange', onHide);
    };
    const onHide = () => { if (document.hidden) finish(false); };
    document.addEventListener('visibilitychange', onHide);

    function finish(ok) {
      if (finished) return;
      finished = true;
      const duration = (performance.now() - started) / 1000;
      rec.onstop = async () => {
        cleanup();
        if (!ok) return;
        if (duration < 0.6) {
          toast('پیام صوتی خیلی کوتاه بود.', 'info');
          return;
        }
        const blob = new Blob(chunks, { type: rec.mimeType || mimeType || 'audio/webm' });
        try {
          sendBtn.disabled = true;
          await send(blob, waveformOf(levels));
        } catch {
          /* خطا را خود فرستنده نشان می‌دهد */
        }
      };
      try {
        rec.state !== 'inactive' ? rec.stop() : rec.onstop();
      } catch {
        cleanup();
      }
    }
    cancelBtn.addEventListener('click', () => finish(false));
    sendBtn.addEventListener('click', () => finish(true));
    rec.start(250);
  }

  return btn;
}

/** موج ۴۸ ستونی بین ۰ تا ۳۱ از سطح‌های ضبط‌شده */
function waveformOf(levels) {
  if (!levels.length) return [];
  const out = [];
  const step = levels.length / BARS;
  let peak = 0.05;
  for (const v of levels) peak = Math.max(peak, v);
  for (let i = 0; i < BARS; i++) {
    const slice = levels.slice(Math.floor(i * step), Math.max(Math.floor(i * step) + 1, Math.floor((i + 1) * step)));
    const v = slice.length ? Math.max(...slice) : 0;
    out.push(Math.round((v / peak) * 31));
  }
  return out;
}

// ------------------------------------------------------------------ پخش
let playing = null;

/**
 * پخش‌کننده پیام صوتی
 * @param {{url:string, duration:number, waveform:number[]}} voice
 */
export function voicePlayer(voice, { mine = false } = {}) {
  const wave = (voice.waveform?.length ? voice.waveform : Array.from({ length: 36 }, (_, i) => 8 + ((i * 7) % 11)));
  const playBtn = h('button', { class: 'vp-play', type: 'button', 'aria-label': 'پخش پیام صوتی' }, icon('play-fill'));
  const bars = h('div', { class: 'vp-wave', role: 'slider', 'aria-label': 'جلو و عقب بردن', 'aria-valuemin': 0, 'aria-valuemax': Math.round(voice.duration), 'aria-valuenow': 0, tabindex: 0 },
    ...wave.map((v) => h('i', { style: { height: `${Math.max(3, Math.round((v / 31) * 24))}px` } })));
  const time = h('span', { class: 'vp-time' }, clock(voice.duration));
  const speedBtn = h('button', { class: 'vp-speed', type: 'button', title: 'سرعت پخش', hidden: true }, '۱×');
  const el = h('div', { class: `voice-player ${mine ? 'mine' : ''}` }, playBtn, h('div', { class: 'vp-body' }, bars, h('div', { class: 'vp-meta' }, time, speedBtn)));
  let audio = null;
  let speed = 1;

  const progress = () => {
    if (!audio) return;
    const d = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : voice.duration;
    const p = Math.min(1, audio.currentTime / d);
    const lit = Math.round(p * bars.children.length);
    [...bars.children].forEach((b, i) => b.classList.toggle('on', i < lit));
    bars.setAttribute('aria-valuenow', String(Math.round(audio.currentTime)));
    time.textContent = clock(audio.paused && audio.currentTime === 0 ? d : d - audio.currentTime);
  };
  const setIcon = (isPlaying) => {
    playBtn.replaceChildren(icon(isPlaying ? 'pause-fill' : 'play-fill'));
    playBtn.setAttribute('aria-label', isPlaying ? 'توقف' : 'پخش پیام صوتی');
    el.classList.toggle('playing', isPlaying);
  };
  const ensure = () => {
    if (audio) return audio;
    audio = new Audio();
    audio.preload = 'auto';
    audio.src = voice.url;
    audio.addEventListener('timeupdate', progress);
    audio.addEventListener('play', () => setIcon(true));
    audio.addEventListener('pause', () => setIcon(false));
    audio.addEventListener('ended', () => { audio.currentTime = 0; progress(); setIcon(false); });
    audio.addEventListener('error', () => { setIcon(false); toast('پخش این پیام صوتی ممکن نشد.', 'warning'); });
    return audio;
  };
  playBtn.addEventListener('click', () => {
    const a = ensure();
    if (a.paused) {
      if (playing && playing !== a) playing.pause();
      playing = a;
      a.playbackRate = speed;
      a.play().catch(() => {});
      speedBtn.hidden = false;
    } else {
      a.pause();
    }
  });
  const seek = (clientX) => {
    const a = ensure();
    const r = bars.getBoundingClientRect();
    // موج از چپ به راست پخش می‌شود (مثل زمان)
    const p = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
    const d = Number.isFinite(a.duration) && a.duration > 0 ? a.duration : voice.duration;
    a.currentTime = p * d;
    progress();
  };
  bars.addEventListener('click', (e) => seek(e.clientX));
  bars.addEventListener('keydown', (e) => {
    if (!audio) return;
    if (e.key === 'ArrowLeft') audio.currentTime = Math.max(0, audio.currentTime - 3);
    if (e.key === 'ArrowRight') audio.currentTime = Math.min(audio.duration || voice.duration, audio.currentTime + 3);
    progress();
  });
  speedBtn.addEventListener('click', () => {
    speed = speed === 1 ? 1.5 : speed === 1.5 ? 2 : 1;
    speedBtn.textContent = `${fa(String(speed).replace('.', '٫'))}×`;
    if (audio) audio.playbackRate = speed;
  });
  return el;
}

/** ارسال فایل ضبط‌شده (نام فایل از روی نوع) */
export function voiceForm(blob, waveform, extra = {}) {
  const ext = /mp4|aac/.test(blob.type) ? 'm4a' : /ogg/.test(blob.type) ? 'ogg' : 'webm';
  const form = new FormData();
  form.append('voice', blob, `voice.${ext}`);
  form.append('waveform', JSON.stringify(waveform || []));
  for (const [k, v] of Object.entries(extra)) if (v !== null && v !== undefined) form.append(k, v);
  return form;
}
