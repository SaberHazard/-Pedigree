/**
 * تماس صوتی و تصویری مستقیم بین اعضا (WebRTC)، دونفره یا گروهی.
 *
 * صدا و تصویر مستقیم بین مرورگرها/گوشی‌ها رد و بدل می‌شود (رمزگذاری‌شده DTLS-SRTP) و از سرور سایت نمی‌گذرد؛
 * سرور فقط پیام‌های کوچک راه‌اندازی (SDP و نامزدهای ICE) را جابه‌جا می‌کند. در تماس گروهی هر نفر مستقیم به بقیه
 * وصل است (mesh). برای جلوگیری از برخورد، همیشه عضوی که شناسه کوچک‌تر دارد «پیشنهاد» اتصال را می‌فرستد.
 *
 * زنگ خوردن: وقتی سایت یا اپ باز است، هر چند ثانیه یک پرسش سبک (فقط از کش سرور)؛ روی گوشی با پوش هم خبر می‌دهد.
 */
import { h } from './dom.js';
import { icon } from './icons.js';
import { get, post, beacon } from './api.js';
import { store } from './store.js';
import { fa } from './format.js';
import { toast, toastError } from './ui.js';
import { avatar } from '../components/avatar.js';

const RING_POLL_MS = 5000;
const seenRings = new Set();
let ringTimer = null;
let active = null;
let incomingBox = null;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// SDP باید با CRLF تمام شود (اگر میان‌افزاری فاصله پایانی را حذف کرده باشد، دوباره اضافه می‌شود)
const sdpOf = (sdp) => `${String(sdp).replace(/\s+$/, '')}\r\n`;
const clock = (sec) => fa(`${Math.floor(sec / 60)}:${String(Math.floor(sec % 60)).padStart(2, '0')}`);

/** مرورگر تماس مستقیم را پشتیبانی می‌کند و مدیر آن را روشن گذاشته است؟ */
export function callsAvailable() {
  return store.config.calls?.enabled !== false && !!window.RTCPeerConnection && !!navigator.mediaDevices?.getUserMedia;
}

export function inCall() {
  return !!active;
}

/** پرسش دوره‌ای «کسی زنگ می‌زند؟» (فقط وقتی صفحه دیده می‌شود) */
export function startRingWatcher() {
  if (ringTimer || store.config.calls?.enabled === false) return;
  const check = async () => {
    if (document.hidden || active || incomingBox || !store.user || store.user.status === 'pending') return;
    try {
      const res = await get('/api/calls/ring', null, { quiet: true });
      if (res.data && !seenRings.has(res.data.id)) {
        seenRings.add(res.data.id);
        showIncoming(res.data);
      }
    } catch {
      /* دفعه بعد */
    }
  };
  ringTimer = setInterval(check, RING_POLL_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
  check();
}

/** پاسخ به تماسی که با لمس اعلان گوشی آمده (#/calls?answer=...) */
export async function answerFromLink(id) {
  if (!/^[0-9a-z]{26}$/.test(id || '') || active) return;
  try {
    const res = await get('/api/calls/ring', null, { quiet: true });
    if (res.data?.id === id) {
      seenRings.add(id);
      showIncoming(res.data);
    } else {
      toast('این تماس دیگر در جریان نیست.', 'warning');
    }
  } catch (e) {
    toastError(e);
  }
}

/** شروع تماس با یک یا چند عضو (شناسه شخص در شجره‌نامه) */
export async function startCall(personIds, kind = 'audio') {
  if (active) return toast('یک تماس در جریان است.', 'warning');
  if (!callsAvailable()) return toast('این مرورگر یا اپ از تماس مستقیم پشتیبانی نمی‌کند؛ مرورگر را به‌روز کنید.', 'warning');
  const stream = await getMedia(kind);
  if (!stream) return undefined;
  try {
    const res = await post('/api/calls', { person_ids: personIds, kind });
    active = new CallSession(res.data, stream, true);
  } catch (e) {
    stream.getTracks().forEach((t) => t.stop());
    toastError(e);
  }
  return undefined;
}

async function joinCall(id, kind) {
  if (active) return;
  const stream = await getMedia(kind);
  if (!stream) {
    post(`/api/calls/${id}/decline`).catch(() => {});
    return;
  }
  try {
    const res = await post(`/api/calls/${id}/answer`);
    active = new CallSession(res.data, stream, false);
  } catch (e) {
    stream.getTracks().forEach((t) => t.stop());
    toastError(e);
  }
}

async function getMedia(kind) {
  const audio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
  try {
    return await navigator.mediaDevices.getUserMedia({
      audio,
      video: kind === 'video' ? { width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 24, max: 30 }, facingMode: 'user' } : false,
    });
  } catch (e) {
    if (kind === 'video') {
      try {
        const s = await navigator.mediaDevices.getUserMedia({ audio, video: false });
        toast('دوربین در دسترس نبود؛ تماس فقط صوتی است.', 'warning');
        return s;
      } catch {
        /* پایین */
      }
    }
    toast(e?.name === 'NotAllowedError' ? 'برای تماس، اجازه میکروفون (و دوربین) را بدهید.' : 'میکروفون در دسترس نیست.', 'warning', 6000);
    return null;
  }
}

