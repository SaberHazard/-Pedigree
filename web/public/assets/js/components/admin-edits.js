/**
 * پنل مدیریت ← «تأیید ویرایش‌ها»: پیشنهادهای بستگان درجه دو و سه (مشخصات پروفایل و ازدواج/طلاق)
 * هر پیشنهاد: شخص، پیشنهاددهنده و درجه خویشاوندی، مقدار قبلی ← مقدار پیشنهادی، توضیح، تأیید یا رد
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post } from '../core/api.js';
import { fa, fullName, timeAgo } from '../core/format.js';
import { toast, toastError, loader, emptyState, segmented, modal } from '../core/ui.js';
import { avatar } from './avatar.js';

export async function adminEdits(body) {
  let status = 'pending';
  const list = h('div');
  const filter = segmented([
    { value: 'pending', label: 'در انتظار' },
    { value: 'approved', label: 'تأییدشده' },
    { value: 'rejected', label: 'ردشده' },
  ], status, (v) => { status = v; load(); });
  body.replaceChildren(
    h('div', { class: 'card mb' },
      h('div', { class: 'card-title' }, h('h3', null, icon('edit'), ' پیشنهادهای ویرایش بستگان')),
      h('p', { class: 'muted small', style: { marginTop: 0 } }, 'خود شخص و بستگان درجه یک مستقیم ویرایش می‌کنند؛ تغییرهای بستگان درجه دو و سه تا تأیید شما در سایت نمایش داده نمی‌شود.'),
      h('div', { class: 'scroll-x', 'data-scroll-x': '' }, filter),
    ),
    list,
  );
  load();

  async function load() {
    list.replaceChildren(loader());
    let res;
    try {
      res = await get('/api/admin/edit-requests', { status });
    } catch (e) {
      list.replaceChildren(emptyState('alert', e.message));
      return;
    }
    list.replaceChildren(res.data.length
      ? h('div', { class: 'col', style: { gap: '12px' } }, ...res.data.map(card))
      : h('div', { class: 'card' }, emptyState('check', status === 'pending' ? 'پیشنهاد تازه‌ای نیست.' : 'موردی نیست.')));
  }

  function card(r) {
    const p = r.person;
    const what = r.kind === 'marriage' ? 'ازدواج' : 'مشخصات';
    return h('div', { class: 'card edit-req' },
      h('div', { class: 'row', style: { gap: '10px', alignItems: 'flex-start' } },
        p ? avatar(p, 'sm') : null,
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', null, h('b', null, `${what} ${p ? fullName(p) : ''}`), ' ', p ? h('a', { class: 'tiny', href: `#/person/${p.id}` }, 'دیدن پروفایل') : null),
          h('div', { class: 'muted small' },
            `پیشنهاد ${r.requester?.name || '—'}`, r.degree ? ` • بستگان درجه ${fa(r.degree)}` : '', r.created_at ? ` • ${timeAgo(r.created_at)}` : '',
            r.decider ? ` • بررسی: ${r.decider}` : ''),
        ),
        r.status !== 'pending' ? h('span', { class: `chip ${r.status === 'approved' ? 'success' : 'danger'}` }, r.status === 'approved' ? 'تأیید شد' : 'رد شد') : null,
      ),
      h('div', { class: 'table-wrap mt-sm' }, h('table', { class: 'table diff-table' },
        h('thead', null, h('tr', null, h('th', null, 'بخش'), h('th', null, 'مقدار فعلی'), h('th', null, 'پیشنهاد'))),
        h('tbody', null, ...r.fields.map((f) => h('tr', { class: f.stale ? 'stale' : '' },
          h('td', { class: 'bold' }, f.label, f.stale ? h('div', { class: 'tiny', style: { color: 'var(--warning)' } }, 'بعد از پیشنهاد تغییر کرده') : null),
          h('td', { class: 'old', dir: 'auto' }, f.old ?? '—'),
          h('td', { class: 'new', dir: 'auto' }, f.new ?? '(خالی)'),
        ))),
      )),
      r.reason ? h('div', { class: 'join-note mt-sm' }, icon('info'), ' ', r.reason) : null,
      r.decision_note ? h('div', { class: 'muted small mt-sm' }, 'دلیل رد: ', r.decision_note) : null,
      r.status === 'pending' ? h('div', { class: 'row wrap mt-sm', style: { gap: '8px', justifyContent: 'flex-end' } },
        h('button', { class: 'btn danger sm', type: 'button', onclick: () => reject(r) }, 'رد'),
        h('button', { class: 'btn primary sm', type: 'button', onclick: (e) => approve(r, e.currentTarget) }, icon('check'), 'تأیید و اعمال'),
      ) : null,
    );
  }

  async function approve(r, btn) {
    btn.disabled = true;
    try {
      const res = await post(`/api/admin/edit-requests/${r.id}/approve`);
      toast(res.message);
      load();
    } catch (e) {
      btn.disabled = false;
      toastError(e);
    }
  }

  function reject(r) {
    const note = h('textarea', { class: 'input', rows: 2, maxlength: 300, placeholder: 'دلیل (برای پیشنهاددهنده؛ اختیاری)' });
    modal({
      title: 'رد پیشنهاد',
      body: note,
      actions: [
        { label: 'انصراف' },
        { label: 'رد پیشنهاد', class: 'danger', onClick: async () => {
          try {
            const res = await post(`/api/admin/edit-requests/${r.id}/reject`, { note: note.value.trim() || null });
            toast(res.message);
            load();
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
