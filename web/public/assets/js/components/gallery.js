/**
 * گالری عکس و ویدیوی یک شخص + آپلود (کشیدن و رها کردن) + رأی‌گیری درون‌خطی
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, upload, post, del, patch, put } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, fileSize, duration } from '../core/format.js';
import { toast, toastError, modal, field, confirmDialog, emptyState, dropdown } from '../core/ui.js';
import { openLightbox } from './lightbox.js';
import { dateInput } from './date-input.js';
import { shrinkImage } from '../core/shrink.js';
import { authorLink } from './author.js';
import { openPhotoReader } from './photo-reader.js';

const STATUS = {
  pending: ['warning', 'در انتظار تأیید'],
  approved: ['success', 'تأیید شده'],
  rejected: ['danger', 'رد شده'],
};

export function gallery(person, { onChange } = {}) {
  const grid = h('div', { class: 'gallery' });
  const el = h('div');
  const canUpload = person.permissions?.upload;
  const cfg = store.config.media || {};

  async function load() {
    grid.replaceChildren(...Array.from({ length: 4 }, () => h('div', { class: 'g-item skeleton' })));
    try {
      const res = await get(`/api/persons/${person.id}/media`);
      render(res.data);
    } catch (e) {
      grid.replaceChildren(emptyState('alert', e.message));
    }
  }

  function render(items) {
    const viewable = items.filter((m) => m.status !== 'rejected' && m.urls);
    if (!items.length) {
      grid.replaceChildren();
      if (!canUpload) grid.append(h('div', { style: { gridColumn: '1/-1' } }, emptyState('image', 'هنوز عکس یا ویدیویی ثبت نشده است.')));
      return;
    }
    grid.replaceChildren(...items.map((m) => {
      const [cls, label] = STATUS[m.status] || STATUS.pending;
      const idx = viewable.indexOf(m);
      const item = h('div', { class: `g-item ${m.status === 'pending' ? 'pending' : ''}`, title: m.caption || '' },
        m.urls?.thumb ? h('img', { src: m.urls.thumb, alt: m.caption || '', loading: 'lazy' }) : h('div', { class: 'g-play', style: { background: 'var(--surface-3)', color: 'var(--muted)' } }, icon('video')),
        m.type === 'video' ? h('div', { class: 'g-play' }, icon('play'), m.duration ? h('span', { class: 'chip', style: { position: 'absolute', bottom: '8px', insetInlineEnd: '8px' } }, duration(m.duration)) : null) : null,
        m.status !== 'approved' ? h('span', { class: `chip ${cls} g-badge` }, label) : m.is_avatar ? h('span', { class: 'chip primary g-badge' }, 'پروفایل') : m.category === 'memory' ? h('span', { class: 'chip g-badge', title: 'در گروه خاندان گذاشته شده' }, '📜 خاطره') : null,
        h('div', { class: 'g-caption' },
          m.caption ? h('div', { class: 'ellipsis' }, m.caption) : null,
          authorLink(m.uploader, { cls: 'g-author' })),
      );
      item.addEventListener('click', () => (idx >= 0 ? openLightbox(viewable, idx) : null));
      item.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        menu(item, m);
      });
      if (m.can.edit || m.can.delete || m.can.set_avatar || m.can.vote) {
        const more = h('button', { class: 'icon-btn', type: 'button', title: 'گزینه‌ها', style: { position: 'absolute', top: '4px', insetInlineEnd: '4px', background: 'rgba(0,0,0,.45)', color: '#fff', width: '32px', height: '32px' }, onclick: (e) => { e.stopPropagation(); menu(more, m); } }, icon('more'));
        item.append(more);
      }
      return item;
    }));
  }

  function menu(anchor, m) {
    dropdown(anchor, [
      m.can.vote ? { label: 'رأی موافق', icon: 'thumbs-up', onClick: () => vote(m, 'approve') } : null,
      m.can.vote ? { label: 'رأی مخالف', icon: 'thumbs-down', onClick: () => vote(m, 'reject') } : null,
      m.can.set_avatar && !m.is_avatar ? { label: 'انتخاب به عنوان عکس پروفایل', icon: 'user', onClick: () => setAvatar(m) } : null,
      m.can.edit ? { label: 'ویرایش توضیحات', icon: 'edit', onClick: () => editMeta(m) } : null,
      store.config.assistant?.restore && m.type === 'image' && m.status === 'approved' && canUpload
        ? { label: 'بازسازی / رنگی کردن با هوش مصنوعی', icon: 'wand', onClick: () => restore(m) } : null,
      store.config.assistant?.reader && m.type === 'image'
        ? { label: 'توضیح عکس یا خواندن دست‌خط با هوش مصنوعی', icon: 'sparkles', onClick: () => openPhotoReader(m, { onSaved: () => load() }) } : null,
      m.status === 'pending' ? { label: `رأی‌ها: ${fa(m.votes.approve)} موافق، ${fa(m.votes.reject)} مخالف از ${fa(m.votes.total)}`, icon: 'info' } : null,
      m.can.delete ? 'sep' : null,
      m.can.delete ? { label: 'حذف', icon: 'trash', danger: true, onClick: () => remove(m) } : null,
    ].filter(Boolean));
  }

  /** زنده کردن عکس قدیمی: نسخه بازسازی‌شده یا رنگی به عنوان عکس تازه ذخیره می‌شود */
  function restore(m) {
    let mode = 'both';
    const options = [['restore', 'بازسازی', 'رفع خط و خش، لک، پارگی و تاری'], ['colorize', 'رنگی کردن', 'برای عکس سیاه‌وسفید'], ['both', 'هر دو', 'بازسازی و رنگی کردن']];
    const box = h('div', { class: 'tpl-list', role: 'radiogroup' });
    const draw = () => box.replaceChildren(...options.map(([key, label, hint]) => h('button', {
      type: 'button', class: `tpl ${key === mode ? 'active' : ''}`, role: 'radio', 'aria-checked': key === mode ? 'true' : 'false',
      onclick: () => { mode = key; draw(); },
    }, h('b', { class: 'small' }, label), h('div', { class: 'tpl-text' }, hint))));
    draw();
    modal({
      title: '✨ زنده کردن عکس قدیمی',
      body: h('div', null,
        m.urls?.thumb ? h('img', { src: m.urls.medium || m.urls.thumb, alt: '', style: { width: '100%', maxHeight: '220px', objectFit: 'contain', borderRadius: '12px', background: 'var(--surface-3)' } }) : null,
        h('div', { class: 'mt-sm' }, box),
        h('p', { class: 'muted tiny mt-sm' }, icon('info'), ' این عکس برای سرویس هوش مصنوعی Google (Gemini) فرستاده می‌شود. عکس اصلی دست نمی‌خورد و نسخه تازه کنارش در گالری ذخیره می‌شود. ساخت آن ممکن است تا یک دقیقه طول بکشد.'),
      ),
      actions: [{ label: 'انصراف' }, { label: 'بساز', class: 'primary', icon: 'wand', onClick: async () => {
        try {
          const res = await post(`/api/media/${m.id}/restore`, { mode });
          toast(res.message, 'success', 6000);
          onChange?.();
          load();
          return true;
        } catch (e) {
          toastError(e);
          return false;
        }
      } }],
    });
  }

  async function vote(m, decision) {
    try {
      const res = await post(`/api/media/${m.id}/vote`, { decision });
      toast(res.data.status === 'pending' ? 'رأی شما ثبت شد.' : res.data.status === 'approved' ? 'با رأی شما فایل تأیید و منتشر شد.' : 'فایل رد شد.');
      load();
      store.refreshCounters?.();
    } catch (e) {
      toastError(e);
    }
  }

  async function setAvatar(m) {
    try {
      await patchAvatar(person.id, m.id);
      toast('عکس پروفایل تغییر کرد.');
      onChange?.();
    } catch (e) {
      toastError(e);
    }
  }

  function editMeta(m) {
    const caption = h('input', { class: 'input', value: m.caption || '' });
    const desc = h('textarea', { class: 'input', rows: 3 }, m.description || '');
    const taken = dateInput('taken_at', m.taken_at || '');
    modal({
      title: 'ویرایش توضیحات',
      body: h('div', null, field('عنوان', caption), h('div', { class: 'field' }, h('label', null, 'تاریخ عکس'), taken), field('توضیحات', desc)),
      actions: [
        { label: 'انصراف', class: 'ghost' },
        { label: 'ذخیره', class: 'primary', onClick: async () => {
          await patch(`/api/media/${m.id}`, { caption: caption.value, description: desc.value, taken_at: taken.value || null });
          load();
        } },
      ],
    });
  }

  async function remove(m) {
    if (!(await confirmDialog('این فایل حذف شود؟', { danger: true, okLabel: 'حذف' }))) return;
    try {
      await del(`/api/media/${m.id}`);
      toast('حذف شد.');
      load();
      onChange?.();
    } catch (e) {
      toastError(e);
    }
  }

  // ------------------------------------------------------------ آپلود
  let dropzone = null;
  if (canUpload) {
    const accept = cfg.accept || 'image/*,video/*';
    const input = h('input', { type: 'file', accept, multiple: true, hidden: true, onchange: () => handleFiles([...input.files]) });
    const progress = h('div', { class: 'col', style: { marginTop: '10px' } });
    dropzone = h('div', { class: 'dropzone', role: 'button', tabindex: 0, onclick: () => input.click(), onkeydown: (e) => e.key === 'Enter' && input.click() },
      icon('upload'),
      h('div', { class: 'bold' }, 'افزودن عکس یا ویدیو'),
      h('div', { class: 'small' }, `کشیدن و رها کردن فایل یا کلیک • هر قالب عکس (حتی HEIC آیفون)${cfg.video_enabled ? ` • ویدیو تا ${fileSize((cfg.video_max_kb || 512000) * 1024)}` : ''}`),
      h('div', { class: 'tiny', style: { marginTop: '6px' } }, 'عکس‌ها و ویدیوها مثل تلگرام بدون افت محسوس کیفیت فشرده می‌شوند. فایل‌هایی که برای دیگران آپلود می‌کنید پس از تأیید بستگان نمایش داده می‌شوند.'),
      input,
    );
    ['dragenter', 'dragover'].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.remove('drag'); }));
    dropzone.addEventListener('drop', (e) => handleFiles([...e.dataTransfer.files]));
    el.append(dropzone, progress);

    async function handleFiles(files) {
      for (const file of files) {
        const bar = h('span', { style: { width: '0%' } });
        const label = h('div', { class: 'small ellipsis' }, file.name);
        const row = h('div', { class: 'card', style: { padding: '10px 14px' } }, label, h('div', { class: 'progress', style: { marginTop: '6px' } }, bar));
        progress.append(row);
        // پیش از آپلود: بررسی حجم و کوچک کردن عکس‌های بزرگ در خود مرورگر
        const isVideo = file.type.startsWith('video/') || /\.(mkv|avi|wmv|flv|3gp|mts|m2ts|ts|mov)$/i.test(file.name);
        const maxKb = isVideo ? cfg.video_max_kb || 512000 : cfg.image_max_kb || 51200;
        const reject = (message) => {
          label.textContent = `${file.name}: ${message}`;
          label.style.color = 'var(--danger)';
          setTimeout(() => row.remove(), 8000);
        };
        if (isVideo && !cfg.video_enabled) {
          reject('آپلود ویدیو فعلاً مجاز نیست.');
          continue;
        }
        const ready = isVideo ? file : await shrinkImage(file);
        if (ready.size > maxKb * 1024) {
          reject(`حجم فایل بیشتر از ${fileSize(maxKb * 1024)} است.`);
          continue;
        }
        const form = new FormData();
        form.append('file', ready);
        try {
          const res = await upload(`/api/persons/${person.id}/media`, form, (p) => {
            bar.style.width = p + '%';
            if (p === 100) label.textContent = `${file.name} - در حال پردازش و فشرده‌سازی...`;
          });
          toast(res.message || 'آپلود شد.', res.data?.status === 'approved' ? 'success' : 'info', 6000);
          row.remove();
        } catch (e) {
          label.textContent = `${file.name}: ${e.message}`;
          label.style.color = 'var(--danger)';
          bar.style.background = 'var(--danger)';
          setTimeout(() => row.remove(), 8000);
        }
      }
      input.value = '';
      load();
    }
  }

  el.append(h('div', { class: 'mt' }, grid));
  load();
  el.reload = load;
  return el;
}

export function patchAvatar(personId, mediaId) {
  return put(`/api/persons/${personId}/avatar`, { media_id: mediaId });
}