// ------------------------------------------------------------------ زنگ ورودی
function showIncoming(ring) {
  const tone = ringtone([[440, 480]], 2, 4);
  const names = [ring.caller.name, ...ring.others.map((o) => o.name)];
  const close = () => {
    tone.stop();
    clearTimeout(timeout);
    incomingBox?.remove();
    incomingBox = null;
  };
  const accept = (kind) => { close(); joinCall(ring.id, kind); };
  incomingBox = h('div', { class: 'call-overlay incoming', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'تماس ورودی' },
    h('div', { class: 'call-card' },
      h('div', { class: 'call-kind' }, icon(ring.kind === 'video' ? 'video' : 'call'), ring.kind === 'video' ? ' تماس تصویری' : ' تماس صوتی', ring.others.length ? ' گروهی' : ''),
      h('div', { class: 'call-ring-avatar' }, avatar(ring.caller.person || { first_name: ring.caller.name }, 'xl', { ring: false })),
      h('b', { class: 'call-name' }, ring.caller.name),
      ring.others.length ? h('div', { class: 'muted small' }, 'با ', names.slice(1).join('، ')) : null,
      h('div', { class: 'call-actions' },
        h('button', { class: 'call-btn end', type: 'button', title: 'رد تماس', 'aria-label': 'رد تماس', onclick: () => { close(); post(`/api/calls/${ring.id}/decline`).catch(() => {}); } }, icon('phone-off')),
        ring.kind === 'video' ? h('button', { class: 'call-btn', type: 'button', title: 'پاسخ فقط با صدا', 'aria-label': 'پاسخ صوتی', onclick: () => accept('audio') }, icon('call')) : null,
        h('button', { class: 'call-btn accept', type: 'button', title: 'پاسخ', 'aria-label': 'پاسخ', onclick: () => accept(ring.kind) }, icon(ring.kind === 'video' ? 'video' : 'call'))),
    ));
  document.body.append(incomingBox);
  if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
    try { new Notification(`📞 ${ring.caller.name}`, { body: 'تماس ورودی', tag: `call-${ring.id}`, requireInteraction: true }); } catch { /* ignore */ }
  }
  const timeout = setTimeout(close, Math.max(5, ring.expires_in) * 1000);
}

/**
 * صدای زنگ/انتظار با WebAudio (بدون فایل صوتی)
 * @param {number[][]} chords فرکانس‌ها
 * @param {number} on ثانیه صدا
 * @param {number} off ثانیه سکوت
 */
function ringtone(chords, on, off) {
  let ctx = null;
  let timer = null;
  try {
    ctx = new (window.AudioContext || window.webkitAudioContext)();
    const play = () => {
      if (!ctx) return;
      const t = ctx.currentTime;
      for (const f of chords[0]) {
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.12, t + 0.05);
        g.gain.setValueAtTime(0.12, t + on - 0.05);
        g.gain.exponentialRampToValueAtTime(0.0001, t + on);
        o.connect(g).connect(ctx.destination);
        o.start(t);
        o.stop(t + on + 0.02);
      }
    };
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    play();
    timer = setInterval(play, (on + off) * 1000);
  } catch {
    /* بدون صدا */
  }
  return {
    stop() {
      clearInterval(timer);
      ctx?.close().catch(() => {});
      ctx = null;
    },
  };
}

// ------------------------------------------------------------------ جلسه تماس
class CallSession {
  constructor(state, stream, outgoing) {
    this.id = state.id;
    this.me = state.me;
    this.kind = state.kind;
    this.ice = state.ice || { servers: [], relay_only: false };
    this.stream = stream;
    this.outgoing = outgoing;
    this.peers = new Map();
    this.after = 0;
    this.ended = false;
    this.failures = 0;
    this.connectedAt = 0;
    this.everJoined = false;
    this.state = state;
    this.ringback = outgoing ? ringtone([[425]], 1, 3) : null;
    this.wakeLock = null;
    this.onUnload = () => beacon(`/api/calls/${this.id}/leave`);
    window.addEventListener('pagehide', this.onUnload);
    try { navigator.wakeLock?.request('screen').then((l) => { this.wakeLock = l; }).catch(() => {}); } catch { /* ignore */ }
    this.buildUi();
    this.update(state);
    this.loop();
    this.tick = setInterval(() => this.renderStatus(), 1000);
  }

