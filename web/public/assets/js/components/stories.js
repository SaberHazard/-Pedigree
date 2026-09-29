/**
 * استوری‌های هر شخص: عکس و فیلم‌هایی که همان لحظه با دوربین گرفته می‌شوند و برای همیشه می‌مانند.
 *
 * - نوار دایره‌ای زیر سربرگ پروفایل + نمایش تمام‌صفحه با پیشرفت خودکار
 * - گرفتن عکس / ضبط فیلم: روی موبایل با دوربین خود گوشی (input capture)،
 *   روی کامپیوتر با وب‌کم داخل صفحه (MediaRecorder)
 * - افزودن و حذف توسط خود شخص و بستگان درجه یک؛ همه در تاریخچه پروفایل ثبت می‌شود.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, upload, del } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, timeAgo, duration } from '../core/format.js';
import { modal, toast, toastError, confirmDialog, dropdown } from '../core/ui.js';
import { shrinkImage } from '../core/shrink.js';

const PHOTO_SECONDS = 6;
const isTouch = () => matchMedia('(pointer: coarse)').matches;

export function storiesStrip(person, { onChange } = {}) {
  const strip = h('div', { class: 'stories-strip', 'aria-label': 'استوری‌ها' });
  const canAdd = !!person.permissions?.upload;
  let items = [];

  async function load() {
    try {
      items = (await get(`/api/persons/${person.id}/media`, { category: 'story' })).data
        .filter((m) => m.urls && m.status !== 'rejected')
        .sort((a, b) => a.created_at.localeCompare(b.created_at));
    } catch {
      items = [];
    }
    render();
  }

  function render() {
    strip.replaceChildren(...[
      canAdd ? h('button', { class: 'story add', type: 'button', title: 'افزودن استوری', onclick: (e) => addMenu(e.currentTarget) },
        h('span', { class: 'story-ring' }, icon('plus')), h('span', { class: 'story-label' }, 'استوری تازه')) : null,
      ...items.map((m, i) => h('button', { class: `story ${m.status === 'pending' ? 'pending' : ''}`, type: 'button', onclick: () => openViewer(items, i, { onDeleted: load }) },
        h('span', { class: 'story-ring' }, h('img', { src: m.urls.thumb || m.urls.poster || '', alt: m.caption || '', loading: 'lazy' }), m.type === 'video' ? h('i', { class: 'story-video' }, icon('play')) : null),
        h('span', { class: 'story-label' }, m.caption || timeAgo(m.created_at)),
      )),
    ].filter(Boolean));
    strip.hidden = !canAdd && !items.length;
  }

  function addMenu(anchor) {
    const video = store.config.media?.video_enabled !== false;
    dropdown(anchor, [
      { label: 'گرفتن عکس', icon: 'camera', onClick: () => capture('image') },
      video ? { label: 'ضبط فیلم', icon: 'video', onClick: () => capture('video') } : null,
      { label: 'انتخاب از گالری گوشی / فایل', icon: 'upload', onClick: () => pickFile() },
    ].filter(Boolean));
  }

  async function capture(kind) {
    // روی گوشی: دوربین خود دستگاه با کیفیت کامل؛ روی کامپیوتر: وب‌کم داخل صفحه
    if (isTouch() || !navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      return pickFile(kind === 'image' ? 'image/*' : 'video/*', true);
    }
    const blob = await recorder(kind);
    if (blob) await send(blob, kind === 'image' ? 'story.jpg' : `story.${blob.type.includes('mp4') ? 'mp4' : 'webm'}`);
  }

  function pickFile(accept = 'image/*,video/*', useCamera = false) {
    const input = h('input', { type: 'file', accept, hidden: true });
    if (useCamera) input.setAttribute('capture', 'environment');
    input.addEventListener('change', async () => {
      const file = input.files[0];
      input.remove();
      if (file) await send(file, file.name);
    });
    document.body.append(input);
    input.click();
  }

  async function send(blob, name) {
    const caption = await askCaption(blob);
    if (caption === null) return;
    const form = new FormData();
    const ready = blob instanceof File ? await shrinkImage(blob) : blob;
    form.append('file', ready, ready === blob ? name : ready.name);
    form.append('category', 'story');
    if (caption) form.append('caption', caption);
    const t = toastProgress();
    try {
      const res = await upload(`/api/persons/${person.id}/media`, form, (p) => t.update(p));
      t.done();
      toast(res.message, res.data.status === 'approved' ? 'success' : 'info', 6000);
      await load();
      onChange?.();
    } catch (e) {
      t.done();
      toastError(e);
    }
  }

  load();
  return strip;
}

/** پیش‌نمایش و نوشتن عنوان استوری پیش از ارسال */
function askCaption(blob) {
  return new Promise((resolve) => {
    let result = null;
    const src = URL.createObjectURL(blob);
    const isVideo = (blob.type || '').startsWith('video/');
    const input = h('input', { class: 'input', maxlength: 300, placeholder: 'عنوان (اختیاری)، مثلاً «جشن تولد ۸۰ سالگی مادربزرگ»' });
    modal({
      title: 'ارسال استوری',
      body: h('div', null,
        h('div', { class: 'story-preview' }, isVideo ? h('video', { src, controls: true, playsinline: true }) : h('img', { src, alt: '' })),
        h('div', { class: 'field mt' }, input),
        h('p', { class: 'muted small' }, 'استوری برای همیشه در پروفایل می‌ماند. اگر پروفایل متعلق به کس دیگری است، طبق قانون خانواده پس از تأیید نمایش داده می‌شود.'),
      ),
      actions: [
        { label: 'انصراف', class: 'ghost' },
        { label: 'ارسال', class: 'primary', icon: 'upload', onClick: () => { result = input.value.trim(); } },
      ],
      onClose: () => {
        URL.revokeObjectURL(src);
        resolve(result);
      },
    });
  });
}

