/**
 * صفحه ورود
 *
 * روش ۱: موبایل ← کد پیامکی (۶ خانه با پرش خودکار و پشتیبانی از چسباندن)
 * روش ۲: کد ملی + رمز عبور
 * اگر شماره در سیستم نباشد و ثبت‌نام آزاد باشد، فرم کوتاه ثبت‌نام نمایش داده می‌شود.
 */
import { h, $ } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { post, get } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, latin } from '../core/format.js';
import { toast, showFormErrors, clearFormErrors, withLoading, segmented, field, switchInput } from '../core/ui.js';
import { brand } from '../components/header.js';
import { dateInput } from '../components/date-input.js';

export default function loginPage(container, { query }) {
  let timer = null;
  const next = query.next || '/';
  const card = h('div', { class: 'auth-card' });

  container.append(h('div', { class: 'auth-page' },
    art(),
    h('div', { class: 'auth-panel' }, card),
  ));

  let method = 'otp';
  showMethod();

  function showMethod() {
    clearInterval(timer);
    const switcher = segmented([
      { value: 'otp', label: 'ورود با موبایل', icon: 'phone' },
      { value: 'password', label: 'ورود با رمز', icon: 'key' },
    ], method, (v) => {
      method = v;
      showMethod();
    });
    switcher.style.width = '100%';
    switcher.querySelectorAll('button').forEach((b) => (b.style.flex = '1'));
    card.replaceChildren(
      brand(),
      h('h2', null, 'خوش آمدید'),
      h('p', { class: 'muted' }, 'برای مشاهده و تکمیل شجره‌نامه خانواده وارد شوید.'),
      switcher,
      h('div', { class: 'mt' }, method === 'otp' ? phoneStep() : passwordForm()),
    );
  }

  // ------------------------------------------------------------ مرحله ۱: موبایل
  function phoneStep(captcha = null, phoneValue = '') {
    const input = h('input', { class: 'input ltr-input', name: 'phone', type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: '۰۹۱۲ ۳۴۵ ۶۷۸۹', value: phoneValue, required: true });
    const captchaInput = h('input', { class: 'input ltr-input', name: 'captcha_answer', inputmode: 'numeric', placeholder: 'عدد تصویر', autocomplete: 'off' });
    const btn = h('button', { class: 'btn primary lg block', type: 'submit' }, 'دریافت کد تأیید', icon('arrow-left'));
    const form = h('form', { novalidate: true },
      field('شماره موبایل', input),
      captcha ? h('div', { class: 'field' },
        h('label', null, 'کد امنیتی'),
        h('div', { class: 'captcha-box' }, h('img', { src: captcha.image, alt: 'کد امنیتی' }), captchaInput),
      ) : null,
      btn,
    );
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFormErrors(form);
      await withLoading(btn, async () => {
        try {
          const res = await post('/api/auth/otp', {
            phone: latin(input.value),
            captcha_id: captcha?.id,
            captcha_answer: captcha ? latin(captchaInput.value) : undefined,
          });
          card.querySelector('.mt').replaceChildren(codeStep(res));
        } catch (err) {
          if (err.data?.captcha_required) {
            card.querySelector('.mt').replaceChildren(phoneStep(err.data.captcha, input.value));
            toast(err.message, 'warning');
            return;
          }
          showFormErrors(form, err);
        }
      });
    });
    setTimeout(() => input.focus(), 100);
    return form;
  }

  // ------------------------------------------------------------ مرحله ۲: کد
  function codeStep(info) {
    const length = info.length || store.config.otp?.length || 6;
    const boxes = Array.from({ length }, (_, i) => h('input', {
      type: 'text', inputmode: 'numeric', maxlength: 1, autocomplete: i === 0 ? 'one-time-code' : 'off', 'aria-label': `رقم ${i + 1}`,
    }));
    const wrap = h('div', { class: 'otp-inputs' }, boxes);
    const resend = h('button', { class: 'btn ghost sm', type: 'button', disabled: true });
    const btn = h('button', { class: 'btn primary lg block', type: 'submit' }, 'ورود');
    const form = h('form', { novalidate: true },
      h('p', null, 'کد ارسال‌شده به ', h('b', { class: 'ltr' }, fa(info.phone)), ' را وارد کنید.'),
      wrap,
      h('div', { class: 'row between mb' },
        h('button', { class: 'btn ghost sm', type: 'button', onclick: () => showMethod() }, icon('edit'), 'تغییر شماره'),
        resend,
      ),
      info.debug_code ? h('div', { class: 'dev-hint' }, 'حالت توسعه (پنل پیامک وصل نیست) - کد: ', h('b', { class: 'ltr' }, info.debug_code)) : null,
      btn,
    );

    const code = () => boxes.map((b) => latin(b.value)).join('');
    const submitIfFull = () => code().length === length && form.requestSubmit();

    boxes.forEach((box, i) => {
      box.addEventListener('input', () => {
        box.value = latin(box.value).replace(/\D/g, '').slice(-1);
        box.classList.toggle('filled', !!box.value);
        if (box.value && boxes[i + 1]) boxes[i + 1].focus();
        submitIfFull();
      });
      box.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !box.value && boxes[i - 1]) boxes[i - 1].focus();
        if (e.key === 'ArrowLeft' && boxes[i + 1]) boxes[i + 1].focus();
        if (e.key === 'ArrowRight' && boxes[i - 1]) boxes[i - 1].focus();
      });
      box.addEventListener('paste', (e) => {
        const digits = latin(e.clipboardData.getData('text')).replace(/\D/g, '').slice(0, length);
        if (!digits) return;
        e.preventDefault();
        digits.split('').forEach((d, j) => {
          if (boxes[j]) {
            boxes[j].value = d;
            boxes[j].classList.add('filled');
          }
        });
        boxes[Math.min(digits.length, length - 1)].focus();
        submitIfFull();
      });
    });

    // شمارش معکوس ارسال مجدد
    let remaining = info.cooldown || 60;
    const tick = () => {
      if (remaining <= 0) {
        clearInterval(timer);
        resend.disabled = false;
        resend.replaceChildren(icon('refresh'), 'ارسال مجدد کد');
        return;
      }
      resend.textContent = `ارسال مجدد تا ${fa(remaining)} ثانیه`;
      remaining--;
    };
    clearInterval(timer);
    tick();
    timer = setInterval(tick, 1000);
    resend.addEventListener('click', async () => {
      await withLoading(resend, async () => {
        try {
          const res = await post('/api/auth/otp', { phone: info.phone });
          card.querySelector('.mt').replaceChildren(codeStep(res));
          toast('کد جدید ارسال شد.');
        } catch (err) {
          if (err.data?.captcha_required) {
            card.querySelector('.mt').replaceChildren(phoneStep(err.data.captcha, info.phone));
          }
          toast(err.message, 'error');
        }
      });
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (code().length !== length) return;
      await withLoading(btn, async () => {
        try {
          const res = await post('/api/auth/otp/verify', { phone: info.phone, code: code() });
          if (res.registration_required) {
            clearInterval(timer);
            card.querySelector('.mt').replaceChildren(registerForm(res.registration_token));
            return;
          }
          loggedIn(res.user);
        } catch (err) {
          wrap.classList.remove('shake');
          void wrap.offsetWidth;
          wrap.classList.add('shake');
          boxes.forEach((b) => {
            b.value = '';
            b.classList.remove('filled');
          });
          boxes[0].focus();
          toast(err.field?.('code') || err.message, 'error');
        }
      });
    });

    setTimeout(() => boxes[0].focus(), 100);
    return form;
  }

  // ------------------------------------------------------------ ثبت‌نام
  function registerForm(token) {
    const gender = h('div', { class: 'segmented', style: { width: '100%' } });
    let g = 'm';
    const renderGender = () => gender.replaceChildren(
      ...[['m', 'مرد'], ['f', 'زن']].map(([v, l]) => h('button', { type: 'button', class: g === v ? 'active' : '', style: { flex: 1 }, onclick: () => { g = v; renderGender(); } }, l)),
    );
    renderGender();
    const birth = dateInput('birth_date');
    const reg = store.config.registration || {};
    const codeField = field(reg.require_national_code === false ? 'کد ملی (اختیاری)' : 'کد ملی',
      h('input', { class: 'input ltr-input', name: 'national_code', inputmode: 'numeric', maxlength: 12, autocomplete: 'off' }),
      { hint: 'برای جلوگیری از ثبت تکراری و پیدا کردن شما در درخت؛ رمزنگاری‌شده ذخیره می‌شود.' });
    const btn = h('button', { class: 'btn primary lg block', type: 'submit' }, 'ساخت حساب و ورود');
    const form = h('form', { novalidate: true },
      h('div', { class: 'chip success mb' }, icon('check'), 'شماره تأیید شد'),
      h('p', { class: 'muted small' }, 'این شماره هنوز در شجره‌نامه ثبت نشده. مشخصات خود را وارد کنید تا پروفایل شما ساخته شود.'),
      h('div', { class: 'form-grid' },
        field('نام', h('input', { class: 'input', name: 'first_name', required: true, autocomplete: 'given-name' })),
        field('نام خانوادگی', h('input', { class: 'input', name: 'last_name', required: true, autocomplete: 'family-name' })),
      ),
      h('div', { class: 'field' }, h('label', null, 'جنسیت'), gender),
      h('div', { class: 'field' }, h('label', null, 'تاریخ تولد (اختیاری)'), birth),
      codeField,
      reg.allow_without_national_code ? h('div', { class: 'field' }, switchInput('no_national_code', 'کد ملی ایرانی ندارم (ساکن خارج یا تبعه کشور دیگر)', false, (v) => {
        codeField.hidden = v;
      })) : null,
      field('رمز عبور برای ورود بدون پیامک (اختیاری)', h('input', { class: 'input ltr-input', name: 'password', type: 'password', autocomplete: 'new-password' }), { hint: 'حداقل ۸ کاراکتر شامل حرف و عدد' }),
      btn,
    );
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFormErrors(form);
      await withLoading(btn, async () => {
        try {
          const res = await post('/api/auth/register', {
            registration_token: token,
            first_name: form.first_name.value.trim(),
            last_name: form.last_name.value.trim(),
            gender: g,
            birth_date: birth.value || null,
            national_code: form.no_national_code?.checked ? null : (latin(form.national_code.value).trim() || null),
            no_national_code: !!form.no_national_code?.checked,
            password: form.password.value || null,
          });
          toast('به شجره‌نامه خوش آمدید!');
          loggedIn(res.user);
        } catch (err) {
          showFormErrors(form, err);
        }
      });
    });
    return form;
  }

  // ------------------------------------------------------------ کد ملی و رمز
  function passwordForm() {
    const pass = h('input', { class: 'input ltr-input', name: 'password', type: 'password', autocomplete: 'current-password', required: true });
    const eye = h('button', { class: 'icon-btn', type: 'button', title: 'نمایش رمز', onclick: () => {
      pass.type = pass.type === 'password' ? 'text' : 'password';
      eye.replaceChildren(icon(pass.type === 'password' ? 'eye' : 'eye-off'));
    } }, icon('eye'));
    const btn = h('button', { class: 'btn primary lg block', type: 'submit' }, 'ورود');
    const form = h('form', { novalidate: true },
      field('کد ملی، نام کاربری یا موبایل', h('input', { class: 'input ltr-input', name: 'identifier', autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false', maxlength: 50, required: true }),
        { hint: 'سالمندانی که موبایل یا کد ملی ندارند با نام کاربری‌ای که مدیر یا فرزندانشان تعیین کرده وارد شوند.' }),
      h('div', { class: 'field' }, h('label', null, 'رمز عبور'), h('div', { class: 'input-group' }, pass, h('span', { class: 'addon' }, eye))),
      btn,
      h('p', { class: 'muted small mt' }, 'رمز را فراموش کرده‌اید؟ با موبایل وارد شوید و از تنظیمات حساب، رمز جدید بسازید. اگر پروفایل شما را بستگانتان ساخته‌اند، رمز را از آن‌ها بپرسید.'),
    );
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFormErrors(form);
      await withLoading(btn, async () => {
        try {
          const res = await post('/api/auth/login', { identifier: latin(form.identifier.value).trim(), password: pass.value });
          loggedIn(res.user);
        } catch (err) {
          showFormErrors(form, err);
        }
      });
    });
    return form;
  }

  function loggedIn(user) {
    clearInterval(timer);
    store.setUser(user);
    const name = user.person?.first_name;
    toast(name ? `${name} عزیز، خوش آمدید` : 'خوش آمدید');
    navigate(next.startsWith('/login') ? '/' : next, { replace: true });
  }

  return () => clearInterval(timer);
}

