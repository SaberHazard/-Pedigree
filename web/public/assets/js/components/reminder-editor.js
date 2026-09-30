/**
 * ساخت یا ویرایش هشدار / یادآور: عنوان، توضیح، تاریخ خورشیدی، ساعت (وقت تهران)، تکرار و «زودتر یادآوری کن».
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { post, put, del } from '../core/api.js';
import { fa, latin } from '../core/format.js';
import { modal, field, toast, toastError, confirmDialog } from '../core/ui.js';
import { dateInput } from './date-input.js';
import { refreshAlarms, askNotificationPermission } from '../core/alarms.js';

export const REPEATS = {
  none: 'یک بار', daily: 'هر روز', weekly: 'هر هفته', monthly: 'هر ماه (خورشیدی)', yearly: 'هر سال (خورشیدی)',
  monthly_hijri: 'هر ماه (قمری)', yearly_hijri: 'هر سال (قمری)',
};

const BEFORE = [
  [0, 'سر موعد'], [5, '۵ دقیقه قبل'], [10, '۱۰ دقیقه قبل'], [15, '۱۵ دقیقه قبل'], [30, 'نیم ساعت قبل'], [60, 'یک ساعت قبل'],
  [120, 'دو ساعت قبل'], [180, 'سه ساعت قبل'], [360, 'شش ساعت قبل'], [720, 'دوازده ساعت قبل'], [1440, 'یک روز قبل'],
  [2880, 'دو روز قبل'], [4320, 'سه روز قبل'], [10080, 'یک هفته قبل'],
];

/**
 * @param {object|null} reminder هشدار موجود (ویرایش) یا null
 * @param {{date?: string, title?: string, repeat?: string, person_id?: string, onSaved?: Function}} opts
 */
export function openReminderEditor(reminder = null, { date = '', title = '', repeat = 'none', personId = null, onSaved } = {}) {
  const r = reminder || {};
  const titleIn = h('input', { class: 'input', maxlength: 120, required: true, value: r.title || title, placeholder: 'مثلاً: دارو، قرار دکتر، تولد مادربزرگ' });
  const note = h('textarea', { class: 'input', rows: 3, maxlength: 1000, placeholder: 'توضیح (اختیاری): این هشدار برای چیست؟' }, r.note || '');
  const day = dateInput('starts_on', r.starts_on || date);
  const time = h('input', { class: 'input ltr-input', type: 'time', value: r.time || '09:00', required: true, step: 60 });
  const rep = h('select', { class: 'input' }, ...Object.entries(REPEATS).map(([k, v]) => h('option', { value: k }, v)));
  rep.value = r.repeat || repeat;
  const until = dateInput('until_on', r.until_on || '');
  const untilField = field('تکرار تا (اختیاری)', until);
  const before = h('select', { class: 'input' }, ...BEFORE.map(([k, v]) => h('option', { value: String(k) }, v)));
  before.value = String(r.remind_before || 0);
  const active = h('input', { type: 'checkbox', checked: r.active !== false });
  const syncUntil = () => { untilField.hidden = rep.value === 'none'; };
  rep.addEventListener('change', syncUntil);
  syncUntil();

  const box = modal({
    title: reminder ? 'ویرایش هشدار' : 'هشدار / یادآور تازه',
    body: h('div', { class: 'reminder-form' },
      field('عنوان', titleIn),
      field('توضیح', note),
      h('div', { class: 'grid grid-2', style: { gap: '10px' } },
        field('تاریخ (خورشیدی)', day),
        field('ساعت (وقت تهران)', time)),
      h('div', { class: 'grid grid-2', style: { gap: '10px' } },
        field('تکرار', rep),
        field('یادآوری', before)),
      untilField,
      reminder ? h('label', { class: 'row', style: { gap: '8px' } }, active, 'فعال باشد') : null,
      h('p', { class: 'muted tiny', style: { margin: '4px 0 0' } }, icon('info'), ' زمان‌ها به وقت تهران است. در اپ گوشی، خود گوشی سر ثانیه زنگ می‌زند (حتی بدون اینترنت)؛ در سایت اگر صفحه باز باشد زنگ و پنجره هشدار می‌آید و همیشه در اعلان‌ها ثبت می‌شود. «ماهانه/سالانه قمری» مطابق تقویم رسمی است.'),
    ),
    actions: [
      reminder ? { label: 'حذف', class: 'danger ghost', icon: 'trash', close: false, onClick: async () => {
        if (!(await confirmDialog('این هشدار حذف شود؟', { danger: true }))) return false;
        try {
          toast((await del(`/api/reminders/${reminder.id}`)).message);
          refreshAlarms();
          onSaved?.();
          box.close();
        } catch (e) {
          toastError(e);
        }
        return false;
      } } : null,
      { label: 'انصراف' },
      { label: 'ذخیره', class: 'primary', icon: 'check', onClick: async () => {
        const payload = {
          title: titleIn.value.trim(),
          note: note.value.trim() || null,
          starts_on: day.value,
          time: latin(time.value).slice(0, 5),
          repeat: rep.value,
          until_on: rep.value === 'none' ? null : (until.value || null),
          remind_before: Number(before.value),
          active: reminder ? active.checked : true,
          person_id: r.person_id || personId || null,
        };
        if (!payload.title) { toast('عنوان را بنویسید.', 'warning'); return false; }
        if (!/^\d{4}-\d{2}-\d{2}$/.test(payload.starts_on)) { toast('تاریخ کامل (روز، ماه و سال) را انتخاب کنید.', 'warning'); return false; }
        if (!/^\d{2}:\d{2}$/.test(payload.time)) { toast('ساعت را انتخاب کنید.', 'warning'); return false; }
        if (payload.until_on && !/^\d{4}-\d{2}-\d{2}$/.test(payload.until_on)) { toast('تاریخ پایان تکرار کامل نیست.', 'warning'); return false; }
        try {
          const res = reminder ? await put(`/api/reminders/${reminder.id}`, payload) : await post('/api/reminders', payload);
          toast(res.message, 'success', 6000);
          askNotificationPermission();
          refreshAlarms();
          onSaved?.(res.data);
          return true;
        } catch (e) {
          toastError(e);
          return false;
        }
      } },
    ].filter(Boolean),
  });
  setTimeout(() => titleIn.focus(), 80);
  return box;
}

export function repeatLabel(r) {
  return REPEATS[r.repeat] || '';
}

export function timeLabel(t) {
  return fa(t || '');
}
