/**
 * چیدمان درخت (محاسبه مختصات گره‌ها و خطوط).
 *
 * الگوریتم: درخت «فشرده» مبتنی بر کانتور (شبیه Reingold-Tilford):
 * زیردرخت‌های خواهر و برادرها تا جایی که در هیچ نسلی روی هم نیفتند به هم
 * نزدیک می‌شوند و والدین بالای فرزندانشان وسط‌چین می‌شوند.
 *
 * هر «واحد» در نمای نوادگان = یک عضو خونی + همسرانش در یک ردیف.
 *   ترتیب همسران در ردیف: [شخص][همسر۱][همسر۲][همسر۳] ... (همسر اول نزدیک‌ترین)
 * فرزندان هر ازدواج زیر نقطه میانی خط ازدواج آویزان می‌شوند.
 *
 * خروجی: { nodes, links, bounds }  (مختصات مرکز دایره‌ها)
 * چیدمان در فضای منطقی چپ‌به‌راست انجام می‌شود و برای فارسی (rtl) قرینه می‌شود
 * تا فرزند ارشد سمت راست و شوهر سمت راستِ همسرش قرار بگیرد.
 */

/**
 * اندازه‌ها (در حالت فشرده کوچک‌تر می‌شوند).
 *
 * متن‌ها نسبت به عکس کوچک و درست بیرون حلقه قرار می‌گیرند؛ شعاع خط پایه متن‌ها از روی
 * اندازه حروف حساب می‌شود تا دنباله حروف (ی، ن، ر) به حلقه نخورد و فاصله اضافه هم نماند.
 * فاصله همسران و خواهر/برادرها کم، و فاصله شاخه‌های خانواده‌های مختلف بیشتر است
 * تا در چاپ دیواری بزرگ (هزار نفر و بیشتر) خانواده‌ها از هم جدا ولی درون خود فشرده باشند.
 */
export function geometry(compact = false) {
  const R = compact ? 32 : 40; // شعاع عکس
  const ring = compact ? 2.5 : 3; // ضخامت حلقه رنگی
  const topSize = compact ? 9 : 10.5; // قلم نام (قوس بالا)
  const bottomSize = compact ? 7.6 : 8.8; // قلم تاریخ‌ها (قوس پایین)
  // قوس بالا: حروف رو به بیرون؛ خط پایه به اندازه دنباله حروف بیرون از حلقه
  const rt = R + ring + topSize * 0.38 + 0.6;
  // قوس پایین: حروف ایستاده و رو به مرکز؛ خط پایه به اندازه بلندی حروف بیرون از حلقه
  const rb = R + ring + bottomSize * 0.8 + 0.8;
  const outer = Math.ceil(Math.max(rt + topSize * 0.8, rb + bottomSize * 0.4) + 1);
  return {
    R,
    ring,
    topSize,
    bottomSize,
    rt,
    rb,
    outer,
    band: outer - R - ring,
    nodeW: outer * 2 + (compact ? 2 : 4),
    spouseGap: compact ? 4 : 8, // فاصله همسران در یک ردیف
    siblingGap: compact ? 4 : 6, // فاصله خواهر و برادرها
    groupGap: compact ? 12 : 16, // فاصله فرزندانِ همسرهای مختلف
    subtreeGap: compact ? 22 : 30, // فاصله شاخه‌های خانواده‌های مختلف (عموزاده‌ها ...)
    levelH: compact ? 146 : 176, // فاصله نسل‌ها
  };
}

// ------------------------------------------------------------------ کانتور
/** قرار دادن زیردرخت‌ها کنار هم؛ خروجی: جابه‌جایی افقی هر کدام */
function packSubtrees(contours, gapFor) {
  const offsets = [];
  const acc = [];
  contours.forEach((contour, i) => {
    let x = 0;
    if (i > 0) {
      x = -Infinity;
      const levels = Math.min(acc.length, contour.length);
      for (let l = 0; l < levels; l++) {
        x = Math.max(x, acc[l].r + gapFor(i, l) - contour[l].l);
      }
      if (x === -Infinity) x = offsets[i - 1];
    }
    offsets.push(x);
    contour.forEach((c, l) => {
      if (!acc[l]) acc[l] = { l: c.l + x, r: c.r + x };
      else {
        acc[l].l = Math.min(acc[l].l, c.l + x);
        acc[l].r = Math.max(acc[l].r, c.r + x);
      }
    });
  });
  return { offsets, contour: acc };
}

