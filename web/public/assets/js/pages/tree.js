/**
 * صفحه درخت
 *
 * آدرس: #/tree/{personId}?mode=descendants|ancestors|hourglass|lineage&depth=4&to={id}&focus={id}
 *
 * حالت‌ها:
 *   نوادگان   - از شخص به پایین (فرزندان، نوه‌ها ...) همراه همسران
 *   نیاکان    - از شخص به بالا (پدر، پدربزرگ ... تا بالاترین جد ثبت‌شده)
 *   ساعت شنی  - هر دو با هم
 *   مسیر      - فقط مسیر نسبی بین دو نفر (مثلاً از جد اعلا تا من)
 */
import { h, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, put } from '../core/api.js';
import { store, saveLocalPrefs } from '../core/store.js';
import { navigate, updateQuery } from '../core/router.js';
import { fa, fullName } from '../core/format.js';
import { toast, toastError, segmented, dropdown, emptyState, loader } from '../core/ui.js';
import { TreeData } from '../tree/model.js';
import { layoutDescendants, layoutAncestors, layoutHourglass } from '../tree/layout.js';
import { TreeView } from '../tree/renderer.js';
import { createDrawer } from '../components/person-drawer.js';
import { pickPerson } from '../components/person-search.js';
import { openExportDialog } from '../components/export-dialog.js';
import { openRelativeDialog } from '../components/relative-dialog.js';
import { openMarriage } from '../components/marriage-sheet.js';

const DEFAULT_DEPTH = { descendants: 4, ancestors: 6, hourglass: 3, lineage: 1 };
const MODE_TITLES = { descendants: 'درخت نوادگان', ancestors: 'درخت نیاکان', hourglass: 'درخت خانوادگی', lineage: 'مسیر نسبی' };

