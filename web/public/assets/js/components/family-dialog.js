/**
 * ساخت/ویرایش خاندان (عنوان + جد اعلا)
 */
import { h } from '../core/dom.js';
import { post, put, del } from '../core/api.js';
import { modal, toast, field, showFormErrors, confirmDialog } from '../core/ui.js';
import { searchBox, personRow } from './person-search.js';

const COLORS = ['#0f766e', '#155e75', '#1d4ed8', '#7c3aed', '#be185d', '#b45309', '#15803d', '#334155'];

export function openFamilyDialog(family, onDone) {
  let rootId = family?.root?.id || null;
  let color = family?.color || COLORS[0];
  const selected = h('div', null, family?.root ? personRow(family.root) : null);
  const colors = h('div', { class: 'row wrap' });
  const renderColors = () => colors.replaceChildren(...COLORS.map((c) => h('button', {
    type: 'button',
    title: c,
    style: { width: '32px', height: '32px', borderRadius: '50%', border: c === color ? '3px solid var(--text)' : '2px solid var(--surface)', background: c, cursor: 'pointer', boxShadow: 'var(--shadow-sm)' },
    onclick: () => {
      color = c;
      renderColors();
    },
  })));
  renderColors();

  const form = h('form', { novalidate: true },
    field('نام خاندان', h('input', { class: 'input', name: 'name', value: family?.name || '', placeholder: 'مثلاً: خاندان احمدی' })),
    h('div', { class: 'field' },
      h('label', null, 'جد اعلا (ریشه درخت)'),
      searchBox({ inline: true, placeholder: 'جستجوی نام جد...', onSelect: (p) => { rootId = p.id; selected.replaceChildren(personRow(p)); } }),
      selected,
      h('div', { class: 'hint' }, 'اگر جد اعلا هنوز ثبت نشده، ابتدا از صفحه «شخص جدید» او را بسازید.'),
    ),
    field('توضیحات', h('textarea', { class: 'input', name: 'description', rows: 3 }, family?.description || '')),
    h('div', { class: 'field' }, h('label', null, 'رنگ'), colors),
  );

  const actions = [
    { label: 'انصراف', class: 'ghost' },
    {
      label: family ? 'ذخیره' : 'ساخت خاندان',
      class: 'primary',
      onClick: async () => {
        const body = { name: form.name.value.trim(), description: form.description.value.trim() || null, root_person_id: rootId, color };
        try {
          if (family) await put(`/api/families/${family.id}`, body);
          else await post('/api/families', body);
          toast('خاندان ذخیره شد.');
          onDone?.();
        } catch (err) {
          showFormErrors(form, err);
          return false;
        }
      },
    },
  ];
  if (family?.can_edit) {
    actions.unshift({
      label: 'حذف',
      class: 'danger',
      close: false,
      onClick: async ({ close }) => {
        if (await confirmDialog('این خاندان حذف شود؟ (اشخاص درخت حذف نمی‌شوند)', { danger: true })) {
          await del(`/api/families/${family.id}`);
          toast('حذف شد.');
          close();
          onDone?.();
        }
      },
    });
  }

  const newPersonLink = h('a', { href: '#/new-person', class: 'small', onclick: () => dlg.close() }, '+ ساخت شخص جدید (جد اعلا)');
  form.append(newPersonLink);
  const dlg = modal({ title: family ? 'ویرایش خاندان' : 'خاندان جدید', body: form, actions });
}
