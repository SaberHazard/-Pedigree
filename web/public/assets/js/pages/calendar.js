/**
 * تقویم فارسی (مثل «باد صبا»): ماه خورشیدی با تاریخ قمری (مطابق تقویم رسمی) و میلادی هر روز، تعطیلات و مناسبت‌های
 * رسمی، مذهبی و جهانی، تولد و سالگردهای بستگان، هشدارهای شخصی، اوقات شرعی و ساعت دقیق تهران.
 */
import { h, fill } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, put } from '../core/api.js';
import { fa, latin, MONTHS, eventYear } from '../core/format.js';
import { segmented, loader, emptyState, toast, toastError, field, withLoading } from '../core/ui.js';
import { updateQuery } from '../core/router.js';
import { syncClock, serverNow, tehranClock, tehranHM } from '../core/clock.js';
import { prayerTimes, CITIES, PRAYER_LABELS } from '../core/praytimes.js';
import { openReminderEditor, REPEATS } from '../components/reminder-editor.js';
import { askNotificationPermission, refreshAlarms } from '../core/alarms.js';

const WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
const WEEK_SHORT = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
const HIJRI_MONTHS = ['محرم', 'صفر', 'ربیع‌الاول', 'ربیع‌الثانی', 'جمادی‌الاول', 'جمادی‌الثانی', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذی‌القعده', 'ذی‌الحجه'];
const gregFmt = new Intl.DateTimeFormat('fa-IR-u-ca-gregory', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
const FAMILY = {
  birthday: ['🎂', (f) => `تولد ${f.person.name}${f.years > 0 ? ` (${fa(f.years)} سالگی)` : ''}`],
  birth_memorial: ['🕊️', (f) => `زادروز ${f.person.name}${f.years > 0 ? ` (${fa(f.years)} سال)` : ''}`],
  anniversary: ['💍', (f) => `سالگرد ازدواج ${f.person.name}${f.years > 0 ? ` (${fa(f.years)} سال)` : ''}`],
  death_anniversary: ['🕯️', (f) => `سالگرد درگذشت ${f.person.name}${f.years > 0 ? ` (${fa(f.years)} سال)` : ''}`],
};
const KIND_ICON = { religious: 'star', national: 'flag', international: 'compass' };

function loadCity() {
  try {
    const saved = JSON.parse(localStorage.getItem('calendar-city') || 'null');
    if (saved && Number.isFinite(saved.lat) && Number.isFinite(saved.lng)) return saved;
  } catch {
    /* پیش‌فرض */
  }
  return { name: 'تهران', lat: 35.6892, lng: 51.3890, tz: 'Asia/Tehran' };
}

export default async function calendarPage(container, { query }) {
  let tab = ['alarms', 'occasions', 'convert'].includes(query.tab) ? query.tab : 'calendar';
  const body = h('div');
  const page = h('div', { class: 'page calendar-page' },
    h('div', { class: 'page-head' },
      h('h1', null, icon('calendar'), ' تقویم'),
      segmented([
        { value: 'calendar', label: 'تقویم', icon: 'calendar' },
        { value: 'alarms', label: 'هشدارهای من', icon: 'bell' },
        { value: 'occasions', label: 'یادآوری مناسبت‌ها', icon: 'cake' },
        { value: 'convert', label: 'تبدیل تاریخ', icon: 'refresh' },
      ], tab, (v) => { tab = v; updateQuery({ tab: v === 'calendar' ? '' : v, date: '' }); show(); }, { compact: true }),
    ),
    body,
  );
  container.append(page);
  const cleanups = [];
  syncClock();

  function show() {
    cleanups.splice(0).forEach((fn) => fn());
    body.replaceChildren(loader());
    ({ calendar: calendarTab, alarms: alarmsTab, occasions: occasionsTab, convert: convertTab })[tab]();
  }

  // ------------------------------------------------------------ تقویم
  async function calendarTab() {
    const months = new Map();
    let city = loadCity();
    let selected = /^\d{4}-\d{2}-\d{2}$/.test(query.date || '') ? query.date : null;
    let [y, m] = selected ? selected.split('-').map(Number) : [0, 0];

    const clock = h('b', { class: 'cal-clock', dir: 'ltr' });
    const todayLine = h('div', { class: 'cal-today-lines' });
    const prayers = h('div', { class: 'cal-prayers' });
    const cityPick = h('select', { class: 'input cal-city', 'aria-label': 'شهر' },
      ...CITIES.map(([name]) => h('option', { value: name }, name)),
      h('option', { value: '__geo' }, '📍 موقعیت من'));
    cityPick.value = CITIES.some(([n]) => n === city.name) ? city.name : '__geo';
    if (cityPick.value === '__geo' && city.name !== 'موقعیت من') cityPick.value = 'تهران';
    cityPick.addEventListener('change', () => {
      if (cityPick.value === '__geo') {
        if (!navigator.geolocation) return toast('مرورگر موقعیت را پشتیبانی نمی‌کند.', 'warning');
        navigator.geolocation.getCurrentPosition((pos) => {
          city = { name: 'موقعیت من', lat: pos.coords.latitude, lng: pos.coords.longitude, tz: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Tehran' };
          saveCity();
        }, () => toast('اجازه موقعیت داده نشد.', 'warning'), { timeout: 10000, maximumAge: 3600000 });
        return;
      }
      const c = CITIES.find(([n]) => n === cityPick.value);
      city = { name: c[0], lat: c[1], lng: c[2], tz: 'Asia/Tehran' };
      saveCity();
    });
    function saveCity() {
      try { localStorage.setItem('calendar-city', JSON.stringify(city)); } catch { /* ignore */ }
      drawPrayers();
      if (selected) drawDay();
    }

    const title = h('div', { class: 'cal-title' });
    const grid = h('div', { class: 'cal-grid', role: 'grid' });
    const dayPanel = h('div', { class: 'card cal-day' });
    const monthPick = h('select', { class: 'input', 'aria-label': 'ماه' }, ...MONTHS.map((n, i) => h('option', { value: String(i + 1) }, n)));
    const yearPick = h('input', { class: 'input cal-year', inputmode: 'numeric', maxlength: 4, 'aria-label': 'سال' });
    const go = h('button', { class: 'btn soft sm', type: 'button', onclick: () => {
      const yy = Number(latin(yearPick.value));
      if (!(yy >= 1 && yy <= 3177)) return toast('سال باید بین ۱ و ۳۱۷۷ باشد.', 'warning');
      load(yy, Number(monthPick.value));
    } }, 'برو');

    body.replaceChildren(
      h('div', { class: 'cal-top' },
        h('div', { class: 'card cal-now' },
          h('div', { class: 'row between wrap', style: { gap: '8px' } },
            h('div', null, h('div', { class: 'tiny muted' }, 'ساعت دقیق تهران'), clock),
            h('button', { class: 'btn primary sm', type: 'button', onclick: () => openReminderEditor(null, { date: todayKey() || selected || '', onSaved: reload }) }, icon('plus'), 'هشدار تازه')),
          todayLine),
        h('div', { class: 'card cal-owqat' },
          h('div', { class: 'row between', style: { gap: '8px' } }, h('b', null, 'اوقات شرعی'), cityPick),
          prayers)),
      h('div', { class: 'card cal-month' },
        h('div', { class: 'cal-nav' },
          h('button', { class: 'icon-btn', type: 'button', title: 'ماه قبل', 'aria-label': 'ماه قبل', onclick: () => shift(-1) }, icon('chevron-right')),
          title,
          h('button', { class: 'icon-btn', type: 'button', title: 'ماه بعد', 'aria-label': 'ماه بعد', onclick: () => shift(1) }, icon('chevron-left'))),
        h('div', { class: 'cal-jump' },
          h('button', { class: 'btn ghost sm', type: 'button', onclick: () => { selected = null; load(0, 0); } }, icon('crosshair'), 'امروز'),
          monthPick, yearPick, go),
        h('div', { class: 'cal-week' }, ...WEEK_SHORT.map((w, i) => h('span', { class: i === 6 ? 'fri' : '' }, w))),
        grid,
        h('div', { class: 'cal-legend tiny muted' },
          h('span', null, h('i', { class: 'dot holiday' }), 'تعطیل'),
          h('span', null, h('i', { class: 'dot family' }), 'خانواده'),
          h('span', null, h('i', { class: 'dot reminder' }), 'هشدار من'),
          h('span', null, h('i', { class: 'dot event' }), 'مناسبت'))),
      dayPanel,
    );

    // کشیدن انگشت روی جدول: ماه قبل/بعد
    let sx = null;
    grid.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; }, { passive: true });
    grid.addEventListener('touchend', (e) => {
      if (sx === null) return;
      const dx = e.changedTouches[0].clientX - sx;
      sx = null;
      if (Math.abs(dx) > 70) shift(dx > 0 ? 1 : -1);
    });

    function tick() {
      clock.textContent = tehranClock();
    }
    tick();
    const t = setInterval(tick, 1000);
    cleanups.push(() => clearInterval(t));

    function todayKey() {
      const now = months.get('today');
      return now || null;
    }

    async function load(yy, mm, keepSelection = false) {
      grid.classList.add('loading');
      try {
        const key = `${yy}-${mm}`;
        const data = months.get(key) || (await get(`/api/calendar${yy ? `?y=${yy}&m=${mm}` : ''}`)).data;
        months.set(`${data.year}-${data.month}`, data);
        const today = data.days.find((d) => d.today);
        if (today) months.set('today', today.date);
        y = data.year;
        m = data.month;
        if (!keepSelection && (!selected || !selected.startsWith(`${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}`))) {
          selected = (today || data.days[0]).date;
        }
        draw(data);
        if (!todayLine.childNodes.length) drawToday();
      } catch (e) {
        grid.replaceChildren(emptyState('alert', e.message));
      } finally {
        grid.classList.remove('loading');
      }
    }

    function reload() {
      months.clear();
      load(y, m, true);
      refreshAlarms();
    }

    function shift(delta) {
      let yy = y;
      let mm = m + delta;
      if (mm < 1) { mm = 12; yy--; }
      if (mm > 12) { mm = 1; yy++; }
      if (yy < 1 || yy > 3177) return;
      load(yy, mm);
    }

    function draw(data) {
      monthPick.value = String(data.month);
      yearPick.value = fa(data.year);
      fill(title,
        h('b', null, `${data.month_name} ${fa(data.year)}`, data.leap && data.month === 12 ? h('span', { class: 'chip tiny', style: { marginInlineStart: '6px' } }, 'کبیسه') : null),
        h('div', { class: 'tiny muted' }, `${data.hijri_label.replace(/\d+/g, (n) => fa(n))} • ${data.gregorian_label.replace(/\d+/g, (n) => fa(n))}`),
        data.official ? null : h('div', { class: 'tiny muted' }, 'مناسبت‌ها: تقویم داخلی (تقویم رسمی این ماه هنوز همگام نشده)'),
      );
      const cells = [];
      for (let i = 0; i < data.days[0].weekday; i++) cells.push(h('span', { class: 'cal-cell empty', 'aria-hidden': 'true' }));
      for (const d of data.days) {
        const classes = ['cal-cell', d.holiday ? 'holiday' : '', d.today ? 'today' : '', d.date === selected ? 'selected' : ''].filter(Boolean).join(' ');
        const dots = [
          d.events.some((e) => e.holiday) ? 'holiday' : null,
          d.family.length ? 'family' : null,
          d.reminders.length ? 'reminder' : null,
          d.events.some((e) => !e.holiday && e.kind !== 'international' && !eventYear(e.note)) ? 'event' : null,
        ].filter(Boolean);
        cells.push(h('button', {
          type: 'button', class: classes, role: 'gridcell', 'aria-selected': String(d.date === selected),
          'aria-label': `${WEEKDAYS[d.weekday]} ${fa(d.day)} ${data.month_name}${d.holiday ? '، تعطیل' : ''}${d.events.some((e) => !eventYear(e.note)) ? '، ' + d.events.filter((e) => !eventYear(e.note)).map((e) => e.title).join('، ') : ''}`,
          onclick: () => { selected = d.date; updateQuery({ date: d.date }); draw(data); },
        },
        h('span', { class: 'cal-d' }, fa(d.day)),
        h('span', { class: 'cal-h' }, fa(d.h[2])),
        h('span', { class: 'cal-g' }, fa(d.g[2])),
        dots.length ? h('span', { class: 'cal-dots' }, ...dots.map((c) => h('i', { class: `dot ${c}` }))) : null));
      }
      grid.replaceChildren(...cells);
      drawDay();
    }

    function currentDay() {
      const data = months.get(`${y}-${m}`);
      return data?.days.find((d) => d.date === selected) || null;
    }

    function drawToday() {
      const key = months.get('today');
      const data = key ? months.get(`${Number(key.slice(0, 4))}-${Number(key.slice(5, 7))}`) : null;
      const d = data?.days.find((x) => x.date === key);
      if (!d) return;
      fill(todayLine,
        h('div', { class: 'cal-today-main' }, `${WEEKDAYS[d.weekday]} ${fa(d.day)} ${MONTHS[data.month - 1]} ${fa(data.year)}`),
        h('div', { class: 'small muted' }, `${fa(d.h[2])} ${HIJRI_MONTHS[d.h[1] - 1]} ${fa(d.h[0])} • ${gregFmt.format(Date.UTC(d.g[0], d.g[1] - 1, d.g[2]))}`),
        d.events.some((e) => !eventYear(e.note)) ? h('div', { class: 'small cal-today-events' }, d.events.filter((e) => !eventYear(e.note)).map((e) => e.title).join(' • ')) : null,
      );
      drawPrayers();
    }

    function drawPrayers() {
      const key = months.get('today');
      const today = key ? months.get(`${Number(key.slice(0, 4))}-${Number(key.slice(5, 7))}`)?.days.find((x) => x.date === key) : null;
      const g = today?.g;
      if (!g) return;
      const times = prayerTimes(g[0], g[1], g[2], city.lat, city.lng);
      const now = serverNow();
      const next = Object.entries(times).find(([, ms]) => ms && ms > now)?.[0];
      prayers.replaceChildren(...Object.entries(PRAYER_LABELS).map(([k, label]) => h('div', { class: `cal-prayer${k === next ? ' next' : ''}` },
        h('span', null, label), h('b', null, times[k] ? tehranHM(times[k], city.tz) : '—'))),
      h('div', { class: 'tiny muted', style: { gridColumn: '1 / -1' } }, `${city.name} • روش مؤسسه ژئوفیزیک (±۱ دقیقه)`));
    }

    function drawDay() {
      const d = currentDay();
      if (!d) {
        dayPanel.replaceChildren();
        return;
      }
      const data = months.get(`${y}-${m}`);
      const times = prayerTimes(d.g[0], d.g[1], d.g[2], city.lat, city.lng);
      // مناسبت‌های اصلی روز؛ سالروزهای تاریخی (یادداشت سال‌دار) جدا و بسته
      const main = d.events.filter((e) => e.holiday || !eventYear(e.note));
      const history = d.events.filter((e) => !e.holiday && eventYear(e.note));
      const eventRow = (e) => h('li', { class: `ev ${e.kind}${e.holiday ? ' holiday' : ''}` },
        icon(KIND_ICON[e.kind] || 'calendar'),
        h('span', { class: 'grow' }, e.title, eventYear(e.note) ? h('small', { class: 'muted' }, ` (${eventYear(e.note)})`) : null),
        e.holiday ? h('span', { class: 'chip danger tiny' }, 'تعطیل') : null);
      const todayDate = months.get('today');
      let distance = '';
      if (todayDate && todayDate !== d.date) {
        const [ty, tm, td] = (months.get(`${Number(todayDate.slice(0, 4))}-${Number(todayDate.slice(5, 7))}`)?.days.find((x) => x.date === todayDate)?.g) || [];
        if (ty) {
          const diff = Math.round((Date.UTC(d.g[0], d.g[1] - 1, d.g[2]) - Date.UTC(ty, tm - 1, td)) / 86400000);
          distance = diff > 0 ? `${fa(diff)} روز دیگر` : `${fa(-diff)} روز پیش`;
        }
      }
      fill(dayPanel,
        h('div', { class: 'row between wrap', style: { gap: '8px' } },
          h('div', null,
            h('h3', { style: { margin: 0 } }, `${WEEKDAYS[d.weekday]} ${fa(d.day)} ${data.month_name} ${fa(data.year)}`, d.holiday ? h('span', { class: 'chip danger', style: { marginInlineStart: '8px' } }, 'تعطیل') : null),
            h('div', { class: 'small muted' }, `${fa(d.h[2])} ${HIJRI_MONTHS[d.h[1] - 1]} ${fa(d.h[0])} • ${gregFmt.format(Date.UTC(d.g[0], d.g[1] - 1, d.g[2]))}${distance ? ` • ${distance}` : d.today ? ' • امروز' : ''}`)),
          h('button', { class: 'btn soft sm', type: 'button', onclick: () => openReminderEditor(null, { date: d.date, onSaved: reload }) }, icon('bell'), 'هشدار برای این روز')),
        main.length ? h('ul', { class: 'cal-events' }, ...main.map(eventRow)) : null,
        d.family.length ? h('ul', { class: 'cal-events family' }, ...d.family.map((f) => {
          const [emoji, text] = FAMILY[f.type] || ['•', () => f.person.name];
          return h('li', null, h('span', { 'aria-hidden': 'true' }, emoji), h('a', { href: `#/person/${f.person.id}`, class: 'grow' }, text(f)),
            h('button', { class: 'btn ghost xs', type: 'button', title: 'یادآوری هر سال', onclick: () => openReminderEditor(null, { date: d.date, title: text(f).replace(/ \(.*\)$/, ''), repeat: 'yearly', personId: f.person.id, onSaved: reload }) }, icon('bell')));
        })) : null,
        d.reminders.length ? h('div', null,
          h('div', { class: 'cal-sub' }, icon('bell'), ' هشدارهای من'),
          h('ul', { class: 'cal-events reminders' }, ...d.reminders.map((r) => h('li', null,
            h('b', { class: 'cal-rtime', dir: 'ltr' }, fa(r.time)),
            h('span', { class: 'grow' }, r.title, r.repeat !== 'none' ? h('small', { class: 'muted' }, ` • ${REPEATS[r.repeat] || ''}`) : null),
            h('button', { class: 'btn ghost xs', type: 'button', title: 'ویرایش', onclick: async () => {
              try {
                const all = (await get('/api/reminders')).data;
                const full = all.find((x) => x.id === r.id);
                if (full) openReminderEditor(full, { onSaved: reload });
              } catch (e) {
                toastError(e);
              }
            } }, icon('edit')))))) : null,
        !main.length && !d.family.length && !d.reminders.length ? h('p', { class: 'muted small' }, 'مناسبتی برای این روز ثبت نشده است.') : null,
        history.length ? h('details', { class: 'cal-history' }, h('summary', null, `رویدادهای تاریخی این روز (${fa(history.length)})`),
          h('ul', { class: 'cal-events' }, ...history.map(eventRow))) : null,
        h('details', { class: 'cal-day-owqat' }, h('summary', null, `اوقات شرعی ${city.name} در این روز`),
          h('div', { class: 'cal-prayers' }, ...Object.entries(PRAYER_LABELS).map(([k, label]) => h('div', { class: 'cal-prayer' }, h('span', null, label), h('b', null, times[k] ? tehranHM(times[k], city.tz) : '—'))))),
      );
    }

    await load(y || 0, m || 0, !!selected);
  }

  // ------------------------------------------------------------ هشدارهای من
  async function alarmsTab() {
    try {
      const res = await get('/api/reminders');
      const list = res.data;
      const notifyBtn = 'Notification' in window && Notification.permission === 'default'
        ? h('button', { class: 'btn soft sm', type: 'button', onclick: async (e) => { await askNotificationPermission(); e.target.closest('button')?.remove(); } }, icon('bell'), 'اجازه اعلان مرورگر')
        : null;
      body.replaceChildren(
        h('div', { class: 'card' },
          h('div', { class: 'row between wrap', style: { gap: '8px' } },
            h('div', null, h('h3', { style: { margin: 0 } }, 'هشدارها و یادآورهای من'),
              h('p', { class: 'muted small', style: { margin: '4px 0 0' } }, 'هر هشدار دقیقاً سر ساعت تهران زنگ می‌زند: در اپ گوشی توسط خود گوشی (حتی بدون اینترنت)، در سایت باز با صدا و پنجره، و همیشه در اعلان‌ها.')),
            h('div', { class: 'row wrap', style: { gap: '6px' } }, notifyBtn,
              h('button', { class: 'btn primary sm', type: 'button', onclick: () => openReminderEditor(null, { onSaved: () => alarmsTab() }) }, icon('plus'), 'هشدار تازه')))),
        list.length ? h('div', { class: 'rem-list' }, ...list.map((r) => h('button', {
          type: 'button', class: `card rem-row${r.active ? '' : ' off'}`, onclick: () => openReminderEditor(r, { onSaved: () => alarmsTab() }),
        },
        h('span', { class: 'rem-icon' }, icon(r.repeat === 'none' ? 'bell' : 'refresh')),
        h('span', { class: 'grow' },
          h('b', null, r.title),
          h('span', { class: 'small muted' }, ` • ${REPEATS[r.repeat] || ''} • ساعت ${fa(r.time)}`),
          r.note ? h('div', { class: 'small text-2 rem-note' }, r.note) : null,
          h('div', { class: 'tiny muted' }, r.next_at ? `زنگ بعدی: ${new Intl.DateTimeFormat('fa-IR', { timeZone: 'Asia/Tehran', dateStyle: 'full', timeStyle: 'short' }).format(new Date(r.next_at))}` : (r.active ? 'زمانش گذشته است' : 'غیرفعال'))),
        icon('edit')))) : emptyState('bell', 'هنوز هشداری نساخته‌اید. برای یک روز خاص در تقویم هم می‌توانید هشدار بسازید.'),
      );
    } catch (e) {
      body.replaceChildren(emptyState('alert', e.message));
    }
  }

  // ------------------------------------------------------------ یادآوری مناسبت‌ها
  async function occasionsTab() {
    try {
      const res = await get('/api/reminders');
      const s = res.settings;
      const opts = res.options;
      const enabled = h('input', { type: 'checkbox', checked: s.enabled });
      const time = h('input', { class: 'input ltr-input', type: 'time', value: s.time, step: 60, style: { maxWidth: '140px' } });
      const cats = Object.entries(opts.categories).map(([k, label]) => {
        const cb = h('input', { type: 'checkbox', value: k, checked: s.categories.includes(k) });
        return [cb, h('label', { class: 'auto-occ' }, cb, label)];
      });
      const beforeLabels = { 0: 'همان روز', 1: 'یک روز قبل', 2: 'دو روز قبل', 3: 'سه روز قبل', 7: 'یک هفته قبل', 14: 'دو هفته قبل' };
      const befores = opts.days_before.map((k) => {
        const cb = h('input', { type: 'checkbox', value: String(k), checked: s.days_before.includes(k) });
        return [cb, h('label', { class: 'auto-occ' }, cb, beforeLabels[k] || `${fa(k)} روز قبل`)];
      });
      const degree = h('select', { class: 'input', style: { maxWidth: '220px' } },
        ...[[1, 'بستگان درجه ۱ (پدر، مادر، همسر، فرزند، خواهر و برادر)'], [2, 'تا درجه ۲ (پدربزرگ، نوه، عمو، خاله ...)'], [3, 'تا درجه ۳'], [4, 'تا درجه ۴']].map(([v, l]) => h('option', { value: String(v) }, l)));
      degree.value = String(s.degree);
      const save = h('button', { class: 'btn primary', type: 'button' }, icon('check'), 'ذخیره');
      save.addEventListener('click', () => withLoading(save, async () => {
        try {
          const r = await put('/api/reminders/settings', {
            enabled: enabled.checked,
            time: latin(time.value).slice(0, 5),
            categories: cats.filter(([cb]) => cb.checked).map(([cb]) => cb.value),
            days_before: befores.filter(([cb]) => cb.checked).map(([cb]) => Number(cb.value)),
            degree: Number(degree.value),
          });
          toast(r.message, 'success', 6000);
          if (enabled.checked) askNotificationPermission();
          refreshAlarms();
        } catch (e) {
          toastError(e);
        }
      }));
      body.replaceChildren(h('div', { class: 'card occ-settings' },
        h('h3', { style: { marginTop: 0 } }, 'یادآوری مناسبت‌ها'),
        h('p', { class: 'muted small' }, 'هر روز سر ساعت دلخواه (وقت تهران) خلاصه‌ای از تولدها، سالگردهای ازدواج و درگذشت بستگان و تعطیلات و مناسبت‌های رسمی «امروز، فردا یا هفته بعد» برایتان زنگ می‌خورد.'),
        h('label', { class: 'row', style: { gap: '8px', fontWeight: 600 } }, enabled, 'یادآوری مناسبت‌ها روشن باشد'),
        field('ساعت یادآوری (وقت تهران)', time),
        h('div', { class: 'field' }, h('label', null, 'چه مناسبت‌هایی؟'), h('div', { class: 'auto-occasions' }, ...cats.map(([, l]) => l))),
        h('div', { class: 'field' }, h('label', null, 'چه زمانی؟'), h('div', { class: 'auto-occasions' }, ...befores.map(([, l]) => l))),
        field('بستگان', degree),
        save));
    } catch (e) {
      body.replaceChildren(emptyState('alert', e.message));
    }
  }

  // ------------------------------------------------------------ تبدیل تاریخ
  function convertTab() {
    let calendar = 'jalali';
    const cal = segmented([{ value: 'jalali', label: 'خورشیدی' }, { value: 'hijri', label: 'قمری' }, { value: 'gregorian', label: 'میلادی' }], 'jalali', (v) => { calendar = v; });
    const yIn = h('input', { class: 'input', inputmode: 'numeric', maxlength: 4, placeholder: 'سال' });
    const mIn = h('input', { class: 'input', inputmode: 'numeric', maxlength: 2, placeholder: 'ماه' });
    const dIn = h('input', { class: 'input', inputmode: 'numeric', maxlength: 2, placeholder: 'روز' });
    const out = h('div', { class: 'conv-out' });
    const run = h('button', { class: 'btn primary', type: 'button' }, icon('refresh'), 'تبدیل');
    run.addEventListener('click', () => withLoading(run, async () => {
      const [yy, mm, dd] = [yIn, mIn, dIn].map((i) => Number(latin(i.value)));
      if (!yy || !mm || !dd) return toast('سال، ماه و روز را وارد کنید.', 'warning');
      try {
        const r = (await get(`/api/calendar/convert?cal=${calendar}&y=${yy}&m=${mm}&d=${dd}`)).data;
        out.replaceChildren(
          h('div', { class: 'conv-row' }, h('span', null, 'خورشیدی'), h('b', null, `${r.weekday_name} ${fa(r.jalali[2])} ${MONTHS[r.jalali[1] - 1]} ${fa(r.jalali[0])}`)),
          h('div', { class: 'conv-row' }, h('span', null, 'قمری'), h('b', null, `${fa(r.hijri[2])} ${HIJRI_MONTHS[r.hijri[1] - 1]} ${fa(r.hijri[0])}`)),
          h('div', { class: 'conv-row' }, h('span', null, 'میلادی'), h('b', null, gregFmt.format(Date.UTC(r.gregorian[0], r.gregorian[1] - 1, r.gregorian[2])))),
          h('a', { class: 'btn ghost sm', href: `#/calendar?date=${r.jalali[0]}-${String(r.jalali[1]).padStart(2, '0')}-${String(r.jalali[2]).padStart(2, '0')}` }, icon('calendar'), 'دیدن در تقویم'));
      } catch (e) {
        out.replaceChildren(h('p', { class: 'chip danger' }, e.message));
      }
    }));
    body.replaceChildren(h('div', { class: 'card conv' },
      h('h3', { style: { marginTop: 0 } }, 'تبدیل تاریخ'),
      h('p', { class: 'muted small' }, 'خورشیدی ↔ قمری ↔ میلادی برای هر تاریخی از سال ۱ تا ۳۱۷۷ خورشیدی (سال‌های کبیسه و ماه‌های ۲۹، ۳۰ و ۳۱ روزه دقیق حساب می‌شوند؛ قمری مطابق تقویم رسمی ایران).'),
      cal,
      h('div', { class: 'conv-inputs' }, dIn, mIn, yIn, run),
      out));
  }

  show();
  return () => cleanups.splice(0).forEach((fn) => fn());
}

