/**
 * پنل مدیریت: نمای کلی (آمار، سلامت سرور، اعلان همگانی) و مدیریت «گروه خاندان» (گزارش‌ها و سکوت‌ها)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, fileSize, timeAgo, fullName, dateTime } from '../core/format.js';
import { toast, toastError, loader, emptyState, confirmDialog, withLoading } from '../core/ui.js';
import { avatar } from './avatar.js';

const STAT_LABELS = [
  ['members', 'users', 'اعضای فعال'],
  ['active_30d', 'user', 'فعال در ۳۰ روز اخیر'],
  ['persons', 'tree', 'اشخاص شجره‌نامه'],
  ['marriages', 'heart', 'ازدواج‌ها'],
  ['images', 'image', 'عکس‌ها'],
  ['videos', 'video', 'فیلم‌ها'],
  ['storage_bytes', 'archive', 'فضای عکس و فیلم'],
  ['pending_media', 'shield', 'در انتظار تأیید'],
  ['video_queue', 'clock', 'فیلم در صف تبدیل'],
  ['messages_today', 'chat', 'پیام خصوصی امروز'],
  ['group_today', 'users', 'پیام گروه امروز'],
  ['open_reports', 'flag', 'گزارش باز گروه'],
  ['sms_today', 'mail', 'پیامک تبریک امروز'],
  ['ai_today', 'bot', 'گفتگوی هوش مصنوعی امروز'],
  ['ai_images_today', 'wand', 'بازسازی عکس امروز'],
  ['blocked', 'ban', 'کاربران مسدود'],
];

export async function adminOverview(body, { openTab } = {}) {
  body.replaceChildren(loader());
  let data;
  try {
    data = (await get('/api/admin/overview')).data;
  } catch (e) {
    body.replaceChildren(emptyState('alert', e.message));
    return;
  }
  const s = data.stats;
  const problems = data.checks.filter((c) => c.ok === false);

  body.replaceChildren(...[
    h('div', { class: 'admin-stats' }, ...STAT_LABELS.map(([key, ic, label]) => h('div', {
      class: `card stat ${key === 'open_reports' && s[key] ? 'warn' : ''}`,
      role: key === 'open_reports' || key === 'pending_media' ? 'button' : null,
      onclick: key === 'open_reports' ? () => openTab?.('group') : key === 'pending_media' ? () => { location.hash = '#/approvals'; } : null,
    },
    h('div', { class: 's-icon' }, icon(ic)),
    h('div', null, h('div', { class: 'muted small' }, label), h('b', null, key === 'storage_bytes' ? fileSize(s[key]) : fa(s[key] ?? 0)))))),

    h('section', { class: 'card mt' },
      h('div', { class: 'card-title' },
        h('h3', null, icon('shield'), ' سلامت سرور'),
        problems.length ? h('span', { class: 'chip warning' }, `${fa(problems.length)} مورد نیاز به توجه`) : h('span', { class: 'chip success' }, icon('check'), 'همه‌چیز درست است'),
      ),
      h('div', { class: 'health-list' }, ...data.checks.map((c) => h('div', { class: `health-row ${c.ok === false ? 'bad' : c.ok ? 'ok' : 'info'}` },
        h('span', { class: 'health-dot' }, icon(c.ok === false ? 'alert' : c.ok ? 'check' : 'info')),
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', null, c.label),
          c.value ? h('div', { class: 'muted tiny ltr ellipsis', dir: 'ltr' }, c.value) : null,
          c.ok === false && c.fix ? h('div', { class: 'tiny', style: { color: 'var(--warning)' } }, 'راه حل: ',
            /^[\x20-\x7E]+$/.test(c.fix) ? h('code', { dir: 'ltr' }, c.fix) : h('span', { dir: 'auto' }, c.fix)) : null,
        ),
      ))),
    ),

    store.user.role === 'super_admin' ? broadcastBox() : null,
  ].filter(Boolean));
}

function broadcastBox() {
  const title = h('input', { class: 'input', maxlength: 80, placeholder: 'مثلاً «دورهمی نوروزی خاندان»' });
  const text = h('textarea', { class: 'input', rows: 3, maxlength: 500, placeholder: 'متن اعلان...' });
  const send = h('button', { class: 'btn primary', type: 'button', onclick: () => withLoading(send, async () => {
    if (!title.value.trim() || !text.value.trim()) return toast('عنوان و متن را بنویسید.', 'warning');
    if (!(await confirmDialog('این اعلان برای همه اعضای فعال (در سایت و روی گوشی) فرستاده شود؟'))) return;
    try {
      const res = await post('/api/admin/broadcast', { title: title.value.trim(), body: text.value.trim() });
      toast(res.message);
      title.value = '';
      text.value = '';
    } catch (e) {
      toastError(e);
    }
  }) }, icon('send'), 'فرستادن به همه');
  return h('section', { class: 'card mt' },
    h('div', { class: 'card-title' }, h('h3', null, icon('bell'), ' اعلان همگانی')),
    h('p', { class: 'muted small', style: { marginTop: 0 } }, 'برای همه اعضا در فهرست اعلان‌ها و (اگر Firebase فعال باشد) روی گوشی. حداکثر ۳ بار در ساعت. برای پیام ماندگار بالای صفحه اول از «تنظیمات ← اطلاعیه سایت» استفاده کنید.'),
    h('div', { class: 'form-grid' },
      h('div', { class: 'field full' }, h('label', null, 'عنوان'), title),
      h('div', { class: 'field full' }, h('label', null, 'متن'), text),
    ),
    h('div', { class: 'row', style: { justifyContent: 'flex-end' } }, send),
  );
}

// ------------------------------------------------------------ مدیریت گروه
export async function adminGroup(body) {
  body.replaceChildren(loader());
  let data;
  try {
    data = await get('/api/admin/group/reports');
  } catch (e) {
    body.replaceChildren(emptyState('alert', e.message));
    return;
  }

  const resolve = async (item, action, days = null) => {
    const texts = { dismiss: 'گزارش‌ها رد شوند و پیام بماند؟', delete: 'این پیام حذف شود؟', mute: days ? `پیام حذف و فرستنده ${fa(days)} روز از پیام دادن در گروه محروم شود؟` : 'پیام حذف و فرستنده تا اطلاع بعدی از پیام دادن در گروه محروم شود؟' };
    if (!(await confirmDialog(texts[action], { danger: action !== 'dismiss' }))) return;
    try {
      await post(`/api/admin/group/reports/${item.message.id}/resolve`, { action, days });
      toast('رسیدگی شد.');
      adminGroup(body);
    } catch (e) {
      toastError(e);
    }
  };
  const unmute = async (u) => {
    try {
      await post(`/api/admin/users/${u.id}/group-mute`, { days: 0 });
      toast('محدودیت برداشته شد.');
      adminGroup(body);
    } catch (e) {
      toastError(e);
    }
  };

  body.replaceChildren(
    h('div', { class: 'row between mb', style: { flexWrap: 'wrap', gap: '8px' } },
      h('h3', { style: { margin: 0 } }, icon('flag'), ' گزارش‌های گروه خاندان'),
      h('a', { class: 'btn soft sm', href: '#/group' }, icon('users'), 'رفتن به گروه'),
    ),
    data.data.length ? h('div', { class: 'col', style: { gap: '10px' } }, ...data.data.map((item) => h('div', { class: 'card' },
      h('div', { class: 'row', style: { gap: '8px', alignItems: 'flex-start' } },
        item.message?.user?.person ? avatar(item.message.user.person, 'sm') : null,
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('b', null, item.author?.name || '—'), ' ', h('span', { class: 'muted tiny' }, item.message ? timeAgo(item.message.at) : ''),
          h('div', { class: 'report-body', dir: 'auto' }, item.message?.deleted ? 'این پیام حذف شده است' : item.message?.body || (item.message?.media ? '[عکس/فیلم]' : '')),
          h('div', { class: 'muted small' }, `${fa(item.reports.length)} گزارش: `, item.reports.map((r) => `${r.by}${r.reason ? ` («${r.reason}»)` : ''}`).join('، ')),
        ),
      ),
      h('div', { class: 'row wrap mt-sm', style: { gap: '6px', justifyContent: 'flex-end' } },
        h('button', { class: 'btn ghost sm', type: 'button', onclick: () => resolve(item, 'dismiss') }, 'رد گزارش'),
        h('button', { class: 'btn sm', type: 'button', onclick: () => resolve(item, 'delete') }, icon('trash'), 'حذف پیام'),
        h('button', { class: 'btn danger sm', type: 'button', onclick: () => resolve(item, 'mute', 7) }, icon('ban'), 'حذف + سکوت ۷ روزه'),
        h('button', { class: 'btn danger sm', type: 'button', onclick: () => resolve(item, 'mute', null) }, 'حذف + سکوت دائم'),
      ),
    ))) : h('div', { class: 'card' }, emptyState('check', 'گزارش بازی نیست.')),

    h('h3', { class: 'mt' }, icon('ban'), ' اعضای محدودشده در گروه'),
    data.muted.length ? h('div', { class: 'card' }, ...data.muted.map((u) => h('div', { class: 'row between', style: { padding: '6px 0', gap: '8px' } },
      h('div', { class: 'row', style: { gap: '8px', minWidth: 0 } }, avatar(u.person, 'xs'), h('span', { class: 'ellipsis' }, u.person ? fullName(u.person) : u.name),
        h('span', { class: 'muted tiny' }, u.forever ? 'دائم' : `تا ${dateTime(u.until)}`)),
      h('button', { class: 'btn soft sm', type: 'button', onclick: () => unmute(u) }, 'برداشتن محدودیت'),
    ))) : h('p', { class: 'muted small' }, 'کسی محدود نشده است.'),
  );
}
