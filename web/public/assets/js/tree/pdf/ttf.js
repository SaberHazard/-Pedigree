/**
 * خواندن فایل فونت TrueType (فقط جدول‌هایی که برای جاسازی در PDF لازم است):
 * cmap (کد یونیکد ← شماره گلیف)، hmtx (پهنای هر گلیف)، head، hhea، OS/2، post، name
 *
 * بدون وابستگی به مرورگر؛ هم در مرورگر و هم در Node (برای تست) کار می‌کند.
 */
export class TrueTypeFont {
  /** @param {ArrayBuffer|Uint8Array} buffer */
  constructor(buffer) {
    this.bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    this.view = new DataView(this.bytes.buffer, this.bytes.byteOffset, this.bytes.byteLength);
    this.tables = {};
    const numTables = this.view.getUint16(4);
    for (let i = 0; i < numTables; i++) {
      const o = 12 + i * 16;
      const tag = String.fromCharCode(...this.bytes.subarray(o, o + 4));
      this.tables[tag] = { offset: this.view.getUint32(o + 8), length: this.view.getUint32(o + 12) };
    }
    for (const t of ['cmap', 'head', 'hhea', 'hmtx', 'maxp']) {
      if (!this.tables[t]) throw new Error(`فونت نامعتبر است (جدول ${t} پیدا نشد)`);
    }
    this.readHead();
    this.readHhea();
    this.readHmtx();
    this.readCmap();
    this.readOs2();
    this.readPost();
    this.name = this.readName() || 'Font';
  }

  u16(o) { return this.view.getUint16(o); }
  i16(o) { return this.view.getInt16(o); }
  u32(o) { return this.view.getUint32(o); }

  readHead() {
    const o = this.tables.head.offset;
    this.unitsPerEm = this.u16(o + 18);
    this.bbox = [this.i16(o + 36), this.i16(o + 38), this.i16(o + 40), this.i16(o + 42)];
  }

  readHhea() {
    const o = this.tables.hhea.offset;
    this.ascent = this.i16(o + 4);
    this.descent = this.i16(o + 6);
    this.numberOfHMetrics = this.u16(o + 34);
    this.numGlyphs = this.u16(this.tables.maxp.offset + 4);
  }

  readHmtx() {
    const o = this.tables.hmtx.offset;
    this.advances = new Uint16Array(this.numGlyphs);
    let last = 0;
    for (let g = 0; g < this.numGlyphs; g++) {
      if (g < this.numberOfHMetrics) last = this.u16(o + g * 4);
      this.advances[g] = last;
    }
  }

  /** زیرجدول‌های cmap: قالب 12 (همه یونیکد) یا 4 (فقط BMP) */
  readCmap() {
    const base = this.tables.cmap.offset;
    const n = this.u16(base + 2);
    let best = null;
    for (let i = 0; i < n; i++) {
      const platform = this.u16(base + 4 + i * 8);
      const encoding = this.u16(base + 6 + i * 8);
      const offset = base + this.u32(base + 8 + i * 8);
      const format = this.u16(offset);
      const score = format === 12 ? 3 : (format === 4 && (platform === 3 || platform === 0)) ? 2 : 0;
      if (score > (best?.score ?? 0)) best = { offset, format, score, platform, encoding };
    }
    if (!best) throw new Error('جدول cmap پشتیبانی نمی‌شود');
    this.cmap = new Map();
    const o = best.offset;
    if (best.format === 12) {
      const groups = this.u32(o + 12);
      for (let i = 0; i < groups; i++) {
        const start = this.u32(o + 16 + i * 12);
        const end = this.u32(o + 20 + i * 12);
        const gid = this.u32(o + 24 + i * 12);
        for (let c = start; c <= end && c - start < 70000; c++) this.cmap.set(c, gid + (c - start));
      }
    } else {
      const segX2 = this.u16(o + 6);
      const ends = o + 14;
      const starts = ends + segX2 + 2;
      const deltas = starts + segX2;
      const rangeOffsets = deltas + segX2;
      for (let s = 0; s < segX2 / 2; s++) {
        const end = this.u16(ends + s * 2);
        const start = this.u16(starts + s * 2);
        const delta = this.i16(deltas + s * 2);
        const ro = this.u16(rangeOffsets + s * 2);
        for (let c = start; c <= end && c !== 0xffff; c++) {
          let gid;
          if (ro === 0) gid = (c + delta) & 0xffff;
          else {
            const addr = rangeOffsets + s * 2 + ro + (c - start) * 2;
            gid = this.u16(addr);
            if (gid !== 0) gid = (gid + delta) & 0xffff;
          }
          if (gid) this.cmap.set(c, gid);
        }
      }
    }
  }

  readOs2() {
    const t = this.tables['OS/2'];
    this.capHeight = Math.round(this.ascent * 0.7);
    this.weight = 400;
    if (!t) return;
    const o = t.offset;
    this.weight = this.u16(o + 4);
    const version = this.u16(o);
    if (version >= 2 && t.length >= 90) this.capHeight = this.i16(o + 88) || this.capHeight;
  }

  readPost() {
    const t = this.tables.post;
    this.italicAngle = t ? this.view.getInt32(t.offset + 4) / 65536 : 0;
  }

  /** نام PostScript فونت (name id 6) */
  readName() {
    const t = this.tables.name;
    if (!t) return null;
    const o = t.offset;
    const count = this.u16(o + 2);
    const strings = o + this.u16(o + 4);
    for (let i = 0; i < count; i++) {
      const r = o + 6 + i * 12;
      const platform = this.u16(r);
      const nameId = this.u16(r + 6);
      if (nameId !== 6) continue;
      const len = this.u16(r + 8);
      const off = strings + this.u16(r + 10);
      const raw = this.bytes.subarray(off, off + len);
      let s = '';
      if (platform === 3 || platform === 0) {
        for (let k = 0; k + 1 < raw.length; k += 2) s += String.fromCharCode((raw[k] << 8) | raw[k + 1]);
      } else {
        s = String.fromCharCode(...raw);
      }
      return s.replace(/[^A-Za-z0-9_-]/g, '');
    }
    return null;
  }

  glyphId(codePoint) {
    return this.cmap.get(codePoint) || 0;
  }

  has(codePoint) {
    return this.cmap.has(codePoint);
  }

  /** پهنای گلیف به واحد em (برای اندازه قلم size ضرب شود) */
  advance(gid) {
    return (this.advances[gid] || 0) / this.unitsPerEm;
  }
}
