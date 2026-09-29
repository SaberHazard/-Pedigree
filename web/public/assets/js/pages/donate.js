/**
 * «حمایت از سازنده»: پرداخت آنلاین با درگاه، شماره کارت/شبا/حساب با دکمه کپی، لینک‌های پرداخت و دیوار سپاس
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, timeAgo, latin, bankNumber } from '../core/format.js';
import { toast, toastError, loader, emptyState, withLoading, switchInput } from '../core/ui.js';
import { avatar } from '../components/avatar.js';

const money = (n) => `${fa(Number(n).toLocaleString('en-US'))} تومان`;

export default async function donatePage(container, { query } = {}) {
  document.title = `حمایت از سازنده | ${store.config.site_name}`;
  const page = h('div', { class: 'page narrow donate' }, loader());
  container.append(page);

  let data;
  try {
    data = (await get('/api/donate')).data;
  } catch (e) {
    page.replaceChildren(emptyState('alert', e.message));
    return;
  }
  if (!data.enabled) {
    page.replaceChildren(h('div', { class: 'card' }, emptyState('gift', 'این بخش فعلاً غیرفعال است.')));
    return;
  }

  // نتیجه بازگشت از درگاه
  let result = null;
  if (query?.result === 'paid' && query?.id) {
    try {
      result = (await get(`/api/donate/${Number(query.id)}`)).data;
    } catch {
      result = null;
    }
  }

  const sections = [
    h('div', { class: 'donate-hero card' },
      h('div', { class: 'donate-heart' }, '❤️'),
      h('h1', null, data.title || 'حمایت از سازنده'),
      data.message ? h('p', { class: 'muted', dir: 'auto' }, data.message) : null,
    ),
  ];
  if (query?.result === 'paid' && result?.status === 'paid') {
    sections.push(h('div', { class: 'card donate-ok' }, h('div', { class: 'donate-check' }, icon('check')),
      h('b', null, `پرداخت ${money(result.amount)} با موفقیت انجام شد. سپاسگزاریم! 🌹`),
      result.ref ? h('div', { class: 'muted small ltr' }, `کد پیگیری: ${result.ref}`) : null));
  } else if (query?.result === 'failed') {
    sections.push(h('div', { class: 'card donate-fail' }, icon('alert'), ' پرداخت انجام نشد یا لغو شد. اگر مبلغی از حسابتان کم شده، طی ۷۲ ساعت خودکار برمی‌گردد.'));
  }

  if (data.gateway) sections.push(gatewayCard());
  if (data.accounts.length) sections.push(accountsCard());
  if (!data.gateway && !data.accounts.length) sections.push(h('div', { class: 'card' }, emptyState('gift', 'راه حمایتی هنوز تعیین نشده است.')));
  if (data.thanks.length) {
    sections.push(h('section', { class: 'card' },
      h('div', { class: 'card-title' }, h('h3', null, '🌷 سپاس از حامیان')),
      h('div', { class: 'thanks-list' }, ...data.thanks.map((t) => h('div', { class: 'thanks-row' },
        avatar(t.person, 'sm'),
        h('div', { class: 'grow', style: { minWidth: 0 } }, h('b', null, t.name), t.message ? h('div', { class: 'muted small', dir: 'auto' }, `«${t.message}»`) : null),
        h('span', { class: 'muted tiny nowrap' }, t.at ? timeAgo(t.at) : ''))))));
  }
  page.replaceChildren(...sections.filter(Boolean));

  function gatewayCard() {
    const { min, max, suggested } = data.limits;
    let amount = suggested[1] || suggested[0] || min;
    const amountInput = h('input', { class: 'input ltr-input donate-amount', inputmode: 'numeric', value: fa(amount.toLocaleString('en-US')), 'aria-label': 'مبلغ به تومان' });
    const chips = h('div', { class: 'row wrap', style: { gap: '8px' } });
    const drawChips = () => chips.replaceChildren(...suggested.map((v) => h('button', { type: 'button', class: `chip-btn ${v === amount ? 'active' : ''}`, onclick: () => { amount = v; amountInput.value = fa(v); drawChips(); } }, money(v))));
    drawChips();
    amountInput.addEventListener('input', () => {
      const v = Number(latin(amountInput.value).replace(/\D/g, '')) || 0;
      amount = v;
      amountInput.value = v ? fa(v.toLocaleString('en-US')) : '';
      drawChips();
    });
    const note = h('input', { class: 'input', maxlength: 200, placeholder: 'پیامی برای سازنده (اختیاری)' });
    const anon = switchInput('anonymous', 'نامم در فهرست حامیان نیاید', false);
    const pay = h('button', { class: 'btn primary lg block', type: 'button', onclick: () => withLoading(pay, async () => {
      if (amount < min || amount > max) return toast(`مبلغ باید بین ${money(min)} و ${money(max)} باشد.`, 'warning');
      try {
        const res = await post('/api/donate', { amount, message: note.value.trim() || null, anonymous: anon.querySelector('input').checked });
        // فقط نشانی درگاه‌های مجاز (همان که سرور ساخته)
        if (/^https:\/\/(payment\.zarinpal\.com|sandbox\.zarinpal\.com|gateway\.zibal\.ir|pay\.ir)\//.test(res.data.url)) location.href = res.data.url;
      } catch (e) {
        toastError(e);
      }
    }) }, icon('card'), 'پرداخت آنلاین با ', data.gateway.label);
    return h('section', { class: 'card' },
      h('div', { class: 'card-title' }, h('h3', null, icon('card'), ' پرداخت آنلاین')),
      h('div', { class: 'field' }, h('label', null, 'مبلغ (تومان)'), amountInput),
      chips,
      h('div', { class: 'field mt' }, note),
      anon,
      h('div', { class: 'mt' }, pay),
      h('p', { class: 'muted tiny', style: { marginBottom: 0 } }, icon('lock'), ' پرداخت در صفحه امن درگاه بانکی (شاپرک) انجام می‌شود؛ اطلاعات کارت شما به این سایت نمی‌رسد.'),
    );
  }

  function accountsCard() {
    const label = { card: 'شماره کارت', sheba: 'شبا', account: 'شماره حساب', link: 'لینک پرداخت' };
    return h('section', { class: 'card' },
      h('div', { class: 'card-title' }, h('h3', null, icon('gift'), ' کارت به کارت و حساب')),
      h('div', { class: 'acc-list' }, ...data.accounts.map((a) => h('div', { class: `acc-row ${a.kind}` },
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', { class: 'muted tiny' }, `${label[a.kind] || ''} • ${a.title}`, a.holder ? ` • به نام ${a.holder}` : ''),
          a.kind === 'link'
            ? h('a', { class: 'btn soft sm mt-sm', href: a.value, target: '_blank', rel: 'noopener noreferrer' }, icon('external'), a.title)
            : h('div', { class: 'acc-value ltr', dir: 'ltr' }, bankNumber(a.kind, a.value)),
          a.note ? h('div', { class: 'muted tiny', dir: 'auto' }, a.note) : null),
        a.kind !== 'link' ? h('button', { class: 'btn ghost sm', type: 'button', onclick: () => copy(a.value) }, icon('copy'), 'کپی') : null,
      ))),
    );
  }

  async function copy(v) {
    try {
      await navigator.clipboard.writeText(v);
      toast('کپی شد.');
    } catch {
      toast('کپی ممکن نشد؛ شماره را دستی یادداشت کنید.', 'warning');
    }
  }
}
