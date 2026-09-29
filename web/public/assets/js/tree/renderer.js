/**
 * رسم زنده درخت در صفحه (SVG) با انیمیشن.
 *
 * - گره‌های جدید با انیمیشن «رشد» از والدشان بیرون می‌آیند
 * - با تغییر چیدمان (باز/بسته کردن شاخه) گره‌ها نرم جابه‌جا می‌شوند
 * - خطوط با انیمیشن «کشیده شدن» ظاهر می‌شوند
 * - نقشه کوچک (مینی‌مپ) و سطح جزئیات بر اساس بزرگ‌نمایی
 *
 * درخت‌های بزرگ (بیش از چند صد نفر) «مجازی» رسم می‌شوند: فقط گره‌هایی که در
 * صفحه دیده می‌شوند در DOM هستند و در بزرگ‌نمایی خیلی کم، کل درخت روی یک
 * canvas سبک کشیده می‌شود. به این ترتیب درخت چند هزار نفری هم روان است.
 */
import { marriageMark } from './marks.js';
import { s } from '../core/dom.js';
import { buildDefs, buildNode, nodeTitle } from './node.js';
import { Viewport } from './viewport.js';

/** بالاتر از این تعداد گره، رسم مجازی فعال می‌شود */
const VIRTUAL_THRESHOLD = 300;
/** در رسم مجازی، زیر این بزرگ‌نمایی نمای کلی canvas نمایش داده می‌شود */
const OVERVIEW_ZOOM = 0.28;

