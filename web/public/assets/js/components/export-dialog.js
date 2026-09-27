/**
 * پنجره خروجی PDF / تصویر / چاپ
 *
 * کاربر انتخاب می‌کند چه بخشی از شجره‌نامه خروجی گرفته شود:
 *   - نمای فعلی (همان چیزی که روی صفحه است)
 *   - نوادگان یک شخص / نیاکان یک شخص (مثلاً «شجره‌نامه من تا جد اعلا») / ساعت شنی
 *   - مسیر بین دو نفر («از فلانی تا فلانی»)
 * و قالب خروجی: PDF (تک‌صفحه یا پوستری چندصفحه)، PNG، SVG برداری، یا چاپ مستقیم.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, download } from '../core/api.js';
import { store } from '../core/store.js';
import { modal, toast, toastError, field, segmented, switchInput } from '../core/ui.js';
import { fa, fullName, dateTime } from '../core/format.js';
import { saveFile } from '../core/native.js';
import { TreeData } from '../tree/model.js';
import { layoutDescendants, layoutAncestors, layoutHourglass } from '../tree/layout.js';
import { buildExportSvg, exportPdf, exportPng, exportSvg, printSvg, tileCount } from '../tree/export.js';
import { personRow, searchBox } from './person-search.js';

const SCOPES = [
  { value: 'current', label: 'نمای فعلی', icon: 'grid' },
  { value: 'descendants', label: 'نوادگان', icon: 'tree' },
  { value: 'ancestors', label: 'نیاکان', icon: 'ancestors' },
  { value: 'hourglass', label: 'ساعت شنی', icon: 'hourglass' },
  { value: 'lineage', label: 'از ... تا ...', icon: 'route' },
];

export function openExportDialog(ctx) {
  const opts = {
    scope: 'current',
    personId: ctx.rootId,
    toId: ctx.to,
    depth: Math.max(ctx.depth || 4, 1),
    format: 'pdf',
    paper: 'A3',
    orientation: 'auto',
    pdfMode: 'fit',
    tileScale: 100,
    photos: store.prefs.show_photos !== false,
    grayscale: false,
    title: '',
  };
  let built = null;
  let buildToken = 0;
  const root = ctx.data.get(ctx.rootId);
  opts.title = defaultTitle();

  const preview = h('div', { class: 'card', style: { padding: '8px', minHeight: '220px', display: 'grid', placeItems: 'center', background: 'var(--surface-2)' } });
  const info = h('div', { class: 'muted small' });
  const scopeBox = h('div');
  const formatBox = h('div');
  const titleInput = h('input', { class: 'input', value: opts.title, oninput: () => { opts.title = titleInput.value; rebuild(); } });

  const body = h('div', { class: 'grid grid-2' },
    h('div', null,
      h('div', { class: 'field' }, h('label', null, 'چه بخشی از شجره‌نامه؟'), segmented(SCOPES, opts.scope, (v) => { opts.scope = v; renderScope(); if (!opts.title || opts.title === lastDefault) setTitle(defaultTitle()); rebuild(); })),
      scopeBox,
      field('عنوان', titleInput),
      h('div', { class: 'row wrap', style: { gap: '18px', marginBottom: '14px' } },
        switchInput('photos', 'عکس‌ها', opts.photos, (v) => { opts.photos = v; rebuild(); }),
        switchInput('gray', 'سیاه و سفید', opts.grayscale, (v) => { opts.grayscale = v; rebuild(); }),
      ),
      h('div', { class: 'field' }, h('label', null, 'قالب خروجی'), segmented([
        { value: 'pdf', label: 'PDF', icon: 'file' },
        { value: 'print', label: 'چاپ', icon: 'printer' },
        { value: 'png', label: 'تصویر', icon: 'image' },
        { value: 'svg', label: 'SVG', icon: 'layers' },
      ], opts.format, (v) => { opts.format = v; renderFormat(); })),
      formatBox,
      h('div', { class: 'mt' },
        h('button', { class: 'btn ghost sm', type: 'button', onclick: gedcom }, icon('gedcom'), 'خروجی GEDCOM (برای MyHeritage، Gramps و ...)'),
      ),
    ),
    h('div', null, h('label', { class: 'label' }, 'پیش‌نمایش'), preview, info),
  );

  let lastDefault = opts.title;
  function setTitle(t) {
    opts.title = t;
    lastDefault = t;
    titleInput.value = t;
  }

  function defaultTitle() {
    const person = ctx.data.get(opts.personId) || root;
    const name = fullName(person);
    switch (opts.scope) {
      case 'ancestors': return `نیاکان ${name}`;
      case 'hourglass': return `شجره‌نامه ${name}`;
      case 'lineage': return `مسیر نسبی ${name}`;
      case 'descendants': return `شجره‌نامه نوادگان ${name}`;
      default: return `شجره‌نامه ${fullName(root)}`;
    }
  }

  function personPicker(label, getId, setId) {
    const current = ctx.data.get(getId());
    const holder = h('div', null, current ? personRow(current) : h('div', { class: 'muted small' }, 'انتخاب نشده'));
    const box = searchBox({
      inline: true,
      placeholder: 'تغییر شخص...',
      onSelect: (p) => {
        setId(p.id);
        holder.replaceChildren(personRow(p));
        box.input.value = '';
        box.querySelector('.choice-list')?.replaceChildren();
        setTitle(defaultTitle());
        rebuild();
      },
    });
    return h('div', { class: 'field' }, h('label', null, label), holder, box);
  }

  function renderScope() {
    scopeBox.replaceChildren();
    if (opts.scope === 'current') {
      scopeBox.append(h('p', { class: 'muted small' }, 'همان بخشی که الان روی صفحه می‌بینید (با شاخه‌های باز و بسته).'));
      return;
    }
    if (opts.scope === 'lineage') {
      scopeBox.append(
        personPicker('از (جد بالاتر)', () => opts.personId, (id) => (opts.personId = id)),
        personPicker('تا (نواده)', () => opts.toId, (id) => (opts.toId = id)),
      );
    } else {
      scopeBox.append(personPicker('شخص', () => opts.personId, (id) => (opts.personId = id)));
    }
    const depthLabel = h('b', null, fa(opts.depth));
    const range = h('input', { type: 'range', min: opts.scope === 'lineage' ? 0 : 1, max: store.config.tree?.max_depth || 30, value: opts.depth, style: { flex: 1, accentColor: 'var(--primary)' } });
    range.addEventListener('input', () => {
      opts.depth = +range.value;
      depthLabel.textContent = fa(opts.depth);
    });
    range.addEventListener('change', rebuild);
    scopeBox.append(h('div', { class: 'field' },
      h('label', null, opts.scope === 'lineage' ? 'نسل‌های پایین‌تر از نفر آخر' : opts.scope === 'ancestors' ? 'تعداد نسل به بالا' : 'تعداد نسل'),
      h('div', { class: 'row' }, range, depthLabel),
      opts.scope === 'ancestors' ? h('div', { class: 'hint' }, 'برای رسیدن به جد اعلا عدد را بزرگ کنید؛ تا جایی که اطلاعات ثبت شده نمایش داده می‌شود.') : null,
    ));
  }

  function renderFormat() {
    formatBox.replaceChildren();
    if (opts.format === 'pdf' || opts.format === 'print') {
      const paper = h('select', { class: 'input', onchange: () => { opts.paper = paper.value; updateInfo(); } },
        ...['A4', 'A3', 'A2', 'A1', 'A0'].map((p) => h('option', { value: p, selected: p === opts.paper }, p)));
      const orient = h('select', { class: 'input', onchange: () => { opts.orientation = orient.value; updateInfo(); } },
        h('option', { value: 'auto' }, 'خودکار'), h('option', { value: 'landscape' }, 'افقی'), h('option', { value: 'portrait' }, 'عمودی'));
      orient.value = opts.orientation;
      formatBox.append(h('div', { class: 'form-grid' }, field('اندازه کاغذ', paper), field('جهت', orient)));
    }
    if (opts.format === 'pdf') {
      const scale = h('input', { class: 'input', type: 'number', min: 30, max: 200, value: opts.tileScale, onchange: () => { opts.tileScale = +scale.value || 100; updateInfo(); } });
      const scaleField = field('اندازه درخت در چاپ پوستری (درصد)', scale, { hint: 'برای چسباندن صفحات کنار هم و ساخت پوستر بزرگ' });
      scaleField.hidden = opts.pdfMode !== 'tiles';
      formatBox.append(
        h('div', { class: 'field' }, h('label', null, 'چیدمان صفحه'), segmented([
          { value: 'fit', label: 'همه در یک صفحه' },
          { value: 'tiles', label: 'پوستری (چند صفحه)' },
        ], opts.pdfMode, (v) => { opts.pdfMode = v; scaleField.hidden = v !== 'tiles'; updateInfo(); })),
        scaleField,
      );
    }
    if (opts.format === 'print') {
      formatBox.append(h('p', { class: 'muted small' }, 'در پنجره چاپ می‌توانید «Save as PDF» را هم انتخاب کنید تا یک PDF کاملاً برداری (با کیفیت نامحدود) داشته باشید.'));
    }
    updateInfo();
  }

  function updateInfo() {
    if (!built) return;
    const parts = [`${fa(built.count)} نفر`, `ابعاد ${fa(built.width)}×${fa(built.height)}`];
    if (opts.format === 'pdf' && opts.pdfMode === 'tiles') {
      const t = tileCount(built, opts);
      parts.push(`${fa(t.total)} صفحه ${opts.paper} (${fa(t.rows)} ردیف × ${fa(t.cols)} ستون)`);
    }
    info.textContent = parts.join(' • ');
  }

  // ------------------------------------------------------------ ساخت
  async function layoutFor() {
    const prefs = store.prefs;
    const base = { rtl: prefs.children_order !== 'ltr', compact: !!prefs.compact };
    if (opts.scope === 'current') return ctx.layout;
    let payload;
    if (opts.scope === 'descendants') payload = await get(`/api/tree/${opts.personId}/descendants`, { depth: opts.depth });
    else if (opts.scope === 'ancestors') payload = await get(`/api/tree/${opts.personId}/ancestors`, { depth: opts.depth });
    else if (opts.scope === 'hourglass') payload = await get(`/api/tree/${opts.personId}/hourglass`, { up: opts.depth, down: opts.depth });
    else {
      if (!opts.toId) throw new Error('شخص دوم (تا) را انتخاب کنید.');
      payload = await get('/api/tree/lineage', { from: opts.personId, to: opts.toId, down: opts.depth });
    }
    const data = new TreeData(payload);
    if (opts.scope === 'descendants') return layoutDescendants(data, opts.personId, base);
    if (opts.scope === 'ancestors') return layoutAncestors(data, opts.personId, base);
    if (opts.scope === 'hourglass') return layoutHourglass(data, opts.personId, base);
    const path = payload.meta.path;
    return layoutDescendants(data, payload.focus, { ...base, path: new Set(path), targetId: path[path.length - 1] });
  }

  let rebuildTimer = null;
  function rebuild() {
    clearTimeout(rebuildTimer);
    rebuildTimer = setTimeout(doBuild, 350);
  }

  async function doBuild() {
    const my = ++buildToken;
    preview.replaceChildren(h('div', { class: 'spinner' }));
    try {
      const layout = await layoutFor();
      const result = await buildExportSvg(layout, {
        title: opts.title,
        subtitle: `${store.config.site_name || ''} - ${dateTime(new Date().toISOString(), false)}`,
        prefs: store.prefs,
        photos: opts.photos,
        grayscale: opts.grayscale,
        siteName: store.config.site_name,
      });
      if (my !== buildToken) return;
      built = { ...result, count: layout.nodes.length };
      const url = URL.createObjectURL(new Blob([result.svg], { type: 'image/svg+xml' }));
      const img = h('img', { src: url, alt: 'پیش‌نمایش', style: { maxWidth: '100%', maxHeight: '340px', borderRadius: '10px', boxShadow: 'var(--shadow-sm)', background: '#fff' } });
      img.onload = () => setTimeout(() => URL.revokeObjectURL(url), 1000);
      preview.replaceChildren(img);
      updateInfo();
    } catch (e) {
      if (my !== buildToken) return;
      built = null;
      preview.replaceChildren(h('div', { class: 'muted center' }, e.message || 'خطا در ساخت پیش‌نمایش'));
    }
  }

  async function run({ button }) {
    if (!built) {
      toast('پیش‌نمایش هنوز آماده نیست.', 'warning');
      return false;
    }
    const name = (opts.title || 'pedigree').replace(/[\\/:*?"<>|]+/g, ' ').trim();
    try {
      if (opts.format === 'svg') await exportSvg(built, `${name}.svg`);
      else if (opts.format === 'png') await exportPng(built, `${name}.png`);
      else if (opts.format === 'print') printSvg(built, opts);
      else {
        const label = button.textContent;
        await exportPdf(built, `${name}.pdf`, {
          ...opts,
          mode: opts.pdfMode,
          title: opts.title,
          onProgress: (p) => (button.lastChild.textContent = `در حال ساخت... ${fa(Math.round(p * 100))}٪`),
        });
        button.lastChild.textContent = label;
      }
      if (opts.format !== 'print') toast('خروجی آماده شد.');
    } catch (e) {
      toastError(e);
      return false;
    }
  }

  async function gedcom() {
    try {
      const scope = opts.scope === 'current' || opts.scope === 'lineage' ? (ctx.mode === 'ancestors' ? 'ancestors' : ctx.mode === 'hourglass' ? 'hourglass' : 'descendants') : opts.scope;
      const { blob, name } = await download('/api/export/gedcom', { mode: scope, person: opts.personId, depth: opts.depth });
      await saveFile(blob, name);
    } catch (e) {
      toastError(e);
    }
  }

  modal({
    title: 'خروجی و چاپ شجره‌نامه',
    size: 'xwide',
    body,
    actions: [
      { label: 'بستن', class: 'ghost' },
      { label: 'ساخت خروجی', class: 'primary', icon: 'download', close: false, onClick: run },
    ],
  });
  renderScope();
  renderFormat();
  doBuild();
}
