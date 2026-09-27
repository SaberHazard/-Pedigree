/**
 * جابه‌جایی و بزرگ‌نمایی نرم (Pan/Zoom) با ماوس، چرخ ماوس، تاچ‌پد و لمس دو انگشتی.
 * همراه با «اینرسی» (ادامه حرکت پس از رها کردن) برای حس طبیعی.
 */
export class Viewport {
  /**
   * @param {HTMLElement} el      ناحیه دریافت رویدادها
   * @param {SVGGElement} world   گروهی که تبدیل روی آن اعمال می‌شود
   * @param {(vp:Viewport)=>void} onChange
   */
  constructor(el, world, onChange) {
    this.el = el;
    this.world = world;
    this.onChange = onChange;
    this.x = 0;
    this.y = 0;
    this.k = 1;
    this.min = 0.008; // درخت‌های خیلی بزرگ در نمای کلی (canvas) کامل دیده شوند
    this.max = 2.6;
    this.pointers = new Map();
    this.suppressClick = false;
    this.raf = null;
    // اندازه ناحیه با ResizeObserver نگهداری می‌شود؛ خواندن clientWidth در هر فریم
    // باعث محاسبه مجدد چیدمان کل SVG می‌شود و در درخت‌های بزرگ بسیار کند است
    this.w = el.clientWidth;
    this.h = el.clientHeight;
    this.resizeObserver = new ResizeObserver((entries) => {
      const r = entries[0].contentRect;
      this.w = r.width;
      this.h = r.height;
    });
    this.resizeObserver.observe(el);

    this.handlers = {
      down: (e) => this.onDown(e),
      move: (e) => this.onMove(e),
      up: (e) => this.onUp(e),
      wheel: (e) => this.onWheel(e),
      click: (e) => {
        if (this.suppressClick) {
          e.stopPropagation();
          e.preventDefault();
          this.suppressClick = false;
        }
      },
      key: (e) => this.onKey(e),
    };
    el.addEventListener('pointerdown', this.handlers.down);
    el.addEventListener('pointermove', this.handlers.move);
    el.addEventListener('pointerup', this.handlers.up);
    el.addEventListener('pointercancel', this.handlers.up);
    el.addEventListener('wheel', this.handlers.wheel, { passive: false });
    el.addEventListener('click', this.handlers.click, true);
    el.addEventListener('keydown', this.handlers.key);
  }

  destroy() {
    this.stop();
    this.resizeObserver.disconnect();
    const el = this.el;
    el.removeEventListener('pointerdown', this.handlers.down);
    el.removeEventListener('pointermove', this.handlers.move);
    el.removeEventListener('pointerup', this.handlers.up);
    el.removeEventListener('pointercancel', this.handlers.up);
    el.removeEventListener('wheel', this.handlers.wheel);
    el.removeEventListener('click', this.handlers.click, true);
    el.removeEventListener('keydown', this.handlers.key);
  }

  get size() {
    return { w: this.w, h: this.h };
  }

  apply() {
    this.world.setAttribute('transform', `translate(${this.x.toFixed(2)},${this.y.toFixed(2)}) scale(${this.k.toFixed(4)})`);
    // حرکت شبکه نقطه‌ای پس‌زمینه همراه درخت
    const grid = 28 * Math.max(0.5, Math.min(2, this.k));
    this.el.style.setProperty('--grid-size', `${grid}px`);
    this.el.style.setProperty('--grid-x', `${this.x % grid}px`);
    this.el.style.setProperty('--grid-y', `${this.y % grid}px`);
    this.onChange?.(this);
  }

  clampK(k) {
    return Math.max(this.min, Math.min(this.max, k));
  }

  zoomAt(factor, cx, cy) {
    const k = this.clampK(this.k * factor);
    this.x = cx - (cx - this.x) * (k / this.k);
    this.y = cy - (cy - this.y) * (k / this.k);
    this.k = k;
    this.apply();
  }

