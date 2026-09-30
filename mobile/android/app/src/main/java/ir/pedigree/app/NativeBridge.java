package ir.pedigree.app;

import android.content.ContentValues;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.print.PrintAttributes;
import android.print.PrintDocumentAdapter;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.util.Base64;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;
import android.widget.Toast;

import androidx.core.content.FileProvider;

import java.io.File;
import java.io.FileOutputStream;
import java.io.OutputStream;

/**
 * پل ارتباطی جاوااسکریپت ↔ اندروید.
 *
 * در وب‌اپ از طریق window.PedigreeNative در دسترس است (فایل assets/js/core/native.js).
 * نکته امنیتی: متدها فقط وقتی اجرا می‌شوند که صفحه فعلی متعلق به سایت خودمان باشد.
 */
public class NativeBridge {

    /** نوع فایل مجاز ← پسوند */
    private static final java.util.Map<String, String> ALLOWED_TYPES = new java.util.HashMap<>();
    /** سقف حجم فایل (حدود ۱۰۰ مگابایت) */
    private static final int MAX_BASE64 = 140 * 1024 * 1024;

    static {
        ALLOWED_TYPES.put("application/pdf", "pdf");
        ALLOWED_TYPES.put("image/png", "png");
        ALLOWED_TYPES.put("image/jpeg", "jpg");
        ALLOWED_TYPES.put("image/svg+xml", "svg");
        ALLOWED_TYPES.put("text/plain", "ged");
        ALLOWED_TYPES.put("application/x-gedcom", "ged");
        ALLOWED_TYPES.put("text/vnd.familysearch.gedcom", "ged");
    }

    private final Context context;
    private final WebView webView;

    NativeBridge(Context context, WebView webView) {
        this.context = context;
        this.webView = webView;
    }

    /** صفحه فعلی از سایت خودمان است؟ (روی ترد اصلی خوانده می‌شود) */
    private boolean trusted() {
        final String[] url = new String[1];
        final Object lock = new Object();
        webView.post(() -> {
            synchronized (lock) {
                url[0] = webView.getUrl();
                lock.notify();
            }
        });
        synchronized (lock) {
            try {
                if (url[0] == null) {
                    lock.wait(1000);
                }
            } catch (InterruptedException ignored) {
                Thread.currentThread().interrupt();
            }
        }
        return url[0] != null && MainActivity.isOwnUrl(Uri.parse(url[0]));
    }

    @JavascriptInterface
    public String version() {
        return BuildConfig.VERSION_NAME;
    }

    /**
     * ذخیره فایل (PDF، تصویر، GEDCOM) در پوشه دانلود و نمایش گزینه باز کردن/اشتراک
     */
    @JavascriptInterface
    public void saveFile(String base64, String filename, String mime) {
        if (!trusted()) {
            return;
        }
        // فقط نوع فایل‌هایی که سایت واقعاً می‌سازد (مثلاً نصب فایل APK ممکن نباشد)
        // «text/plain; charset=utf-8» ← «text/plain»
        final String cleanMime = mime == null ? "" : mime.split(";")[0].trim().toLowerCase(java.util.Locale.ROOT);
        String type = ALLOWED_TYPES.get(cleanMime);
        if (type == null || base64 == null || base64.length() > MAX_BASE64) {
            webView.post(() -> Toast.makeText(context, R.string.save_failed, Toast.LENGTH_LONG).show());
            return;
        }
        try {
            byte[] bytes = Base64.decode(base64, Base64.DEFAULT);
            String base = filename == null ? "pedigree" : filename.replaceAll("[\\\\/:*?\"<>|\\p{Cntrl}]", "_").replaceAll("\\.[A-Za-z0-9]{1,8}$", "");
            if (base.isEmpty() || base.startsWith(".")) {
                base = "pedigree" + base;
            }
            if (base.length() > 80) {
                base = base.substring(0, 80);
            }
            String safeName = base + "." + type;
            Uri uri;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                ContentValues values = new ContentValues();
                values.put(MediaStore.Downloads.DISPLAY_NAME, safeName);
                values.put(MediaStore.Downloads.MIME_TYPE, cleanMime);
                values.put(MediaStore.Downloads.RELATIVE_PATH, "Download/Pedigree");
                uri = context.getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values);
                if (uri == null) {
                    throw new IllegalStateException("insert failed");
                }
                try (OutputStream out = context.getContentResolver().openOutputStream(uri)) {
                    if (out == null) {
                        throw new IllegalStateException("stream failed");
                    }
                    out.write(bytes);
                }
            } else {
                File dir = new File(context.getExternalFilesDir(null), "exports");
                //noinspection ResultOfMethodCallIgnored
                dir.mkdirs();
                File file = new File(dir, safeName);
                try (FileOutputStream out = new FileOutputStream(file)) {
                    out.write(bytes);
                }
                uri = FileProvider.getUriForFile(context, context.getPackageName() + ".files", file);
            }