  rtcConfig() {
    return { iceServers: this.ice.servers || [], iceTransportPolicy: this.ice.relay_only ? 'relay' : 'all', bundlePolicy: 'max-bundle' };
  }

  async loop() {
    while (!this.ended) {
      try {
        const res = await get(`/api/calls/${this.id}/poll?after=${this.after}`, null, { quiet: true });
        this.failures = 0;
        for (const s of res.signals) {
          this.after = Math.max(this.after, s.id);
          await this.onSignal(s);
        }
        this.update(res.call);
      } catch (e) {
        if (e?.status === 404 || e?.status === 410) {
          this.finish('تماس تمام شد');
          break;
        }
        if (++this.failures >= 12) {
          this.finish('ارتباط با سرور قطع شد');
          break;
        }
      }
      const settling = [...this.peers.values()].some((p) => !['connected', 'completed'].includes(p.pc.iceConnectionState));
      await sleep(settling || this.peers.size === 0 ? 900 : 2500);
    }
  }

  update(call) {
    if (this.ended || !call) return;
    this.state = call;
    const others = call.participants.filter((p) => p.user_id !== this.me);
    if (others.some((p) => p.state === 'joined')) this.everJoined = true;
    if (call.status === 'ended') {
      let reason = 'تماس تمام شد';
      if (this.outgoing && !this.everJoined) {
        reason = others.some((p) => p.state === 'declined') ? 'تماس رد شد' : 'پاسخی نداد';
      }
      this.finish(reason);
      return;
    }
    for (const p of others) {
      if (p.state === 'joined' && p.online && !this.peers.has(p.user_id)) this.connectTo(p);
      if (this.peers.has(p.user_id) && ['left', 'declined', 'missed'].includes(p.state)) this.dropPeer(p.user_id);
    }
    if (this.everJoined && this.ringback) {
      this.ringback.stop();
      this.ringback = null;
    }
    this.render();
  }

  connectTo(p) {
    const pc = new RTCPeerConnection(this.rtcConfig());
    const peer = { uid: p.user_id, info: p, pc, remote: new MediaStream(), queue: [], out: [], flush: null, tile: null, media: null };
    this.stream.getTracks().forEach((t) => pc.addTrack(t, this.stream));
    pc.ontrack = (e) => {
      if (!peer.remote.getTracks().includes(e.track)) peer.remote.addTrack(e.track);
      this.attach(peer);
    };
    pc.onicecandidate = (e) => {
      if (!e.candidate) return;
      peer.out.push(e.candidate.toJSON());
      clearTimeout(peer.flush);
      peer.flush = setTimeout(() => this.send(peer.uid, 'candidates', { list: peer.out.splice(0) }), 250);
    };
    pc.oniceconnectionstatechange = () => {
      const st = pc.iceConnectionState;
      if ((st === 'connected' || st === 'completed') && !this.connectedAt) this.connectedAt = Date.now();
      if (st === 'failed' && this.me < peer.uid) this.offer(peer, true);
      this.render();
    };
    this.peers.set(p.user_id, peer);
    if (this.me < p.user_id) this.offer(peer, false);
    return peer;
  }

  async offer(peer, restart) {
    try {
      const offer = await peer.pc.createOffer({ iceRestart: restart });
      await peer.pc.setLocalDescription(offer);
      this.send(peer.uid, 'offer', { sdp: peer.pc.localDescription.sdp });
    } catch {
      /* دفعه بعد */
    }
  }

  async onSignal(s) {
    let peer = this.peers.get(s.from);
    if (!peer && s.type === 'offer') {
      const info = this.state.participants.find((p) => p.user_id === s.from) || { user_id: s.from, name: 'عضو' };
      peer = this.connectTo(info);
    }
    if (!peer || !s.data) return;
    try {
      if (s.type === 'offer' && typeof s.data.sdp === 'string') {
        await peer.pc.setRemoteDescription({ type: 'offer', sdp: sdpOf(s.data.sdp) });
        await this.flushQueue(peer);
        const answer = await peer.pc.createAnswer();
        await peer.pc.setLocalDescription(answer);
        this.send(peer.uid, 'answer', { sdp: peer.pc.localDescription.sdp });
      } else if (s.type === 'answer' && typeof s.data.sdp === 'string' && peer.pc.signalingState === 'have-local-offer') {
        await peer.pc.setRemoteDescription({ type: 'answer', sdp: sdpOf(s.data.sdp) });
        await this.flushQueue(peer);
      } else if (s.type === 'candidates' && Array.isArray(s.data.list)) {
        for (const c of s.data.list.slice(0, 50)) {
          if (peer.pc.remoteDescription) await peer.pc.addIceCandidate(c).catch(() => {});
          else peer.queue.push(c);
        }
      } else if (s.type === 'bye') {
        this.dropPeer(peer.uid);
      }
    } catch {
      /* پیام نامعتبر؛ نادیده */
    }
  }

