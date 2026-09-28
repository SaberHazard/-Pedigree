/**
 * بازی‌های خانوادگی: از روی خود شجره‌نامه («این کیه؟»، «نسبت فامیلی»، «کی بزرگ‌تره؟»)
 * و بازی‌های هوش مصنوعی (مشاعره، بیست سؤالی، چیستان، مسابقه، داستان‌سازی، ضرب‌المثل).
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get } from '../core/api.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { fa } from '../core/format.js';
import { loader, emptyState } from '../core/ui.js';

const FAMILY = [
  { type: 'who', emoji: '🖼️', title: 'این کیه؟', text: 'از روی عکس پروفایل، عضو خاندان را بشناسید.' },
  { type: 'kin', emoji: '🌳', title: 'نسبت فامیلی', text: 'فلانی دقیقاً چه نسبتی با شما دارد؟' },
  { type: 'older', emoji: '🎂', title: 'کی بزرگ‌تره؟', text: 'از دو نفر، کدام زودتر به دنیا آمده؟' },
];
const AI = [
  ['mushaere', '📖', 'مشاعره'], ['twenty', '❓', 'بیست سؤالی'], ['riddle', '🧩', 'چیستان و معما'],
  ['quiz', '🏆', 'مسابقه ایران‌شناسی'], ['story', '📚', 'داستان‌سازی'], ['proverb', '🗝️', 'ضرب‌المثل'], ['memory', '📜', 'زنده کردن خاطره'],
];

function best(type) {
  try {
    return Number(localStorage.getItem(`game-best:${type}`) || 0);
  } catch {
    return 0;
  }
}
function saveBest(type, value) {
  try {
    if (value > best(type)) localStorage.setItem(`game-best:${type}`, String(value));
  } catch {
    /* ignore */
  }
}

export default function gamesPage(container, { query }) {
  const page = h('div', { class: 'page narrow games' });
  container.append(page);
  document.title = `بازی‌ها | ${store.config.site_name}`;
  if (query?.type && FAMILY.some((g) => g.type === query.type)) play(query.type);
  else home();

  function home() {
    const ai = store.config.assistant?.enabled;
    page.replaceChildren(...[
      h('div', { class: 'page-head' }, h('div', null,
        h('h1', null, icon('gamepad'), ' بازی‌های خانوادگی'),
        h('p', { class: 'muted', style: { margin: 0 } }, 'با بازی، اعضای خاندان و نسبت‌ها را بهتر بشناسید.'))),
      h('div', { class: 'game-grid' }, ...FAMILY.map((g) => h('button', { class: 'card game-card', type: 'button', onclick: () => play(g.type) },
        h('div', { class: 'game-emoji' }, g.emoji),
        h('b', null, g.title),
        h('p', { class: 'muted small' }, g.text),
        best(g.type) ? h('span', { class: 'chip' }, `رکورد: ${fa(best(g.type))}`) : null,
      ))),
      ai ? h('section', { class: 'card mt' },
        h('div', { class: 'card-title' }, h('h3', null, icon('bot'), ' بازی با هوش مصنوعی')),
        h('div', { class: 'row wrap', style: { gap: '8px' } }, ...AI.map(([mode, emoji, label]) => h('button', {
          class: 'btn soft', type: 'button', onclick: () => navigate(`/assistant?mode=${mode}`),
        }, emoji, ' ', label))),
      ) : null,
    ].filter(Boolean));
  }

  function play(type) {
    const game = FAMILY.find((g) => g.type === type);
    let score = 0;
    let streak = 0;
    let rounds = 0;
    const scoreEl = h('div', { class: 'row', style: { gap: '8px' } });
    const body = h('div', { class: 'card game-board' });
    const drawScore = () => scoreEl.replaceChildren(...[
      h('span', { class: 'chip primary' }, `امتیاز: ${fa(score)} از ${fa(rounds)}`),
      streak > 1 ? h('span', { class: 'chip success' }, `🔥 ${fa(streak)} پشت سر هم`) : null,
      h('span', { class: 'chip' }, `رکورد: ${fa(best(type))}`),
    ].filter(Boolean));
    drawScore();
    page.replaceChildren(
      h('div', { class: 'row between mb', style: { flexWrap: 'wrap', gap: '8px' } },
        h('button', { class: 'btn ghost sm', type: 'button', onclick: home }, icon('chevron-right'), 'همه بازی‌ها'),
        h('h2', { style: { margin: 0 } }, `${game.emoji} ${game.title}`),
        scoreEl,
      ),
      body,
    );
    next();

    async function next() {
      body.replaceChildren(loader());
      let q;
      try {
        q = (await get('/api/games/question', { type })).data;
      } catch (e) {
        body.replaceChildren(emptyState('info', e.message));
        return;
      }
      let answered = false;
      const feedback = h('div', { class: 'game-feedback', hidden: true });
      const nextBtn = h('button', { class: 'btn primary', type: 'button', hidden: true, onclick: next }, 'سؤال بعدی', icon('chevron-left'));
      const buttons = q.options.map((o) => h('button', {
        type: 'button', class: `game-option ${o.image !== undefined ? 'with-image' : ''}`,
        onclick: () => choose(o),
      }, o.image !== undefined ? h('span', { class: 'game-ava' }, o.image ? h('img', { src: o.image, alt: '' }) : icon('user')) : null, h('span', null, o.label)));

      function choose(o) {
        if (answered) return;
        answered = true;
        rounds++;
        const right = o.id === q.answer;
        if (right) {
          score++;
          streak++;
          saveBest(type, streak);
        } else {
          streak = 0;
        }
        buttons.forEach((b, i) => {
          b.disabled = true;
          const opt = q.options[i];
          if (opt.id === q.answer) b.classList.add('right');
          else if (opt.id === o.id) b.classList.add('wrong');
        });
        feedback.hidden = false;
        feedback.className = `game-feedback ${right ? 'ok' : 'no'}`;
        feedback.replaceChildren(...[h('b', null, right ? '👏 آفرین، درست بود!' : '😅 نه، این نبود.'), ' ', q.explain || '',
          q.person_id ? h('a', { href: `#/person/${q.person_id}`, class: 'small', style: { marginInlineStart: '8px' } }, 'دیدن پروفایل') : null].filter(Boolean));
        nextBtn.hidden = false;
        drawScore();
      }

      body.replaceChildren(...[
        q.image ? h('img', { class: 'game-photo', src: q.image, alt: 'عکس' }) : null,
        h('h3', { class: 'game-question' }, q.question),
        h('div', { class: `game-options ${q.options.length === 2 ? 'two' : ''}` }, ...buttons),
        feedback,
        h('div', { class: 'row', style: { justifyContent: 'center', marginTop: '12px' } }, nextBtn),
      ].filter(Boolean));
    }
  }
}
