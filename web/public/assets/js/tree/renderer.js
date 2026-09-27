/**
 * رسم زنده درخت در صفحه (SVG) با انیمیشن.
 *
 * - گره‌های جدید با انیمیشن «رشد» از والدشان بیرون می‌آیند
 * - با تغییر چیدمان (باز/بسته کردن شاخه) گره‌ها نرم جابه‌جا می‌شوند
 * - خطوط با انیمیشن «کشیده شدن» ظاهر می‌شوند
 * - نقشه کوچک (مینی‌مپ) و سطح جزئیات بر اساس بزرگ‌نمایی
 */
import { s } from '../core/dom.js';
import { buildDefs, buildNode, nodeTitle } from './node.js';
import { Viewport } from './viewport.js';

/** استایل داخلی SVG (برای خروجی PDF/SVG هم استفاده می‌شود) */
export const TREE_CSS = `
.t-arc{fill:var(--t-text,#1d2a2f);paint-order:stroke;stroke:var(--t-halo,#fbf8f2);stroke-width:3.2px;stroke-linejoin:round;font-family:Vazirmatn,Tahoma,sans-serif}
.t-arc-bottom{fill:var(--t-text-2,#56666b)}
.t-link{fill:none;stroke:var(--t-link,#b39a74);stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.t-link.marriage{stroke:var(--t-marriage,#c9a24a);stroke-width:2.6}
.t-link.divorced{stroke-dasharray:7 6;opacity:.7}
.t-link.implied{stroke-dasharray:2 6}
.t-link.hl{stroke:var(--t-hl,#14a39a);stroke-width:3.2}
.t-mark circle{fill:var(--t-mark-bg,#fff);stroke:var(--t-marriage,#c9a24a);stroke-width:1.5}
.t-mark path{fill:#e11d48}
.t-mark.divorced path{fill:#94a3b8}
.t-btn-bg{fill:var(--t-btn-bg,#fff);stroke:var(--t-btn-border,#d8cebd);stroke-width:1.2}
.t-btn{color:var(--t-btn-fg,#56666b)}
.t-btn-label{font:700 10px Vazirmatn,Tahoma,sans-serif;fill:currentColor}
.t-btn.expand .t-btn-bg{fill:var(--t-hl,#14a39a);stroke:none}
.t-btn.expand{color:#fff}
.t-btn.dashed .t-btn-bg{fill:var(--t-btn-bg,#fff);stroke:var(--t-hl,#14a39a);stroke-dasharray:3 2.5;stroke-width:1.6}
.t-btn.dashed{color:var(--t-hl,#14a39a)}
.t-btn.family .t-btn-bg{fill:#c9a24a;stroke:#fff;stroke-width:2}
.t-btn.family{color:#fff}
`;

export class TreeView {
  /**
   * @param {HTMLElement} container
   * @param {object} opts  onNode(id, key, node), onAction(action, id, key), onBackground(), minimap: HTMLCanvasElement
   */
  constructor(container, opts = {}) {
    this.container = container;
    this.opts = opts;
    this.nodeEls = new Map();
    this.layout = null;
    this.selectedKey = null;
    this.prefs = {};
    this.idp = 'tv';

    this.svg = s('svg', { class: 'tree-svg', role: 'img', 'aria-label': 'درخت خانوادگی' });
    this.style = s('style', null, TREE_CSS);
    this.defs = s('defs');
    this.world = s('g', { class: 'world' });
    this.linksLayer = s('g', { class: 'links' });
    this.marksLayer = s('g', { class: 'marks' });
    this.nodesLayer = s('g', { class: 'nodes' });
    this.world.append(this.linksLayer, this.marksLayer, this.nodesLayer);
    this.svg.append(this.style, this.defs, this.world);
    container.append(this.svg);
    container.tabIndex = 0;

    this.viewport = new Viewport(container, this.world, () => this.onViewport());

    this.svg.addEventListener('click', (e) => this.onClick(e));
    this.svg.addEventListener('dblclick', (e) => {
      const nodeEl = e.target.closest('.t-node');
      if (nodeEl) this.opts.onAction?.('focus', nodeEl.dataset.id, nodeEl.dataset.key);
    });
    this.svg.addEventListener('pointerover', (e) => this.onHover(e, true));
    this.svg.addEventListener('pointerout', (e) => this.onHover(e, false));

    this.resizeObserver = new ResizeObserver(() => this.drawMinimap());
    this.resizeObserver.observe(container);
  }

