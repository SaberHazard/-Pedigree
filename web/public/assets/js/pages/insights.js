/**
 * بینش‌های خاندان: آمار (جمعیت، نسل‌ها، میانگین عمر، اندازه خانواده در هر نسل، نام‌ها و شهرهای پرتکرار، رکوردها)
 * و «بررسی داده‌ها» (ناسازگاری تاریخ‌ها و روابط، شخص احتمالاً تکراری) با لینک مستقیم برای اصلاح.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { fa, num } from '../core/format.js';
import { segmented, loader, emptyState } from '../core/ui.js';
import { updateQuery } from '../core/router.js';

export default async function insights(container, { query }) {
  let tab = query.tab === 'check' ? 'check' : 'stats';
  const body = h('div');
  const page = h('div', { class: 'page insights-page' },
    h('div', { class: 'page-head' },
      h('div', null, h('h1', null, 'بینش‌های خاندان'), h('p', { class: 'muted', style: { margin: '4px 0 0' } }, 'آمار و نمودارهای خاندان، و بررسی خودکار درستی اطلاعات')),
      segmented([
        { value: 'stats', label: 'آمار خاندان', icon: 'grid' },
        { value: 'check', label: 'بررسی داده‌ها', icon: 'shield' },
      ], tab, (v) => { tab = v; updateQuery({ tab: v === 'check' ? 'check' : '' }); show(); }),
    ),
    body,
  );
  container.append(page);

  async function show() {
    body.replaceChildren(loader());
    try {
      body.replaceChildren(tab === 'check' ? checkView((await get('/api/insights/consistency')).data) : statsView((await get('/api/insights/stats')).data));
    } catch (e) {
      body.replaceChildren(emptyState('alert', e.message));
    }
  }
  show();
}

// ------------------------------------------------------------------ آمار

const personLink = (p) => (p ? h('a', { href: `#/person/${p.id}`, class: 'ins-person' }, p.name) : null);

function statsView(d) {
  const t = d.totals;
  const tile = (ic, label, value, hint) => h('div', { class: 'card ins-tile' },
    h('div', { class: 'ins-tile-icon' }, icon(ic)),
    h('div', null, h('div', { class: 'ins-tile-value' }, value), h('div', { class: 'muted small' }, label), hint ? h('div', { class: 'tiny muted' }, hint) : null));
  const lifespan = d.lifespan.all !== null
    ? tile('flame', 'میانگین عمر درگذشتگان', `${fa(d.lifespan.all)} سال`, `مردان ${d.lifespan.male !== null ? fa(d.lifespan.male) : '—'} • زنان ${d.lifespan.female !== null ? fa(d.lifespan.female) : '—'} • از ${fa(d.lifespan.count)} نفر`)
    : null;

  const records = [
    ['baby', 'مسن‌ترین عضو زنده', d.records.oldest_living, (v) => `${fa(v)} سال`],
    ['star', 'طولانی‌ترین عمر', d.records.longest_lived, (v) => `${fa(v)} سال`],
    ['users', 'بیشترین فرزند', d.records.most_children, (v) => `${fa(v)} فرزند`],
    ['sparkles', 'بیشترین نوه', d.records.most_grandchildren, (v) => `${fa(v)} نوه`],
  ].filter((r) => r[2]);

  return h('div', { class: 'ins-stats' },
    h('div', { class: 'ins-tiles' },
      tile('users', 'نفر در شجره‌نامه', num(t.persons), `${num(t.living)} زنده • ${num(t.deceased)} درگذشته`),
      tile('tree', 'نسل پیاپی', fa(t.generations)),
      tile('heart', 'ازدواج ثبت‌شده', num(t.marriages), t.divorces ? `${num(t.divorces)} جدایی` : null),
      tile('user', 'عضو فعال سایت', num(t.members)),
      tile('baby', 'مرد / زن', `${num(t.male)} / ${num(t.female)}`),
      lifespan,
    ),
    records.length ? h('div', { class: 'card mt' }, h('div', { class: 'card-title' }, h('h3', null, 'رکوردهای خاندان'), icon('crown')),
      h('div', { class: 'ins-records' }, ...records.map(([ic, label, r, fmt]) => h('div', { class: 'ins-record' },
        h('span', { class: 'ins-record-icon' }, icon(ic)),
        h('div', null, h('div', { class: 'muted small' }, label), personLink(r.person), h('b', { class: 'ins-record-value' }, fmt(r.value))))))) : null,
    h('div', { class: 'grid grid-2 mt' },
      columns('تولدها در هر دهه', 'تعداد تولد ثبت‌شده در هر دهه خورشیدی', d.births_by_decade.map((x) => ({ label: fa(x.decade), value: x.count, tip: `دهه ${fa(x.decade)}: ${num(x.count)} تولد` }))),
      columns('میانگین فرزندان در هر نسل', 'بر اساس دهه تولد مادر (مادرانی که ۴۵ سالشان گذشته)', d.family_size.map((x) => ({ label: fa(x.decade), value: x.avg, tip: `مادران متولد دهه ${fa(x.decade)}: میانگین ${fa(x.avg)} فرزند (${fa(x.mothers)} مادر)` })), (v) => fa(v)),
    ),
    h('div', { class: 'grid grid-2 mt' },
      columns('ماه تولد', 'تولدهایی که ماهشان ثبت شده', d.birth_months.map((x) => ({ label: x.label.slice(0, 3), value: x.count, tip: `${x.label}: ${num(x.count)} تولد` })), undefined, true),
      ranking('تحصیلات', d.education.map((x) => ({ name: x.label, count: x.count }))),
    ),
    h('div', { class: 'grid grid-3 mt' },
      ranking('نام‌های پرتکرار پسران', d.names.male),
      ranking('نام‌های پرتکرار دختران', d.names.female),
      ranking('نام‌های خانوادگی', d.names.last),
    ),
    h('div', { class: 'grid grid-3 mt' },
      ranking('زادگاه‌ها', d.places.birth),
      ranking('شهر محل زندگی', d.places.city),
      ranking('شغل‌ها', d.occupations),
    ),
  );
}

/** نمودار ستونی تک‌سری (بدون راهنما؛ عنوان نام سری است) با راهنمای شناور و جدول */
function columns(title, subtitle, rows, fmt = num, compactLabels = false) {
  if (!rows.length) return h('div', { class: 'card' }, h('h3', null, title), emptyState('info', 'هنوز داده کافی ثبت نشده است.'));
  const max = Math.max(...rows.map((r) => r.value), 1);
  const peak = rows.reduce((a, b) => (b.value > a.value ? b : a), rows[0]);
  // با ستون‌های زیاد فقط برچسب برخی نمایش داده می‌شود (بقیه در راهنمای شناور و جدول)
  const step = compactLabels ? 1 : Math.max(1, Math.ceil(rows.length / 8));
  const table = h('table', { class: 'table ins-table' },
    h('thead', null, h('tr', null, h('th', null, 'دسته'), h('th', null, 'مقدار'))),
    h('tbody', null, ...rows.map((r) => h('tr', null, h('td', null, r.label), h('td', null, fmt(r.value))))));
  const details = h('details', { class: 'ins-table-toggle' }, h('summary', null, 'نمایش جدول'), table);
  return h('div', { class: 'card ins-chart' },
    h('div', { class: 'card-title' }, h('div', null, h('h3', null, title), h('div', { class: 'tiny muted' }, subtitle))),
    h('div', { class: `ins-cols${compactLabels ? ' compact' : ''}`, role: 'img', 'aria-label': `${title}: بیشترین ${peak.label} با ${fmt(peak.value)}` },
      ...rows.map((r, i) => h('div', { class: 'ins-col', tabindex: '0', 'data-tip': r.tip, 'aria-label': r.tip },
        h('div', { class: 'ins-col-track' },
          r === peak ? h('span', { class: 'ins-col-peak' }, fmt(r.value)) : null,
          h('div', { class: 'ins-col-bar', style: { height: `${Math.max(2, (r.value / max) * 100)}%` } })),
        h('span', { class: 'ins-col-label' }, i % step === 0 ? r.label : '')))),
    details,
  );
}

