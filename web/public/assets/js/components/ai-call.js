/**
 * تماس صوتی زنده با هوش مصنوعی (مثل حالت صوتی Gemini و ChatGPT)
 *
 * سرور سایت فقط یک کلید یک‌بارمصرف چنددقیقه‌ای می‌سازد (POST /api/assistant/live) و مرورگر
 * مستقیم به سرویس وصل می‌شود؛ صدا از سرور سایت عبور نمی‌کند و کلید اصلی هرگز به مرورگر نمی‌رسد.
 *  - Gemini Live: WebSocket؛ میکروفون ← PCM شانزده کیلوهرتز، پاسخ ← PCM بیست‌وچهار کیلوهرتز
 *  - OpenAI Realtime: WebRTC (صدا دوطرفه، رویدادها در کانال داده)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { post } from '../core/api.js';
import { fa } from '../core/format.js';
import { toast, toastError } from '../core/ui.js';

const clock = (sec) => fa(`${Math.floor(sec / 60)}:${String(Math.floor(sec % 60)).padStart(2, '0')}`);

export function callSupported() {
  return !!(navigator.mediaDevices?.getUserMedia && (window.AudioContext || window.webkitAudioContext) && window.WebSocket);
}

/** @param {{ mode: string, modeLabel?: string, provider: string }} o */
export async function openAiCall({ mode = 'chat', modeLabel = '' } = {}) {
  if (!callSupported()) {
    toast('این مرورگر از تماس صوتی پشتیبانی نمی‌کند.', 'warning');
    return;
  }
  const status = h('div', { class: 'call-status' }, 'در حال اتصال...');
  const orb = h('div', { class: 'call-orb connecting' }, h('span'), h('span'), h('span'), icon('bot'));
  const timer = h('div', { class: 'call-timer' }, clock(0));
  const captions = h('div', { class: 'call-captions', 'aria-live': 'polite' });
  const muteBtn = h('button', { class: 'call-btn', type: 'button', title: 'قطع صدای من', 'aria-label': 'قطع میکروفون' }, icon('mic'));
  const endBtn = h('button', { class: 'call-btn end', type: 'button', title: 'پایان تماس', 'aria-label': 'پایان تماس' }, icon('phone-off'));
  const overlay = h('div', { class: 'call-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'تماس صوتی با دستیار' },
    h('div', { class: 'call-card' },
      h('div', { class: 'call-title' }, h('b', null, 'دستیار هوشمند'), modeLabel ? h('span', { class: 'chip' }, modeLabel) : null),
      orb, status, timer, captions,
      h('div', { class: 'call-actions' }, muteBtn, endBtn),
      h('p', { class: 'muted tiny call-note' }, icon('lock'), ' صدای شما مستقیم (بدون عبور از سرور سایت) به سرویس هوش مصنوعی فرستاده و ذخیره نمی‌شود. از داخل ایران ممکن است فیلترشکن لازم باشد.'),
    ));
  document.body.append(overlay);
  document.body.style.overflow = 'hidden';

  let ended = false;
  let stopFn = () => {};
  let muted = false;
  let startedAt = 0;
  let maxSeconds = 600;
  let tick = null;
  const cap = { user: '', ai: '' };
  const drawCaptions = () => captions.replaceChildren(...[
    cap.user ? h('p', { class: 'cap user', dir: 'auto' }, cap.user) : null,
    cap.ai ? h('p', { class: 'cap ai', dir: 'auto' }, cap.ai) : null,
  ].filter(Boolean));
  const setState = (text, cls) => {
    status.textContent = text;
    orb.className = `call-orb ${cls || ''}`;
  };

  const end = (message) => {
    if (ended) return;
    ended = true;
    clearInterval(tick);
    try { stopFn(); } catch { /* ignore */ }
    overlay.classList.add('closing');
    setTimeout(() => { overlay.remove(); document.body.style.overflow = ''; }, 220);
    if (message) toast(message, 'info');
  };
  endBtn.addEventListener('click', () => end());
  document.addEventListener('keydown', function onKey(e) {
    if (e.key === 'Escape') { end(); document.removeEventListener('keydown', onKey); }
  });

  let micStream;
  try {
    micStream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } });
  } catch {
    end();
    toast('اجازه میکروفون داده نشد. از تنظیمات مرورگر یا گوشی اجازه میکروفون را بدهید.', 'warning', 6000);
    return;
  }
  muteBtn.addEventListener('click', () => {
    muted = !muted;
    micStream.getAudioTracks().forEach((t) => { t.enabled = !muted; });
    muteBtn.classList.toggle('off', muted);
    muteBtn.replaceChildren(icon(muted ? 'x' : 'mic'));
  });

  let session;
  try {
    session = (await post('/api/assistant/live', { mode })).data;
  } catch (e) {
    micStream.getTracks().forEach((t) => t.stop());
    end();
    toastError(e);
    return;
  }
  maxSeconds = session.max_seconds || 600;

  const startClock = () => {
    startedAt = performance.now();
    tick = setInterval(() => {
      const sec = (performance.now() - startedAt) / 1000;
      timer.textContent = `${clock(sec)} / ${clock(maxSeconds)}`;
      if (sec >= maxSeconds) end('زمان تماس تمام شد.');
    }, 500);
  };

  try {
    stopFn = session.provider === 'openai'
      ? await openAiRealtime(session, micStream, { setState, cap, drawCaptions, startClock, onClose: () => end('تماس قطع شد.') })
      : await geminiLive(session, micStream, { setState, cap, drawCaptions, startClock, onClose: (msg) => end(msg || 'تماس قطع شد.') });
  } catch (e) {
    micStream.getTracks().forEach((t) => t.stop());
    end();
    toast(e?.message || 'اتصال به سرویس صوتی ممکن نشد. اگر در ایران هستید فیلترشکن لازم است.', 'warning', 7000);
  }
}

