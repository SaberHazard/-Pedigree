/**
 * پنل مدیریت: کاربران، لاگ فعالیت‌ها، سطل زباله، ادغام پروفایل‌های تکراری و خروجی کامل
 */
import { h, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, patch, post, download } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, timeAgo, dateTime } from '../core/format.js';
import { tabs, segmented, toast, toastError, loader, emptyState, confirmDialog, withLoading, dropdown } from '../core/ui.js';
import { saveFile } from '../core/native.js';
import { avatar } from '../components/avatar.js';
import { personRow, pickPerson } from '../components/person-search.js';
import { actionLabel } from '../components/labels.js';
import { adminSettings, adminSmsReport } from '../components/admin-settings.js';
import { adminTemplates } from '../components/admin-templates.js';
import { adminOverview, adminGroup } from '../components/admin-overview.js';
import { adminEdits } from '../components/admin-edits.js';

const ROLES = { member: 'عضو', admin: 'مدیر', super_admin: 'مدیر کل' };

export default function adminPage(container, { params, query }) {
  if (!store.user?.is_admin) {
    container.append(h('div', { class: 'page' }, emptyState('lock', 'این بخش فقط برای مدیران است.')));
    return;
  }
  const body = h('div');
  const superAdmin = store.user.role === 'super_admin';
  const active = ['settings', 'templates'].includes(params.tab) && !superAdmin ? 'overview' : params.tab || 'overview';
  container.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', null, icon('crown'), ' مدیریت')),
    tabs([
      { value: 'overview', label: 'نمای کلی', icon: 'shield' },
      { value: 'users', label: 'کاربران', icon: 'users' },
      { value: 'edits', label: 'تأیید ویرایش‌ها', icon: 'edit' },
      { value: 'group', label: 'گروه خاندان', icon: 'flag' },
      superAdmin ? { value: 'settings', label: 'تنظیمات و اتصال‌ها (API)', icon: 'key' } : null,
      superAdmin ? { value: 'templates', label: 'قالب پیامک‌ها', icon: 'edit' } : null,
      { value: 'sms', label: 'پیامک‌ها', icon: 'mail' },
      { value: 'activity', label: 'لاگ فعالیت‌ها', icon: 'history' },
      { value: 'trash', label: 'سطل زباله', icon: 'trash' },
      { value: 'tools', label: 'ابزارها', icon: 'settings' },
    ].filter(Boolean), active, (t) => { history.replaceState(null, '', `#/admin/${t}`); show(t); }),
    body,
  ));
  show(active);

  function openTab(tab) {
    history.replaceState(null, '', `#/admin/${tab}`);
    container.querySelector('.tabs')?.setActive?.(tab);
    show(tab);
  }

  function show(tab) {
    body.replaceChildren(loader());
    ({ users, activity, trash, tools, edits: () => adminEdits(body), overview: () => adminOverview(body, { openTab }), group: () => adminGroup(body), settings: () => adminSettings(body), templates: () => adminTemplates(body), sms: () => adminSmsReport(body, { dateTime, fullName, avatar }) })[tab]?.();
  }

  // ------------------------------------------------------------ کاربران
  let userStatus = query?.status === 'pending' ? 'pending' : '';
  async function users(q = '') {
    const search = h('input', { class: 'input', type: 'search', placeholder: 'جستجوی نام...', value: q });
    const filter = segmented([
      { value: '', label: 'همه' },
      { value: 'pending', label: 'در انتظار تأیید' },
      { value: 'active', label: 'فعال' },
      { value: 'blocked', label: 'مسدود' },
    ], userStatus, (v) => { userStatus = v; users(search.value); });
    const table = h('div', { class: 'card table-wrap' }, loader());
    body.replaceChildren(h('div', { class: 'row wrap mb', style: { gap: '8px' } }, h('div', { class: 'grow', style: { minWidth: '200px' } }, search), h('div', { class: 'scroll-x', 'data-scroll-x': '' }, filter)), table);
    search.addEventListener('input', debounce(() => users(search.value), 400));
    try {
      const res = await get('/api/admin/users', { q, status: userStatus });
      if (res.pending && userStatus !== 'pending') {
        body.insertBefore(h('button', { class: 'card pending-banner mb', type: 'button', onclick: () => { userStatus = 'pending'; users(); } },
          icon('user'), ` ${fa(res.pending)} درخواست عضویت در انتظار تأیید شماست`, icon('chevron-left')), table);
      }
      if (userStatus === 'pending') {
        table.replaceChildren(res.data.length ? h('div', { class: 'pending-list' }, ...res.data.map(pendingRow)) : emptyState('check', 'درخواست عضویتی در انتظار نیست.'));
        return;
      }
      table.replaceChildren(h('table', { class: 'table' },
        h('thead', null, h('tr', null, ...['شخص', 'نقش', 'وضعیت', 'آخرین ورود', ''].map((t) => h('th', null, t)))),
        h('tbody', null, ...res.data.map((u) => {
          const role = h('select', { class: 'input', style: { minHeight: '34px' }, disabled: !store.user.role.includes('super') || u.id === store.user.id || u.status === 'pending' },
            ...Object.entries(ROLES).map(([v, l]) => h('option', { value: v, selected: v === u.role }, l)));
          role.addEventListener('change', () => update(u, { role: role.value }));
          const blocked = u.status === 'blocked';
          const pending = u.status === 'pending';
          return h('tr', null,
            h('td', null, u.person ? h('a', { class: 'row', href: `#/person/${u.person.id}` }, avatar(u.person, 'xs'), fullName(u.person)) : '—'),
            h('td', null, role),
            h('td', null, h('span', { class: `chip ${blocked ? 'danger' : pending ? 'warning' : 'success'}` }, blocked ? 'مسدود' : pending ? 'در انتظار تأیید' : 'فعال')),
            h('td', { class: 'small muted' }, u.last_login_at ? timeAgo(u.last_login_at) : 'هرگز'),
            h('td', { class: 'nowrap' }, u.id === store.user.id ? '' : pending
              ? h('div', { class: 'row', style: { gap: '4px' } },
                h('button', { class: 'btn sm success', type: 'button', onclick: () => decide(u, 'approve') }, icon('check'), 'تأیید'),
                h('button', { class: 'btn sm danger', type: 'button', onclick: () => decide(u, 'reject') }, 'رد'))
              : h('div', { class: 'row', style: { gap: '4px' } },
                h('button', { class: `btn sm ${blocked ? 'success' : 'danger'}`, type: 'button', onclick: () => update(u, { status: blocked ? 'active' : 'blocked' }) }, blocked ? 'فعال‌سازی' : 'مسدودسازی'),
                moreMenu(u),
              )),
          );
        })),
      ), h('div', { class: 'muted small', style: { padding: '10px' } }, `${fa(res.meta.total)} کاربر`));
    } catch (e) {
      table.replaceChildren(emptyState('alert', e.message));
    }
  }

  /** یک درخواست عضویت: نام، معرفی و دکمه‌های تأیید/رد */
  function pendingRow(u) {
    return h('div', { class: 'pending-row' },
      h('div', { class: 'row', style: { gap: '10px', alignItems: 'flex-start', minWidth: 0 } },
        u.person ? avatar(u.person, 'sm') : null,
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('b', null, u.person ? fullName(u.person) : '—'), ' ',
          h('span', { class: 'muted tiny' }, u.created_at ? `ثبت‌نام ${timeAgo(u.created_at)}` : ''),
          h('div', { class: 'join-note', dir: 'auto' }, u.join_note || 'بدون معرفی'),
        ),
      ),
      h('div', { class: 'row wrap', style: { gap: '6px', justifyContent: 'flex-end' } },
        u.person ? h('a', { class: 'btn ghost sm', href: `#/person/${u.person.id}` }, icon('user'), 'پروفایل') : null,
        h('button', { class: 'btn sm danger', type: 'button', onclick: () => decide(u, 'reject') }, 'رد درخواست'),
        h('button', { class: 'btn sm primary', type: 'button', onclick: () => decide(u, 'approve') }, icon('check'), 'تأیید عضویت'),
      ),
    );
  }

  async function decide(u, action) {
    const name = u.person ? fullName(u.person) : 'این شخص';
    const question = action === 'approve'
      ? `عضویت «${name}» تأیید شود؟ از این پس کل شجره‌نامه را می‌بیند.`
      : `درخواست «${name}» رد شود؟ حساب مسدود و پروفایلی که خودش ساخته (اگر به کسی وصل نیست) حذف می‌شود.`;
    if (!(await confirmDialog(question, { danger: action === 'reject' }))) return;
    try {
      const res = await post(`/api/admin/users/${u.id}/${action}`);
      toast(res.message);
      users();
    } catch (e) {
      toastError(e);
    }
  }

  /** کارهای بیشتر: خروج از همه دستگاه‌ها، محدود کردن در گروه خاندان */
  function moreMenu(u) {
    const btn = h('button', { class: 'icon-btn', type: 'button', title: 'کارهای بیشتر', onclick: () => dropdown(btn, [
      { label: 'خروج از همه دستگاه‌ها', icon: 'logout', onClick: () => act(`/api/admin/users/${u.id}/logout-all`, {}, `«${u.person ? fullName(u.person) : 'این کاربر'}» از همه دستگاه‌ها خارج شود؟`) },
      { label: 'سکوت ۷ روزه در گروه خاندان', icon: 'ban', onClick: () => act(`/api/admin/users/${u.id}/group-mute`, { days: 7 }, 'این عضو ۷ روز نتواند در گروه پیام بدهد؟') },
      { label: 'سکوت دائم در گروه خاندان', icon: 'ban', danger: true, onClick: () => act(`/api/admin/users/${u.id}/group-mute`, { days: null }, 'این عضو تا اطلاع بعدی نتواند در گروه پیام بدهد؟') },
      { label: 'برداشتن سکوت گروه', icon: 'check', onClick: () => act(`/api/admin/users/${u.id}/group-mute`, { days: 0 }) },
    ]) }, icon('more'));
    return btn;
  }

  async function act(url, payload, question) {
    if (question && !(await confirmDialog(question, { danger: true }))) return;
    try {
      const res = await post(url, payload);
      toast(res.message || 'انجام شد.');
    } catch (e) {
      toastError(e);
    }
  }

  async function update(u, data) {
    try {
      await patch(`/api/admin/users/${u.id}`, data);
      toast('ذخیره شد.');
      users();
    } catch (e) {
      toastError(e);
      users();
    }
  }

  // ------------------------------------------------------------ لاگ
  async function activity() {
    try {
      const res = await get('/api/admin/activity');
      body.replaceChildren(h('div', { class: 'card table-wrap' }, h('table', { class: 'table' },
        h('thead', null, h('tr', null, ...['زمان', 'کاربر', 'رویداد', 'موضوع', 'IP'].map((t) => h('th', null, t)))),
        h('tbody', null, ...res.data.map((l) => h('tr', null,
          h('td', { class: 'small nowrap' }, dateTime(l.created_at)),
          h('td', null, l.user?.name || '—'),
          h('td', null, actionLabel(l.action), h('div', { class: 'tiny muted ltr' }, l.action)),
          h('td', { class: 'small' }, l.subject_type === 'Person' ? h('a', { href: `#/person/${l.subject_id}` }, l.properties?.name || 'مشاهده') : (l.subject_type || '')),
          h('td', { class: 'small muted ltr' }, l.ip_address || ''),
        ))),
      )));
    } catch (e) {
      body.replaceChildren(emptyState('alert', e.message));
    }
  }

  // ------------------------------------------------------------ سطل زباله
  async function trash() {
    try {
      const res = await get('/api/admin/trash');
      body.replaceChildren(res.data.length ? h('div', { class: 'card' }, ...res.data.map((p) => personRow(p, {
        extra: h('button', { class: 'btn soft sm', type: 'button', onclick: async (e) => {
          e.stopPropagation();
          await post(`/api/admin/trash/${p.id}/restore`);
          toast('بازیابی شد.');
          trash();
        } }, icon('refresh'), 'بازیابی'),
      }))) : emptyState('trash', 'سطل زباله خالی است.'));
    } catch (e) {
      body.replaceChildren(emptyState('alert', e.message));
    }
  }

  // ------------------------------------------------------------ ابزارها
  function tools() {
    let keep = null;
    let dup = null;
    const keepBox = h('div', { class: 'muted small' }, 'انتخاب نشده');
    const dupBox = h('div', { class: 'muted small' }, 'انتخاب نشده');
    const mergeBtn = h('button', { class: 'btn primary', type: 'button' }, icon('merge'), 'ادغام');
    mergeBtn.addEventListener('click', () => withLoading(mergeBtn, async () => {
      if (!keep || !dup) return toast('هر دو شخص را انتخاب کنید.', 'warning');
      if (!(await confirmDialog(`پروفایل «${fullName(dup)}» در «${fullName(keep)}» ادغام و حذف شود؟ همه فرزندان، همسران و عکس‌ها منتقل می‌شوند.`, { danger: true }))) return;
      try {
        await post(`/api/admin/persons/${keep.id}/merge`, { duplicate_id: dup.id });
        toast('ادغام انجام شد.');
        navigate(`/person/${keep.id}`);
      } catch (e) {
        toastError(e);
      }
    }));
    const exportBtn = h('button', { class: 'btn', type: 'button' }, icon('gedcom'), 'دانلود GEDCOM کل شجره‌نامه');
    exportBtn.addEventListener('click', () => withLoading(exportBtn, async () => {
      try {
        const { blob, name } = await download('/api/export/gedcom', { mode: 'all' });
        await saveFile(blob, name);
      } catch (e) {
        toastError(e);
      }
    }));

    body.replaceChildren(
      h('div', { class: 'card mb' },
        h('h3', null, 'ادغام پروفایل‌های تکراری'),
        h('p', { class: 'muted small' }, 'وقتی یک نفر دو بار (مثلاً در دو شاخه مختلف) ثبت شده، پروفایل تکراری را در پروفایل اصلی ادغام کنید.'),
        h('div', { class: 'grid grid-2' },
          h('div', null, h('button', { class: 'btn soft block mb', type: 'button', onclick: async () => { keep = await pickPerson({ title: 'پروفایل اصلی (باقی می‌ماند)' }) || keep; keepBox.replaceChildren(keep ? personRow(keep) : 'انتخاب نشده'); } }, 'انتخاب پروفایل اصلی'), keepBox),
          h('div', null, h('button', { class: 'btn soft block mb', type: 'button', onclick: async () => { dup = await pickPerson({ title: 'پروفایل تکراری (حذف می‌شود)' }) || dup; dupBox.replaceChildren(dup ? personRow(dup) : 'انتخاب نشده'); } }, 'انتخاب پروفایل تکراری'), dupBox),
        ),
        h('div', { class: 'mt' }, mergeBtn),
      ),
      h('div', { class: 'card' },
        h('h3', null, 'پشتیبان و انتقال'),
        h('p', { class: 'muted small' }, 'فایل GEDCOM استاندارد جهانی شجره‌نامه است و در نرم‌افزارهایی مثل Gramps، MyHeritage و FamilySearch باز می‌شود. (برای پشتیبان کامل، از پایگاه داده و پوشه storage نسخه پشتیبان بگیرید.)'),
        exportBtn,
      ),
    );
  }
}
