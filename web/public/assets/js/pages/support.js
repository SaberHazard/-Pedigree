/**
 * صفحه «پشتیبانی»: تنها راه ارتباط اعضا با مدیران سایت (متن، ایموجی و پیام صوتی)
 */
import { h } from '../core/dom.js';
import { store } from '../core/store.js';
import { supportChat } from '../components/support-chat.js';

export default function supportPage(container) {
  document.title = `پشتیبانی | ${store.config.site_name}`;
  const box = h('div', { class: 'page narrow sup-page' });
  container.append(box);

  return supportChat(box);
}
