/**
 * سازنده سبک فایل PDF برداری (بدون کتابخانه خارجی).
 *
 * امکانات: جاسازی فونت TrueType (Type0 / Identity-H با نقشه ToUnicode تا متن قابل
 * جستجو و کپی باشد)، تصویر JPEG، گرادیان (Shading)، شفافیت، فرم مشترک (XObject)
 * برای تکرار یک طرح بزرگ در چند صفحه، و UserUnit برای صفحه‌های بزرگ‌تر از ۵ متر.
 * جریان‌ها با Flate فشرده می‌شوند (CompressionStream مرورگر یا Node).
 */
const enc = new TextEncoder();

/** عدد کوتاه برای PDF */
export function num(v) {
  if (!Number.isFinite(v)) return '0';
  const r = Math.round(v * 100) / 100;
  return Number.isInteger(r) ? String(r) : r.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

function utf16Hex(text) {
  let hex = 'FEFF';
  for (const ch of text) {
    const code = ch.codePointAt(0);
    if (code > 0xffff) {
      const c = code - 0x10000;
      hex += (0xd800 + (c >> 10)).toString(16).padStart(4, '0') + (0xdc00 + (c & 0x3ff)).toString(16).padStart(4, '0');
    } else hex += code.toString(16).padStart(4, '0');
  }
  return `<${hex.toUpperCase()}>`;
}

async function deflate(bytes) {
  if (typeof CompressionStream === 'undefined') return null;
  const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate'));
  return new Uint8Array(await new Response(stream).arrayBuffer());
}

export class PdfDocument {
  constructor({ title = '', compress = true } = {}) {
    this.title = title;
    this.compress = compress;
    this.objects = []; // id → {dict, stream?, raw?}
    this.fonts = [];
    this.images = [];
    this.shadings = [];
    this.gstates = new Map();
    this.forms = [];
    this.pages = [];
    this.catalogId = this.alloc();
    this.pagesId = this.alloc();
    this.infoId = this.alloc();
    this.resourcesId = this.alloc();
  }

  alloc() {
    this.objects.push(null);
    return this.objects.length;
  }

  set(id, value) {
    this.objects[id - 1] = value;
  }

  // ---------------------------------------------------------------- منابع

  /** ثبت فونت؛ گلیف‌های مصرف‌شده برای جدول پهنا و ToUnicode جمع می‌شوند */
  addFont(ttf) {
    const font = { ttf, name: `F${this.fonts.length + 1}`, used: new Map() };
    this.fonts.push(font);
    return font;
  }

  /** @param {Uint8Array} jpeg */
  addImage(jpeg, width, height, gray = false) {
    const id = this.alloc();
    const name = `Im${this.images.length + 1}`;
    this.set(id, {
      dict: `/Type /XObject /Subtype /Image /Width ${width} /Height ${height} /ColorSpace /${gray ? 'DeviceGray' : 'DeviceRGB'} /BitsPerComponent 8 /Filter /DCTDecode`,
      stream: jpeg,
      noCompress: true,
    });
    this.images.push({ id, name });
    return name;
  }

  /**
   * گرادیان خطی
   * @param {Array<[number, [number,number,number]]>} stops  [جایگاه ۰..۱، رنگ RGB ۰..۱]
   * @param {number[]} coords [x0 y0 x1 y1]
   */
  addShading(stops, coords) {
    const id = this.alloc();
    const name = `Sh${this.shadings.length + 1}`;
    const fn = (a, b) => `<< /FunctionType 2 /Domain [0 1] /C0 [${a.map(num).join(' ')}] /C1 [${b.map(num).join(' ')}] /N 1 >>`;
    let func;
    if (stops.length === 2) func = fn(stops[0][1], stops[1][1]);
    else {
      const parts = [];
      const bounds = [];
      const encode = [];
      for (let i = 0; i < stops.length - 1; i++) {
        parts.push(fn(stops[i][1], stops[i + 1][1]));
        if (i > 0) bounds.push(num(stops[i][0]));
        encode.push('0 1');
      }
      func = `<< /FunctionType 3 /Domain [0 1] /Functions [${parts.join(' ')}] /Bounds [${bounds.join(' ')}] /Encode [${encode.join(' ')}] >>`;
    }
    this.set(id, { dict: `/ShadingType 2 /ColorSpace /DeviceRGB /Coords [${coords.map(num).join(' ')}] /Function ${func} /Extend [true true]` });
    this.shadings.push({ id, name });
    return name;
  }

  /** شفافیت (opacity) پر کردن/خط */
  alpha(value) {
    const key = num(value);
    if (!this.gstates.has(key)) {
      const id = this.alloc();
      this.set(id, { dict: `/Type /ExtGState /ca ${key} /CA ${key}` });
      this.gstates.set(key, { id, name: `GS${this.gstates.size + 1}` });
    }
    return this.gstates.get(key).name;
  }

  /** فرم مشترک: یک طرح که در چند صفحه (با برش متفاوت) تکرار می‌شود */
  addForm(content, bbox) {
    const id = this.alloc();
    const name = `Fm${this.forms.length + 1}`;
    this.forms.push({ id, name, content, bbox });
    return name;
  }

  /**
   * @param {number} width  عرض صفحه به point (۱/۷۲ اینچ)
   * @param {number} height ارتفاع صفحه به point
   * @param {string} content دستورهای رسم (به واحد صفحه پس از UserUnit)
   * @param {number} userUnit هر واحد صفحه چند point است (برای صفحه‌های بزرگ‌تر از ۲۰۰ اینچ)
   * @param {{rect:number[], uri:string}[]} links ناحیه‌های قابل کلیک (به واحد صفحه) که به یک نشانی https می‌روند
   */
  addPage(width, height, content, userUnit = 1, links = []) {
    const safe = links.filter((l) => /^https?:\/\/[A-Za-z0-9.\-]+(:\d+)?(\/[A-Za-z0-9._~\/?#&=%+-]*)?$/.test(l.uri || ''))
      .map((l) => ({ ...l, id: this.alloc() }));
    this.pages.push({ width, height, content, userUnit, links: safe, id: this.alloc(), contentId: this.alloc() });
  }

  // ---------------------------------------------------------------- ساخت فایل

  async build() {
    // فونت‌ها
    for (const f of this.fonts) await this.buildFont(f);

    // فرم‌ها
    for (const form of this.forms) {
      this.set(form.id, {
        dict: `/Type /XObject /Subtype /Form /BBox [${form.bbox.map(num).join(' ')}] /Resources ${this.resourcesId} 0 R`,
        stream: enc.encode(form.content),
      });
    }

    // منابع مشترک همه صفحه‌ها و فرم‌ها
    const fontRes = this.fonts.map((f) => `/${f.name} ${f.type0Id} 0 R`).join(' ');
    const xobj = [...this.images, ...this.forms].map((x) => `/${x.name} ${x.id} 0 R`).join(' ');
    const sh = this.shadings.map((s) => `/${s.name} ${s.id} 0 R`).join(' ');
    const gs = [...this.gstates.values()].map((g) => `/${g.name} ${g.id} 0 R`).join(' ');
    this.set(this.resourcesId, { dict: `/Font << ${fontRes} >> /XObject << ${xobj} >> /Shading << ${sh} >> /ExtGState << ${gs} >> /ProcSet [/PDF /Text /ImageC /ImageB]`, bare: true });

    const needs16 = this.pages.some((p) => p.userUnit !== 1);
    for (const p of this.pages) {
      for (const l of p.links) {
        this.set(l.id, { dict: `/Type /Annot /Subtype /Link /Rect [${l.rect.map(num).join(' ')}] /Border [0 0 0] /A << /S /URI /URI (${l.uri}) >>`, bare: true });
      }
      const annots = p.links.length ? ` /Annots [${p.links.map((l) => `${l.id} 0 R`).join(' ')}]` : '';
      this.set(p.id, {
        dict: `/Type /Page /Parent ${this.pagesId} 0 R /MediaBox [0 0 ${num(p.width / p.userUnit)} ${num(p.height / p.userUnit)}]${p.userUnit !== 1 ? ` /UserUnit ${num(p.userUnit)}` : ''} /Resources ${this.resourcesId} 0 R /Contents ${p.contentId} 0 R${annots}`,
      });
      this.set(p.contentId, { dict: '', stream: enc.encode(p.content) });
    }
    this.set(this.catalogId, { dict: `/Type /Catalog /Pages ${this.pagesId} 0 R /ViewerPreferences << /Direction /R2L /DisplayDocTitle true >>` });
    this.set(this.pagesId, { dict: `/Type /Pages /Kids [${this.pages.map((p) => `${p.id} 0 R`).join(' ')}] /Count ${this.pages.length}` });
    const date = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
    this.set(this.infoId, { dict: `/Title ${utf16Hex(this.title)} /Producer (Pedigree vector PDF) /Creator (Pedigree) /CreationDate (D:${date})` });

    // نوشتن
    const chunks = [];
    let length = 0;
    const offsets = [];
    const push = (data) => {
      const bytes = typeof data === 'string' ? enc.encode(data) : data;
      chunks.push(bytes);
      length += bytes.length;
    };
    push(`%PDF-${needs16 ? '1.6' : '1.5'}\n%\xE2\xE3\xCF\xD3\n`);
    for (let i = 0; i < this.objects.length; i++) {
      const obj = this.objects[i];
      const id = i + 1;
      offsets[id] = length;
      push(`${id} 0 obj\n`);
      if (obj.stream) {
        let data = obj.stream;
        let filter = '';
        if (this.compress && !obj.noCompress && data.length > 256) {
          const z = await deflate(data);
          if (z && z.length < data.length) {
            data = z;
            filter = ' /Filter /FlateDecode';
          }
        }
        push(`<< ${obj.dict}${filter} /Length ${data.length}${obj.extra || ''} >>\nstream\n`);
        push(data);
        push('\nendstream');
      } else {
        push(`<< ${obj.dict} >>`);
      }
      push('\nendobj\n');
    }
    const xref = length;
    let table = `xref\n0 ${this.objects.length + 1}\n0000000000 65535 f \n`;
    for (let id = 1; id <= this.objects.length; id++) table += `${String(offsets[id]).padStart(10, '0')} 00000 n \n`;
    push(table);
    push(`trailer\n<< /Size ${this.objects.length + 1} /Root ${this.catalogId} 0 R /Info ${this.infoId} 0 R >>\nstartxref\n${xref}\n%%EOF`);
    return new Blob(chunks, { type: 'application/pdf' });
  }

  /** فونت Type0 + CIDFontType2 + جاسازی کامل فایل TTF + ToUnicode */
  async buildFont(f) {
    const t = f.ttf;
    const scale = 1000 / t.unitsPerEm;
    const fileId = this.alloc();
    const descId = this.alloc();
    const cidId = this.alloc();
    const toUniId = this.alloc();
    f.type0Id = this.alloc();
    const name = t.name || f.name;

    this.set(fileId, { dict: `/Length1 ${t.bytes.length}`, stream: t.bytes });
    this.set(descId, {
      dict: `/Type /FontDescriptor /FontName /${name} /Flags 4 /FontBBox [${t.bbox.map((v) => num(v * scale)).join(' ')}] /ItalicAngle ${num(t.italicAngle)} /Ascent ${num(t.ascent * scale)} /Descent ${num(t.descent * scale)} /CapHeight ${num(t.capHeight * scale)} /StemV ${t.weight >= 600 ? 120 : 80} /FontFile2 ${fileId} 0 R`,
    });

    // پهنای گلیف‌های مصرف‌شده
    const gids = [...f.used.keys()].sort((a, b) => a - b);
    const w = gids.map((g) => `${g} [${num(t.advances[g] * scale)}]`).join(' ');
    this.set(cidId, {
      dict: `/Type /Font /Subtype /CIDFontType2 /BaseFont /${name} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ${descId} 0 R /DW 500 /W [${w}] /CIDToGIDMap /Identity`,
    });

    // ToUnicode: گلیف ← متن اصلی (متن PDF قابل جستجو و کپی می‌شود)
    const lines = [];
    for (const g of gids) {
      const src = f.used.get(g);
      if (!src) continue;
      let hex = '';
      for (const ch of src) {
        const code = ch.codePointAt(0);
        if (code > 0xffff) {
          const c = code - 0x10000;
          hex += (0xd800 + (c >> 10)).toString(16).padStart(4, '0') + (0xdc00 + (c & 0x3ff)).toString(16).padStart(4, '0');
        } else hex += code.toString(16).padStart(4, '0');
      }
      lines.push(`<${g.toString(16).padStart(4, '0')}> <${hex}>`);
    }
    let cmap = '/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n';
    for (let i = 0; i < lines.length; i += 100) {
      const part = lines.slice(i, i + 100);
      cmap += `${part.length} beginbfchar\n${part.join('\n')}\nendbfchar\n`;
    }
    cmap += 'endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend';
    this.set(toUniId, { dict: '', stream: enc.encode(cmap) });

    this.set(f.type0Id, {
      dict: `/Type /Font /Subtype /Type0 /BaseFont /${name} /Encoding /Identity-H /DescendantFonts [${cidId} 0 R] /ToUnicode ${toUniId} 0 R`,
    });
  }
}