// ------------------------------------------------------------------ Gemini Live (WebSocket)
async function geminiLive(session, micStream, ui) {
  const Ctx = window.AudioContext || window.webkitAudioContext;
  const ctx = new Ctx();
  await ctx.resume().catch(() => {});
  const ws = new WebSocket(`${session.url}?access_token=${encodeURIComponent(session.token)}`);
  let ready = false;
  let playhead = 0;
  const sources = new Set();
  const outGain = ctx.createGain();
  outGain.connect(ctx.destination);

  // میکروفون ← PCM شانزده‌بیتی ۱۶ کیلوهرتز
  const src = ctx.createMediaStreamSource(micStream);
  const proc = ctx.createScriptProcessor(4096, 1, 1);
  const ratio = ctx.sampleRate / 16000;
  proc.onaudioprocess = (e) => {
    if (!ready || ws.readyState !== WebSocket.OPEN) return;
    const input = e.inputBuffer.getChannelData(0);
    const len = Math.floor(input.length / ratio);
    const pcm = new Int16Array(len);
    for (let i = 0; i < len; i++) {
      const s = Math.max(-1, Math.min(1, input[Math.floor(i * ratio)]));
      pcm[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
    }
    ws.send(JSON.stringify({ realtimeInput: { audio: { data: b64(pcm.buffer), mimeType: 'audio/pcm;rate=16000' } } }));
  };
  const mute = ctx.createGain();
  mute.gain.value = 0;
  src.connect(proc);
  proc.connect(mute);
  mute.connect(ctx.destination);

  const play = (base64, rate = 24000) => {
    const bytes = atob(base64);
    const n = bytes.length >> 1;
    if (!n) return;
    const buf = ctx.createBuffer(1, n, rate);
    const ch = buf.getChannelData(0);
    for (let i = 0; i < n; i++) {
      let v = bytes.charCodeAt(i * 2) | (bytes.charCodeAt(i * 2 + 1) << 8);
      if (v >= 0x8000) v -= 0x10000;
      ch[i] = v / 0x8000;
    }
    const node = ctx.createBufferSource();
    node.buffer = buf;
    node.connect(outGain);
    playhead = Math.max(playhead, ctx.currentTime + 0.03);
    node.start(playhead);
    playhead += buf.duration;
    sources.add(node);
    node.onended = () => { sources.delete(node); if (!sources.size) ui.setState('گوش می‌دهم...', 'listening'); };
    ui.setState('در حال صحبت...', 'speaking');
  };
  const interrupt = () => {
    sources.forEach((s) => { try { s.stop(); } catch { /* ignore */ } });
    sources.clear();
    playhead = 0;
  };

  await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('اتصال به Gemini برقرار نشد. اگر در ایران هستید فیلترشکن لازم است.')), 15000);
    ws.onopen = () => ws.send(JSON.stringify({ setup: session.setup }));
    ws.onerror = () => { clearTimeout(timeout); reject(new Error('اتصال به Gemini برقرار نشد. اگر در ایران هستید فیلترشکن لازم است.')); };
    ws.onmessage = async (ev) => {
      const text = typeof ev.data === 'string' ? ev.data : await ev.data.text();
      let msg;
      try { msg = JSON.parse(text); } catch { return; }
      if (msg.setupComplete && !ready) {
        ready = true;
        clearTimeout(timeout);
        ui.setState('گوش می‌دهم... صحبت کنید', 'listening');
        ui.startClock();
        resolve();
        return;
      }
      const sc = msg.serverContent;
      if (!sc) return;
      if (sc.interrupted) interrupt();
      for (const part of sc.modelTurn?.parts || []) {
        if (part.inlineData?.data) play(part.inlineData.data, Number(/rate=(\d+)/.exec(part.inlineData.mimeType || '')?.[1]) || 24000);
      }
      if (sc.inputTranscription?.text) { ui.cap.user = (sc.inputTranscription.finished ? '' : ui.cap.user) + sc.inputTranscription.text; ui.drawCaptions(); }
      if (sc.outputTranscription?.text) { ui.cap.ai += sc.outputTranscription.text; ui.drawCaptions(); }
      if (sc.turnComplete) { ui.cap.ai = ui.cap.ai.slice(-400); ui.cap.user = ''; }
      if (msg.goAway) ui.onClose('زمان تماس رو به پایان است.');
    };
  });
  ws.onclose = () => ui.onClose();
  ui.cap.ai = '';

  return () => {
    ws.onclose = null;
    try { ws.close(); } catch { /* ignore */ }
    interrupt();
    proc.disconnect();
    src.disconnect();
    micStream.getTracks().forEach((t) => t.stop());
    ctx.close().catch(() => {});
  };
}