/** تصویر تزئینی: درختی که شاخه‌هایش رشد می‌کند و برگ‌ها آرام می‌ریزند */
function art() {
  const wrap = h('div', { class: 'auth-art' });
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'art-tree');
  svg.setAttribute('viewBox', '0 0 600 700');
  svg.setAttribute('preserveAspectRatio', 'xMidYMid slice');
  const branches = [
    'M300 700 C300 600 295 540 300 470',
    'M300 520 C250 480 210 450 160 420', 'M300 500 C350 460 390 430 450 405',
    'M160 420 C130 380 115 340 110 300', 'M160 420 C190 380 205 350 215 310',
    'M450 405 C480 370 495 330 500 290', 'M450 405 C420 370 405 340 395 300',
    'M300 470 C300 420 300 380 300 330', 'M300 330 C270 300 255 270 250 240', 'M300 330 C330 300 345 270 352 235',
  ];
  let markup = '<defs><radialGradient id="glow"><stop offset="0" stop-color="#fef3c7" stop-opacity=".5"/><stop offset="1" stop-color="#fef3c7" stop-opacity="0"/></radialGradient></defs>';
  markup += '<circle cx="300" cy="330" r="260" fill="url(#glow)"/>';
  branches.forEach((d, i) => {
    markup += `<path d="${d}" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="${i === 0 ? 7 : 4 - Math.min(2, i / 4)}" stroke-linecap="round" pathLength="1" style="stroke-dasharray:1;animation:grow 1.4s cubic-bezier(.22,1,.36,1) ${0.2 + i * 0.12}s both"/>`;
  });
  const nodes = [[300, 470], [160, 420], [450, 405], [110, 300], [215, 310], [500, 290], [395, 300], [300, 330], [250, 240], [352, 235]];
  nodes.forEach(([x, y], i) => {
    const color = i % 3 === 0 ? '#fde68a' : i % 3 === 1 ? '#99f6e4' : '#fbcfe8';
    markup += `<g style="animation:pop .6s cubic-bezier(.34,1.56,.64,1) ${0.6 + i * 0.12}s both;transform-origin:${x}px ${y}px"><circle cx="${x}" cy="${y}" r="17" fill="rgba(255,255,255,.14)" stroke="${color}" stroke-width="2.5"/><circle cx="${x}" cy="${y}" r="7" fill="${color}"/></g>`;
  });
  for (let i = 0; i < 9; i++) {
    const x = 60 + Math.random() * 520;
    markup += `<path d="M0 0 C6 -8 16 -8 20 0 C16 8 6 8 0 0Z" fill="rgba(153,246,228,.55)" transform="translate(${x},-20)" style="animation:leafFall ${9 + Math.random() * 8}s linear ${Math.random() * 8}s infinite"/>`;
  }
  svg.innerHTML = markup;
  wrap.append(svg, h('div', { class: 'art-text' },
    h('h1', null, 'ریشه‌ها و شاخه‌های خانواده'),
    h('p', null, 'شجره‌نامه خانواده‌تان را بسازید، نیاکان را تا دورترین نسل‌ها ثبت کنید و درخت همسران را به هم پیوند دهید؛ با عکس‌ها و خاطراتی که با تأیید بستگان ماندگار می‌شوند.'),
  ));
  return wrap;
}
