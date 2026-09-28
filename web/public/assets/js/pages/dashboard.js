/**
 * صفحه اصلی (داشبورد): خوشامد، آمار، خاندان‌ها، مناسبت‌ها و فعالیت‌های اخیر
 */
import { h, onVisible } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa, num, fullName, formatDate, timeAgo } from '../core/format.js';
import { countUp, emptyState } from '../core/ui.js';
import { avatar } from '../components/avatar.js';
import { openFamilyDialog } from '../components/family-dialog.js';
import { openRelativeDialog } from '../components/relative-dialog.js';
import { actionLabel } from '../components/labels.js';

export default async function dashboard(container) {
  const user = store.user;
  const me = user.person;
  const page = h('div', { class: 'page' });
  container.append(page);

  // ------------------------------------------------------------ خوشامد
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'صبح بخیر' : hour < 18 ? 'روز بخیر' : 'عصر بخیر';
  page.append(h('section', { class: 'hero' },
    avatar(me, 'lg', { ring: false }),
    h('div', { class: 'grow' },
      h('h1', null, `${greet}، ${me?.first_name || ''} عزیز`),
      h('p', null, 'شجره‌نامه خانواده‌تان را کامل کنید؛ هر نامی که ثبت می‌کنید، شاخه‌ای به این درخت اضافه می‌کند.'),
    ),
    h('div', { class: 'row wrap' },
      h('a', { class: 'btn accent', href: `#/tree/${me.id}?mode=hourglass` }, icon('tree'), 'درخت من'),
      h('a', { class: 'btn', href: `#/tree/${me.id}?mode=ancestors` }, icon('ancestors'), 'نیاکان من'),
      h('a', { class: 'btn', href: `#/person/${me.id}` }, icon('user'), 'پروفایل من'),
      store.config.assistant?.enabled ? h('a', { class: 'btn', href: '#/assistant' }, icon('bot'), 'دستیار هوشمند') : null,
    ),
  ));

  // تأییدهای در انتظار
  const c = user.counters || {};
  if ((c.votes || 0) + (c.links || 0) > 0) {
    page.append(h('a', { class: 'card hover row mt', href: '#/approvals', style: { borderColor: 'var(--warning)', textDecoration: 'none', color: 'inherit' } },
      h('div', { class: 'stat' }, h('div', { class: 's-icon', style: { background: 'var(--warning-soft)', color: 'var(--warning)' } }, icon('shield'))),
      h('div', { class: 'grow' },
        h('b', null, 'منتظر نظر شما هستند'),
        h('div', { class: 'muted small' }, [c.votes ? `${fa(c.votes)} عکس/ویدیو برای رأی` : null, c.links ? `${fa(c.links)} درخواست اتصال درخت` : null].filter(Boolean).join(' • ')),
      ),
      icon('chevron-left'),
    ));
  }

  // پیام‌های نخوانده
  if (c.messages > 0) {
    page.append(h('a', { class: 'card hover row mt', href: '#/messages', style: { textDecoration: 'none', color: 'inherit' } },
      h('div', { class: 'stat' }, h('div', { class: 's-icon' }, icon('chat'))),
      h('div', { class: 'grow' }, h('b', null, `${fa(c.messages)} پیام نخوانده دارید`), h('div', { class: 'muted small' }, 'برای خواندن و پاسخ دادن بزنید')),
      icon('chevron-left'),
    ));
  }

  // تولدهای امروز (با دکمه تبریک پیامکی)
  const birthdays = h('div');
  page.append(birthdays);
  get('/api/greetings').then((res) => {
    const today = (res.birthdays || []).filter((r) => r.in_days === 0 && !r.is_me);
    const mine = (res.birthdays || []).find((r) => r.in_days === 0 && r.is_me);
    if (!today.length && !mine) return;
    birthdays.replaceChildren(h('section', { class: 'card mt birthday-card' },
      h('div', { class: 'card-title' }, h('h3', null, '🎂 تولدهای امروز'), h('a', { class: 'btn ghost sm', href: '#/greetings' }, 'همه', icon('chevron-left'))),
      mine ? h('p', { class: 'bold', style: { margin: '0 0 8px' } }, `🎉 ${me.first_name} عزیز، تولدتان مبارک!`) : null,
      h('div', { class: 'row wrap', style: { gap: '10px' } }, ...today.slice(0, 8).map((r) => h('a', { class: 'bd-chip', href: `#/greetings?person=${r.person.id}` },
        avatar(r.person, 'sm'),
        h('span', null, h('b', null, fullName(r.person)), h('span', { class: 'muted tiny' }, [r.age ? `${fa(r.age)} سالگی` : null, r.relation].filter(Boolean).join(' • '))),
        h('span', { class: 'btn primary xs' }, icon('mail'), 'تبریک'),
      ))),
    ));
  }).catch(() => {});

  // دعوت به پرسش‌نامه تکمیل پروفایل (اگر پروفایل هنوز کامل نیست)
  const interview = h('div');
  page.append(interview);
  get(`/api/persons/${me.id}`).then(({ data }) => {
    const done = data.completeness;
    if (!done || done.percent >= 100) return;
    interview.replaceChildren(h('a', { class: 'card hover row mt interview-invite', href: `#/person/${me.id}/interview` },
      h('div', { class: 'stat' }, h('div', { class: 's-icon', style: { background: 'var(--primary-soft)', color: 'var(--primary)' } }, icon('sparkles'))),
      h('div', { class: 'grow' },
        h('b', null, `پروفایل شما ${fa(done.percent)}٪ کامل است`),
        h('div', { class: 'muted small' }, 'به چند سؤال کوتاه جواب دهید (اینستاگرام، تحصیلات، خاطره‌ها و ...) تا خانواده شما را بهتر بشناسد.'),
      ),
      h('span', { class: 'btn primary sm' }, 'شروع', icon('chevron-left')),
    ));
  }).catch(() => {});

  // ------------------------------------------------------------ آمار
  const stats = h('div', { class: 'stats' }, ...Array.from({ length: 4 }, () => h('div', { class: 'card skeleton', style: { height: '86px' } })));
  page.append(stats);

  // ------------------------------------------------------------ شروع سریع (اگر هنوز بستگانی ثبت نشده)
  const onboarding = h('div');
  page.append(onboarding);

  // ------------------------------------------------------------ خاندان‌ها
  const familiesGrid = h('div', { class: 'grid grid-auto' });
  page.append(
    h('div', { class: 'section-title' }, icon('users'), 'خاندان‌ها'),
    familiesGrid,
  );

  // ------------------------------------------------------------ مناسبت‌ها و فعالیت‌ها
  const events = h('div', { class: 'card' }, h('div', { class: 'card-title' }, h('h3', null, 'مناسبت‌های پیش رو'), icon('calendar')), h('div', { class: 'skeleton', style: { height: '120px' } }));
  const feed = h('div', { class: 'card' }, h('div', { class: 'card-title' }, h('h3', null, 'فعالیت‌های اخیر'), icon('history')), h('div', { class: 'skeleton', style: { height: '120px' } }));
  page.append(h('div', { class: 'grid grid-2 mt' }, events, feed));

  // بارگذاری موازی
  const [statsRes, familiesRes, eventsRes, feedRes, relativesRes] = await Promise.allSettled([
    get('/api/dashboard/stats'),
    get('/api/families'),
    get('/api/dashboard/events'),
    get('/api/dashboard/activity'),
    get(`/api/persons/${me.id}/relatives`),
  ]);

  // آمار
  if (statsRes.status === 'fulfilled') {
    const s = statsRes.value;
    const tiles = [
      ['users', 'نفر در شجره‌نامه', s.persons, 'var(--primary-soft)', 'var(--primary)'],
      ['heart', 'ازدواج ثبت‌شده', s.marriages, 'var(--female-soft)', 'var(--female-1)'],
      ['image', 'عکس و ویدیو', s.photos + s.videos, 'var(--accent-soft)', 'var(--accent)'],
      ['tree', 'خاندان', s.families, 'var(--male-soft)', 'var(--male-1)'],
    ];
    stats.replaceChildren(...tiles.map(([ic, label, value, bg, fg]) => {
      const v = h('div', { class: 'value' }, '۰');
      const card = h('div', { class: 'card stat' }, h('div', { class: 's-icon', style: { background: bg, color: fg } }, icon(ic)), h('div', null, v, h('div', { class: 'label' }, label)));
      onVisible(card, () => countUp(v, value, num));
      return card;
    }));
  } else {
    stats.remove();
  }

  // شروع سریع
  if (relativesRes.status === 'fulfilled') {
    const r = relativesRes.value;
    const empty = !r.father && !r.mother && !r.spouses.length && !r.children.length;
    if (empty) {
      const add = (type, label, ic) => h('button', { type: 'button', onclick: () => openRelativeDialog(me, type, { onDone: () => navigate(`/tree/${me.id}?mode=hourglass`) }) }, icon(ic), label);
      onboarding.append(h('div', { class: 'card mt' },
        h('h3', null, icon('sparkles'), ' شروع ساخت شجره‌نامه'),
        h('p', { class: 'muted' }, 'با افزودن پدر، مادر، همسر و فرزندانتان شروع کنید. بعد از آن بستگانتان هم می‌توانند وارد شوند و شاخه‌های خودشان را کامل کنند.'),
        h('div', { class: 'onboarding' },
          add('father', 'افزودن پدر', 'user'),
          add('mother', 'افزودن مادر', 'user'),
          add('spouse', 'افزودن همسر', 'heart'),
          add('child', 'افزودن فرزند', 'baby'),
        ),
      ));
    }
  }

  // خاندان‌ها
  const families = familiesRes.status === 'fulfilled' ? familiesRes.value.data : [];
  familiesGrid.replaceChildren(
    ...families.map((f) => h('div', {
      class: 'card family-card hover',
      style: { '--fc-color': f.color || null },
      onclick: () => f.root && navigate(`/tree/${f.root.id}`),
    },
    h('div', { class: 'fc-top' }),
    h('div', { class: 'fc-body' },
      h('div', { class: 'row between' },
        avatar(f.root, 'lg', { ring: false }),
        f.can_edit ? h('button', { class: 'btn ghost sm icon-only', type: 'button', title: 'ویرایش', onclick: (e) => { e.stopPropagation(); openFamilyDialog(f, () => navigate('/')); } }, icon('edit')) : null,
      ),
      h('h3', null, f.name),
      h('div', { class: 'muted small' }, f.root ? `جد اعلا: ${fullName(f.root)}` : ''),
      f.description ? h('p', { class: 'small text-2', style: { margin: '6px 0 0' } }, f.description.slice(0, 120)) : null,
    ))),
    h('div', { class: 'card family-card new hover', onclick: () => openFamilyDialog(null, () => navigate('/')) },
      h('div', null, icon('plus'), h('div', { class: 'bold' }, 'خاندان جدید'), h('div', { class: 'small' }, 'یک جد اعلا انتخاب کنید و درختش را بسازید')),
    ),
  );

  // مناسبت‌ها
  const evList = eventsRes.status === 'fulfilled' ? eventsRes.value.data : [];
  events.lastElementChild.replaceWith(evList.length
    ? h('div', null, ...evList.slice(0, 8).map((e) => h('div', { class: 'event-row', style: { cursor: 'pointer' }, onclick: () => navigate(`/person/${e.person.id}`) },
      avatar(e.person, 'sm'),
      h('div', { class: 'grow' },
        h('div', { class: 'bold' }, fullName(e.person)),
        h('div', { class: 'muted small' }, e.kind === 'birthday'
          ? `🎂 تولد ${fa(e.years)} سالگی • ${formatDate(e.date).split(' ').slice(0, 2).join(' ')}`
          : `🕯 سالگرد درگذشت (${fa(e.years)} سال) • ${formatDate(e.date).split(' ').slice(0, 2).join(' ')}`),
      ),
      h('div', { class: 'when' }, e.in_days === 0 ? h('b', null, 'امروز') : [h('b', null, fa(e.in_days)), 'روز دیگر']),
    )))
    : emptyState('calendar', 'در ۳۰ روز آینده مناسبتی ثبت نشده است.'));

  // فعالیت‌ها
  const feedList = feedRes.status === 'fulfilled' ? feedRes.value.data : [];
  feed.lastElementChild.replaceWith(feedList.length
    ? h('div', null, ...feedList.slice(0, 10).map((a) => h('div', { class: 'feed-item' },
      h('div', { class: 'dot' }, icon(a.action.startsWith('media') ? 'image' : a.action.startsWith('link') ? 'link' : 'user-plus')),
      h('div', { class: 'grow' },
        h('div', null, h('b', null, a.user?.name || 'سیستم'), ' ', actionLabel(a.action), a.subject_name ? [' ', h('a', { href: `#/person/${a.subject_id}` }, a.subject_name)] : null, a.properties?.name ? ` (${a.properties.name})` : ''),
        h('div', { class: 'muted tiny' }, timeAgo(a.created_at)),
      ),
    )))
    : emptyState('history', 'هنوز فعالیتی ثبت نشده است.'));
}