function shiftContour(contour, dx) {
  return contour.map((c) => ({ l: c.l + dx, r: c.r + dx }));
}

/**
 * جایگاه همسران در ردیف: همه در یک سمت شخص و به ترتیب ازدواج
 * (همسر اول نزدیک‌ترین، دوم کنار او، سوم بعدی ...).
 * سمت: همسرانِ مرد سمت چپ او و همسرانِ زن سمت راست او (پس از قرینه‌سازی راست‌به‌چپ)
 * تا همیشه شوهر سمت راست همسرش باشد.
 */
function spouseSlot(i, gender) {
  return (gender === 'f' ? -1 : 1) * (i + 1);
}

// ------------------------------------------------------------------ نمای نوادگان
/**
 * @param {import('./model.js').TreeData} data
 * @param {string} rootId
 * @param {object} opts  collapsed:Set, maxDepth, rtl, compact, path:Set (حالت مسیر), targetId
 */
export function layoutDescendants(data, rootId, opts = {}) {
  const raw = descendantsRaw(data, rootId, opts);
  return finish(raw.nodes, raw.links, raw.g, opts.rtl);
}

function descendantsRaw(data, rootId, opts = {}) {
  const g = geometry(opts.compact);
  const collapsed = opts.collapsed || new Set();
  const maxDepth = opts.maxDepth ?? Infinity;
  const slotW = g.nodeW + g.spouseGap;
  const placed = new Set();
  const blood = data.descendantsOf(rootId);

  // حالت «مسیر نسبی»: فقط اعضای مسیر نمایش داده می‌شوند تا به شخص هدف برسیم؛
  // از شخص هدف به پایین همه نوادگان (تا عمق مشخص)
  const path = opts.path || null;

  function build(personId, depth, below) {
    placed.add(personId);
    const isBelow = !path || below || personId === opts.targetId;

    // همسران
    const marriages = data.marriagesOf(personId);
    const gender = data.get(personId)?.gender;
    const spouses = marriages.map((m, i) => ({
      marriage: m,
      id: data.partnerOf(m, personId),
      slot: spouseSlot(i, gender),
    }));

    // گروه‌بندی فرزندان بر اساس والد دیگر
    const allChildren = data.childrenOf(personId);
    // لنگر گروه: وسط خط ازدواج؛ برای همسر سوم به بعد، زیر خودِ همسر
    const groups = spouses.map((s) => ({ spouse: s, anchor: Math.abs(s.slot) > 1 ? s.slot * slotW : (s.slot * slotW) / 2, children: [] }));
    const solo = { spouse: null, anchor: 0, children: [] };
    for (const child of allChildren) {
      const other = child.father_id === personId ? child.mother_id : child.father_id;
      const group = groups.find((gr) => gr.spouse.id === other) || solo;
      group.children.push(child);
    }
    if (solo.children.length) groups.push(solo);
    groups.sort((a, b) => a.anchor - b.anchor);

    const visibleCount = allChildren.length;
    const showChildren = !collapsed.has(personId) && depth < maxDepth;

    const unit = { personId, depth, spouses, groups: [], children: [], childCount: visibleCount, collapsed: collapsed.has(personId) };
    const contours = [];
    const childMeta = [];

    if (showChildren) {
      for (const group of groups) {
        const gChildren = [];
        for (const child of group.children) {
          if (placed.has(child.id)) continue; // فرزندِ ازدواج فامیلی که قبلاً زیر والد دیگر قرار گرفته
          if (!isBelow && !path.has(child.id)) continue;
          const sub = build(child.id, depth + 1, isBelow);
          gChildren.push(sub);
          contours.push(sub.contour);
          childMeta.push({ group: unit.groups.length });
        }
        unit.groups.push({ spouseId: group.spouse?.id || null, marriage: group.spouse?.marriage || null, anchor: group.anchor, units: gChildren });
      }
    }

    // ردیف خود واحد
    const slots = [0, ...spouses.map((s) => s.slot)];
    const rowL = Math.min(...slots) * slotW - g.nodeW / 2;
    const rowR = Math.max(...slots) * slotW + g.nodeW / 2;

    let contour = [{ l: rowL, r: rowR }];
    if (contours.length) {
      const { offsets, contour: cc } = packSubtrees(contours, (i, level) => {
        if (level > 0) return g.subtreeGap;
        return childMeta[i].group !== childMeta[i - 1].group ? g.groupGap : g.siblingGap;
      });
      // وسط‌چین کردن فرزندان زیر نقطه‌های اتصال (میانگین لنگرها)
      const usedAnchors = unit.groups.filter((gr) => gr.units.length).map((gr) => gr.anchor);
      const target = usedAnchors.reduce((a, b) => a + b, 0) / usedAnchors.length;
      const center = (offsets[0] + offsets[offsets.length - 1]) / 2;
      const shift = target - center;
      let k = 0;
      for (const gr of unit.groups) {
        for (const sub of gr.units) {
          unit.children.push({ unit: sub, x: offsets[k] + shift });
          k++;
        }
      }
      contour = contour.concat(shiftContour(cc, shift));
    }

    unit.contour = contour;
    return unit;
  }

  const rootUnit = build(rootId, 0, false);

  // موقعیت مطلق
  const nodes = [];
  const links = [];
  const nodeByKey = new Map();

  function addNode(node) {
    nodes.push(node);
    nodeByKey.set(node.key, node);
    return node;
  }

  function assign(unit, x, y, from = null) {
    const person = data.get(unit.personId);
    const main = addNode({
      key: unit.personId,
      id: unit.personId,
      from,
      person,
      x,
      y,
      depth: unit.depth,
      kind: 'blood',
      childCount: unit.childCount,
      collapsed: unit.collapsed,
      expandable: data.expandable.has(unit.personId) && unit.childCount === 0,
      isRoot: unit.depth === 0,
    });

    const spouseNodes = new Map();
    for (const s of unit.spouses) {
      const alias = blood.has(s.id) && s.id !== unit.personId;
      const sp = addNode({
        key: alias ? `${s.id}@${unit.personId}` : s.id,
        id: s.id,
        person: data.get(s.id),
        x: x + s.slot * slotW,
        y,
        depth: unit.depth,
        kind: alias ? 'alias' : 'spouse',
        marriage: s.marriage,
        from: main.key,
      });
      spouseNodes.set(s.id, sp);
      links.push({ type: 'marriage', a: main, b: sp, marriage: s.marriage, far: Math.abs(s.slot) > 1, level: Math.abs(s.slot) });
    }

    for (const gr of unit.groups) {
      const spouseNode = gr.spouseId ? spouseNodes.get(gr.spouseId) : null;
      for (const sub of gr.units) {
        const entry = unit.children.find((c) => c.unit === sub);
        const child = assign(sub, x + entry.x, y + g.levelH, main.key);
        const far = spouseNode && Math.abs(unit.spouses.find((sp) => sp.id === gr.spouseId)?.slot || 0) > 1;
        links.push({ type: 'child', parent: main, spouse: spouseNode, spouseFar: far, child });
      }
    }
    return main;
  }

  assign(rootUnit, 0, 0);
  return { nodes, links, g };
}

