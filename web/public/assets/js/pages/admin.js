/**
 * پنل مدیریت: کاربران، لاگ فعالیت‌ها، سطل زباله، ادغام پروفایل‌های تکراری و خروجی کامل
 */
import { h, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, patch, post, download } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, fullName, timeAgo, dateTime } from '../core/format.js';
import { tabs, toast, toastError, loader, emptyState, confirmDialog, withLoading } from '../core/ui.js';
import { saveFile } from '../core/native.js';
import { avatar } from '../components/avatar.js';
import { personRow, pickPerson } from '../components/person-search.js';
import { actionLabel } from '../components/labels.js';

const ROLES = { member: 'عضو', admin: 'مدیر', super_admin: 'مدیر کل' };

export default function adminPage(container, { params }) {
  if (!store.user?.is_admin) {
    container.append(h('div', { class: 'page' }, emptyState('lock', 'این بخش فقط برای مدیران است.')));
    return;
  }
  const body = h('div');
  const active = params.tab || 'users';
  container.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', null, icon('crown'), ' مدیریت')),
    tabs([
      { value: 'users', label: 'کاربران', icon: 'users' },
      { value: 'activity', label: 'لاگ فعالیت‌ها', icon: 'history' },
      { value: 'trash', label: 'سطل زباله', icon: 'trash' },
      { value: 'tools', label: 'ابزارها', icon: 'settings' },
    ], active, (t) => { history.replaceState(null, '', `#/admin/${t}`); show(t); }),
    body,
  ));
  show(active);

  function show(tab) {
    body.replaceChildren(loader());
    ({ users, activity, trash, tools })[tab]?.();
  }

  // ------------------------------------------------------------ کاربران
  async function users(q = '') {
    const search = h('input', { class: 'input mb', type: 'search', placeholder: 'جستجوی نام...', value: q });
    const table = h('div', { class: 'card table-wrap' }, loader());
    body.replaceChildren(search, table);
    search.addEventListener('input', debounce(() => users(search.value), 400));
    search.focus();
    try {
      const res = await get('/api/admin/users', { q });
      table.replaceChildren(h('table', { class: 'table' },
        h('thead', null, h('tr', null, ...['شخص', 'نقش', 'وضعیت', 'آخرین ورود', ''].map((t) => h('th', null, t)))),
        h('tbody', null, ...res.data.map((u) => {
          const role = h('select', { class: 'input', style: { minHeight: '34px' }, disabled: !store.user.role.includes('super') || u.id === store.user.id },
            ...Object.entries(ROLES).map(([v, l]) => h('option', { value: v, selected: v === u.role }, l)));
          role.addEventListener('change', () => update(u, { role: role.value }));
          const blocked = u.status === 'blocked';
          return h('tr', null,
            h('td', null, u.person ? h('a', { class: 'row', href: `#/person/${u.person.id}` }, avatar(u.person, 'xs'), fullName(u.person)) : '—'),
            h('td', null, role),
            h('td', null, h('span', { class: `chip ${blocked ? 'danger' : 'success'}` }, blocked ? 'مسدود' : 'فعال')),
            h('td', { class: 'small muted' }, u.last_login_at ? timeAgo(u.last_login_at) : 'هرگز'),
            h('td', null, u.id === store.user.id ? '' : h('button', { class: `btn sm ${blocked ? 'success' : 'danger'}`, type: 'button', onclick: () => update(u, { status: blocked ? 'active' : 'blocked' }) }, blocked ? 'فعال‌سازی' : 'مسدودسازی')),
          );
        })),
      ), h('div', { class: 'muted small', style: { padding: '10px' } }, `${fa(res.meta.total)} کاربر`));
    } catch (e) {
      table.replaceChildren(emptyState('alert', e.message));
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