  async flushQueue(peer) {
    for (const c of peer.queue.splice(0)) await peer.pc.addIceCandidate(c).catch(() => {});
  }

  send(to, type, data) {
    post(`/api/calls/${this.id}/signal`, { to, type, data }, { quiet: true }).catch(() => {});
  }

  dropPeer(uid) {
    const peer = this.peers.get(uid);
    if (!peer) return;
    clearTimeout(peer.flush);
    try { peer.pc.close(); } catch { /* ignore */ }
    peer.tile?.remove();
    this.peers.delete(uid);
    this.render();
  }

  // -------------------------------------------------------------- رابط کاربری
  buildUi() {
    const video = this.kind === 'video';
    this.stage = h('div', { class: `call-stage${video ? ' video' : ''}` });
    this.local = video ? h('video', { class: 'call-local', autoplay: true, muted: true, playsinline: true }) : null;
    if (this.local) {
      this.local.srcObject = this.stream;
      this.local.muted = true;
    }
    this.title = h('b', { class: 'call-name' });
    this.status = h('div', { class: 'call-status' });
    const mic = h('button', { class: 'call-btn', type: 'button', title: 'قطع/وصل میکروفون', 'aria-label': 'میکروفون' }, icon('mic'));
    mic.addEventListener('click', () => {
      const track = this.stream.getAudioTracks()[0];
      if (!track) return;
      track.enabled = !track.enabled;
      mic.classList.toggle('off', !track.enabled);
      mic.replaceChildren(icon(track.enabled ? 'mic' : 'mic-off'));
    });
    const cam = video ? h('button', { class: 'call-btn', type: 'button', title: 'روشن/خاموش کردن دوربین', 'aria-label': 'دوربین' }, icon('video')) : null;
    cam?.addEventListener('click', () => {
      const track = this.stream.getVideoTracks()[0];
      if (!track) return;
      track.enabled = !track.enabled;
      cam.classList.toggle('off', !track.enabled);
      cam.replaceChildren(icon(track.enabled ? 'video' : 'video-off'));
    });
    const flip = video && this.stream.getVideoTracks().length ? h('button', { class: 'call-btn', type: 'button', title: 'دوربین جلو/عقب', 'aria-label': 'تعویض دوربین', onclick: () => this.flipCamera() }, icon('switch-camera')) : null;
    const end = h('button', { class: 'call-btn end', type: 'button', title: 'پایان تماس', 'aria-label': 'پایان تماس', onclick: () => this.hangup() }, icon('phone-off'));
    this.overlay = h('div', { class: `call-overlay live${video ? ' is-video' : ''}`, role: 'dialog', 'aria-modal': 'true', 'aria-label': 'تماس' },
      h('div', { class: 'call-card live' },
        h('div', { class: 'call-head' }, this.title, this.status),
        this.stage,
        this.local,
        h('div', { class: 'call-actions' }, mic, cam, flip, end),
        h('p', { class: 'muted tiny call-note' }, icon('lock'), this.ice.relay_only
          ? ' صدا و تصویر رمزگذاری‌شده از رله تماس سایت می‌گذرد و ذخیره نمی‌شود.'
          : ' صدا و تصویر رمزگذاری‌شده و مستقیم بین گوشی‌ها می‌رود؛ از سرور سایت نمی‌گذرد و ذخیره نمی‌شود.')));
    document.body.append(this.overlay);
    document.body.style.overflow = 'hidden';
  }

  async flipCamera() {
    const current = this.stream.getVideoTracks()[0];
    if (!current) return;
    const facing = current.getSettings().facingMode === 'environment' ? 'user' : 'environment';
    try {
      const fresh = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
      const track = fresh.getVideoTracks()[0];
      for (const peer of this.peers.values()) {
        const sender = peer.pc.getSenders().find((s) => s.track?.kind === 'video');
        await sender?.replaceTrack(track);
      }
      this.stream.removeTrack(current);
      current.stop();
      this.stream.addTrack(track);
      if (this.local) this.local.srcObject = this.stream;
    } catch {
      toast('دوربین دیگری در دسترس نیست.', 'warning');
    }
  }

