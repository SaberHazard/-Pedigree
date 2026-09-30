/**
 * پل ارتباطی با اپ‌های اندروید و iOS.
 *
 * وقتی سایت داخل اپ موبایل (WebView) باز شود، دانلود فایل و چاپ باید
 * توسط کد بومی اپ انجام شود؛ این ماژول تشخیص می‌دهد و درخواست را منتقل می‌کند.
 *  - اندروید: window.PedigreeNative (JavascriptInterface)
 *  - iOS:      window.webkit.messageHandlers.pedigree
 */

export const isAndroidApp = () => typeof window.PedigreeNative !== 'undefined';
export const isIosApp = () => !!window.webkit?.messageHandlers?.pedigree;
export const isNativeApp = () => isAndroidApp() || isIosApp();

function blobToBase64(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1]);
    reader.onerror = reject;
    reader.readAsDataURL(blob);
  });
}

/** ذخیره/اشتراک‌گذاری فایل */
export async function saveFile(blob, filename) {
  if (isAndroidApp()) {
    window.PedigreeNative.saveFile(await blobToBase64(blob), filename, blob.type || 'application/octet-stream');
    return;
  }
  if (isIosApp()) {
    window.webkit.messageHandlers.pedigree.postMessage({
      action: 'saveFile',
      name: filename,
      mime: blob.type || 'application/octet-stream',
      data: await blobToBase64(blob),
    });
    return;
  }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.append(a);
  a.click();
  setTimeout(() => {
    URL.revokeObjectURL(a.href);
    a.remove();
  }, 1500);
}

/** چاپ صفحه */
export function printPage() {
  if (isAndroidApp() && window.PedigreeNative.print) {
    window.PedigreeNative.print();
  } else if (isIosApp()) {
    window.webkit.messageHandlers.pedigree.postMessage({ action: 'print' });
  } else {
    window.print();
  }
}

/** اشتراک‌گذاری لینک (اشتراک بومی در صورت وجود) */
export async function shareLink(title, url) {
  if (isAndroidApp() && window.PedigreeNative.share) {
    window.PedigreeNative.share(title, url);
    return true;
  }
  if (navigator.share) {
    try {
      await navigator.share({ title, url });
      return true;
    } catch {
      return false;
    }
  }
  try {
    await navigator.clipboard.writeText(url);
    return 'copied';
  } catch {
    return false;
  }
}

/**
 * هشدارهای پیش رو به اپ گوشی داده می‌شود تا خود گوشی سر ثانیه (حتی بدون اینترنت) زنگ بزند.
 * هر بار کل فهرست جایگزین می‌شود. خروجی: آیا اپ این قابلیت را دارد؟
 */
export function scheduleNativeAlarms(items) {
  const clean = items.slice(0, 60).map((i) => ({ key: String(i.key), title: String(i.title).slice(0, 120), body: String(i.body || '').slice(0, 400), at: Number(i.at), link: String(i.link || '') }));
  if (isAndroidApp() && typeof window.PedigreeNative.scheduleAlarms === 'function') {
    window.PedigreeNative.scheduleAlarms(JSON.stringify(clean));
    return true;
  }
  if (isIosApp()) {
    window.webkit.messageHandlers.pedigree.postMessage({ action: 'alarms', items: clean });
    return true;
  }
  return false;
}
