/**
 * خواندن عکس و سند با هوش مصنوعی: توضیح عکس و حدس دهه، یا خواندن متن و دست‌خط (سند، نامه، قباله، پشت‌نویس عکس).
 * کاربر پیش از ارسال می‌داند که همین عکس برای سرویس هوش مصنوعی فرستاده می‌شود؛ نتیجه ذخیره نمی‌شود مگر
 * خودش آن را به توضیح عکس اضافه کند (با نام خودش).
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { post, patch } from '../core/api.js';
import { fa } from '../core/format.js';
import { modal, toast, toastError, loader, withLoading } from '../core/ui.js';

const TASKS = [
  ['describe', 'توضیح عکس', 'چه کسانی، کجا، چه حال و هوایی و حدود چه دهه‌ای', 'image'],
  ['transcribe', 'خواندن متن و دست‌خط', 'سند، نامه، قباله، شناسنامه قدیمی، پشت‌نویس عکس', 'file'],
];

export function openPhotoReader(media, { onSaved } = {}) {
  let task = 'describe';
  const result = h('div', { class: 'ai-read-result' });
  const choices = h('div', { class: 'tpl-list', role: 'radiogroup' });
  const drawChoices = () => choices.replaceChildren(...TASKS.map(([key, label, hint, ic]) => h('button', {
    type: 'button', class: `tpl ${key === task ? 'active' : ''}`, role: 'radio', 'aria-checked': String(key === task),
    onclick: () => { task = key; drawChoices(); },
  }, h('b', { class: 'small' }, icon(ic), ' ', label), h('div', { class: 'tpl-text' }, hint))));
  drawChoices();
  const run = h('button', { class: 'btn primary', type: 'button' }, icon('sparkles'), 'بخوان');

  const box = modal({
    title: '✨ خواندن عکس با هوش مصنوعی',
    size: 'wide',
    body: h('div', null,
      h('div', { class: 'ai-read-grid' },
        h('img', { src: media.urls?.medium || media.urls?.thumb, alt: media.caption || '', class: 'ai-read-img' }),
        h('div', null,
          choices,
          h('p', { class: 'muted tiny mt-sm' }, icon('info'), ' همین عکس برای سرویس هوش مصنوعی سایت فرستاده می‌شود و نتیجه جایی ذخیره نمی‌شود. هوش مصنوعی ممکن است اشتباه کند؛ نتیجه را با دقت بخوانید.'),
          run)),
      result,
    ),
    actions: [{ label: 'بستن' }],
  });

  run.addEventListener('click', () => withLoading(run, async () => {
    result.replaceChildren(loader());
    try {
      const res = (await post(`/api/media/${media.id}/ai/read`, { task })).data;
      const text = h('textarea', { class: 'input', rows: 9, dir: 'auto' }, res.text);
      result.replaceChildren(
        h('div', { class: 'tiny muted mt-sm' }, TASKS.find((t) => t[0] === task)[1], ' — قابل ویرایش:'),
        text,
        h('div', { class: 'row wrap mt-sm', style: { gap: '6px' } },
          h('button', { class: 'btn soft sm', type: 'button', onclick: async () => {
            try {
              await navigator.clipboard.writeText(text.value);
              toast('کپی شد.');
            } catch {
              text.select();
            }
          } }, icon('copy'), 'کپی'),
          res.can_save ? h('button', { class: 'btn primary sm', type: 'button', onclick: (e) => withLoading(e.currentTarget, async () => {
            try {
              const current = (media.description || '').trim();
              const description = `${current ? `${current}\n\n` : ''}${text.value.trim()}`.slice(0, 5000);
              const saved = (await patch(`/api/media/${media.id}`, { description })).data;
              media.description = saved.description;
              toast('به توضیحات عکس اضافه شد.');
              onSaved?.(saved);
              box.close();
            } catch (err) {
              toastError(err);
            }
          }) }, icon('check'), 'افزودن به توضیحات عکس') : null,
          h('span', { class: 'tiny muted' }, `سهمیه باقی‌مانده امروز: ${fa(res.remaining)}`)),
      );
    } catch (e) {
      result.replaceChildren(h('p', { class: 'chip danger', style: { whiteSpace: 'normal' } }, icon('alert'), e.message));
    }
  }));
}