  destroy() {
    this.viewport.destroy();
    this.resizeObserver.disconnect();
    this.svg.remove();
  }

  // ------------------------------------------------------------ رسم
  /**
   * @param {object} layout خروجی layout.js
   * @param {object} opts   first: اولین رسم (انیمیشن ظاهر شدن)، prefs
   */
  render(layout, { first = false, prefs = this.prefs } = {}) {
    const g = layout.geometry;
    const geometryChanged = !this.layout || this.layout.geometry.R !== g.R;
    const prefsKey = JSON.stringify([prefs.arc_top, prefs.arc_bottom, prefs.show_photos]);
    const rebuildAll = geometryChanged || prefsKey !== this.prefsKey;
    this.prefs = prefs;
    this.prefsKey = prefsKey;

    if (geometryChanged) this.defs.replaceWith((this.defs = buildDefs(g, this.idp)));

    const oldPositions = new Map();
    for (const [key, el] of this.nodeEls) oldPositions.set(key, el._pos);
    const rootNode = layout.nodes.find((n) => n.isRoot) || layout.nodes[0];
    const seen = new Set();

    layout.nodes.forEach((node) => {
      seen.add(node.key);
      let el = this.nodeEls.get(node.key);
      const sig = signature(node);
      if (!el || el._sig !== sig || rebuildAll) {
        const fresh = buildNode(node, g, { prefs, idPrefix: this.idp });
        fresh._sig = sig;
        fresh.append(s('title', null, nodeTitle(node.person)));
        if (el) {
          fresh._pos = el._pos;
          fresh.style.transform = el.style.transform;
          el.replaceWith(fresh);
        } else {
          this.nodesLayer.append(fresh);
        }
        el = fresh;
        this.nodeEls.set(node.key, el);
      }
      el._node = node;
      el.classList.toggle('selected', node.key === this.selectedKey);

      const target = `translate(${node.x}px, ${node.y}px)`;
      el.style.setProperty('--tx', `${node.x}px`);
      el.style.setProperty('--ty', `${node.y}px`);

      if (!el._pos) {
        // گره جدید
        const origin = node.from && oldPositions.get(node.from);
        if (!first && origin) {
          // رشد از جایگاه والد
          el.style.transition = 'none';
          el.style.opacity = '0';
          el.style.transform = `translate(${origin.x}px, ${origin.y}px) scale(.3)`;
          el.getBoundingClientRect();
          el.style.transition = '';
          requestAnimationFrame(() => {
            el.style.opacity = '';
            el.style.transform = target;
          });
        } else {
          el.style.transform = target;
          if (first) {
            const dist = rootNode ? Math.abs(node.depth - rootNode.depth) : 0;
            el.style.animationDelay = `${Math.min(dist * 110 + (Math.abs(node.x) / 4000) * 200, 1400)}ms`;
            el.classList.add('appear');
            el.addEventListener('animationend', () => {
              el.classList.remove('appear');
              el.style.animationDelay = '';
            }, { once: true });
          }
        }
      } else if (el._pos.x !== node.x || el._pos.y !== node.y) {
        el.style.transform = target;
      }
      el._pos = { x: node.x, y: node.y };
    });

    // حذف گره‌های قدیمی با محو شدن
    for (const [key, el] of this.nodeEls) {
      if (seen.has(key)) continue;
      this.nodeEls.delete(key);
      const node = el._node;
      const parentPos = node?.from && this.nodeEls.get(node.from)?._pos;
      el.style.opacity = '0';
      if (parentPos) el.style.transform = `translate(${parentPos.x}px, ${parentPos.y}px) scale(.3)`;
      setTimeout(() => el.remove(), 500);
    }

    this.renderLinks(layout, first);
    this.layout = layout;
    this.drawMinimap();
  }