/** فهرست رتبه‌ای با نوار افقی */
function ranking(title, rows) {
  const max = Math.max(...rows.map((r) => r.count), 1);
  return h('div', { class: 'card ins-rank' },
    h('h3', null, title),
    rows.length
      ? h('ol', null, ...rows.map((r) => h('li', { title: `${r.name}: ${num(r.count)}` },
        h('span', { class: 'ins-rank-name' }, r.name),
        h('span', { class: 'ins-rank-bar' }, h('i', { style: { width: `${(r.count / max) * 100}%` } })),
        h('b', null, num(r.count)))))
      : h('p', { class: 'muted small' }, 'هنوز ثبت نشده است.'),
  );
}

// ------------------------------------------------------------------ بررسی داده‌ها

function checkView(d) {
  let level = 'all';
  let code = '';
  const list = h('div', { class: 'ins-issues' });
  const codes = Object.entries(d.by_code).sort((a, b) => b[1] - a[1]);
  const select = h('select', { class: 'input', 'aria-label': 'نوع مورد', onchange: () => { code = select.value; draw(); } },
    h('option', { value: '' }, 'همه موارد'),
    ...codes.map(([c, n]) => h('option', { value: c }, `${d.codes[c] || c} (${fa(n)})`)));

  function draw() {
    const items = d.issues.filter((i) => (level === 'all' || i.level === level) && (!code || i.code === code));
    list.replaceChildren(...(items.length ? items.map((i) => h('div', { class: `ins-issue ${i.level}` },
      h('span', { class: 'ins-issue-icon', 'aria-hidden': 'true' }, icon(i.level === 'error' ? 'alert' : 'info')),
      h('div', { class: 'grow' },
        h('div', null, h('span', { class: `chip ${i.level === 'error' ? 'danger' : 'warning'}` }, i.level === 'error' ? 'خطا' : 'هشدار'), ' ', h('b', null, d.codes[i.code] || i.code)),
        h('div', { class: 'ins-issue-text' }, personLink(i.person), ': ', i.message, i.other ? [' ', h('span', { class: 'muted' }, '(مربوط به '), personLink(i.other), h('span', { class: 'muted' }, ')')] : null),
      ),
      h('a', { class: 'btn soft sm', href: `#/person/${i.person.id}/edit` }, icon('edit'), 'اصلاح'),
    )) : [emptyState('check', 'موردی با این فیلتر نیست.')]));
  }
  draw();

  return h('div', null,
    h('div', { class: 'card ins-check-head' },
      h('div', { class: 'ins-check-summary' },
        h('div', null, h('b', { class: 'ins-big' }, num(d.checked)), h('div', { class: 'muted small' }, 'نفر بررسی شد')),
        h('div', null, h('b', { class: 'ins-big danger' }, num(d.counts.error)), h('div', { class: 'muted small' }, 'خطا (ناممکن)')),
        h('div', null, h('b', { class: 'ins-big warning' }, num(d.counts.warning)), h('div', { class: 'muted small' }, 'هشدار (بعید)')),
      ),
      h('p', { class: 'muted small', style: { margin: '10px 0 0' } }, 'تاریخ‌ها و روابط همه اعضا خودکار بررسی می‌شود: وفات پیش از تولد، والد خیلی جوان یا مسن، تولد پس از فوت مادر، ازدواج پیش از تولد، فاصله ناممکن تولد خواهر و برادر، و افراد احتمالاً تکراری. با «اصلاح» مستقیم به ویرایش همان پروفایل بروید.'),
      d.total > d.issues.length ? h('p', { class: 'tiny muted' }, `${num(d.issues.length)} مورد اول از ${num(d.total)} نمایش داده شده است.`) : null,
      h('div', { class: 'row wrap gap mt' },
        segmented([
          { value: 'all', label: 'همه' },
          { value: 'error', label: 'خطاها' },
          { value: 'warning', label: 'هشدارها' },
        ], level, (v) => { level = v; draw(); }, { compact: true }),
        select,
      ),
    ),
    d.total ? list : emptyState('check', 'آفرین! هیچ ناسازگاری‌ای در اطلاعات خاندان پیدا نشد.'),
  );
}