/** اعلان پیشرفت آپلود */
function toastProgress() {
  const bar = h('i');
  const el = h('div', { class: 'upload-progress', role: 'status' }, h('span', null, 'در حال ارسال...'), h('div', { class: 'progress' }, bar));
  document.body.append(el);
  return {
    update: (percent) => { bar.style.width = `${percent}%`; },
    done: () => el.remove(),
  };
}

/**
 * ضبط با وب‌کم داخل صفحه (کامپیوتر).
 * @returns {Promise<Blob|null>}
 */
function recorder(kind) {
  return new Promise((resolve) => {
    let stream = null;
    let rec = null;
    let chunks = [];
    let result = null;
    let timer = null;
    let facing = 'user';
    const video = h('video', { autoplay: true, muted: true, playsinline: true, class: 'rec-video' });
    const clock = h('span', { class: 'rec-clock', hidden: true });
    const status = h('div', { class: 'muted small' }, 'در حال روشن کردن دوربین...');
    const shoot = h('button', { class: `rec-shutter ${kind}`, type: 'button', title: kind === 'image' ? 'گرفتن عکس' : 'شروع ضبط', disabled: true });
    const flip = h('button', { class: 'btn ghost sm', type: 'button', onclick: () => start(facing === 'user' ? 'environment' : 'user') }, icon('refresh'), 'چرخش دوربین');

    const dlg = modal({
      title: kind === 'image' ? 'گرفتن عکس' : 'ضبط فیلم',
      size: 'wide',
      body: h('div', { class: 'recorder' }, h('div', { class: 'rec-stage' }, video, clock), h('div', { class: 'row between mt-sm' }, flip, shoot, h('span', { style: { width: '90px' } })), status),
      onClose: () => {
        clearInterval(timer);
        if (rec && rec.state !== 'inactive') {
          rec.onstop = null;
          rec.stop();
        }
        stream?.getTracks().forEach((t) => t.stop());
        resolve(result);
      },
    });

    async function start(mode) {
      facing = mode;
      stream?.getTracks().forEach((t) => t.stop());
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: kind === 'video',
        });
        video.srcObject = stream;
        shoot.disabled = false;
        status.textContent = kind === 'image' ? 'آماده؛ دکمه را بزنید.' : 'آماده ضبط؛ برای شروع و پایان دکمه را بزنید.';
      } catch {
        status.textContent = 'دسترسی به دوربین داده نشد. از تنظیمات مرورگر اجازه دوربین را بدهید یا «انتخاب فایل» را بزنید.';
      }
    }

    shoot.addEventListener('click', () => {
      if (kind === 'image') {
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.toBlob((b) => {
          result = b;
          dlg.close();
        }, 'image/jpeg', 0.92);
        return;
      }
      if (rec && rec.state === 'recording') {
        rec.stop();
        return;
      }
      const type = ['video/mp4;codecs=avc1', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm']
        .find((t) => MediaRecorder.isTypeSupported?.(t)) || '';
      chunks = [];
      rec = new MediaRecorder(stream, type ? { mimeType: type, videoBitsPerSecond: 2_500_000 } : undefined);
      rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
      rec.onstop = () => {
        clearInterval(timer);
        result = new Blob(chunks, { type: (rec.mimeType || 'video/webm').split(';')[0] });
        dlg.close();
      };
      rec.start(1000);
      shoot.classList.add('recording');
      const t0 = Date.now();
      clock.hidden = false;
      timer = setInterval(() => { clock.textContent = duration(Math.round((Date.now() - t0) / 1000)); }, 500);
      status.textContent = 'در حال ضبط... برای پایان دوباره بزنید.';
    });

    start(facing);
  });
}