export default async function treePage(container, { params, query }) {
  if (!params.id) {
    const me = store.user?.person;
    navigate(me ? `/tree/${me.id}?mode=hourglass` : '/', { replace: true });
    return;
  }

  const state = {
    rootId: params.id,
    mode: DEFAULT_DEPTH[query.mode] !== undefined ? query.mode : 'descendants',
    depth: null,
    to: query.to || null,
    collapsed: new Set(),
    collapsedUp: new Set(),
    data: null,
    layout: null,
    path: null,
  };
  state.depth = +query.depth || DEFAULT_DEPTH[state.mode];

  // ------------------------------------------------------------ ساختار صفحه
  const canvas = h('div', { class: 'tree-canvas' });
  const title = h('div', { class: 'tree-title' });
  const minimap = h('canvas');
  const minimapBox = h('div', { class: 'minimap', title: 'نقشه کلی درخت' }, minimap);
  const legend = h('div', { class: 'tree-legend' },
    h('span', null, h('i', { style: { background: 'linear-gradient(135deg,#2563eb,#06b6d4)' } }), 'مرد'),
    h('span', null, h('i', { style: { background: 'linear-gradient(135deg,#db2777,#a855f7)' } }), 'زن'),
    h('span', null, h('i', { style: { background: 'linear-gradient(135deg,#1f2937,#a9773f)' } }), 'شادروان'),
  );
  const toolbar = h('div', { class: 'tree-toolbar' });
  const page = h('div', { class: 'tree-page' }, canvas, title, legend, minimapBox, toolbar);
  container.append(page);

  const loading = h('div', { class: 'tree-empty' }, loader());
  page.append(loading);

  const view = new TreeView(canvas, {
    minimap,
    onNode: (id, key, node) => drawer.open(id, node),
    onMarriage: (id) => openMarriage(id, { onChange: () => load({ keepView: true }) }),
    onAction: handleAction,
    onBackground: () => drawer.close(),
  });
  minimapBox.addEventListener('click', (e) => {
    const r = minimap.getBoundingClientRect();
    view.minimapNavigate(e.clientX - r.left, e.clientY - r.top);
  });

  const drawer = createDrawer(page, {
    onAction: (action, id) => {
      if (action === 'reload') load({ keepView: true });
      if (action === 'export') openExport({ personId: id, scope: state.mode === 'ancestors' ? 'ancestors' : 'descendants' });
      if (action === 'lineage-to') {
        const top = state.mode === 'ancestors' ? id : state.rootId;
        navigate(`/tree/${top}?mode=lineage&to=${state.mode === 'ancestors' ? state.rootId : id}`);
      }
    },
  });

  // ------------------------------------------------------------ نوار ابزار
  const modeSwitch = segmented([
    { value: 'descendants', label: 'نوادگان', icon: 'tree', title: 'نوادگان (از این شخص به پایین)' },
    { value: 'ancestors', label: 'نیاکان', icon: 'ancestors', title: 'نیاکان (از این شخص به بالا)' },
    { value: 'hourglass', label: 'ساعت شنی', icon: 'hourglass', title: 'نیاکان و نوادگان با هم' },
    { value: 'lineage', label: 'مسیر', icon: 'route', title: 'مسیر نسبی بین دو نفر' },
  ], state.mode, async (mode) => {
    if (mode === 'lineage' && !state.to) {
      const target = await pickPerson({ title: 'مسیر تا چه کسی؟', hint: 'شخصی را انتخاب کنید که از نوادگان (یا نیاکان) این شخص است؛ فقط مسیر نسبی بین این دو نمایش داده می‌شود.' });
      if (!target) {
        modeSwitch.setValue(state.mode);
        return;
      }
      state.to = target.id;
    }
    state.mode = mode;
    state.depth = DEFAULT_DEPTH[mode];
    state.collapsed.clear();
    state.collapsedUp.clear();
    updateQuery({ mode, depth: state.depth, to: mode === 'lineage' ? state.to : '' });
    load();
  }, { compact: true });

  const depthValue = h('b');
  const depthStepper = h('div', { class: 'depth-stepper', title: 'تعداد نسل‌ها' },
    h('button', { class: 'btn ghost sm icon-only', type: 'button', title: 'نسل کمتر', onclick: () => changeDepth(-1) }, icon('minus')),
    h('span', { class: 'hide-mobile' }, 'نسل'), depthValue,
    h('button', { class: 'btn ghost sm icon-only', type: 'button', title: 'نسل بیشتر', onclick: () => changeDepth(1) }, icon('plus')),
  );
  const searchInput = h('input', { class: 'input', type: 'search', placeholder: 'جستجو در درخت...', style: { minHeight: '34px', width: '170px', padding: '4px 12px' } });
  const tool = (ic, label, fn) => h('button', { class: 'btn ghost sm icon-only', type: 'button', title: label, 'aria-label': label, onclick: fn }, icon(ic));

  toolbar.append(
    modeSwitch,
    h('span', { class: 'sep' }),
    depthStepper,
    h('span', { class: 'sep' }),
    tool('zoom-in', 'بزرگ‌نمایی', () => view.viewport.zoomBy(1.3)),
    tool('zoom-out', 'کوچک‌نمایی', () => view.viewport.zoomBy(0.77)),
    tool('fit', 'نمایش کل درخت', () => view.fit()),
    tool('crosshair', 'مرکز روی شخص اصلی', () => view.centerOn(state.rootId, { zoom: 1 })),
    h('span', { class: 'sep hide-mobile' }),
    h('span', { class: 'hide-mobile' }, searchInput),
    tool('settings', 'تنظیمات نمایش', (e) => settingsMenu(e.currentTarget)),
    tool('printer', 'خروجی PDF و چاپ', () => openExport()),
    tool('maximize', 'تمام صفحه', () => toggleFullscreen()),
  );

  // جستجو داخل درخت
  let matches = [];
  let matchIndex = 0;
  searchInput.addEventListener('input', debounce(() => {
    const q = searchInput.value.trim();
    if (!q || !state.data) {
      view.highlight(null);
      matches = [];
      return;
    }
    const norm = (t) => (t || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').toLowerCase();
    const ids = new Set();
    for (const p of state.data.persons.values()) {
      if (norm(`${p.display_title || p.title || ''} ${p.first_name} ${p.last_name || ''} ${p.nickname || ''}`).includes(norm(q))) ids.add(p.id);
    }
    view.highlight(ids);
    matches = state.layout.nodes.filter((n) => ids.has(n.id));
    matchIndex = 0;
    if (matches[0]) view.centerOn(matches[0].key, { zoom: Math.max(view.viewport.k, 0.9) });
  }, 300));
  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && matches.length) {
      matchIndex = (matchIndex + 1) % matches.length;
      view.centerOn(matches[matchIndex].key);
    }
    if (e.key === 'Escape') {
      searchInput.value = '';
      view.highlight(null);
    }
  });

  // ------------------------------------------------------------ بارگذاری داده
  async function fetchTree(mode, id, depth) {
    if (mode === 'descendants') return get(`/api/tree/${id}/descendants`, { depth });
    if (mode === 'ancestors') return get(`/api/tree/${id}/ancestors`, { depth });
    if (mode === 'hourglass') return get(`/api/tree/${id}/hourglass`, { up: depth + 1, down: depth });
    return get('/api/tree/lineage', { from: id, to: state.to, down: depth });
  }

  async function load({ keepView = false } = {}) {
    depthValue.textContent = fa(state.depth);
    loading.hidden = false;
    let payload;
    try {
      payload = await fetchTree(state.mode, state.rootId, state.depth);
    } catch (e) {
      loading.replaceChildren(emptyState('alert', e.message, h('a', { class: 'btn', href: '#/' }, 'بازگشت')));
      return;
    }
    loading.hidden = true;
    state.data = new TreeData(payload);
    state.path = state.mode === 'lineage' ? payload.meta.path : null;
    const topId = state.mode === 'lineage' ? payload.focus : state.rootId;
    state.topId = topId;

    const root = state.data.get(state.rootId) || state.data.get(topId);
    title.replaceChildren(
      icon(state.mode === 'ancestors' ? 'ancestors' : state.mode === 'lineage' ? 'route' : 'tree'),
      h('div', null,
        h('div', null, `${MODE_TITLES[state.mode]} ${fullName(root)}`),
        h('div', { class: 'tt-sub' }, `${fa(payload.meta.count)} نفر${payload.meta.truncated ? ' (نمایش محدود شده)' : ''}`),
      ),
    );
    document.title = `${MODE_TITLES[state.mode]} ${fullName(root)} | ${store.config.site_name}`;

    relayout(!keepView);
    if (!keepView) {
      requestAnimationFrame(() => {
        const focus = query.focus && view.keyOf(query.focus);
        if (focus) {
          view.select(focus);
          view.viewport.fit(state.layout.bounds, { animate: false });
          setTimeout(() => view.centerOn(focus, { zoom: 1 }), 300);
          drawer.open(query.focus, view.nodeOf(focus));
        } else {
          view.initialView();
        }
      });
    }
  }

  function relayout(first = false) {
    const prefs = store.prefs;
    const opts = {
      rtl: prefs.children_order !== 'ltr',
      compact: !!prefs.compact,
      collapsed: state.collapsed,
      collapsedUp: state.collapsedUp,
    };
    let layout;
    if (state.mode === 'descendants') layout = layoutDescendants(state.data, state.rootId, opts);
    else if (state.mode === 'ancestors') layout = layoutAncestors(state.data, state.rootId, { ...opts, collapsed: state.collapsedUp });
    else if (state.mode === 'hourglass') layout = layoutHourglass(state.data, state.rootId, opts);
    else layout = layoutDescendants(state.data, state.topId, { ...opts, path: new Set(state.path), targetId: state.path[state.path.length - 1] });

    state.layout = layout;
    view.render(layout, { first, prefs });
  }

  function changeDepth(delta) {
    const max = store.config.tree?.max_depth || 30;
    const next = Math.max(state.mode === 'lineage' ? 0 : 1, Math.min(max, state.depth + delta));
    if (next === state.depth) return;
    state.depth = next;
    updateQuery({ depth: next });
    load({ keepView: true });
  }

  // ------------------------------------------------------------ دکمه‌های روی گره‌ها
  async function handleAction(action, id, key) {
    if (action === 'toggle') {
      state.collapsed.has(id) ? state.collapsed.delete(id) : state.collapsed.add(id);
      relayout();
    } else if (action === 'toggle-up') {
      state.collapsedUp.has(id) ? state.collapsedUp.delete(id) : state.collapsedUp.add(id);
      relayout();
    } else if (action === 'load-children') {
      try {
        state.data.merge(await get(`/api/tree/${id}/descendants`, { depth: 3 }));
        state.data.expandable.delete(id);
        relayout();
        toast('شاخه‌های بیشتری نمایش داده شد.', 'info', 2000);
      } catch (e) {
        toastError(e);
      }
    } else if (action === 'load-parents') {
      if (state.collapsedUp.has(id)) {
        state.collapsedUp.delete(id);
        relayout();
        return;
      }
      if (state.mode === 'descendants' || state.mode === 'lineage') {
        navigate(`/tree/${id}?mode=ancestors`);
        return;
      }
      try {
        state.data.merge(await get(`/api/tree/${id}/ancestors`, { depth: 4 }));
        relayout();
      } catch (e) {
        toastError(e);
      }
    } else if (action === 'spouse-tree') {
      navigate(`/tree/${id}?mode=hourglass`);
      toast('درخت خانواده همسر', 'info', 2000);
    } else if (action === 'goto') {
      view.centerOn(id, { zoom: 1 });
      view.select(view.keyOf(id));
    } else if (action === 'focus') {
      navigate(`/tree/${id}?mode=${state.mode === 'lineage' ? 'descendants' : state.mode}`);
    } else if (action === 'add') {
      openRelativeDialog(state.data.get(id), 'child', { onDone: () => load({ keepView: true }) });
    }
  }

  // ------------------------------------------------------------ تنظیمات نمایش
  function settingsMenu(anchor) {
    const prefs = store.prefs;
    const set = (key, value) => {
      saveLocalPrefs({ [key]: value });
      if (store.user) {
        store.user.preferences = { ...(store.user.preferences || {}), [key]: value };
        put('/api/account/preferences', { [key]: value }).catch(() => {});
      }
      relayout();
    };
    const check = (on) => (on ? 'check' : null);
    dropdown(anchor, [
      { label: 'بالای دایره: نام (با دکتر/مهندس)', icon: check(prefs.arc_top === 'name'), onClick: () => set('arc_top', 'name') },
      { label: 'بالای دایره: نام با همه عنوان‌ها', icon: check(prefs.arc_top === 'fullname'), onClick: () => set('arc_top', 'fullname') },
      { label: 'بالای دایره: شهرت', icon: check(prefs.arc_top === 'nickname'), onClick: () => set('arc_top', 'nickname') },
      'sep',
      { label: 'پایین دایره: سال تولد و وفات', icon: check(prefs.arc_bottom === 'dates'), onClick: () => set('arc_bottom', 'dates') },
      { label: 'پایین دایره: محل تولد', icon: check(prefs.arc_bottom === 'place'), onClick: () => set('arc_bottom', 'place') },
      { label: 'پایین دایره: شغل', icon: check(prefs.arc_bottom === 'occupation'), onClick: () => set('arc_bottom', 'occupation') },
      { label: 'پایین دایره: تحصیلات', icon: check(prefs.arc_bottom === 'education'), onClick: () => set('arc_bottom', 'education') },
      { label: 'پایین دایره: شهر محل زندگی', icon: check(prefs.arc_bottom === 'city'), onClick: () => set('arc_bottom', 'city') },
      { label: 'پایین دایره: هیچ', icon: check(prefs.arc_bottom === 'none'), onClick: () => set('arc_bottom', 'none') },
      'sep',
      { label: 'فرزند ارشد سمت راست', icon: check(prefs.children_order !== 'ltr'), onClick: () => set('children_order', 'rtl') },
      { label: 'فرزند ارشد سمت چپ', icon: check(prefs.children_order === 'ltr'), onClick: () => set('children_order', 'ltr') },
      { label: prefs.show_photos === false ? 'نمایش عکس‌ها' : 'پنهان کردن عکس‌ها', icon: 'image', onClick: () => set('show_photos', prefs.show_photos === false) },
      { label: prefs.compact ? 'نمایش معمولی' : 'نمایش فشرده', icon: 'grid', onClick: () => set('compact', !prefs.compact) },
    ], { width: 250 });
  }

  function openExport(extra = {}) {
    openExportDialog({
      data: state.data,
      layout: state.layout,
      mode: state.mode,
      rootId: state.rootId,
      depth: state.depth,
      to: state.to,
      ...extra,
    });
  }

  function toggleFullscreen() {
    if (document.fullscreenElement) document.exitFullscreen();
    else page.requestFullscreen?.().catch(() => {});
  }

  // میانبرهای صفحه‌کلید
  const onKey = (e) => {
    if (e.target.closest('input, textarea, select')) return;
    if (e.key === '0') view.fit();
    if (e.key === 'f' || e.key === 'ب') {
      e.preventDefault();
      searchInput.focus();
    }
    if (e.key === 'Escape') drawer.close();
  };
  document.addEventListener('keydown', onKey);
  const unsub = store.on('tree:reload', () => load({ keepView: true }));

  await load();
  canvas.focus({ preventScroll: true });

  return () => {
    document.removeEventListener('keydown', onKey);
    unsub();
    view.destroy();
    document.title = store.config.site_name || '';
  };
}