            final Uri shareUri = uri;
            webView.post(() -> {
                Toast.makeText(context, context.getString(R.string.saved_to_downloads), Toast.LENGTH_SHORT).show();
                Intent view = new Intent(Intent.ACTION_VIEW);
                view.setDataAndType(shareUri, cleanMime);
                view.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
                try {
                    context.startActivity(Intent.createChooser(view, context.getString(R.string.open_with))
                            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
                } catch (Exception ignored) {
                    // برنامه‌ای برای باز کردن فایل نصب نیست؛ فایل در پوشه دانلود ذخیره شده
                }
            });
        } catch (Exception e) {
            webView.post(() -> Toast.makeText(context, R.string.save_failed, Toast.LENGTH_LONG).show());
        }
    }

    /** چاپ صفحه فعلی (خروجی چاپ درخت) با سرویس چاپ اندروید؛ امکان «ذخیره به PDF» هم دارد */
    @JavascriptInterface
    public void print() {
        if (!trusted()) {
            return;
        }
        webView.post(() -> {
            PrintManager manager = (PrintManager) context.getSystemService(Context.PRINT_SERVICE);
            String job = context.getString(R.string.app_name);
            PrintDocumentAdapter adapter = webView.createPrintDocumentAdapter(job);
            manager.print(job, adapter, new PrintAttributes.Builder()
                    .setMediaSize(PrintAttributes.MediaSize.ISO_A4.asLandscape())
                    .build());
        });
    }

    /**
     * هشدارهای محلی: فهرست زنگ‌های آینده (JSON) از سایت؛ گوشی خودش سر ثانیه زنگ می‌زند
     * (حتی با اپ بسته و بدون اینترنت). تعداد زنگ‌های تنظیم‌شده برمی‌گردد.
     */
    @JavascriptInterface
    public int scheduleAlarms(String json) {
        if (!trusted() || json == null || json.length() > 200_000) {
            return -1;
        }
        int count = AlarmScheduler.replaceAll(context, json);
        if (count > 0) {
            askNotificationPermission();
        }
        return count;
    }

    /** آیا اندروید اجازه «زنگ دقیق» داده است؟ */
    @JavascriptInterface
    public boolean exactAlarms() {
        return AlarmScheduler.exactAllowed(context);
    }

    /** صفحه تنظیمات «زنگ‌ها و یادآورها» برای دادن اجازه زنگ دقیق (اندروید ۱۲ به بعد) */
    @JavascriptInterface
    public void openAlarmSettings() {
        if (!trusted() || Build.VERSION.SDK_INT < Build.VERSION_CODES.S) {
            return;
        }
        Intent intent = new Intent(android.provider.Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM, Uri.parse("package:" + context.getPackageName()))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            context.startActivity(intent);
        } catch (Exception ignored) {
            // برخی گوشی‌ها این صفحه را ندارند
        }
    }

    /** اجازه نمایش اعلان (اندروید ۱۳ به بعد)؛ فقط یک بار پرسیده می‌شود */
    private void askNotificationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU || !(context instanceof android.app.Activity)) {
            return;
        }
        if (androidx.core.content.ContextCompat.checkSelfPermission(context, android.Manifest.permission.POST_NOTIFICATIONS)
                == android.content.pm.PackageManager.PERMISSION_GRANTED) {
            return;
        }
        android.content.SharedPreferences prefs = context.getSharedPreferences("alarms", Context.MODE_PRIVATE);
        if (prefs.getBoolean("asked_notifications", false)) {
            return;
        }
        prefs.edit().putBoolean("asked_notifications", true).apply();
        final android.app.Activity activity = (android.app.Activity) context;
        webView.post(() -> androidx.core.app.ActivityCompat.requestPermissions(activity,
                new String[]{android.Manifest.permission.POST_NOTIFICATIONS}, 7301));
    }

    /** اشتراک‌گذاری لینک با برنامه‌های دیگر */
    @JavascriptInterface
    public void share(String title, String url) {
        if (!trusted()) {
            return;
        }
        Intent send = new Intent(Intent.ACTION_SEND);
        send.setType("text/plain");
        send.putExtra(Intent.EXTRA_SUBJECT, title);
        send.putExtra(Intent.EXTRA_TEXT, title + "\n" + url);
        context.startActivity(Intent.createChooser(send, title).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
    }
}