// ------------------------------------------------------------------ OpenAI Realtime (WebRTC)
async function openAiRealtime(session, micStream, ui) {
  if (!window.RTCPeerConnection) throw new Error('این مرورگر از تماس صوتی (WebRTC) پشتیبانی نمی‌کند.');
  const pc = new RTCPeerConnection();
  const audio = new Audio();
  audio.autoplay = true;
  pc.ontrack = (e) => { audio.srcObject = e.streams[0]; };
  micStream.getAudioTracks().forEach((t) => pc.addTrack(t, micStream));
  const dc = pc.createDataChannel('oai-events');
  dc.onmessage = (e) => {
    let ev;
    try { ev = JSON.parse(e.data); } catch { return; }
    switch (ev.type) {
      case 'input_audio_buffer.speech_started': ui.setState('گوش می‌دهم...', 'listening'); break;
      case 'response.created': ui.setState('در حال صحبت...', 'speaking'); ui.cap.ai = ''; break;
      case 'response.done': ui.setState('گوش می‌دهم... صحبت کنید', 'listening'); break;
      case 'response.output_audio_transcript.delta':
      case 'response.audio_transcript.delta': ui.cap.ai += ev.delta || ''; ui.drawCaptions(); break;
      case 'conversation.item.input_audio_transcription.completed': ui.cap.user = ev.transcript || ''; ui.drawCaptions(); break;
      default:
    }
  };
  pc.onconnectionstatechange = () => { if (['failed', 'closed', 'disconnected'].includes(pc.connectionState)) ui.onClose(); };

  const offer = await pc.createOffer();
  await pc.setLocalDescription(offer);
  let res;
  try {
    res = await fetch(session.url, { method: 'POST', body: offer.sdp, headers: { Authorization: `Bearer ${session.token}`, 'Content-Type': 'application/sdp' } });
  } catch {
    pc.close();
    throw new Error('اتصال به OpenAI برقرار نشد. اگر در ایران هستید فیلترشکن لازم است.');
  }
  if (!res.ok) {
    pc.close();
    throw new Error('سرویس صوتی OpenAI تماس را نپذیرفت.');
  }
  await pc.setRemoteDescription({ type: 'answer', sdp: await res.text() });
  ui.setState('گوش می‌دهم... صحبت کنید', 'listening');
  ui.startClock();

  return () => {
    pc.onconnectionstatechange = null;
    try { dc.close(); } catch { /* ignore */ }
    pc.getSenders().forEach((s) => s.track?.stop());
    pc.close();
    audio.srcObject = null;
    micStream.getTracks().forEach((t) => t.stop());
  };
}

function b64(buffer) {
  const bytes = new Uint8Array(buffer);
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  return btoa(s);
}
