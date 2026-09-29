/**
 * سوابق رزومه (تحصیل، کار، سربازی، جایزه ...) به صورت خط زمانی
 * افزودن/ویرایش/حذف برای خود شخص و بستگان درجه یک؛ همه تغییرات در تاریخچه ثبت می‌شود.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { post, patch, del } from '../core/api.js';
import { store } from '../core/store.js';
import { formatDate } from '../core/format.js';
import { modal, field, switchInput, toast, toastError, confirmDialog, showFormErrors, clearFormErrors, formData } from '../core/ui.js';
import { dateInput } from './date-input.js';
import { authorColor, contributorLabel } from './attributed-text.js';
import { authorLink } from './author.js';

const TYPE_ICONS = {
  education: 'graduation', work: 'briefcase', military: 'shield', award: 'star', certificate: 'file',
  publication: 'book', skill: 'sparkles', volunteer: 'heart', travel: 'compass', other: 'info',
};

/**
 * @param {object} ctx { person, byId: Map, colored: () => bool, onContributors, onUsed }
 */
export function resumeSection(ctx) {
  const { person } = ctx;
  const canEdit = !!person.permissions?.edit;
  let items = [...(person.resume || [])];
  const types = store.config.profile?.resume_types || {};
  const list = h('div', { class: 'resume-list' });

  const card = h('section', { class: 'card' },
    h('div', { class: 'card-title' },
      h('h3', null, icon('briefcase'), ' سوابق و رزومه'),
      canEdit ? h('button', { class: 'btn soft sm', type: 'button', onclick: () => openForm() }, icon('plus'), 'افزودن سابقه') : null,
    ),
    list,
  );

  function render() {
    ctx.onUsed?.(items.map((i) => i.author_id));
    if (!items.length) {
      list.replaceChildren(h('p', { class: 'muted small', style: { margin: 0 } }, canEdit ? 'تحصیلات، سوابق کاری، خدمت سربازی، افتخارات، مهارت‌ها و ... را اینجا اضافه کنید.' : 'سابقه‌ای ثبت نشده است.'));
      return;
    }
    list.replaceChildren(...items.map((item) => {
      const author = ctx.byId.get(item.author_id);
      const range = [formatDate(item.start_date), item.is_current ? 'اکنون' : formatDate(item.end_date)].filter(Boolean).join(' تا ');
      return h('div', { class: 'resume-item', style: ctx.colored() && author ? { '--c': authorColor(author) } : null },
        h('div', { class: 'ri-icon' }, icon(TYPE_ICONS[item.type] || 'info')),
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', { class: 'ri-title' }, item.title, h('span', { class: 'chip', style: { marginInlineStart: '6px' } }, types[item.type] || item.type)),
          item.organization || item.location ? h('div', { class: 'text-2 small' }, [item.organization, item.location].filter(Boolean).join(' • ')) : null,
          range ? h('div', { class: 'muted tiny' }, range) : null,
          item.description ? h('p', { class: 'small', style: { whiteSpace: 'pre-line', margin: '4px 0 0' } }, item.description) : null,
          author ? h('div', { class: 'muted tiny ri-author', title: contributorLabel(author) }, authorLink(author, { label: 'ثبت', withIcon: false })) : null,
        ),
        canEdit ? h('div', { class: 'row', style: { gap: '2px', alignSelf: 'flex-start' } },
          h('button', { class: 'icon-btn', type: 'button', title: 'ویرایش', onclick: () => openForm(item) }, icon('edit')),
          h('button', { class: 'icon-btn', type: 'button', title: 'حذف', onclick: () => remove(item) }, icon('trash')),
        ) : null,
      );
    }));
  }

  function openForm(item = null) {
    const start = dateInput('start_date', item?.start_date || '');
    const end = dateInput('end_date', item?.end_date || '');
    const endField = h('div', { class: 'field', hidden: !!item?.is_current }, h('label', null, 'تا تاریخ'), end);
    const form = h('form', { novalidate: true },
      h('div', { class: 'form-grid' },
        field('نوع', h('select', { class: 'input', name: 'type' }, ...Object.entries(types).map(([k, l]) => h('option', { value: k, selected: k === (item?.type || 'work') }, l)))),
        field('عنوان', h('input', { class: 'input', name: 'title', value: item?.title || '', required: true, placeholder: 'مثلاً کارشناسی حقوق، مدیر فروش، سرباز وظیفه' })),
        field('سازمان / دانشگاه / محل', h('input', { class: 'input', name: 'organization', value: item?.organization || '' })),
        field('شهر / کشور', h('input', { class: 'input', name: 'location', value: item?.location || '' })),
        h('div', { class: 'field' }, h('label', null, 'از تاریخ'), start),
        endField,
        h('div', { class: 'field full' }, switchInput('is_current', 'هنوز ادامه دارد', !!item?.is_current, (v) => { endField.hidden = v; })),
        field('توضیحات', h('textarea', { class: 'input', name: 'description', rows: 3 }, item?.description || ''), { full: true }),
      ),
    );
    modal({
      title: item ? 'ویرایش سابقه' : 'افزودن سابقه',
      body: form,
      actions: [
        { label: 'انصراف', class: 'ghost' },
        { label: 'ذخیره', class: 'primary', icon: 'check', onClick: async () => {
          clearFormErrors(form);
          const data = formData(form);
          data.start_date = start.value || null;
          data.end_date = data.is_current ? null : (end.value || null);
          try {
            const res = item ? await patch(`/api/resume/${item.id}`, data) : await post(`/api/persons/${person.id}/resume`, data);
            items = item ? items.map((i) => (i.id === item.id ? res.data : i)) : [res.data, ...items];
            ctx.onContributors?.(res.contributors);
            render();
            toast('ذخیره شد.');
          } catch (e) {
            showFormErrors(form, e);
            return false;
          }
        } },
      ],
    });
  }

  async function remove(item) {
    if (!(await confirmDialog(`«${item.title}» از رزومه حذف شود؟ (در تاریخچه پروفایل ثبت می‌شود)`, { danger: true, okLabel: 'حذف' }))) return;
    try {
      await del(`/api/resume/${item.id}`);
      items = items.filter((i) => i.id !== item.id);
      render();
    } catch (e) {
      toastError(e);
    }
  }

  render();
  card.rerender = render;
  return card;
}
