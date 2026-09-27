/**
 * سازنده PDF بسیار سبک (بدون کتابخانه خارجی).
 *
 * هر صفحه یک تصویر JPEG با کیفیت بالا است که از رسم برداری درخت ساخته می‌شود.
 * چون متن فارسی از قبل توسط مرورگر (با فونت وزیرمتن) رسم شده، هیچ مشکلی در
 * اتصال حروف و راست‌به‌چپ پیش نمی‌آید.
 */
const enc = new TextEncoder();

function utf16Hex(text) {
  let hex = 'FEFF';
  for (const ch of text) {
    const code = ch.codePointAt(0);
    if (code > 0xffff) {
      const c = code - 0x10000;
      hex += (0xd800 + (c >> 10)).toString(16).padStart(4, '0') + (0xdc00 + (c & 0x3ff)).toString(16).padStart(4, '0');
    } else {
      hex += code.toString(16).padStart(4, '0');
    }
  }
  return `<${hex.toUpperCase()}>`;
}

export class PdfWriter {
  constructor(title = '') {
    this.title = title;
    this.pages = [];
  }

  /**
   * @param {Uint8Array} jpeg  داده تصویر
   * @param {number} pxW,pxH   ابعاد تصویر به پیکسل
   * @param {number} ptW,ptH   ابعاد صفحه به point (1/72 اینچ)
   * @param {object} place     محل تصویر روی صفحه {x,y,w,h} به point (پیش‌فرض کل صفحه)
   */
  addPage(jpeg, pxW, pxH, ptW, ptH, place = null) {
    this.pages.push({ jpeg, pxW, pxH, ptW, ptH, place: place || { x: 0, y: 0, w: ptW, h: ptH } });
  }

  build() {
    const chunks = [];
    const offsets = [];
    let length = 0;
    const push = (data) => {
      const bytes = typeof data === 'string' ? enc.encode(data) : data;
      chunks.push(bytes);
      length += bytes.length;
    };
    const obj = (id, body) => {
      offsets[id] = length;
      push(`${id} 0 obj\n`);
      body();
      push('\nendobj\n');
    };

    push('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');

    const pageIds = this.pages.map((_, i) => 4 + i * 3);
    obj(1, () => push('<< /Type /Catalog /Pages 2 0 R >>'));
    obj(2, () => push(`<< /Type /Pages /Kids [${pageIds.map((id) => `${id} 0 R`).join(' ')}] /Count ${this.pages.length} >>`));
    const date = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
    obj(3, () => push(`<< /Title ${utf16Hex(this.title)} /Producer (Pedigree) /CreationDate (D:${date}) >>`));

    this.pages.forEach((page, i) => {
      const pageId = 4 + i * 3;
      const imgId = pageId + 1;
      const contentId = pageId + 2;
      const { x, y, w, h } = page.place;
      obj(pageId, () => push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${page.ptW.toFixed(2)} ${page.ptH.toFixed(2)}] /Resources << /XObject << /Im${i} ${imgId} 0 R >> >> /Contents ${contentId} 0 R >>`));
      obj(imgId, () => {
        push(`<< /Type /XObject /Subtype /Image /Width ${page.pxW} /Height ${page.pxH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${page.jpeg.length} >>\nstream\n`);
        push(page.jpeg);
        push('\nendstream');
      });
      const content = `q ${w.toFixed(2)} 0 0 ${h.toFixed(2)} ${x.toFixed(2)} ${(page.ptH - y - h).toFixed(2)} cm /Im${i} Do Q`;
      obj(contentId, () => push(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`));
    });

    const xref = length;
    const count = 4 + this.pages.length * 3;
    let table = `xref\n0 ${count}\n0000000000 65535 f \n`;
    for (let id = 1; id < count; id++) table += `${String(offsets[id]).padStart(10, '0')} 00000 n \n`;
    push(table);
    push(`trailer\n<< /Size ${count} /Root 1 0 R /Info 3 0 R >>\nstartxref\n${xref}\n%%EOF`);

    return new Blob(chunks, { type: 'application/pdf' });
  }
}

/** ابعاد کاغذ به میلی‌متر (عمودی) */
export const PAPER = {
  A4: [210, 297],
  A3: [297, 420],
  A2: [420, 594],
  A1: [594, 841],
  A0: [841, 1189],
  Letter: [216, 279],
};

export const mmToPt = (mm) => (mm / 25.4) * 72;