  zoomBy(factor) {
    const { w, h } = this.size;
    this.animateTo({ k: this.clampK(this.k * factor), x: w / 2 - ((w / 2 - this.x) * this.clampK(this.k * factor)) / this.k, y: h / 2 - ((h / 2 - this.y) * this.clampK(this.k * factor)) / this.k });
  }

  /** مختصات صفحه ← مختصات درخت */
  toWorld(sx, sy) {
    return { x: (sx - this.x) / this.k, y: (sy - this.y) / this.k };
  }

  centerOn(wx, wy, k = this.k, animate = true) {
    const { w, h } = this.size;
    const target = { k, x: w / 2 - wx * k, y: h / 2 - wy * k };
    if (animate) {
      this.animateTo(target);
    } else {
      Object.assign(this, target);
      this.apply();
    }
  }

  fit(bounds, { padding = 60, bottomSpace = 80, maxK = 1.1, animate = true } = {}) {
    const { w, h } = this.size;
    if (!bounds || !w || !h) return;
    const bw = Math.max(bounds.width, 1);
    const bh = Math.max(bounds.height, 1);
    const k = this.clampK(Math.min((w - padding * 2) / bw, (h - padding * 2 - bottomSpace) / bh, maxK));
    const target = {
      k,
      x: w / 2 - (bounds.minX + bw / 2) * k,
      y: (h - bottomSpace) / 2 - (bounds.minY + bh / 2) * k,
    };
    if (animate) this.animateTo(target, 700);
    else {
      Object.assign(this, target);
      this.apply();
    }
  }

