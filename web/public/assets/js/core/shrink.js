/**
 * کوچک کردن عکس پیش از آپلود (مثل تلگرام): اینترنت کاربر کمتر مصرف می‌شود و آپلود سریع‌تر است.
 *
 * - فقط JPEG/PNG/WebP بزرگ (بیش از ۲۵۶۰ پیکسل یا حجیم‌تر از ۱٫۵ مگابایت)؛ GIF، HEIC و ویدیو دست‌نخورده می‌مانند
 * - چرخش عکس گوشی (EXIF) اعمال می‌شود و بقیه اطلاعات EXIF (از جمله موقعیت مکانی) همین‌جا حذف می‌شود
 * - اگر نتیجه کوچک‌تر نشد یا مرورگر پشتیبانی نکرد، همان فایل اصلی فرستاده می‌شود
 * سرور در هر حال دوباره به WebP فشرده می‌کند.
 */
export const MAX_SIDE = 2560;

export async function shrinkImage(file, { maxSide = MAX_SIDE, quality = 0.9, minBytes = 1_500_000 } = {}) {
  if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || typeof createImageBitmap !== 'function') return file;
  let bitmap;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch {
    return file;
  }
  try {
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && file.size < minBytes) return file;
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bitmap, 0, 0, width, height);
    // PNG ممکن است شفافیت داشته باشد؛ WebP شفافیت را نگه می‌دارد
    const type = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, quality));
    canvas.width = canvas.height = 0;
    if (!blob || blob.size >= file.size || !/^image\/(jpeg|webp|png)$/.test(blob.type)) return file;
    const ext = { 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/png': 'png' }[blob.type];
    return new File([blob], `${file.name.replace(/\.[^.]+$/, '') || 'photo'}.${ext}`, { type: blob.type, lastModified: file.lastModified });
  } catch {
    return file;
  } finally {
    bitmap.close?.();
  }
}