  attach(peer) {
    const hasVideo = peer.remote.getVideoTracks().length > 0;
    if (!peer.media || (hasVideo && peer.media.tagName !== 'VIDEO')) {
      peer.media?.remove();
      peer.media = h(hasVideo ? 'video' : 'audio', { autoplay: true, playsinline: true, class: hasVideo ? 'call-remote' : '' });
      peer.media.srcObject = peer.remote;
    }
    peer.media.play?.().catch(() => {});
    this.render();
  }

  render() {
    if (this.ended) return;
    const others = this.state.participants.filter((p) => p.user_id !== this.me);
    this.title.textContent = others.map((p) => p.name).join('، ');
    for (const p of others) {
      const peer = this.peers.get(p.user_id);
      if (!peer) continue;
      if (!peer.tile) {
        peer.tile = h('div', { class: 'call-tile' });
        peer.shown = undefined;
        this.stage.append(peer.tile);
      }
      // محتوای کاشی فقط وقتی صدا/تصویر طرف مقابل عوض شد دوباره ساخته می‌شود (پخش قطع نشود)
      if (peer.shown !== (peer.media || null)) {
        peer.shown = peer.media || null;
        const video = peer.media?.tagName === 'VIDEO';
        peer.tile.classList.toggle('has-video', video);
        peer.tile.replaceChildren(...[
          peer.media,
          video ? null : h('div', { class: 'call-tile-avatar' }, avatar(p.person || { first_name: p.name }, 'lg', { ring: false })),
          h('span', { class: 'call-tile-name' }, p.name),
        ].filter(Boolean));
      }
      peer.tile.classList.toggle('connecting', !['connected', 'completed'].includes(peer.pc.iceConnectionState));
    }
    // کسانی که هنوز جواب نداده‌اند
    const waiting = others.filter((p) => p.state === 'invited');
    if (!this.peers.size) {
      if (!this.stage.querySelector('.call-waiting')) {
        this.stage.replaceChildren(h('div', { class: 'call-waiting' }, ...others.slice(0, 4).map((p) => avatar(p.person || { first_name: p.name }, 'xl', { ring: false }))));
      }
    } else {
      this.stage.querySelector('.call-waiting')?.remove();
    }
    this.stage.dataset.count = String(Math.max(1, this.peers.size));
    this.waitingCount = waiting.length;
    this.renderStatus();
  }

  renderStatus() {
    if (this.ended) return;
    const connected = [...this.peers.values()].some((p) => ['connected', 'completed'].includes(p.pc.iceConnectionState));
    let text;
    if (connected) text = clock((Date.now() - this.connectedAt) / 1000);
    else if (this.peers.size) text = 'در حال اتصال...';
    else text = this.outgoing ? 'در حال زنگ خوردن...' : 'در حال اتصال...';
    if (connected && this.waitingCount) text += ` • منتظر ${fa(this.waitingCount)} نفر`;
    this.status.textContent = text;
  }

  hangup() {
    // اول «خداحافظ» به هر نفر (قطع فوری طرف مقابل)، بعد خروج از تماس
    const byes = [...this.peers.keys()].map((to) => post(`/api/calls/${this.id}/signal`, { to, type: 'bye', data: {} }, { quiet: true }).catch(() => {}));
    Promise.allSettled(byes).then(() => post(`/api/calls/${this.id}/leave`, {}, { quiet: true }).catch(() => {}));
    this.finish('تماس تمام شد', true);
  }

  finish(reason, silent = false) {
    if (this.ended) return;
    this.ended = true;
    clearInterval(this.tick);
    this.ringback?.stop();
    window.removeEventListener('pagehide', this.onUnload);
    this.wakeLock?.release?.().catch(() => {});
    for (const peer of this.peers.values()) {
      clearTimeout(peer.flush);
      try { peer.pc.close(); } catch { /* ignore */ }
    }
    this.peers.clear();
    this.stream.getTracks().forEach((t) => t.stop());
    this.overlay.classList.add('closing');
    setTimeout(() => {
      this.overlay.remove();
      if (!document.querySelector('.overlay, .call-overlay')) document.body.style.overflow = '';
    }, 220);
    if (!silent || reason !== 'تماس تمام شد') toast(reason, reason === 'تماس تمام شد' ? 'success' : 'warning');
    active = null;
    store.emit('call-ended', this.id);
  }
}
