// تست‌های چیدمان درخت (بدون مرورگر)
import test from 'node:test';
import assert from 'node:assert/strict';
import { TreeData } from '../../public/assets/js/tree/model.js';
import { geometry, layoutDescendants, layoutAncestors } from '../../public/assets/js/tree/layout.js';

let seq = 0;
function person(attrs) {
  return { id: `p${++seq}`, first_name: 'x', gender: 'm', father_id: null, mother_id: null, ...attrs };
}

/** خانواده نمونه: پدربزرگ با سه همسر، هر همسر چند فرزند، و نوه‌ها */
function sample() {
  const persons = [];
  const marriages = [];
  const add = (a) => {
    const p = person(a);
    persons.push(p);
    return p;
  };
  const marry = (h, w, order, date) => marriages.push({ id: `m${marriages.length}`, husband_id: h.id, wife_id: w.id, sort_order: order, marriage_date: date, status: 'married' });
  const root = add({ first_name: 'پدربزرگ' });
  const wives = [0, 1, 2].map((i) => add({ gender: 'f', first_name: `همسر${i + 1}` }));
  // ترتیب ثبت عمداً به هم ریخته است؛ ترتیب ازدواج باید رعایت شود
  marry(root, wives[2], 2, '1320');
  marry(root, wives[0], 0, '1300');
  marry(root, wives[1], 1, '1310');
  const kids = [];
  wives.forEach((w, i) => {
    for (let k = 0; k < 2 + i; k++) kids.push(add({ father_id: root.id, mother_id: w.id, gender: k % 2 ? 'f' : 'm', birth_order: k + 1 }));
  });
  // نوه‌ها
  for (const kid of kids.slice(0, 4)) {
    for (let k = 0; k < 3; k++) add(kid.gender === 'm' ? { father_id: kid.id } : { mother_id: kid.id });
  }
  return { data: new TreeData({ persons, marriages }), root, wives };
}

function assertNoOverlap(layout) {
  const g = layout.geometry;
  const rows = new Map();
  for (const n of layout.nodes) {
    if (!rows.has(n.y)) rows.set(n.y, []);
    rows.get(n.y).push(n.x);
  }
  for (const [y, xs] of rows) {
    xs.sort((a, b) => a - b);
    for (let i = 1; i < xs.length; i++) {
      assert.ok(xs[i] - xs[i - 1] >= g.nodeW - 1, `overlap in row ${y}: ${xs[i - 1]} / ${xs[i]}`);
    }
  }
}

test('هندسه: متن نزدیک عکس و کوچک‌تر از آن', () => {
  for (const compact of [false, true]) {
    const g = geometry(compact);
    assert.ok(g.rt > g.R + g.ring, 'top baseline outside the ring');
    assert.ok(g.rb > g.R + g.ring, 'bottom baseline outside the ring');
    assert.ok(g.outer - (g.R + g.ring) <= 15, 'text band stays tight around the photo');
    assert.ok(g.topSize / g.R < 0.3, 'name font is small relative to the photo');
    assert.ok(g.subtreeGap > g.siblingGap, 'families are separated more than siblings');
    assert.ok(g.levelH >= g.outer * 2 + 40, 'generations do not collide');
  }
});

test('نوادگان: بدون هم‌پوشانی و همسران به ترتیب ازدواج (اولی نزدیک‌ترین)', () => {
  const { data, root, wives } = sample();
  for (const rtl of [false, true]) {
    for (const compact of [false, true]) {
      const layout = layoutDescendants(data, root.id, { rtl, compact });
      assertNoOverlap(layout);
      const rootNode = layout.nodes.find((n) => n.key === root.id);
      const dist = wives.map((w) => Math.abs(layout.nodes.find((n) => n.id === w.id).x - rootNode.x));
      assert.ok(dist[0] < dist[1] && dist[1] < dist[2], `spouse order ${dist}`);
      // همه همسران در یک سمت
      const sides = wives.map((w) => Math.sign(layout.nodes.find((n) => n.id === w.id).x - rootNode.x));
      assert.equal(new Set(sides).size, 1);
      // در نمای راست‌به‌چپ همسرانِ مرد سمت چپ او هستند (شوهر سمت راست)
      if (rtl) assert.equal(sides[0], -1);
    }
  }
});

test('نوادگان: خطوط ازدواج دوم به بعد قوس‌های جدا دارند', () => {
  const { data, root } = sample();
  const layout = layoutDescendants(data, root.id, { rtl: true });
  const marriages = layout.links.filter((l) => l.type === 'marriage' && l.a === root.id);
  assert.equal(marriages.length, 3);
  const mids = marriages.map((l) => l.mid.y).sort((a, b) => a - b);
  assert.ok(mids[0] < mids[1] && mids[1] <= mids[2], 'arcs are stacked at different heights');
});

test('نیاکان: پدر و مادر بدون هم‌پوشانی', () => {
  const persons = [];
  const add = (a) => {
    const p = person(a);
    persons.push(p);
    return p;
  };
  let gen = [add({})];
  const me = gen[0];
  for (let d = 0; d < 4; d++) {
    const next = [];
    for (const c of gen) {
      const f = add({});
      const m = add({ gender: 'f' });
      c.father_id = f.id;
      c.mother_id = m.id;
      next.push(f, m);
    }
    gen = next;
  }
  const layout = layoutAncestors(new TreeData({ persons, marriages: [] }), me.id, { rtl: true });
  assert.equal(layout.nodes.length, 31);
  assertNoOverlap(layout);
});
