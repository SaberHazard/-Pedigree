/**
 * جزئیات یک ازدواج (با کلیک روی قلب در درخت یا از فهرست همسران):
 * تاریخ ازدواج، جدایی (طلاق) و تاریخ آن، یا فوت همسر.
 *
 * - بستگان درجه یک (و اگر درجه یکی عضو نیست، درجه دو) مستقیم ویرایش می‌کنند
 * - بستگان درجه دو و سه «پیشنهاد» می‌دهند که پس از تأیید مدیر اعمال می‌شود
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, patch } from '../core/api.js';
import { fullName, formatDate } from '../core/format.js';
import { modal, toast, toastError, field } from '../core/ui.js';
import { avatar } from './avatar.js';
import { dateInput } from './date-input.js';
import { authorLink } from './author.js';

const STATUS = {
  married: { label: 'متأهل', emoji: '❤️' },
  divorced: { label: 'جدا شده (طلاق)', emoji: '💔' },
  widowed: { label: 'فوت همسر', emoji: '🖤' },
};

export async function openMarriage(id, { onChange } = {}) {
  let data;
  try {
    data = (await get(`/api/marriages/${id}`)).data;
  } catch (e) {
    toastError(e);
    return;
  }
  const m = data.marriage;
  const status = STATUS[m.status] || STATUS.married;
  const spouse = (p) => (p ? h('a', { class: 'mar-spouse', href: `#/person/${p.id}` }, avatar(p, 'md'), h('b', null, fullName(p))) : h('span', { class: 'muted' }, '—'));
  const row = (label, value) => h('div', { class: 'mar-row' }, h('span', { class: 'muted' }, label), h('b', null, value));

  const view = h('div', { class: `mar-sheet ${m.status}` },
    h('div', { class: 'mar-couple' }, spouse(data.husband), h('div', { class: 'mar-heart', 'aria-hidden': 'true' }, status.emoji), spouse(data.wife)),
    h('div', { class: 'mar-facts' },
      row('وضعیت', status.label),
      row('تاریخ ازدواج', m.marriage_date ? formatDate(m.marriage_date) : 'ثبت نشده'),
      m.status === 'divorced' ? row('تاریخ طلاق', m.end_date ? formatDate(m.end_date) : 'ثبت نشده') : null,
      m.status === 'widowed' ? row('تاریخ فوت همسر', m.end_date ? formatDate(m.end_date) : 'ثبت نشده') : null,
    ),
    data.creator ? h('div', { class: 'tiny muted mar-author' }, authorLink(data.creator, { label: 'ثبت این ازدواج' })) : null,
    data.pending_suggestion ? h('p', { class: 'chip warning', style: { margin: 0 } }, icon('clock'), 'پیشنهاد ویرایش شما در انتظار تأیید مدیر است') : null,
  );

  const canChange = data.can_edit || data.can_suggest;
  const box = modal({
    title: 'ازدواج',
    body: view,
    actions: canChange ? [
      { label: 'بستن' },
      { label: data.can_edit ? 'ویرایش' : 'پیشنهاد ویرایش', class: 'primary', icon: 'edit', close: false, onClick: () => { box.close(); edit(); } },
    ] : [{ label: 'بستن' }],
  });

  function edit() {
    let st = m.status;
    const married = dateInput('marriage_date', m.marriage_date || '');
    const ended = dateInput('end_date', m.end_date || '');
    const endField = field('تاریخ طلاق', ended);
    const endLabel = endField.querySelector('label');
    const reason = data.can_edit ? null : h('textarea', { class: 'input', rows: 2, maxlength: 300, placeholder: 'از کجا می‌دانید؟ (برای مدیر؛ اختیاری)' });
    const statusBtns = h('div', { class: 'segmented', style: { width: '100%' } });
    const drawStatus = () => {
      statusBtns.replaceChildren(...Object.entries(STATUS).map(([k, v]) => h('button', { type: 'button', class: st === k ? 'active' : '', style: { flex: 1 }, onclick: () => { st = k; drawStatus(); } }, `${v.emoji} ${v.label}`)));
      endField.hidden = st === 'married';
      if (endLabel) endLabel.textContent = st === 'widowed' ? 'تاریخ فوت همسر' : 'تاریخ طلاق';
    };
    drawStatus();
    modal({
      title: data.can_edit ? 'ویرایش ازدواج' : 'پیشنهاد ویرایش ازدواج',
      body: h('div', null,
        data.can_edit ? null : h('p', { class: 'muted small', style: { marginTop: 0 } }, 'شما از بستگان درجه دو یا سه هستید؛ تغییر شما پس از تأیید مدیر سایت در درخت نمایش داده می‌شود.'),
        h('div', { class: 'field' }, h('label', null, 'وضعیت'), statusBtns),
        field('تاریخ ازدواج', married),
        endField,
        reason ? field('توضیح برای مدیر', reason) : null,
      ),
      actions: [
        { label: 'انصراف' },
        { label: data.can_edit ? 'ذخیره' : 'فرستادن برای تأیید', class: 'primary', icon: 'check', onClick: async () => {
          try {
            const res = await patch(`/api/marriages/${id}`, {
              status: st,
              marriage_date: married.value || null,
              end_date: st === 'married' ? null : (ended.value || null),
              reason: reason?.value.trim() || null,
            });
            toast(res.pending ? res.message : 'ذخیره شد.');
            if (!res.pending) onChange?.();
            return true;
          } catch (e) {
            toastError(e);
            return false;
          }
        } },
      ],
    });
  }
}
