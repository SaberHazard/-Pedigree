/**
 * نشانه ازدواج روی خط بین همسران: قلب قرمز، و برای طلاق قلب خاکستری شکسته
 * (مشترک بین صفحه درخت، خروجی SVG/PNG و PDF برداری)
 */
import { s } from '../core/dom.js';

export const HEART = 'M0,4.6 C-6.4,0.2 -5.2,-5.2 -2.2,-5 C-1,-4.9 -0.3,-4.2 0,-3.4 C0.3,-4.2 1,-4.9 2.2,-5 C5.2,-5.2 6.4,0.2 0,4.6 Z';

/** قلب شکسته (طلاق): دو نیمه با ترک زیگزاگ که کمی از هم جدا شده‌اند */
export const BROKEN_HEART = {
  left: 'M0,-3.4 C-0.3,-4.2 -1,-4.9 -2.2,-5 C-5.2,-5.2 -6.4,0.2 0,4.6 L-0.5,2.4 L0.8,0.6 L-1,-1.3 Z',
  right: 'M0,-3.4 C0.3,-4.2 1,-4.9 2.2,-5 C5.2,-5.2 6.4,0.2 0,4.6 L-0.5,2.4 L0.8,0.6 L-1,-1.3 Z',
  leftTransform: 'rotate(-11 0 4.6) translate(-0.9 0)',
  rightTransform: 'rotate(11 0 4.6) translate(0.9 0)',
};

/** نشانه ازدواج: قلب قرمز، یا قلب خاکستری شکسته برای طلاق */
export function marriageMark(l, extra = {}) {
  const g = s('g', { class: `t-mark ${l.divorced ? 'divorced' : ''} ${l.marriage?.id ? 'clickable' : ''}`, transform: `translate(${l.mid.x},${l.mid.y})`, ...extra },
    s('circle', { r: 9.5 }));
  if (l.divorced) {
    g.append(
      s('path', { d: BROKEN_HEART.left, transform: BROKEN_HEART.leftTransform }),
      s('path', { d: BROKEN_HEART.right, transform: BROKEN_HEART.rightTransform }),
    );
  } else {
    g.append(s('path', { d: HEART }));
  }
  return g;
}