/** نمایش تمام‌صفحه استوری‌ها با پیشرفت خودکار */
export function openViewer(items, index = 0, { onDeleted } = {}) {
  let i = index;
  let timer = null;
  let started = 0;
  const bars = h('div', { class: 'sv-bars' }, ...items.map(() => h('div', { class: 'sv-bar' }, h('i'))));
  const stage = h('div', { class: 'sv-stage' });
  const info = h('div', { class: 'sv-info' });
  const close = () => {
    clearInterval(timer);
    overlay.querySelectorAll('video').forEach((v) => v.pause());
    overlay.remove();
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('hashchange', close);
    document.body.style.overflow = '';
  };
  const overlay = h('div', { class: 'story-viewer', role: 'dialog', 'aria-modal': 'true' },
    bars,
    h('button', { class: 'sv-close icon-btn', type: 'button', 'aria-label': 'بستن', onclick: close }, icon('x')),
    stage,
    h('button', { class: 'sv-nav prev', type: 'button', 'aria-label': 'قبلی', onclick: () => go(i - 1) }),
    h('button', { class: 'sv-nav next', type: 'button', 'aria-label': 'بعدی', onclick: () => go(i + 1) }),
    info,
  );

  function progress(fraction) {
    bars.children[i]?.firstChild && (bars.children[i].firstChild.style.width = `${Math.min(100, fraction * 100)}%`);
  }

  function go(n) {
    clearInterval(timer);
    if (n < 0) n = 0;
    if (n >= items.length) return close();
    i = n;
    [...bars.children].forEach((b, k) => { b.firstChild.style.width = k < i ? '100%' : '0%'; });
    const m = items[i];
    if (m.type === 'video') {
      const v = h('video', { src: m.urls.original, poster: m.urls.poster || undefined, autoplay: true, playsinline: true, controls: false });
      v.addEventListener('timeupdate', () => v.duration && progress(v.currentTime / v.duration));
      v.addEventListener('ended', () => go(i + 1));
      v.addEventListener('click', () => (v.paused ? v.play() : v.pause()));
      stage.replaceChildren(v);
    } else {
      stage.replaceChildren(h('img', { src: m.urls.medium || m.urls.original, alt: m.caption || '' }));
      started = Date.now();
      timer = setInterval(() => {
        const f = (Date.now() - started) / (PHOTO_SECONDS * 1000);
        progress(f);
        if (f >= 1) go(i + 1);
      }, 50);
    }
    info.replaceChildren(...[
      h('div', null,
        m.caption ? h('div', { class: 'bold' }, m.caption) : null,
        h('div', { class: 'tiny' }, [m.uploader?.name, timeAgo(m.created_at), m.status === 'pending' ? 'در انتظار تأیید' : null].filter(Boolean).join(' • ')),
      ),
      m.can?.delete ? h('button', { class: 'btn ghost sm', type: 'button', onclick: async () => {
        clearInterval(timer);
        if (!(await confirmDialog('این استوری حذف شود؟ (حذف در تاریخچه پروفایل ثبت می‌شود)', { danger: true, okLabel: 'حذف' }))) return go(i);
        try {
          await del(`/api/media/${m.id}`);
          toast('استوری حذف شد.');
          close();
          onDeleted?.();
        } catch (e) {
          toastError(e);
        }
      } }, icon('trash'), 'حذف') : null,
    ].filter(Boolean));
  }

  function onKey(e) {
    if (e.key === 'Escape') close();
    // صفحه راست‌به‌چپ: فلش چپ = بعدی
    if (e.key === 'ArrowLeft') go(i + 1);
    if (e.key === 'ArrowRight') go(i - 1);
  }

  document.addEventListener('keydown', onKey);
  window.addEventListener('hashchange', close);
  document.body.append(overlay);
  document.body.style.overflow = 'hidden';
  go(i);
  return { close, count: fa(items.length) };
}