// ------------------------------------------------------------------ نمای نیاکان
export function layoutAncestors(data, rootId, opts = {}) {
  const raw = ancestorsRaw(data, rootId, opts);
  return finish(raw.nodes, raw.links, raw.g, opts.rtl);
}

function ancestorsRaw(data, rootId, opts = {}) {
  const g = geometry(opts.compact);
  const collapsed = opts.collapsed || new Set();
  const maxDepth = opts.maxDepth ?? Infinity;
  const placed = new Set();

  function build(personId, depth, alias = false) {
    placed.add(personId);
    const p = data.get(personId);
    const unit = { personId, depth, alias, parents: [], contour: [{ l: -g.nodeW / 2, r: g.nodeW / 2 }] };
    const parentIds = [p.father_id, p.mother_id].filter((id) => id && data.get(id));
    unit.hiddenParents = !!(p.father_id || p.mother_id) && (collapsed.has(personId) || depth >= maxDepth || parentIds.length === 0);
    unit.canCollapse = parentIds.length > 0 && !collapsed.has(personId) && depth < maxDepth && !alias;
    if (alias || collapsed.has(personId) || depth >= maxDepth || !parentIds.length) return unit;

    const subs = parentIds.map((id) => build(id, depth + 1, placed.has(id)));
    const { offsets, contour } = packSubtrees(subs.map((s) => s.contour), (i, level) => (level === 0 ? g.spouseGap : g.subtreeGap));
    const center = (offsets[0] + offsets[offsets.length - 1]) / 2;
    unit.parents = subs.map((s, i) => ({ unit: s, x: offsets[i] - center }));
    unit.contour = unit.contour.concat(shiftContour(contour, -center));
    return unit;
  }

  const root = build(rootId, 0);
  const nodes = [];
  const links = [];

  function assign(unit, x, y, childNode) {
    const node = {
      key: unit.alias ? `${unit.personId}@a${nodes.length}` : unit.personId,
      id: unit.personId,
      from: childNode?.key || null,
      person: data.get(unit.personId),
      x,
      y,
      depth: -unit.depth,
      kind: unit.alias ? 'alias' : unit.depth === 0 ? 'blood' : 'ancestor',
      expandUp: unit.hiddenParents && !unit.alias,
      canCollapseUp: unit.canCollapse,
      isRoot: unit.depth === 0,
    };
    nodes.push(node);
    const parentNodes = unit.parents.map((p) => assign(p.unit, x + p.x, y - g.levelH, node));
    if (parentNodes.length === 2) {
      links.push({ type: 'marriage', a: parentNodes[0], b: parentNodes[1], marriage: findMarriage(data, parentNodes[0].id, parentNodes[1].id) });
      links.push({ type: 'child', parent: parentNodes[0], spouse: parentNodes[1], child: node });
    } else if (parentNodes.length === 1) {
      links.push({ type: 'child', parent: parentNodes[0], spouse: null, child: node });
    }
    return node;
  }

  assign(root, 0, 0, null);
  return { nodes, links, g };
}

