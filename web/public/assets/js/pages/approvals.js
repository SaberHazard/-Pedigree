/**
 * صف تأییدها: رأی به عکس/ویدیوهای بستگان، درخواست‌های اتصال درخت، وضعیت آپلودهای من
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { get, post } from '../core/api.js';
import { store } from '../core/store.js';
import { fa, fullName, timeAgo } from '../core/format.js';
import { tabs, toast, toastError, emptyState, loader, withLoading, modal, field } from '../core/ui.js';
import { avatar } from '../components/avatar.js';
import { openLightbox } from '../components/lightbox.js';

const LINK_TYPES = { spouse: 'همسرِ', father: 'فرزندِ', mother: 'فرزندِ', child: 'والدِ' };
const MODE_TEXT = { owner: 'تصمیم با صاحب پروفایل', vote: 'رأی‌گیری بستگان', admin: 'بررسی مدیر', auto: 'تأیید خودکار' };

export default async function approvalsPage(container) {
  const page = h('div', { class: 'page narrow' });
  const body = h('div');
  container.append(page);
  let current = 'vote';

  page.append(
    h('div', { class: 'page-head' }, h('div', null,
      h('h1', null, 'تأییدها'),
      h('p', { class: 'muted', style: { margin: 0 } }, 'برای جلوگیری از انتشار محتوای نامناسب، عکس‌ها و ویدیوها با رأی بستگان منتشر می‌شوند.'),
    )),
  );
  const tabBar = h('div');
  page.append(tabBar, body);
  await load();

  async function load() {
    body.replaceChildren(loader());
    let media;
    let links;
    try {
      [media, links] = await Promise.all([get('/api/approvals'), get('/api/link-requests')]);
    } catch (e) {
      body.replaceChildren(emptyState('alert', e.message));
      return;
    }
    const incoming = links.data.filter((l) => l.can.decide);
    const mine = links.data.filter((l) => !l.can.decide);
    const items = [
      { value: 'vote', label: 'منتظر رأی من', icon: 'thumbs-up', badge: media.to_vote.length ? fa(media.to_vote.length) : null },
      { value: 'links', label: 'اتصال درخت‌ها', icon: 'link', badge: incoming.length ? fa(incoming.length) : null },
      { value: 'mine', label: 'آپلودها و درخواست‌های من', icon: 'upload' },
      store.user.is_admin ? { value: 'admin', label: 'صف مدیر', icon: 'crown', badge: media.admin_queue.length ? fa(media.admin_queue.length) : null } : null,
    ].filter(Boolean);
    tabBar.replaceChildren(tabs(items, current, (v) => { current = v; render(); }));

    function render() {
      if (current === 'vote') {
        body.replaceChildren(media.to_vote.length ? h('div', { class: 'col gap-lg' }, ...media.to_vote.map((m) => voteCard(m, false))) : emptyState('check-circle', 'موردی منتظر رأی شما نیست.'));
      } else if (current === 'links') {
        body.replaceChildren(incoming.length ? h('div', { class: 'col' }, ...incoming.map(linkCard)) : emptyState('link', 'درخواست اتصالی منتظر شما نیست.'));
      } else if (current === 'admin') {
        body.replaceChildren(media.admin_queue.length ? h('div', { class: 'col gap-lg' }, ...media.admin_queue.map((m) => voteCard(m, true))) : emptyState('check-circle', 'صف خالی است.'));
      } else {
        body.replaceChildren(
          h('h3', null, 'آپلودهای من'),
          media.mine.length ? h('div', { class: 'col' }, ...media.mine.map(mineRow)) : emptyState('upload', 'هنوز فایلی آپلود نکرده‌اید.'),
          h('h3', { class: 'mt' }, 'درخواست‌های اتصال من'),
          mine.length ? h('div', { class: 'col' }, ...mine.map(linkCard)) : emptyState('link', 'درخواستی ثبت نکرده‌اید.'),
        );
      }
    }
    render();
  }

  function preview(m) {
    return h('div', { class: 'vc-media', onclick: () => m.urls && openLightbox([m], 0) },
      m.urls?.thumb ? h('img', { src: m.urls.medium || m.urls.thumb, alt: '' }) : h('div', { class: 'center', style: { paddingTop: '40%' } }, icon('video')),
    );
  }

  function voteCard(m, asAdmin) {
    const comment = h('input', { class: 'input', placeholder: 'توضیح (اختیاری)', maxlength: 500 });
    const yes = h('button', { class: 'btn success', type: 'button' }, icon('thumbs-up'), asAdmin ? 'تأیید' : 'موافقم');
    const no = h('button', { class: 'btn danger', type: 'button' }, icon('thumbs-down'), asAdmin ? 'رد' : 'مخالفم');
    const act = (decision, btn) => withLoading(btn, async () => {
      try {
        const url = asAdmin ? `/api/media/${m.id}/decide` : `/api/media/${m.id}/vote`;
        const res = await post(url, asAdmin ? { decision, note: comment.value } : { decision, comment: comment.value });
        toast(res.data.status === 'pending' ? 'رأی شما ثبت شد؛ منتظر رأی بقیه.' : res.data.status === 'approved' ? 'تأیید و منتشر شد.' : 'رد شد.');
        store.refreshCounters?.();
        load();
      } catch (e) {
        toastError(e);
      }
    });
    yes.addEventListener('click', () => act('approve', yes));
    no.addEventListener('click', () => act('reject', no));

    const total = m.votes.total || 1;
    return h('div', { class: 'card vote-card' },
      preview(m),
      h('div', { class: 'col' },
        h('div', null,
          h('div', { class: 'bold' }, m.type === 'video' ? 'ویدیو' : 'عکس', ' برای ', h('a', { href: `#/person/${m.person_id}` }, m.person_name)),
          h('div', { class: 'muted small' }, `آپلود توسط ${m.uploader?.name || '—'} • ${timeAgo(m.created_at)} • ${MODE_TEXT[m.approval_mode] || ''}`),
        ),
        m.caption ? h('div', null, m.caption) : null,
        m.description ? h('div', { class: 'small text-2' }, m.description) : null,
        m.approval_mode === 'vote' ? h('div', null,
          h('div', { class: 'progress votes' },
            h('span', { class: 'yes', style: { width: `${(m.votes.approve / total) * 100}%` } }),
            h('span', { class: 'no', style: { width: `${(m.votes.reject / total) * 100}%` } }),
          ),
          h('div', { class: 'tiny muted', style: { marginTop: '4px' } }, `${fa(m.votes.approve)} موافق • ${fa(m.votes.reject)} مخالف • از ${fa(m.votes.total)} رأی‌دهنده (برای تأیید بیش از نصف لازم است)`),
        ) : null,
        comment,
        h('div', { class: 'row' }, yes, no),
      ),
    );
  }

  function mineRow(m) {
    const cls = { approved: 'success', rejected: 'danger', pending: 'warning' }[m.status];
    const label = { approved: 'تأیید شد', rejected: 'تأیید نشد', pending: 'در انتظار' }[m.status];
    return h('div', { class: 'card row', style: { padding: '12px' } },
      h('div', { style: { width: '64px', height: '64px', borderRadius: '12px', overflow: 'hidden', flex: 'none', background: 'var(--surface-3)' } },
        m.urls?.thumb ? h('img', { src: m.urls.thumb, alt: '', style: { width: '100%', height: '100%', objectFit: 'cover' } }) : null),
      h('div', { class: 'grow' },
        h('div', { class: 'bold' }, m.caption || (m.type === 'video' ? 'ویدیو' : 'عکس'), ' - ', h('a', { href: `#/person/${m.person_id}` }, m.person_name)),
        h('div', { class: 'muted small' }, timeAgo(m.created_at), m.status === 'pending' && m.approval_mode === 'vote' ? ` • ${fa(m.votes.approve)} موافق از ${fa(m.votes.total)}` : ''),
        m.decision_note ? h('div', { class: 'small text-2' }, m.decision_note) : null,
      ),
      h('span', { class: `chip ${cls}` }, label),
    );
  }

  function linkCard(l) {
    const statusChip = { pending: ['warning', 'در انتظار'], accepted: ['success', 'پذیرفته شد'], rejected: ['danger', 'رد شد'], cancelled: ['', 'لغو شد'] }[l.status];
    const actions = [];
    if (l.can.decide) {
      const ok = h('button', { class: 'btn success sm', type: 'button' }, icon('check'), 'تأیید اتصال');
      const no = h('button', { class: 'btn danger sm', type: 'button' }, icon('x'), 'رد');
      ok.onclick = () => withLoading(ok, () => post(`/api/link-requests/${l.id}/accept`).then(() => { toast('دو درخت به هم وصل شد.'); store.refreshCounters?.(); load(); }).catch(toastError));
      no.onclick = () => withLoading(no, () => post(`/api/link-requests/${l.id}/reject`).then(() => { toast('رد شد.'); store.refreshCounters?.(); load(); }).catch(toastError));
      actions.push(ok, no);
    }
    if (l.can.cancel) {
      const c = h('button', { class: 'btn ghost sm', type: 'button' }, 'لغو درخواست');
      c.onclick = () => withLoading(c, () => post(`/api/link-requests/${l.id}/cancel`).then(load).catch(toastError));
      actions.push(c);
    }
    return h('div', { class: 'card' },
      h('div', { class: 'row wrap' },
        h('a', { class: 'row', href: `#/person/${l.subject.id}` }, avatar(l.subject, 'sm'), h('b', null, fullName(l.subject))),
        h('span', { class: 'muted' }, `به عنوان ${LINK_TYPES[l.type] || ''}`),
        h('a', { class: 'row', href: `#/person/${l.target.id}` }, avatar(l.target, 'sm'), h('b', null, fullName(l.target))),
        h('span', { class: `chip ${statusChip[0]}`, style: { marginInlineStart: 'auto' } }, statusChip[1]),
      ),
      h('div', { class: 'muted small', style: { marginTop: '8px' } }, `درخواست از ${l.requester || '—'} • ${timeAgo(l.created_at)}`),
      l.message ? h('div', { class: 'small', style: { marginTop: '4px' } }, `«${l.message}»`) : null,
      actions.length ? h('div', { class: 'row', style: { marginTop: '10px' } }, ...actions) : null,
    );
  }
}
