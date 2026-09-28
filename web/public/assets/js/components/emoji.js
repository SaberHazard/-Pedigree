/**
 * صفحه ایموجی سبک (بدون کتابخانه و بدون دانلود): همان ایموجی‌های یونیکد تلگرام و واتس‌اپ.
 * استیکر و گیف عمداً نیست تا فضای سرور اشغال نشود.
 */
import { h } from '../core/dom.js';

const GROUPS = [
  ['😀', 'چهره‌ها', '😀 😃 😄 😁 😆 😅 😂 🤣 🥲 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😗 😙 😚 😋 😛 😝 😜 🤪 🤨 🧐 🤓 😎 🥸 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 ☹️ 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🫣 🤭 🫢 🤫 🤥 😶 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕 🤑 🤠'],
  ['❤️', 'دل و دست', '❤️ 🧡 💛 💚 💙 💜 🤎 🖤 🤍 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💐 🌹 🌷 🌸 👍 👎 👌 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 🤝 🙏 👏 🙌 🫶 💪 ✍️ 🤲'],
  ['👨‍👩‍👧', 'خانواده', '👶 🧒 👦 👧 🧑 👨 👩 🧓 👴 👵 👨‍👩‍👧 👨‍👩‍👧‍👦 👨‍👩‍👦 👪 💑 👩‍❤️‍👨 💏 🤰 🤱 👰 🤵 🧕 👳 👲 🧔 👨‍🎓 👩‍🎓 👨‍⚕️ 👩‍⚕️ 👨‍🏫 👩‍🏫 👨‍🌾 👩‍🌾 👨‍💻 👩‍💻 👨‍🍳 👩‍🍳'],
  ['🎉', 'جشن', '🎉 🎊 🎂 🍰 🧁 🎁 🎈 🎀 🥂 🍾 ✨ 🎇 🎆 🪅 🏆 🥇 🎓 💍 💎 🕯️ 📿 🕌 🕋 ⭐ 🌟 💫 🔥 💯 ✅ ☑️ ❗ ❓ 💤'],
  ['🌿', 'طبیعت و غذا', '☀️ 🌤️ ⛅ 🌧️ ⛄ 🌈 🌙 🌿 🍀 🌳 🌴 🌵 🌾 🍁 🍂 🌺 🌻 🌼 🍎 🍊 🍋 🍉 🍇 🍓 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🥕 🌽 🍞 🧀 🍗 🍖 🍕 🍔 🍟 🍚 🍛 🍜 🍲 🥗 🍯 ☕ 🍵 🧃 🥤 🍫 🍬 🍭 🍦'],
  ['🚗', 'سفر و چیزها', '🚗 🚕 🚌 🚑 🚒 🚲 🛵 ✈️ 🚆 🚢 🏠 🏡 🏫 🏥 🕰️ ⌚ 📱 💻 📷 📚 📖 ✏️ 📝 📌 📎 🔑 🎵 🎶 🎤 🎧 ⚽ 🏀 🏐 🎾 🏓 ♟️ 🎮 🧩 🪁 🛒 💰 📅 ⏰ 🇮🇷'],
];

/**
 * @param {(emoji:string) => void} onPick
 * @returns {HTMLElement}
 */
export function emojiPanel(onPick) {
  const grid = h('div', { class: 'emoji-grid' });
  const tabs = h('div', { class: 'emoji-tabs' });
  const show = (index) => {
    tabs.querySelectorAll('button').forEach((b, i) => b.classList.toggle('active', i === index));
    grid.replaceChildren(...GROUPS[index][2].split(' ').map((e) => h('button', { type: 'button', class: 'emoji', 'aria-label': e, onclick: () => onPick(e) }, e)));
    grid.scrollTop = 0;
  };
  tabs.append(...GROUPS.map(([icon, title], i) => h('button', { type: 'button', title, 'aria-label': title, onclick: () => show(i) }, icon)));
  show(0);
  return h('div', { class: 'emoji-panel' }, tabs, grid);
}

/** درج متن در محل مکان‌نما */
export function insertAtCursor(field, text) {
  const start = field.selectionStart ?? field.value.length;
  const end = field.selectionEnd ?? field.value.length;
  field.value = field.value.slice(0, start) + text + field.value.slice(end);
  const pos = start + text.length;
  field.setSelectionRange?.(pos, pos);
  field.dispatchEvent(new Event('input', { bubbles: true }));
  field.focus();
}