function findMarriage(data, a, b) {
  return data.marriagesOf(a).find((m) => data.partnerOf(m, a) === b) || null;
}

// ------------------------------------------------------------------ ساعت شنی
/** ترکیب نیاکان (بالا) و نوادگان (پایین) یک شخص */
export function layoutHourglass(data, rootId, opts = {}) {
  const down = descendantsRaw(data, rootId, { ...opts, maxDepth: opts.down ?? opts.maxDepth });
  const up = ancestorsRaw(data, rootId, { ...opts, maxDepth: opts.up ?? opts.maxDepth, collapsed: opts.collapsedUp });

  // گره کانونی در هر دو هست؛ نسخه نیاکان حذف و خطوطش به نسخه نوادگان وصل می‌شود
  const focusDown = down.nodes.find((n) => n.key === rootId);
  const focusUp = up.nodes.find((n) => n.key === rootId);
  focusDown.expandUp = focusUp?.expandUp;
  focusDown.canCollapseUp = focusUp?.canCollapseUp;
  const upNodes = up.nodes.filter((n) => n !== focusUp);
  const upLinks = up.links.map((l) => (l.child === focusUp ? { ...l, child: focusDown } : l));

  // کلیدهای تکراری (مثلاً همسرِ کانون که در نیاکان هم هست) یکتا شوند
  const keys = new Set(down.nodes.map((n) => n.key));
  for (const n of upNodes) {
    if (keys.has(n.key)) n.key = `${n.key}@up`;
  }

  return finish([...down.nodes, ...upNodes], [...down.links, ...upLinks], down.g, opts.rtl);
}