  animateTo(target, duration = 550) {
    this.stop();
    const from = { x: this.x, y: this.y, k: this.k };
    const to = { x: target.x ?? this.x, y: target.y ?? this.y, k: target.k ?? this.k };
    const start = performance.now();
    this.el.classList.add('animating');
    const step = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const e = 1 - Math.pow(1 - t, 3);
      this.x = from.x + (to.x - from.x) * e;
      this.y = from.y + (to.y - from.y) * e;
      this.k = from.k + (to.k - from.k) * e;
      this.apply();
      if (t < 1) this.raf = requestAnimationFrame(step);
      else {
        this.raf = null;
        this.el.classList.remove('animating');
      }
    };
    this.raf = requestAnimationFrame(step);
  }

  stop() {
    if (this.raf) cancelAnimationFrame(this.raf);
    this.raf = null;
  }

  // ------------------------------------------------------------ رویدادها
  local(e) {
    // موقعیت ناحیه فقط هنگام شروع تعامل خوانده و کش می‌شود
    if (!this.rect || e.type === 'pointerdown' || e.type === 'wheel' && !this.wheeling) {
      this.rect = this.el.getBoundingClientRect();
    }
    return { x: e.clientX - this.rect.left, y: e.clientY - this.rect.top };
  }

  onDown(e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    this.stop();
    this.pointers.set(e.pointerId, this.local(e));
    if (this.pointers.size === 1) {
      const p = this.local(e);
      this.start = { px: p.x, py: p.y, x: this.x, y: this.y };
      this.moved = false;
      this.samples = [{ t: performance.now(), x: p.x, y: p.y }];
    } else if (this.pointers.size === 2) {
      const [a, b] = [...this.pointers.values()];
      this.pinch = {
        dist: Math.hypot(a.x - b.x, a.y - b.y) || 1,
        cx: (a.x + b.x) / 2,
        cy: (a.y + b.y) / 2,
        k: this.k,
        x: this.x,
        y: this.y,
      };
      this.moved = true;
    }
  }

  onMove(e) {
    if (!this.pointers.has(e.pointerId)) return;
    const p = this.local(e);
    this.pointers.set(e.pointerId, p);

    if (this.pointers.size === 1 && this.start) {
      const dx = p.x - this.start.px;
      const dy = p.y - this.start.py;
      if (!this.moved) {
        if (Math.hypot(dx, dy) < 5) return;
        this.moved = true;
        this.el.classList.add('dragging');
        try {
          this.el.setPointerCapture(e.pointerId);
        } catch {
          /* ignore */
        }
      }
      this.x = this.start.x + dx;
      this.y = this.start.y + dy;
      this.samples.push({ t: performance.now(), x: p.x, y: p.y });
      if (this.samples.length > 6) this.samples.shift();
      this.apply();
    } else if (this.pointers.size === 2 && this.pinch) {
      const [a, b] = [...this.pointers.values()];
      const dist = Math.hypot(a.x - b.x, a.y - b.y) || 1;
      const cx = (a.x + b.x) / 2;
      const cy = (a.y + b.y) / 2;
      const k = this.clampK(this.pinch.k * (dist / this.pinch.dist));
      const wx = (this.pinch.cx - this.pinch.x) / this.pinch.k;
      const wy = (this.pinch.cy - this.pinch.y) / this.pinch.k;
      this.k = k;
      this.x = cx - wx * k;
      this.y = cy - wy * k;
      this.apply();
    }
  }

  onUp(e) {
    if (!this.pointers.has(e.pointerId)) return;
    this.pointers.delete(e.pointerId);
    if (this.pointers.size < 2) this.pinch = null;
    if (this.pointers.size === 1) {
      // از دو انگشت به یک انگشت: شروع مجدد کشیدن از نقطه فعلی
      const p = [...this.pointers.values()][0];
      this.start = { px: p.x, py: p.y, x: this.x, y: this.y };
      this.samples = [];
      return;
    }
    if (this.pointers.size === 0) {
      this.el.classList.remove('dragging');
      if (this.moved) {
        this.suppressClick = true;
        setTimeout(() => (this.suppressClick = false), 50);
        this.inertia();
      }
      this.start = null;
    }
  }

  inertia() {
    const s = this.samples || [];
    if (s.length < 2) return;
    const a = s[0];
    const b = s[s.length - 1];
    const dt = Math.max(16, b.t - a.t);
    if (performance.now() - b.t > 80) return; // کاربر مکث کرده
    let vx = ((b.x - a.x) / dt) * 16;
    let vy = ((b.y - a.y) / dt) * 16;
    const step = () => {
      vx *= 0.92;
      vy *= 0.92;
      if (Math.abs(vx) < 0.3 && Math.abs(vy) < 0.3) {
        this.raf = null;
        return;
      }
      this.x += vx;
      this.y += vy;
      this.apply();
      this.raf = requestAnimationFrame(step);
    };
    this.raf = requestAnimationFrame(step);
  }

  onWheel(e) {
    e.preventDefault();
    this.stop();
    const p = this.local(e);
    this.wheeling = true;
    clearTimeout(this.wheelTimer);
    this.wheelTimer = setTimeout(() => (this.wheeling = false), 200);
    const mouseLike = e.deltaMode === 1 || (e.deltaX === 0 && Math.abs(e.deltaY) >= 50 && Number.isInteger(e.deltaY));
    if (e.ctrlKey || e.metaKey || mouseLike) {
      const delta = e.deltaMode === 1 ? e.deltaY * 33 : e.deltaY;
      const factor = Math.exp(-delta * (e.ctrlKey ? 0.012 : 0.0016));
      this.zoomAt(factor, p.x, p.y);
    } else {
      this.x -= e.deltaX;
      this.y -= e.deltaY;
      this.apply();
    }
  }

  onKey(e) {
    if (e.target.closest('input, textarea, select')) return;
    const step = 80;
    const map = {
      ArrowLeft: () => { this.x += step; this.apply(); },
      ArrowRight: () => { this.x -= step; this.apply(); },
      ArrowUp: () => { this.y += step; this.apply(); },
      ArrowDown: () => { this.y -= step; this.apply(); },
      '+': () => this.zoomBy(1.25),
      '=': () => this.zoomBy(1.25),
      '-': () => this.zoomBy(0.8),
    };
    if (map[e.key]) {
      e.preventDefault();
      map[e.key]();
    }
  }
}
