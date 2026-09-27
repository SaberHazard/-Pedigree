/**
 * مدل داده درخت در سمت کاربر.
 *
 * داده‌های API (persons + marriages) را نگه می‌دارد، ایندکس «فرزندان هر شخص» و
 * «ازدواج‌های هر شخص» را می‌سازد و با بارگذاری شاخه‌های جدید (گسترش درخت)
 * داده‌ها را ادغام می‌کند.
 */

function birthKey(p) {
  // مرتب‌سازی فرزندان: ترتیب تولد، سپس تاریخ تولد، سپس نام
  const order = p.birth_order ?? 999;
  const date = p.birth_date || '9999';
  return `${String(order).padStart(3, '0')}|${date}|${p.first_name || ''}`;
}

export class TreeData {
  constructor(payload) {
    this.persons = new Map();
    this.marriages = new Map();
    this.expandable = new Set();
    this.focus = payload?.focus || null;
    this.mode = payload?.mode || 'descendants';
    this.meta = payload?.meta || {};
    if (payload) this.merge(payload);
  }

  /** ادغام داده جدید (مثلاً بعد از گسترش یک شاخه) */
  merge(payload) {
    for (const p of payload.persons || []) {
      this.persons.set(p.id, { ...(this.persons.get(p.id) || {}), ...p });
    }
    for (const m of payload.marriages || []) this.marriages.set(m.id, m);
    for (const id of payload.meta?.expandable || []) this.expandable.add(id);
    this.index();
  }

  index() {
    this.childrenMap = new Map();
    this.marriageMap = new Map();

    // ازدواج‌های ضمنی: پدر و مادرِ فرزندی که ازدواجشان ثبت نشده
    const pairs = new Set([...this.marriages.values()].map((m) => `${m.husband_id}|${m.wife_id}`));
    for (const p of this.persons.values()) {
      if (p.father_id && p.mother_id && this.persons.has(p.father_id) && this.persons.has(p.mother_id)) {
        const key = `${p.father_id}|${p.mother_id}`;
        if (!pairs.has(key)) {
          pairs.add(key);
          this.marriages.set(`implied:${key}`, {
            id: `implied:${key}`, husband_id: p.father_id, wife_id: p.mother_id, status: 'married', sort_order: 99, implied: true,
          });
        }
      }
    }

    for (const p of this.persons.values()) {
      for (const parentId of [p.father_id, p.mother_id]) {
        if (!parentId || !this.persons.has(parentId)) continue;
        if (!this.childrenMap.has(parentId)) this.childrenMap.set(parentId, []);
        this.childrenMap.get(parentId).push(p);
      }
      // اگر فرزندان این شخص بارگذاری شده‌اند، دیگر «قابل گسترش» نیست
    }
    for (const list of this.childrenMap.values()) list.sort((a, b) => birthKey(a).localeCompare(birthKey(b)));

    for (const m of this.marriages.values()) {
      if (!this.persons.has(m.husband_id) || !this.persons.has(m.wife_id)) continue;
      for (const id of [m.husband_id, m.wife_id]) {
        if (!this.marriageMap.has(id)) this.marriageMap.set(id, []);
        this.marriageMap.get(id).push(m);
      }
    }
    for (const list of this.marriageMap.values()) {
      list.sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || String(a.marriage_date || '9999').localeCompare(String(b.marriage_date || '9999')));
    }
  }

  get(id) {
    return this.persons.get(id);
  }

  childrenOf(id) {
    return this.childrenMap.get(id) || [];
  }

  marriagesOf(id) {
    return this.marriageMap.get(id) || [];
  }

  partnerOf(marriage, id) {
    return marriage.husband_id === id ? marriage.wife_id : marriage.husband_id;
  }

  /** همه نوادگانِ بارگذاری‌شده یک شخص (برای تشخیص اعضای خونی) */
  descendantsOf(rootId, maxDepth = Infinity) {
    const out = new Set([rootId]);
    let frontier = [rootId];
    for (let d = 0; d < maxDepth && frontier.length; d++) {
      const next = [];
      for (const id of frontier) {
        for (const c of this.childrenOf(id)) {
          if (!out.has(c.id)) {
            out.add(c.id);
            next.push(c.id);
          }
        }
      }
      frontier = next;
    }
    return out;
  }
}
