/**
 * برش دایره‌ای عکس پروفایل قبل از آپلود (کشیدن برای جابه‌جایی، اسلایدر/چرخ ماوس برای زوم، چرخش)
 * خروجی: Blob تصویر JPEG مربعی ۹۰۰×۹۰۰
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { modal } from '../core/ui.js';

export function cropImage(file) {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => open();
    img.onerror = () => {
      URL.revokeObjectURL(url);
      resolve(null);
    };
    img.src = url;

    function open() {
      const SIZE = 280;
      const canvas = h('canvas', { width: SIZE * 2, height: SIZE * 2 });
      const stage = h('div', { class: 'c-stage' }, canvas);
      const ctx = canvas.getContext('2d');
      let rotation = 0;
      const baseScale = () => {
        const w = rotation % 180 ? img.height : img.width;
        const hh = rotation % 180 ? img.width : img.height;
        return Math.max(SIZE / w, SIZE / hh);
      };
      let zoom = 1;
      let ox = 0;
      let oy = 0;

      const draw = () => {
        const s = baseScale() * zoom * 2;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.translate(SIZE + ox * 2, SIZE + oy * 2);
        ctx.rotate((rotation * Math.PI) / 180);
        ctx.drawImage(img, (-img.width * s) / 2, (-img.height * s) / 2, img.width * s, img.height * s);
      };

      const slider = h('input', { type: 'range', min: 1, max: 4, step: 0.01, value: 1, 'aria-label': 'بزرگ‌نمایی' });
      slider.addEventListener('input', () => {
        zoom = +slider.value;
        draw();
      });

      let drag = null;
      stage.addEventListener('pointerdown', (e) => {
        drag = { x: e.clientX, y: e.clientY, ox, oy };
        stage.setPointerCapture(e.pointerId);
      });
      stage.addEventListener('pointermove', (e) => {
        if (!drag) return;
        ox = drag.ox + (e.clientX - drag.x);
        oy = drag.oy + (e.clientY - drag.y);
        draw();
      });
      stage.addEventListener('pointerup', () => (drag = null));
      stage.addEventListener('wheel', (e) => {
        e.preventDefault();
        zoom = Math.max(1, Math.min(4, zoom * Math.exp(-e.deltaY * 0.002)));
        slider.value = zoom;
        draw();
      }, { passive: false });

      const body = h('div', { class: 'cropper' },
        stage,
        h('div', { class: 'row' }, icon('zoom-out'), slider, icon('zoom-in')),
        h('button', { class: 'btn ghost sm', type: 'button', onclick: () => { rotation = (rotation + 90) % 360; draw(); } }, icon('refresh'), 'چرخش'),
        h('p', { class: 'muted small', style: { margin: 0 } }, 'عکس را بکشید تا چهره وسط دایره قرار بگیرد.'),
      );

      let result = null;
      modal({
        title: 'تنظیم عکس پروفایل',
        body,
        actions: [
          { label: 'انصراف', class: 'ghost' },
          {
            label: 'تأیید و آپلود',
            class: 'primary',
            icon: 'check',
            onClick: () => new Promise((done) => {
              const out = h('canvas', { width: 900, height: 900 });
              const octx = out.getContext('2d');
              const k = 900 / (SIZE * 2);
              octx.scale(k, k);
              octx.drawImage(canvas, 0, 0);
              out.toBlob((blob) => {
                result = blob;
                done();
              }, 'image/jpeg', 0.92);
            }),
          },
        ],
        onClose: () => {
          URL.revokeObjectURL(url);
          resolve(result);
        },
      });
      draw();
    }
  });
}
