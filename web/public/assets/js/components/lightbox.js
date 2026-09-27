/**
 * نمایشگر تمام‌صفحه عکس و ویدیو (با کلیدهای جهت‌نما و کشیدن انگشت)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { fa, formatDate } from '../core/format.js';
import { saveFile } from '../core/native.js';

export function openLightbox(items, index = 0) {
  let i = index;
  const stage = h('div', { class: 'lb-stage' });
  const caption = h('div', { class: 'grow' });
  const counter = h('span', { class: 'small', style: { opacity: 0.7 } });
  const prev = h('button', { class: 'icon-btn lb-nav lb-prev', type: 'button', title: 'قبلی', onclick: () => go(-1) }, icon('chevron-right'));
  const next = h('button', { class: 'icon-btn lb-nav lb-next', type: 'button', title: 'بعدی', onclick: () => go(1) }, icon('chevron-left'));
  const download = h('button', { class: 'icon-btn', type: 'button', title: 'دانلود', onclick: save }, icon('download'));
  const el = h('div', { class: 'lightbox', role: 'dialog', 'aria-modal': 'true' },
    stage, prev, next,
    h('div', { class: 'lb-bar' }, caption, counter, download, h('button', { class: 'icon-btn', type: 'button', title: 'بستن', onclick: close }, icon('x'))),
  );

  function render() {
    const m = items[i];
    stage.replaceChildren(prev, next);
    if (m.type === 'video') {
      if (m.processing !== 'ready' && m.processing !== 'failed') {
        stage.append(h('div', { class: 'center' }, h('div', { class: 'spinner', style: { margin: '0 auto 12px' } }), 'ویدیو در حال پردازش است...'));
      } else {
        stage.append(h('video', { src: m.urls?.original, poster: m.urls?.poster || null, controls: true, autoplay: true, playsinline: true, preload: 'metadata' }));
      }
    } else {
      stage.append(h('img', { src: m.urls?.medium || m.urls?.original, alt: m.caption || '' }));
    }
    caption.replaceChildren(
      h('div', { class: 'bold' }, m.caption || ''),
      h('div', { class: 'small', style: { opacity: 0.7 } }, [m.taken_at ? formatDate(m.taken_at) : null, m.uploader ? `آپلود: ${m.uploader.name}` : null].filter(Boolean).join(' • ')),
    );
    counter.textContent = items.length > 1 ? `${fa(i + 1)} از ${fa(items.length)}` : '';
    prev.hidden = next.hidden = items.length < 2;
  }

  function go(d) {
    i = (i + d + items.length) % items.length;
    render();
  }

  async function save() {
    const m = items[i];
    const res = await fetch(m.urls.original, { credentials: 'same-origin' });
    const blob = await res.blob();
    const ext = m.type === 'video' ? 'mp4' : 'webp';
    await saveFile(blob, `${(m.caption || 'media').replace(/[\\/:*?"<>|]/g, '')}.${ext}`);
  }

  function close() {
    el.remove();
    document.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
  }

  function onKey(e) {
    if (e.key === 'Escape') close();
    if (e.key === 'ArrowLeft') go(1);
    if (e.key === 'ArrowRight') go(-1);
  }

  // کشیدن انگشت برای رفتن به بعدی/قبلی
  let sx = null;
  stage.addEventListener('pointerdown', (e) => (sx = e.clientX));
  stage.addEventListener('pointerup', (e) => {
    if (sx !== null && Math.abs(e.clientX - sx) > 60) go(e.clientX < sx ? 1 : -1);
    sx = null;
  });
  el.addEventListener('click', (e) => e.target === stage && close());

  document.addEventListener('keydown', onKey);
  document.body.style.overflow = 'hidden';
  document.body.append(el);
  render();
}
