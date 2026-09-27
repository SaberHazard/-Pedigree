/**
 * تنظیمات حساب: ظاهر، رمز عبور، شماره موبایل، نشست‌ها و دستگاه‌ها
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, put, post, del } from '../core/api.js';
import { store, applyTheme, saveLocalPrefs } from '../core/store.js';
import { fa, fullName, timeAgo, latin } from '../core/format.js';
import { toast, toastError, field, segmented, switchInput, showFormErrors, clearFormErrors, withLoading, confirmDialog, loader } from '../core/ui.js';
import { avatar } from '../components/avatar.js';
import { logout } from '../components/header.js';

export default function accountPage(container) {
  const user = store.user;
  const page = h('div', { class: 'page narrow' });
  container.append(page);

  page.append(
    h('div', { class: 'card row mb' },
      avatar(user.person, 'lg'),
      h('div', { class: 'grow' },
        h('h2', { style: { margin: 0 } }, fullName(user.person)),
        h('div', { class: 'muted small' }, { super_admin: 'مدیر کل', admin: 'مدیر', member: 'عضو' }[user.role]),
      ),
      h('a', { class: 'btn soft', href: `#/person/${user.person.id}` }, icon('user'), 'پروفایل من'),
    ),
    appearance(),
    passwordCard(),
    usernameCard(),
    phoneCard(),
    sessionsCard(),
    h('div', { class: 'card mt row between' },
      h('div', null, h('b', null, 'خروج از حساب'), h('div', { class: 'muted small' }, 'از این دستگاه خارج می‌شوید.')),
      h('button', { class: 'btn danger', type: 'button', onclick: logout }, icon('logout'), 'خروج'),
    ),
  );

  // ------------------------------------------------------------ ظاهر
  function appearance() {
    const prefs = store.prefs;
    const save = async (key, value) => {
      saveLocalPrefs({ [key]: value });
      try {
        const res = await put('/api/account/preferences', { [key]: value });
        store.user = res.data;
      } catch (e) {
        toastError(e);
      }
      if (key === 'theme') applyTheme(value);
    };
    return h('div', { class: 'card mb' },
      h('div', { class: 'card-title' }, h('h3', null, 'ظاهر و نمایش درخت'), icon('palette')),
      h('div', { class: 'field' }, h('label', null, 'تم'), segmented([
        { value: 'auto', label: 'خودکار' }, { value: 'light', label: 'روشن', icon: 'sun' }, { value: 'dark', label: 'تیره', icon: 'moon' },
      ], prefs.theme, (v) => save('theme', v))),
      h('div', { class: 'form-grid' },
        field('متن بالای دایره', select('arc_top', prefs.arc_top, { name: 'نام (با دکتر/مهندس)', fullname: 'نام با همه عنوان‌ها', nickname: 'شهرت', none: 'هیچ' }, save)),
        field('متن پایین دایره', select('arc_bottom', prefs.arc_bottom, { dates: 'سال تولد و وفات', place: 'محل تولد', occupation: 'شغل', education: 'تحصیلات', city: 'شهر محل زندگی', none: 'هیچ' }, save)),
        field('ترتیب فرزندان', select('children_order', prefs.children_order, { rtl: 'ارشد سمت راست', ltr: 'ارشد سمت چپ' }, save)),
      ),
      h('div', { class: 'row wrap', style: { gap: '20px' } },
        switchInput('show_photos', 'نمایش عکس‌ها در درخت', prefs.show_photos !== false, (v) => save('show_photos', v)),
        switchInput('compact', 'نمایش فشرده درخت', !!prefs.compact, (v) => save('compact', v)),
      ),
    );
  }

  function select(name, value, options, onChange) {
    const el = h('select', { class: 'input', name, onchange: () => onChange(name, el.value) },
      ...Object.entries(options).map(([v, l]) => h('option', { value: v, selected: v === value }, l)));
    return el;
  }

  // ------------------------------------------------------------ رمز عبور
  function passwordCard() {
    const form = h('form', { novalidate: true },
      user.has_password ? field('رمز فعلی', h('input', { class: 'input ltr-input', type: 'password', name: 'current_password', autocomplete: 'current-password' }), { hint: 'اگر همین الان با پیامک وارد شده‌اید، لازم نیست.' }) : null,
      h('div', { class: 'form-grid' },
        field('رمز جدید', h('input', { class: 'input ltr-input', type: 'password', name: 'password', autocomplete: 'new-password' }), { hint: 'حداقل ۸ کاراکتر، شامل حرف و عدد' }),
        field('تکرار رمز جدید', h('input', { class: 'input ltr-input', type: 'password', name: 'password_confirmation', autocomplete: 'new-password' })),
      ),
    );
    const btn = h('button', { class: 'btn primary', type: 'submit' }, 'ذخیره رمز');
    form.append(btn);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFormErrors(form);
      await withLoading(btn, async () => {
        try {
          const res = await put('/api/account/password', {
            current_password: form.current_password?.value || null,
            password: form.password.value,
            password_confirmation: form.password_confirmation.value,
          });
          toast(res.message);
          form.reset();
          store.user.has_password = true;
        } catch (err) {
          showFormErrors(form, err);
        }
      });
    });
    return h('div', { class: 'card mb' },
      h('div', { class: 'card-title' }, h('h3', null, user.has_password ? 'تغییر رمز عبور' : 'تعیین رمز عبور'), icon('key')),
      h('p', { class: 'muted small' }, 'با رمز عبور می‌توانید با کد ملی، نام کاربری یا شماره موبایل هم وارد شوید (وقتی پیامک به دستتان نمی‌رسد).'),
      form,
    );
  }

  // ------------------------------------------------------------ نام کاربری
  function usernameCard() {
    const input = h('input', { class: 'input ltr-input', name: 'username', value: user.username || '', autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false', maxlength: 30, placeholder: 'مثلاً ali.ahmadi' });
    const form = h('form', { novalidate: true }, field('نام کاربری', input, { hint: 'حروف کوچک انگلیسی، عدد، نقطه، خط تیره یا زیرخط؛ با حرف شروع شود. خالی = حذف' }));
    const btn = h('button', { class: 'btn primary', type: 'submit' }, 'ذخیره نام کاربری');
    form.append(btn);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFormErrors(form);
      await withLoading(btn, async () => {
        try {
          const res = await put('/api/account/username', { username: latin(input.value).trim() || null });
          store.user.username = res.username;
          input.value = res.username || '';
          toast(res.message);
        } catch (err) {
          showFormErrors(form, err);
        }
      });
    });
    return h('div', { class: 'card mb' },
      h('div', { class: 'card-title' }, h('h3', null, 'نام کاربری'), icon('user')),
      h('p', { class: 'muted small' }, 'اختیاری؛ برای ورود با «نام کاربری + رمز» به جای کد ملی. برای پدربزرگ و مادربزرگی که موبایل ندارند، از صفحه ویرایش پروفایل آن‌ها نام کاربری و رمز تعیین کنید.'),
      form,
    );
  }

  // ------------------------------------------------------------ موبایل
  function phoneCard() {
    const phone = h('input', { class: 'input ltr-input', type: 'tel', name: 'phone', placeholder: '09xxxxxxxxx' });
    const code = h('input', { class: 'input ltr-input', name: 'code', inputmode: 'numeric', placeholder: 'کد تأیید', hidden: true });
    const btn = h('button', { class: 'btn', type: 'submit' }, 'ارسال کد تأیید');
    let step = 1;
    const form = h('form', { novalidate: true }, h('div', { class: 'form-grid' }, field('شماره جدید', phone), h('div', { class: 'field' }, h('label', null, ' '), code)), btn);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFormErrors(form);
      await withLoading(btn, async () => {
        try {
          if (step === 1) {
            await post('/api/account/phone/otp', { phone: latin(phone.value) });
            step = 2;
            code.hidden = false;
            code.focus();
            btn.textContent = 'تأیید و ذخیره';
            toast('کد تأیید به شماره جدید ارسال شد.');
          } else {
            const res = await put('/api/account/phone', { phone: latin(phone.value), code: latin(code.value) });
            toast(res.message);
            step = 1;
            form.reset();
            code.hidden = true;
            btn.textContent = 'ارسال کد تأیید';
          }
        } catch (err) {
          showFormErrors(form, err);
        }
      });
    });
    return h('div', { class: 'card mb' },
      h('div', { class: 'card-title' }, h('h3', null, 'تغییر شماره موبایل'), icon('phone')),
      form,
    );
  }

  // ------------------------------------------------------------ نشست‌ها
  function sessionsCard() {
    const list = h('div', null, loader());
    const load = async () => {
      try {
        const res = await get('/api/account/sessions');
        list.replaceChildren(...res.data.map((s) => h('div', { class: 'person-row' },
          h('div', { class: 'stat' }, h('div', { class: 's-icon', style: { width: '40px', height: '40px' } }, icon(s.type === 'web' ? 'monitor' : 'phone'))),
          h('div', { class: 'grow' },
            h('div', { class: 'bold' }, s.name || 'دستگاه', s.current ? h('span', { class: 'chip success', style: { marginInlineStart: '8px' } }, 'همین دستگاه') : null),
            h('div', { class: 'muted tiny' }, [s.ip_address ? `IP: ${s.ip_address}` : null, s.last_used_at ? `آخرین فعالیت ${timeAgo(s.last_used_at)}` : null].filter(Boolean).join(' • ')),
          ),
          s.current ? null : h('button', { class: 'btn ghost sm', type: 'button', onclick: async () => {
            if (!(await confirmDialog('این نشست بسته شود؟'))) return;
            await del(`/api/account/sessions/${encodeURIComponent(s.id)}`);
            toast('نشست بسته شد.');
            load();
          } }, icon('logout'), 'خروج'),
        )));
        if (!res.data.length) list.replaceChildren(h('p', { class: 'muted' }, 'نشستی یافت نشد.'));
      } catch (e) {
        list.replaceChildren(h('p', { class: 'muted' }, e.message));
      }
    };
    load();
    return h('div', { class: 'card mb' },
      h('div', { class: 'card-title' }, h('h3', null, 'دستگاه‌ها و نشست‌های فعال'), icon('shield')),
      h('p', { class: 'muted small' }, 'اگر دستگاه ناآشنایی می‌بینید، آن را خارج کنید و رمز خود را تغییر دهید.'),
      list,
    );
  }
}