/** استایل داخلی SVG (برای خروجی PDF/SVG هم استفاده می‌شود) */
export const TREE_CSS = `
.t-arc{fill:var(--t-text,#1d2a2f);paint-order:stroke;stroke:var(--t-halo,#fbf8f2);stroke-width:2.4px;stroke-linejoin:round;font-family:Vazirmatn,Tahoma,sans-serif}
.t-arc-bottom{fill:var(--t-text-2,#56666b)}
.t-link{fill:none;stroke:var(--t-link,#b39a74);stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.t-link.marriage{stroke:var(--t-marriage,#c9a24a);stroke-width:2.6}
.t-link.divorced{stroke-dasharray:7 6;opacity:.7}
.t-link.implied{stroke-dasharray:2 6}
.t-link.hl{stroke:var(--t-hl,#14a39a);stroke-width:3.2}
.t-mark circle{fill:var(--t-mark-bg,#fff);stroke:var(--t-marriage,#c9a24a);stroke-width:1.5}
.t-mark path{fill:#e11d48}
.t-mark.divorced path{fill:#94a3b8}
.t-mark.divorced circle{stroke:#b8c2cf}
.t-mark.clickable{cursor:pointer}
.t-mark.clickable circle{transition:r .15s}
.t-mark.clickable:hover circle{r:11}
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
    this.linkEls = new Map();
    this.nodeMap = new Map();
    this.layout = null;
    this.selectedKey = null;
    this.highlightIds = null;
    this.prefs = {};
    this.idp = 'tv';
    this.big = false;

    // canvas نمای کلی (برای درخت‌های بزرگ در بزرگ‌نمایی کم)
    this.overviewCanvas = document.createElement('canvas');
    this.overviewCanvas.className = 'tree-overview';
    this.overviewCanvas.hidden = true;

    this.svg = s('svg', { class: 'tree-svg', role: 'img', 'aria-label': 'درخت خانوادگی' });
    this.style = s('style', null, TREE_CSS);
    this.defs = s('defs');
    this.world = s('g', { class: 'world' });
    this.linksLayer = s('g', { class: 'links' });
    this.marksLayer = s('g', { class: 'marks' });
    this.nodesLayer = s('g', { class: 'nodes' });
    this.world.append(this.linksLayer, this.marksLayer, this.nodesLayer);
    this.svg.append(this.style, this.defs, this.world);
    container.append(this.overviewCanvas, this.svg);
    container.tabIndex = 0;

    this.viewport = new Viewport(container, this.world, () => this.onViewport());

    this.svg.addEventListener('click', (e) => this.onClick(e));
    this.svg.addEventListener('dblclick', (e) => {
      const nodeEl = e.target.closest('.t-node');
      if (nodeEl) this.opts.onAction?.('focus', nodeEl.dataset.id, nodeEl.dataset.key);
    });
    this.svg.addEventListener('pointerover', (e) => this.onHover(e, true));
    this.svg.addEventListener('pointerout', (e) => this.onHover(e, false));

    // اندازه نقشه کوچک کش می‌شود (خواندن clientWidth در هر فریم کند است)
    this.mmSize = { w: 0, h: 0 };
    this.resizeObserver = new ResizeObserver(() => {
      if (opts.minimap) {
        this.mmSize = { w: opts.minimap.clientWidth, h: opts.minimap.clientHeight };
        this.mmCache = null;
      }
      this.refresh(true);
    });
    this.resizeObserver.observe(container);
    if (opts.minimap) this.resizeObserver.observe(opts.minimap);
  }

  destroy() {
    this.viewport.destroy();
    this.resizeObserver.disconnect();
    this.svg.remove();
    this.overviewCanvas.remove();
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
    this.geometry = g;
    if (geometryChanged) this.defs.replaceWith((this.defs = buildDefs(g, this.idp)));

    const wasBig = this.big;
    this.big = layout.nodes.length > VIRTUAL_THRESHOLD;
    this.nodeMap = new Map(layout.nodes.map((n) => [n.key, n]));

    if (this.big || wasBig) {
      this.renderVirtual(layout, rebuildAll || !wasBig);
    } else {
      this.renderFull(layout, first, rebuildAll);
    }
    this.mmCache = null;
    this.drawMinimap();
  }

  /** رسم کامل با انیمیشن (درخت‌های کوچک و متوسط) */
  renderFull(layout, first, rebuildAll) {
    const oldPositions = new Map();
    for (const [key, el] of this.nodeEls) oldPositions.set(key, el._pos);
    const rootNode = layout.nodes.find((n) => n.isRoot) || layout.nodes[0];
    const seen = new Set();
    const fragment = document.createDocumentFragment();

    for (const node of layout.nodes) {
      seen.add(node.key);
      let el = this.nodeEls.get(node.key);
      if (!el || el._sig !== signature(node) || rebuildAll) {
        const fresh = this.buildNodeEl(node);
        if (el) {
          fresh._pos = el._pos;
          fresh.style.transform = el.style.transform;
          el.replaceWith(fresh);
        } else {
          fragment.append(fresh);
        }
        el = fresh;
        this.nodeEls.set(node.key, el);
      }
      el._node = node;
      this.applyState(el);

      const target = `translate(${node.x}px, ${node.y}px)`;
      el.style.setProperty('--tx', `${node.x}px`);
      el.style.setProperty('--ty', `${node.y}px`);

      if (!el._pos) {
        const origin = node.from && oldPositions.get(node.from);
        if (!first && origin) {
          // رشد از جایگاه والد
          el.style.transition = 'none';
          el.style.opacity = '0';
          el.style.transform = `translate(${origin.x}px, ${origin.y}px) scale(.3)`;
          fragment.append(el);
          requestAnimationFrame(() => requestAnimationFrame(() => {
            el.style.transition = '';
            el.style.opacity = '';
            el.style.transform = target;
          }));
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
    }
    this.nodesLayer.append(fragment);

    // حذف گره‌های قدیمی با محو شدن
    for (const [key, el] of this.nodeEls) {
      if (seen.has(key)) continue;
      this.nodeEls.delete(key);
      const parentPos = el._node?.from && this.nodeEls.get(el._node.from)?._pos;
      el.style.opacity = '0';
      if (parentPos) el.style.transform = `translate(${parentPos.x}px, ${parentPos.y}px) scale(.3)`;
      setTimeout(() => el.remove(), 500);
    }

    const links = [];
    const marks = [];
    const byDepth = (l) => Math.abs((this.nodeMap.get(l.child || l.a)?.depth ?? 0) - (rootNode?.depth ?? 0));
    for (const l of layout.links) {
      const path = this.buildLinkEl(l);
      if (first) {
        path.setAttribute('pathLength', '1');
        path.style.strokeDasharray = '1';
        path.style.animationDelay = `${Math.min(byDepth(l) * 110 + 150, 1500)}ms`;
        path.classList.add('appear');
        path.addEventListener('animationend', () => {
          path.classList.remove('appear');
          path.removeAttribute('pathLength');
          path.style.strokeDasharray = '';
          path.style.animationDelay = '';
        }, { once: true });
      }
      links.push(path);
      if (l.type === 'marriage' && !l.implied) marks.push(this.buildMarkEl(l));
    }

    if (!first && this.layout) {
      // در جابه‌جایی: خطوط بعد از رسیدن گره‌ها به جای جدید ظاهر شوند
      for (const layer of [this.linksLayer, this.marksLayer]) {
        layer.style.transition = 'none';
        layer.style.opacity = '0';
      }
      clearTimeout(this.linkTimer);
      this.linkTimer = setTimeout(() => {
        for (const layer of [this.linksLayer, this.marksLayer]) {
          layer.style.transition = 'opacity .35s';
          layer.style.opacity = '';
        }
      }, 420);
    }
    this.linksLayer.replaceChildren(...links);
    this.marksLayer.replaceChildren(...marks);
    this.layout = layout;
  }

  /** رسم مجازی (درخت‌های بزرگ): فقط بخش دیده‌شده در DOM قرار می‌گیرد */
  renderVirtual(layout, rebuildAll) {
    const reuse = new Map();
    if (!rebuildAll) {
      for (const n of layout.nodes) {
        const el = this.nodeEls.get(n.key);
        if (el && el._sig === signature(n)) reuse.set(n.key, el);
      }
    }
    this.nodesLayer.replaceChildren();
    this.linksLayer.replaceChildren();
    this.marksLayer.replaceChildren();
    for (const layer of [this.linksLayer, this.marksLayer]) layer.style.opacity = '';
    this.nodeEls = reuse;
    this.linkEls = new Map();
    this.markEls = new Map();

    // محدوده هر خط (برای تشخیص دیده شدن)
    this.linkBoxes = layout.links.map((l) => {
      const pts = [l.a, l.b, l.parent, l.spouse, l.child].map((k) => k && this.nodeMap.get(k)).filter(Boolean);
      return {
        x1: Math.min(...pts.map((p) => p.x)),
        x2: Math.max(...pts.map((p) => p.x)),
        y1: Math.min(...pts.map((p) => p.y)),
        y2: Math.max(...pts.map((p) => p.y)),
      };
    });
    this.layout = layout;
    this.cullKey = null;
    this.refresh(true);
  }

  buildNodeEl(node) {
    const el = buildNode(node, this.geometry, { prefs: this.prefs, idPrefix: this.idp });
    el._sig = signature(node);
    el.append(s('title', null, nodeTitle(node.person)));
    return el;
  }

  buildLinkEl(l) {
    const cls = ['t-link', l.type, l.divorced ? 'divorced' : '', l.implied ? 'implied' : ''].filter(Boolean).join(' ');
    return s('path', { class: cls, d: l.d, 'data-key': l.key, 'data-child': l.child || null, 'data-parent': l.parent || null });
  }

  buildMarkEl(l) {
    const mark = marriageMark(l, { 'data-marriage': l.marriage?.id ?? null, role: l.marriage?.id ? 'button' : null });
    if (l.marriage?.id) mark.append(s('title', null, l.divorced ? 'جدا شده — برای دیدن تاریخ‌ها بزنید' : 'ازدواج — برای دیدن تاریخ‌ها بزنید'));
    return mark;
  }

  /** وضعیت انتخاب و برجستگی جستجو روی یک گره */
  applyState(el) {
    el.classList.toggle('selected', el.dataset.key === this.selectedKey);
    const ids = this.highlightIds;
    const active = ids && ids.size > 0;
    const match = active && ids.has(el.dataset.id);
    el.classList.toggle('match', !!match);
    el.classList.toggle('dim', !!active && !match);
  }

  /** به‌روزرسانی رسم مجازی بر اساس ناحیه دیده‌شده */
  refresh(force = false) {
    if (!this.big || !this.layout) return;
    const vp = this.viewport;
    const overview = vp.k < OVERVIEW_ZOOM;
    if (overview !== this.overviewActive) {
      this.overviewActive = overview;
      this.overviewCanvas.hidden = !overview;
      this.svg.classList.toggle('overview', overview);
      force = true;
    }
    if (overview) {
      this.drawOverview();
      return;
    }

    const { w, h } = vp.size;
    const margin = 240 / vp.k + this.layout.geometry.outer;
    const a = vp.toWorld(0, 0);
    const b = vp.toWorld(w, h);
    const box = { x1: a.x - margin, y1: a.y - margin, x2: b.x + margin, y2: b.y + margin };
    const key = [box.x1, box.y1, box.x2, box.y2].map((v) => Math.round(v / 120)).join(',');
    if (!force && key === this.cullKey) return;
    this.cullKey = key;

    const inside = (n) => n.x >= box.x1 && n.x <= box.x2 && n.y >= box.y1 && n.y <= box.y2;
    const addNodes = document.createDocumentFragment();
    for (const node of this.layout.nodes) {
      let el = this.nodeEls.get(node.key);
      if (inside(node)) {
        if (!el || el._sig !== signature(node)) {
          el?.remove();
          el = this.buildNodeEl(node);
          this.nodeEls.set(node.key, el);
        }
        el._node = node;
        el.style.transform = `translate(${node.x}px, ${node.y}px)`;
        this.applyState(el);
        if (!el.isConnected) addNodes.append(el);
      } else if (el?.isConnected) {
        el.remove();
      }
    }
    this.nodesLayer.append(addNodes);

    const addLinks = document.createDocumentFragment();
    const addMarks = document.createDocumentFragment();
    this.layout.links.forEach((l, i) => {
      const lb = this.linkBoxes[i];
      const vis = lb.x1 <= box.x2 && lb.x2 >= box.x1 && lb.y1 <= box.y2 && lb.y2 >= box.y1;
      let path = this.linkEls.get(l.key);
      if (vis) {
        if (!path) {
          path = this.buildLinkEl(l);
          this.linkEls.set(l.key, path);
        }
        if (!path.isConnected) addLinks.append(path);
      } else if (path?.isConnected) {
        path.remove();
      }
      if (l.type === 'marriage' && !l.implied) {
        let mark = this.markEls.get(l.key);
        if (vis) {
          if (!mark) {
            mark = this.buildMarkEl(l);
            this.markEls.set(l.key, mark);
          }
          if (!mark.isConnected) addMarks.append(mark);
        } else if (mark?.isConnected) {
          mark.remove();
        }
      }
    });
    this.linksLayer.append(addLinks);
    this.marksLayer.append(addMarks);
  }

  /** نمای کلی روی canvas: دایره‌های رنگی و خطوط ساده */
  drawOverview() {
    const canvas = this.overviewCanvas;
    const { w, h } = this.viewport.size;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) {
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
    }
    const ctx = canvas.getContext('2d');
    const { x, y, k } = this.viewport;
    const g = this.layout.geometry;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.setTransform(dpr * k, 0, 0, dpr * k, dpr * x, dpr * y);

    if (!this.ovColors) {
      const style = getComputedStyle(document.documentElement);
      this.ovColors = { link: style.getPropertyValue('--link-color').trim() || '#b39a74' };
    }
    ctx.strokeStyle = this.ovColors.link;
    ctx.lineWidth = 2 / Math.max(k, 0.05) * 0.6;
    ctx.beginPath();
    for (const n of this.layout.nodes) {
      const p = n.from && this.nodeMap.get(n.from);
      if (!p) continue;
      if (p.y === n.y) {
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(n.x, n.y);
      } else {
        const midY = (p.y + n.y) / 2;
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(p.x, midY);
        ctx.lineTo(n.x, midY);
        ctx.lineTo(n.x, n.y);
      }
    }
    ctx.stroke();

    const r = g.R + g.ring;
    for (const [color, test] of [
      ['#2563eb', (n) => n.person.gender !== 'f' && !n.person.is_deceased],
      ['#db2777', (n) => n.person.gender === 'f' && !n.person.is_deceased],
      ['#4b5563', (n) => n.person.is_deceased],
    ]) {
      ctx.fillStyle = color;
      ctx.beginPath();
      for (const n of this.layout.nodes) {
        if (!test(n)) continue;
        ctx.moveTo(n.x + r, n.y);
        ctx.arc(n.x, n.y, r, 0, Math.PI * 2);
      }
      ctx.fill();
    }
    if (this.highlightIds?.size) {
      ctx.strokeStyle = '#14a39a';
      ctx.lineWidth = 8;
      ctx.beginPath();
      for (const n of this.layout.nodes) {
        if (!this.highlightIds.has(n.id)) continue;
        ctx.moveTo(n.x + r + 10, n.y);
        ctx.arc(n.x, n.y, r + 10, 0, Math.PI * 2);
      }
      ctx.stroke();
    }
  }

  // ------------------------------------------------------------ تعامل
  onClick(e) {
    const markEl = e.target.closest('.t-mark[data-marriage]');
    if (markEl) {
      e.stopPropagation();
      this.opts.onMarriage?.(markEl.dataset.marriage, markEl);
      return;
    }
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
    if (this.overviewActive) {
      // در نمای کلی، کلیک = بزرگ‌نمایی روی نزدیک‌ترین شخص به محل کلیک
      const r = this.container.getBoundingClientRect();
      const p = this.viewport.toWorld(e.clientX - r.left, e.clientY - r.top);
      let best = null;
      let bestD = Infinity;
      for (const n of this.layout.nodes) {
        const d = (n.x - p.x) ** 2 + (n.y - p.y) ** 2;
        if (d < bestD) {
          bestD = d;
          best = n;
        }
      }
      if (best) this.viewport.centerOn(best.x, best.y, 0.75, true);
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
    if (this.nodeMap.has(personId)) return personId;
    return this.layout.nodes.find((n) => n.id === personId)?.key || null;
  }

  nodeOf(key) {
    return this.nodeMap.get(key) || null;
  }

  centerOn(personIdOrKey, { zoom, animate = true } = {}) {
    const node = this.nodeOf(personIdOrKey) || this.nodeOf(this.keyOf(personIdOrKey));
    if (!node) return false;
    this.viewport.centerOn(node.x, node.y, zoom ?? Math.max(this.viewport.k, 0.85), animate);
    return true;
  }

  fit(animate = true) {
    if (this.layout) this.viewport.fit(this.layout.bounds, { animate });
  }

  /** نمای شروع: درخت کوچک کامل دیده می‌شود؛ درخت بزرگ از ریشه با اندازه خوانا */
  initialView() {
    if (!this.layout) return;
    const { w, h } = this.viewport.size;
    const b = this.layout.bounds;
    const fitK = Math.min((w - 120) / b.width, (h - 200) / b.height);
    if (!this.big || fitK >= 0.35) {
      this.fit(false);
      return;
    }
    const root = this.layout.nodes.find((n) => n.isRoot) || this.layout.nodes[0];
    const k = 0.6;
    const rootIsTop = root.y <= b.minY + this.layout.geometry.outer + 20;
    // در نمای نوادگان ریشه در بالای صفحه قرار می‌گیرد، در بقیه حالت‌ها وسط صفحه
    this.viewport.centerOn(root.x, rootIsTop ? root.y + (h / 2 - 150) / k : root.y, k, false);
  }

  /** برجسته کردن نتایج جستجو داخل درخت (بقیه کم‌رنگ می‌شوند) */
  highlight(ids) {
    this.highlightIds = ids && ids.size ? ids : null;
    for (const el of this.nodeEls.values()) this.applyState(el);
    if (this.overviewActive) this.drawOverview();
  }

  // ------------------------------------------------------------ نقشه کوچک
  onViewport() {
    const k = this.viewport.k;
    this.svg.classList.toggle('lod-low', k < 0.42);
    this.svg.classList.toggle('lod-min', k < 0.16);
    if (this.framePending) return;
    this.framePending = true;
    requestAnimationFrame(() => {
      this.framePending = false;
      this.refresh();
      this.drawMinimap();
    });
  }

  drawMinimap() {
    const canvas = this.opts.minimap;
    if (!canvas || !this.layout) return;
    const dpr = window.devicePixelRatio || 1;
    const { w, h } = this.mmSize;
    if (!w || !h) return;

    // لایه ثابت (گره‌ها و خطوط) فقط یک‌بار برای هر چیدمان رسم و کش می‌شود
    if (!this.mmCache || this.mmCache.w !== w || this.mmCache.h !== h) {
      const b = this.layout.bounds;
      const scale = Math.min((w - 16) / b.width, (h - 16) / b.height);
      const ox = (w - b.width * scale) / 2 - b.minX * scale;
      const oy = (h - b.height * scale) / 2 - b.minY * scale;
      const base = document.createElement('canvas');
      base.width = w * dpr;
      base.height = h * dpr;
      const bctx = base.getContext('2d');
      bctx.scale(dpr, dpr);
      const style = getComputedStyle(document.documentElement);
      bctx.strokeStyle = style.getPropertyValue('--link-color') || '#b39a74';
      bctx.globalAlpha = 0.5;
      bctx.lineWidth = 1;
      bctx.beginPath();
      for (const n of this.layout.nodes) {
        const p = n.from && this.nodeMap.get(n.from);
        if (!p) continue;
        bctx.moveTo(ox + p.x * scale, oy + p.y * scale);
        bctx.lineTo(ox + n.x * scale, oy + n.y * scale);
      }
      bctx.stroke();
      bctx.globalAlpha = 1;
      const r = Math.max(1.2, Math.min(5, this.layout.geometry.R * scale));
      for (const [color, test] of [['#2563eb', (n) => n.person.gender !== 'f' && !n.person.is_deceased], ['#db2777', (n) => n.person.gender === 'f' && !n.person.is_deceased], ['#64748b', (n) => n.person.is_deceased]]) {
        bctx.fillStyle = color;
        bctx.beginPath();
        for (const n of this.layout.nodes) {
          if (!test(n)) continue;
          bctx.moveTo(ox + n.x * scale + r, oy + n.y * scale);
          bctx.arc(ox + n.x * scale, oy + n.y * scale, r, 0, Math.PI * 2);
        }
        bctx.fill();
      }
      this.mmCache = { w, h, base, primary: style.getPropertyValue('--primary') || '#0f766e' };
      this.mm = { scale, ox, oy };
    }

    if (canvas.width !== w * dpr) {
      canvas.width = w * dpr;
      canvas.height = h * dpr;
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(this.mmCache.base, 0, 0);
    ctx.scale(dpr, dpr);

    // مستطیل ناحیه دیده‌شده
    const { scale, ox, oy } = this.mm;
    const vp = this.viewport;
    const { w: vw, h: vh } = vp.size;
    const tl = vp.toWorld(0, 0);
    const br = vp.toWorld(vw, vh);
    ctx.strokeStyle = this.mmCache.primary;
    ctx.lineWidth = 1.5;
    ctx.fillStyle = 'rgba(20,163,154,.1)';
    const rx = ox + tl.x * scale;
    const ry = oy + tl.y * scale;
    ctx.fillRect(rx, ry, (br.x - tl.x) * scale, (br.y - tl.y) * scale);
    ctx.strokeRect(rx, ry, (br.x - tl.x) * scale, (br.y - tl.y) * scale);
  }

  /** کلیک روی نقشه کوچک: رفتن به آن نقطه */
  minimapNavigate(mx, my) {
    if (!this.mm) return;
    const wx = (mx - this.mm.ox) / this.mm.scale;
    const wy = (my - this.mm.oy) / this.mm.scale;
    this.viewport.centerOn(wx, wy, Math.max(this.viewport.k, this.big ? 0.5 : this.viewport.k), true);
  }
}

/** امضای بصری گره: اگر تغییر کند گره از نو ساخته می‌شود */
function signature(n) {
  const p = n.person;
  return [
    p.first_name, p.last_name, p.title, p.display_title, p.nickname, p.gender, p.is_deceased, p.birth_date, p.death_date,
    p.avatar, p.birth_place, p.occupation, p.has_parents,
    n.kind, n.isRoot, n.childCount, n.collapsed, n.expandable, n.expandUp, n.canCollapseUp,
  ].join('|');
}