  renderLinks(layout, first) {
    const links = [];
    const marks = [];
    const rootDepth = (layout.nodes.find((n) => n.isRoot) || { depth: 0 }).depth;
    const byKey = new Map(layout.nodes.map((n) => [n.key, n]));

    for (const l of layout.links) {
      const cls = ['t-link', l.type, l.divorced ? 'divorced' : '', l.implied ? 'implied' : ''].filter(Boolean).join(' ');
      const path = s('path', { class: cls, d: l.d, 'data-key': l.key, 'data-child': l.child || null, 'data-parent': l.parent || null, pathLength: first ? 1 : null });
      if (first) {
        const depth = Math.abs((byKey.get(l.child || l.a)?.depth ?? 0) - rootDepth);
        path.style.strokeDasharray = '1';
        path.style.animationDelay = `${Math.min(depth * 110 + 150, 1500)}ms`;
        path.classList.add('appear');
        path.addEventListener('animationend', () => {
          path.classList.remove('appear');
          path.removeAttribute('pathLength');
          path.style.strokeDasharray = '';
          path.style.animationDelay = '';
        }, { once: true });
      }
      links.push(path);

      if (l.type === 'marriage' && !l.implied) {
        const mark = s('g', { class: `t-mark ${l.divorced ? 'divorced' : ''}`, transform: `translate(${l.mid.x},${l.mid.y})` },
          s('circle', { r: 9.5 }),
          s('path', { d: 'M0,4.6 C-6.4,0.2 -5.2,-5.2 -2.2,-5 C-1,-4.9 -0.3,-4.2 0,-3.4 C0.3,-4.2 1,-4.9 2.2,-5 C5.2,-5.2 6.4,0.2 0,4.6 Z' }),
        );
        if (l.divorced) mark.append(s('path', { d: 'M0,-4.5 L-1.2,-1 L1.2,0.6 L0,4', stroke: '#fff', 'stroke-width': 1.2, fill: 'none' }));
        marks.push(mark);
      }
    }

    if (!first && this.layout) {
      // در جابه‌جایی: خطوط بعد از رسیدن گره‌ها به جای جدید ظاهر شوند
      this.linksLayer.style.transition = 'none';
      this.linksLayer.style.opacity = '0';
      this.marksLayer.style.opacity = '0';
      clearTimeout(this.linkTimer);
      this.linkTimer = setTimeout(() => {
        this.linksLayer.style.transition = 'opacity .35s';
        this.marksLayer.style.transition = 'opacity .35s';
        this.linksLayer.style.opacity = '';
        this.marksLayer.style.opacity = '';
      }, 420);
    }
    this.linksLayer.replaceChildren(...links);
    this.marksLayer.replaceChildren(...marks);
  }

  // ------------------------------------------------------------ تعامل
  onClick(e) {
    const btn = e.target.closest('.t-btn');
    const nodeEl = e.target.closest('.t-node');
    if (btn && nodeEl) {
      e.stopPropagation();
      this.opts.onAction?.(btn.dataset.action, nodeEl.dataset.id, nodeEl.dataset.key);
      return;
    }
    if (nodeEl) {
      this.select(nodeEl.dataset.key);
      this.opts.onNode?.(nodeEl.dataset.id, nodeEl.dataset.key, nodeEl._node);
      return;
    }
    this.select(null);
    this.opts.onBackground?.();
  }

  onHover(e, over) {
    const nodeEl = e.target.closest?.('.t-node');
    if (!nodeEl || (e.relatedTarget && nodeEl.contains(e.relatedTarget))) return;
    const key = nodeEl.dataset.key;
    // برجسته کردن خطوط متصل به گره
    this.linksLayer.querySelectorAll(`[data-child="${CSS.escape(key)}"], [data-parent="${CSS.escape(key)}"]`)
      .forEach((p) => p.classList.toggle('hl', over));
  }

  select(key) {
    if (this.selectedKey) this.nodeEls.get(this.selectedKey)?.classList.remove('selected');
    this.selectedKey = key;
    if (key) this.nodeEls.get(key)?.classList.add('selected');
  }

  /** کلید اصلی یک شخص در چیدمان فعلی */
  keyOf(personId) {
    if (!this.layout) return null;
    const node = this.layout.nodes.find((n) => n.key === personId) || this.layout.nodes.find((n) => n.id === personId);
    return node?.key || null;
  }

  nodeOf(key) {
    return this.layout?.nodes.find((n) => n.key === key) || null;
  }

  centerOn(personIdOrKey, { zoom, animate = true } = {}) {
    const key = this.nodeEls.has(personIdOrKey) ? personIdOrKey : this.keyOf(personIdOrKey);
    const node = this.nodeOf(key);
    if (!node) return false;
    this.viewport.centerOn(node.x, node.y, zoom ?? Math.max(this.viewport.k, 0.85), animate);
    return true;
  }