// ------------------------------------------------------------------ پایان: قرینه‌سازی و محاسبه مسیر خطوط
function finish(nodes, links, g, rtl) {
  if (rtl) {
    for (const n of nodes) n.x = -n.x;
  }
  // گرد کردن برای وضوح خطوط
  for (const n of nodes) {
    n.x = Math.round(n.x);
    n.y = Math.round(n.y);
  }

  const out = [];
  for (const l of links) {
    if (l.type === 'marriage') {
      out.push(marriagePath(l, g));
    } else {
      out.push(childPath(l, g));
    }
  }

  const bounds = { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity };
  for (const n of nodes) {
    bounds.minX = Math.min(bounds.minX, n.x - g.outer);
    bounds.maxX = Math.max(bounds.maxX, n.x + g.outer);
    bounds.minY = Math.min(bounds.minY, n.y - g.outer - 14);
    bounds.maxY = Math.max(bounds.maxY, n.y + g.outer + 18);
  }
  if (!nodes.length) Object.assign(bounds, { minX: 0, minY: 0, maxX: 0, maxY: 0 });
  bounds.width = bounds.maxX - bounds.minX;
  bounds.height = bounds.maxY - bounds.minY;

  return { nodes, links: out, bounds, geometry: g };
}

function marriagePath(l, g) {
  const a = l.a;
  const b = l.b;
  const left = a.x < b.x ? a : b;
  const right = a.x < b.x ? b : a;
  const edge = g.R + g.ring + 2;
  const divorced = l.marriage?.status === 'divorced';
  let d;
  let mid;
  if (!l.far) {
    d = `M${left.x + edge},${a.y} H${right.x - edge}`;
    mid = { x: (left.x + right.x) / 2, y: a.y };
  } else {
    // همسر دوم به بعد: قوس از بالای ردیف (هر همسر بعدی کمی بالاتر تا قوس‌ها روی هم نیفتند)
    const lift = g.outer + 26 + Math.max(0, (l.level || 2) - 2) * 18;
    const y0 = a.y - g.R * 0.75;
    d = `M${left.x},${y0} C${left.x},${a.y - lift} ${right.x},${a.y - lift} ${right.x},${y0}`;
    mid = { x: (left.x + right.x) / 2, y: a.y - lift * 0.76 };
  }
  return { key: `m:${a.key}:${b.key}`, type: 'marriage', d, mid, divorced, implied: !!l.marriage?.implied, marriage: l.marriage, a: a.key, b: b.key };
}

function childPath(l, g) {
  const parent = l.parent;
  const child = l.child;
  const up = child.y < parent.y; // در نمای نیاکان فرزند پایین‌تر است؛ این حالت عملاً رخ نمی‌دهد
  const r = 14;
  let sx;
  let sy;
  if (l.spouse && !l.spouseFar) {
    sx = (parent.x + l.spouse.x) / 2;
    sy = parent.y;
  } else if (l.spouse) {
    sx = l.spouse.x;
    sy = l.spouse.y + g.outer + 2;
  } else {
    sx = parent.x;
    sy = parent.y + g.outer + 2;
  }
  const ex = child.x;
  const ey = child.y - g.outer - 6;
  const busY = Math.round(Math.max(sy + 20, (parent.y + child.y) / 2 + (up ? 0 : 6)));

  let d;
  if (Math.abs(ex - sx) < 1) {
    d = `M${sx},${sy} V${ey}`;
  } else {
    const dir = ex > sx ? 1 : -1;
    const rr = Math.min(r, Math.abs(ex - sx) / 2, (busY - sy) / 2, (ey - busY) / 2);
    d = `M${sx},${sy} V${busY - rr} Q${sx},${busY} ${sx + dir * rr},${busY} H${ex - dir * rr} Q${ex},${busY} ${ex},${busY + rr} V${ey}`;
  }
  return { key: `c:${parent.key}:${child.key}`, type: 'child', d, parent: parent.key, spouse: l.spouse?.key, child: child.key };
}
