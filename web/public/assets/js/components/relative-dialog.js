/**
 * پنجره افزودن بستگان: فرزند، همسر، پدر، مادر، خواهر/برادر
 *   - «شخص جدید»: ساخت پروفایل تازه
 *   - «از افراد موجود»: اتصال شخصی که قبلاً (مثلاً در درخت خانواده دیگری) ثبت شده
 *     → این همان «وصل کردن درخت همسر» است
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post } from '../core/api.js';
import { modal, toast, segmented, field, showFormErrors, clearFormErrors } from '../core/ui.js';
import { fullName, fa } from '../core/format.js';
import { avatar } from './avatar.js';
import { personFields, collectPerson } from './person-form.js';
import { searchBox, personRow } from './person-search.js';
import { dateInput } from './date-input.js';
import { RELATIONS, MARRIAGE_STATUS } from './labels.js';

/**
 * @param {object} anchor  شخصی که بستگان برایش اضافه می‌شود
 * @param {string} type    child|spouse|father|mother|sibling
 * @param {object} opts    onDone(person)
 */
export async function openRelativeDialog(anchor, type = 'child', { onDone } = {}) {
  let relatives = null;
  try {
    relatives = await get(`/api/persons/${anchor.id}/relatives`);
  } catch {
    relatives = { spouses: [] };
  }

  let mode = 'new';
  const typesEl = h('div', { class: 'rel-types' });
  const modeEl = h('div', { class: 'mb' });
  const content = h('form', { novalidate: true });
  const body = h('div', null,
    h('p', { class: 'muted small', style: { marginTop: 0 } }, 'برای ', h('b', null, fullName(anchor)), ' چه کسی را اضافه می‌کنید؟'),
    typesEl, modeEl, content,
  );

  const disabled = {
    father: !!anchor.father_id,
    mother: !!anchor.mother_id,
    sibling: !anchor.father_id && !anchor.mother_id,
  };
  if (disabled[type]) type = 'child';

  const dlg = modal({
    title: 'افزودن بستگان',
    size: 'wide',
    body,
    actions: [
      { label: 'انصراف', class: 'ghost' },
      { label: 'ذخیره', class: 'primary', icon: 'check', onClick: submit },
    ],
  });

  function renderTypes() {
    typesEl.replaceChildren(...Object.entries(RELATIONS).map(([key, r]) => h('button', {
      type: 'button',
      class: key === type ? 'active' : '',
      disabled: disabled[key],
      title: disabled[key] ? (key === 'sibling' ? 'ابتدا پدر یا مادر را ثبت کنید' : 'قبلاً ثبت شده است') : '',
      style: disabled[key] ? { opacity: 0.4 } : null,
      onclick: () => {
        type = key;
        if (type === 'sibling') mode = 'new';
        render();
      },
    }, icon(r.icon), r.label)));
  }

  function render() {
    renderTypes();
    modeEl.replaceChildren(type === 'sibling' ? '' : segmented([
      { value: 'new', label: 'شخص جدید', icon: 'user-plus' },
      { value: 'existing', label: 'انتخاب از افراد موجود', icon: 'link' },
    ], mode, (v) => {
      mode = v;
      renderContent();
    }));
    renderContent();
  }

  // ------------------------------------------------------------ شخص جدید
  function renderContent() {
    clearFormErrors(content);
    content.replaceChildren();
    if (mode === 'existing') return renderExisting();

    const defaults = {};
    if (['child', 'sibling', 'father'].includes(type) && anchor.last_name) {
      // نام خانوادگی پدری معمولاً یکسان است
      if (type !== 'child' || anchor.gender === 'm') defaults.last_name = anchor.last_name;
    }
    content.append(personFields(defaults, { full: false, gender: ['child', 'sibling'].includes(type) }));

    if (type === 'child') content.append(otherParentChoice());
    if (type === 'spouse') content.append(marriageFields());
  }

  function otherParentChoice() {
    const spouses = relatives?.spouses || [];
    const label = anchor.gender === 'f' ? 'پدر این فرزند' : 'مادر این فرزند';
    return h('fieldset', null,
      h('legend', null, label),
      h('div', { class: 'choice-list' },
        ...spouses.map((s, i) => h('label', { class: 'choice' },
          h('input', { type: 'radio', name: 'other_parent_id', value: s.person.id, checked: i === 0 }),
          avatar(s.person, 'sm'),
          h('span', null, fullName(s.person)),
        )),
        h('label', { class: 'choice' },
          h('input', { type: 'radio', name: 'other_parent_id', value: '', checked: spouses.length === 0 }),
          h('span', { class: 'muted' }, 'نامشخص / بعداً ثبت می‌کنم'),
        ),
      ),
    );
  }

  function marriageFields() {
    return h('fieldset', null,
      h('legend', null, 'ازدواج'),
      h('div', { class: 'form-grid' },
        field('وضعیت', h('select', { class: 'input', name: 'marriage_status' }, ...Object.entries(MARRIAGE_STATUS).map(([v, l]) => h('option', { value: v }, l)))),
        h('div', { class: 'field' }, h('label', null, 'تاریخ ازدواج'), dateInput('marriage_date')),
      ),
    );
  }

  // ------------------------------------------------------------ شخص موجود
  function renderExisting() {
    const filters = {};
    if (type === 'father') filters.gender = 'm';
    if (type === 'mother') filters.gender = 'f';
    if (type === 'spouse') filters.gender = anchor.gender === 'm' ? 'f' : 'm';

    const selected = h('div');
    const hidden = h('input', { type: 'hidden', name: 'target_id' });
    const hints = {
      spouse: 'اگر همسر در درخت خانواده پدری‌اش ثبت شده، او را جستجو و انتخاب کنید تا دو درخت به هم وصل شوند و بتوان نیاکان همسر را هم دید.',
      father: 'پدری که قبلاً در شجره‌نامه ثبت شده را انتخاب کنید (مثلاً در شاخه‌ای که یکی از اقوام ساخته).',
      mother: 'مادری که قبلاً در شجره‌نامه ثبت شده را انتخاب کنید.',
      child: 'فرزندی که قبلاً ثبت شده ولی به والدش وصل نشده را انتخاب کنید.',
    };
    content.append(
      h('p', { class: 'muted small' }, hints[type]),
      searchBox({
        inline: true,
        filters,
        placeholder: 'جستجوی نام...',
        onSelect: (p) => {
          hidden.value = p.id;
          selected.replaceChildren(h('div', { class: 'card', style: { padding: '8px', borderColor: 'var(--primary)' } }, personRow(p, { extra: icon('check-circle') })));
        },
      }),
      hidden,
      h('div', { class: 'mt' }, selected),
      type === 'spouse' ? marriageFields() : null,
      field('پیام برای تأییدکننده (اختیاری)', h('input', { class: 'input', name: 'message', placeholder: 'مثلاً: همسر من است، دختر آقای ...' }), { hint: 'اگر به طرف مقابل دسترسی نداشته باشید، اتصال پس از تأیید بستگانِ او انجام می‌شود.' }),
    );
  }

  // ------------------------------------------------------------ ارسال
  async function submit() {
    const data = collectPerson(content);
    clearFormErrors(content);
    try {
      if (mode === 'existing') {
        if (!data.target_id) {
          toast('لطفاً یک شخص را انتخاب کنید.', 'warning');
          return false;
        }
        const res = await post(`/api/persons/${anchor.id}/link`, {
          type,
          target_id: data.target_id,
          marriage_status: data.marriage_status || null,
          marriage_date: data.marriage_date || null,
          message: data.message || null,
        });
        toast(res.message, res.status === 'linked' ? 'success' : 'info', 6000);
        onDone?.(null, res);
        return true;
      }

      if (!data.first_name) {
        showFormErrors(content, { errors: { first_name: ['نام را وارد کنید.'] } });
        return false;
      }
      const res = await post(`/api/persons/${anchor.id}/relatives`, { ...data, type });
      toast(`${fullName(res.data)} با موفقیت اضافه شد.`);
      onDone?.(res.data);
      return true;
    } catch (err) {
      showFormErrors(content, err);
      return false;
    }
  }

  render();
  return dlg;
}
