/**
 * ویرایش مشخصات شخص (یا ساخت شخص مستقل جدید مثل جد اعلای یک خاندان)
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post, patch, del } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fullName } from '../core/format.js';
import { toast, toastError, showFormErrors, clearFormErrors, withLoading, confirmDialog, emptyState, loader } from '../core/ui.js';
import { personFields, collectPerson } from '../components/person-form.js';

export default async function personEdit(container, { params }) {
  const page = h('div', { class: 'page narrow' }, loader());
  container.append(page);

  let person = {};
  const creating = !params.id;
  if (!creating) {
    try {
      person = (await get(`/api/persons/${params.id}`)).data;
    } catch (e) {
      page.replaceChildren(emptyState('alert', e.message));
      return;
    }
    if (!person.permissions?.edit) {
      page.replaceChildren(emptyState('lock', 'شما اجازه ویرایش این پروفایل را ندارید. فقط خود شخص، بستگان نزدیک و (برای درگذشتگان) نوادگان می‌توانند ویرایش کنند.', h('a', { class: 'btn', href: `#/person/${person.id}` }, 'بازگشت به پروفایل')));
      return;
    }
  }

  const sensitive = creating || person.permissions?.sensitive;
  const saveBtn = h('button', { class: 'btn primary lg', type: 'submit' }, icon('check'), creating ? 'ساخت شخص' : 'ذخیره تغییرات');
  const form = h('form', { novalidate: true },
    personFields(person, { sensitive, gender: true, full: true }),
    !sensitive && person.has_national_code ? h('p', { class: 'muted small' }, icon('lock'), ' اطلاعات هویتی این شخص فقط توسط خودش قابل مشاهده و تغییر است.') : null,
    h('div', { class: 'row between wrap', style: { position: 'sticky', bottom: 'calc(12px + var(--bottom-nav-h))', background: 'var(--glass)', backdropFilter: 'blur(12px)', padding: '12px', borderRadius: '16px', border: '1px solid var(--border)', boxShadow: 'var(--shadow)' } },
      h('div', { class: 'row' },
        h('a', { class: 'btn ghost', href: creating ? '#/' : `#/person/${person.id}` }, 'انصراف'),
        person.permissions?.delete ? h('button', { class: 'btn danger', type: 'button', onclick: remove }, icon('trash'), 'حذف') : null,
      ),
      saveBtn,
    ),
  );

  page.replaceChildren(
    h('div', { class: 'page-head' },
      h('div', null,
        h('h1', null, creating ? 'ثبت شخص جدید' : `ویرایش ${fullName(person)}`),
        creating ? h('p', { class: 'muted', style: { margin: 0 } }, 'برای ساخت ریشه یک خاندان جدید (مثلاً جد اعلا). بستگان دیگر را بعداً از روی درخت اضافه کنید.') : null,
      ),
    ),
    form,
  );

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFormErrors(form);
    const data = collectPerson(form);
    if (!sensitive) {
      ['national_code', 'phone', 'birth_cert_no', 'email', 'password', 'is_locked'].forEach((k) => delete data[k]);
    }
    await withLoading(saveBtn, async () => {
      try {
        const res = creating ? await post('/api/persons', data) : await patch(`/api/persons/${person.id}`, data);
        toast('ذخیره شد.');
        if (!creating && store.user?.person?.id === person.id) store.refreshCounters?.();
        navigate(`/person/${res.data.id}`);
      } catch (err) {
        showFormErrors(form, err);
      }
    });
  });

  async function remove() {
    if (!(await confirmDialog(`«${fullName(person)}» از شجره‌نامه حذف شود؟ (مدیر می‌تواند آن را بازیابی کند)`, { danger: true, okLabel: 'حذف' }))) return;
    try {
      await del(`/api/persons/${person.id}`);
      toast('حذف شد.');
      navigate('/');
    } catch (e) {
      toastError(e);
    }
  }
}