  fit(animate = true) {
    if (this.layout) this.viewport.fit(this.layout.bounds, { animate });
  }

  /** برجسته کردن نتایج جستجو داخل درخت (بقیه کم‌رنگ می‌شوند) */
  highlight(ids) {
    const active = ids && ids.size > 0;
    for (const el of this.nodeEls.values()) {
      const match = active && ids.has(el.dataset.id);
      el.classList.toggle('match', !!match);
      el.classList.toggle('dim', active && !match);
    }
  }

  // ------------------------------------------------------------ نقشه کوچک
  onViewport() {
    const k = this.viewport.k;
    this.svg.classList.toggle('lod-low', k < 0.42);
    this.svg.classList.toggle('lod-min', k < 0.16);
    this.drawMinimapThrottled();
  }

  drawMinimapThrottled() {
    if (this.mmPending) return;
    this.mmPending = true;
    requestAnimationFrame(() => {
      this.mmPending = false;
      this.drawMinimap();
    });
  }

  drawMinimap() {
    const canvas = this.opts.minimap;
    if (!canvas || !this.layout) return;
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    if (!w || !h) return;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    const b = this.layout.bounds;
    const scale = Math.min((w - 16) / b.width, (h - 16) / b.height);
    const ox = (w - b.width * scale) / 2 - b.minX * scale;
    const oy = (h - b.height * scale) / 2 - b.minY * scale;
    this.mm = { scale, ox, oy };

    const style = getComputedStyle(document.documentElement);
    ctx.strokeStyle = style.getPropertyValue('--link-color') || '#b39a74';
    ctx.globalAlpha = 0.5;
    ctx.lineWidth = 1;
    for (const n of this.layout.nodes) {
      if (!n.from) continue;
      const p = this.nodeOf(n.from);
      if (!p) continue;
      ctx.beginPath();
      ctx.moveTo(ox + p.x * scale, oy + p.y * scale);
      ctx.lineTo(ox + n.x * scale, oy + n.y * scale);
      ctx.stroke();
    }
    ctx.globalAlpha = 1;
    const r = Math.max(1.5, Math.min(5, this.layout.geometry.R * scale));
    for (const n of this.layout.nodes) {
      ctx.fillStyle = n.person.gender === 'f' ? '#db2777' : '#2563eb';
      if (n.person.is_deceased) ctx.fillStyle = '#64748b';
      ctx.beginPath();
      ctx.arc(ox + n.x * scale, oy + n.y * scale, r, 0, Math.PI * 2);
      ctx.fill();
    }

    // مستطیل ناحیه دیده‌شده
    const vp = this.viewport;
    const { w: vw, h: vh } = vp.size;
    const tl = vp.toWorld(0, 0);
    const br = vp.toWorld(vw, vh);
    ctx.strokeStyle = style.getPropertyValue('--primary') || '#0f766e';
    ctx.lineWidth = 1.5;
    ctx.fillStyle = 'rgba(20,163,154,.08)';
    const rx = ox + tl.x * scale;
    const ry = oy + tl.y * scale;
    const rw = (br.x - tl.x) * scale;
    const rh = (br.y - tl.y) * scale;
    ctx.fillRect(rx, ry, rw, rh);
    ctx.strokeRect(rx, ry, rw, rh);
  }

  /** کلیک روی نقشه کوچک: رفتن به آن نقطه */
  minimapNavigate(mx, my) {
    if (!this.mm) return;
    const wx = (mx - this.mm.ox) / this.mm.scale;
    const wy = (my - this.mm.oy) / this.mm.scale;
    this.viewport.centerOn(wx, wy, this.viewport.k, true);
  }
}

/** امضای بصری گره: اگر تغییر کند گره از نو ساخته می‌شود */
function signature(n) {
  const p = n.person;
  return [
    p.first_name, p.last_name, p.title, p.nickname, p.gender, p.is_deceased, p.birth_date, p.death_date,
    p.avatar, p.birth_place, p.occupation, p.has_parents,
    n.kind, n.isRoot, n.childCount, n.collapsed, n.expandable, n.expandUp, n.canCollapseUp,
  ].join('|');
}
