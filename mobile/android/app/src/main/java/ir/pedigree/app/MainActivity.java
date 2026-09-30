package ir.pedigree.app;

import android.Manifest;
import android.annotation.SuppressLint;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Bundle;
import android.provider.MediaStore;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;

import androidx.activity.EdgeToEdge;
import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;

import java.io.File;
import java.io.IOException;
import java.util.ArrayList;
import java.util.List;

/**
 * صفحه اصلی اپ اندروید شجره‌نامه.
 *
 * اپ یک «پوسته بومی» است: همان وب‌اپ سایت را با ظاهر یکسان نمایش می‌دهد و
 * کارهایی که مرورگر داخلی نمی‌تواند (ذخیره PDF، چاپ، اشتراک، دوربین) را
 * از طریق NativeBridge انجام می‌دهد. هر تغییری در سایت بلافاصله در اپ هم دیده می‌شود.
 *
 * برای ساخت صفحات بومی در آینده، همه داده‌ها از REST API (مستند در docs/API.md) در دسترس است.
 */
public class MainActivity extends AppCompatActivity {

    private WebView webView;
    private ProgressBar progress;
    private View offlineView;

    private ValueCallback<Uri[]> fileCallback;
    /** درخواست موقعیت مکانی صفحه که منتظر اجازه کاربر است */
    private String geoOrigin;
    private GeolocationPermissions.Callback geoCallback;
    private Uri cameraUri;
    private Uri videoUri;

    /** انتخاب فایل برای <input type="file"> صفحه (عکس و ویدیو) */
    private final ActivityResultLauncher<Intent> filePicker = registerForActivityResult(
            new ActivityResultContracts.StartActivityForResult(), this::onFilePicked);

    /** درخواست میکروفون/دوربین صفحه که منتظر اجازه اندروید است */
    private PermissionRequest pendingMediaRequest;

    /** اجازه میکروفون و دوربین (پیام صوتی، گفتگوی صوتی با دستیار، تماس صوتی و تصویری) */
    private final ActivityResultLauncher<String[]> mediaPermission = registerForActivityResult(
            new ActivityResultContracts.RequestMultiplePermissions(), result -> {
                PermissionRequest request = pendingMediaRequest;
                pendingMediaRequest = null;
                if (request != null) {
                    grantAllowed(request);
                }
            });

    /** انتخاب فایلی که منتظر اجازه دوربین است (برای گزینه «عکس/فیلم گرفتن») */
    private WebChromeClient.FileChooserParams pendingChooser;

    private final ActivityResultLauncher<String> cameraForChooser = registerForActivityResult(
            new ActivityResultContracts.RequestPermission(), granted -> {
                WebChromeClient.FileChooserParams params = pendingChooser;
                pendingChooser = null;
                if (params != null && !openChooser(params, Boolean.TRUE.equals(granted)) && fileCallback != null) {
                    fileCallback.onReceiveValue(null);
                    fileCallback = null;
                }
            });

    /** اجازه موقعیت مکانی (برای دکمه «موقعیت من» روی نقشه) */
    private final ActivityResultLauncher<String[]> locationPermission = registerForActivityResult(
            new ActivityResultContracts.RequestMultiplePermissions(), result -> {
                boolean granted = Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_FINE_LOCATION))
                        || Boolean.TRUE.equals(result.get(Manifest.permission.ACCESS_COARSE_LOCATION));
                if (geoCallback != null) {
                    geoCallback.invoke(geoOrigin, granted, false);
                }
                geoCallback = null;
                geoOrigin = null;
            });

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        // نمایش لبه‌به‌لبه در همه نسخه‌ها (در اندروید ۱۵ اجباری است)؛ فاصله‌ها با insets تنظیم می‌شوند
        EdgeToEdge.enable(this);
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        // فاصله از نوار وضعیت، نوار ناوبری، بریدگی صفحه و صفحه‌کلید
        // (با لبه‌به‌لبه، adjustResize دیگر کار نمی‌کند و صفحه‌کلید روی فرم‌ها می‌افتاد)
        View root = findViewById(R.id.root);
        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars()
                    | WindowInsetsCompat.Type.displayCutout()
                    | WindowInsetsCompat.Type.ime());
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom);
            return WindowInsetsCompat.CONSUMED;
        });

        webView = findViewById(R.id.webview);
        progress = findViewById(R.id.progress);
        offlineView = findViewById(R.id.offline);
        findViewById(R.id.retry).setOnClickListener(v -> reload());

        // امکان دیباگ صفحه با chrome://inspect فقط در نسخه توسعه
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        // امنیت: دسترسی صفحه به فایل‌های گوشی بسته است
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setAllowFileAccessFromFileURLs(false);
        s.setAllowUniversalAccessFromFileURLs(false);
        s.setSupportMultipleWindows(false);
        s.setGeolocationEnabled(true);
        s.setSafeBrowsingEnabled(true);
        s.setUserAgentString(s.getUserAgentString() + " PedigreeApp/Android/" + BuildConfig.VERSION_NAME);

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptThirdPartyCookies(webView, false);

        webView.addJavascriptInterface(new NativeBridge(this, webView), "PedigreeNative");
        // کانال اعلان «هشدارها» (برای زنگ محلی و پوش هشدار Firebase)
        AlarmScheduler.ensureChannel(this);
        webView.setWebViewClient(new Client());
        webView.setWebChromeClient(new ChromeClient());

        // دکمه برگشت: اول صفحه قبلی داخل وب‌اپ، بعد خروج
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack();
                } else {
                    finish();
                }
            }
        });

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState);
        } else {
            webView.loadUrl(startUrl(getIntent()));
        }
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        // باز شدن لینک سایت (مثلاً از پیامک یا اعلان) داخل اپ
        if (intent.getData() != null && isOwnUrl(intent.getData())) {
            webView.loadUrl(intent.getData().toString());
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        webView.saveState(outState);
    }

    @Override
    protected void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }

    private String startUrl(Intent intent) {
        Uri data = intent != null ? intent.getData() : null;
        return data != null && isOwnUrl(data) ? data.toString() : BuildConfig.BASE_URL;
    }

    /** آیا آدرس متعلق به سایت خودمان است؟ (پروتکل، دامنه و پورت یکسان) */
    /** طرح‌هایی که صفحه اجازه دارد با برنامه‌های دیگر گوشی باز کند */
    static final java.util.Set<String> EXTERNAL_SCHEMES = new java.util.HashSet<>(
            java.util.Arrays.asList("http", "https", "tel", "mailto", "sms", "geo"));

    static boolean isOwnUrl(Uri uri) {
        if (uri == null) {
            return false;
        }
        Uri base = Uri.parse(BuildConfig.BASE_URL);
        return uri.getHost() != null
                && uri.getHost().equalsIgnoreCase(base.getHost())
                && base.getScheme() != null && base.getScheme().equalsIgnoreCase(uri.getScheme())
                && effectivePort(uri) == effectivePort(base);
    }

    private static int effectivePort(Uri uri) {
        if (uri.getPort() != -1) {
            return uri.getPort();
        }
        return "https".equalsIgnoreCase(uri.getScheme()) ? 443 : 80;
    }

    private void reload() {
        offlineView.setVisibility(View.GONE);
        webView.setVisibility(View.VISIBLE);
        if (webView.getUrl() == null) {
            webView.loadUrl(BuildConfig.BASE_URL);
        } else {
            webView.reload();
        }
    }

    // ------------------------------------------------------------------ مرورگر داخلی

    private class Client extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            Uri uri = request.getUrl();
            if (isOwnUrl(uri)) {
                return false;
            }
            // لینک‌های بیرونی در برنامه مربوط باز می‌شوند (wa.me ← واتس‌اپ، t.me ← تلگرام،
            // instagram.com ← اینستاگرام، tel: ← شماره‌گیر، نقشه ← مسیریاب)؛ فقط طرح‌های امن
            String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(java.util.Locale.ROOT);
            if (!EXTERNAL_SCHEMES.contains(scheme)) {
                return true;
            }
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW, uri);
                intent.addCategory(Intent.CATEGORY_BROWSABLE);
                startActivity(intent);
            } catch (ActivityNotFoundException ignored) {
                // برنامه‌ای برای باز کردن این لینک نیست
            }
            return true;
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            if (request.isForMainFrame()) {
                view.setVisibility(View.INVISIBLE);
                offlineView.setVisibility(View.VISIBLE);
            }
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            progress.setVisibility(View.GONE);
        }

        /** اگر موتور مرورگر گوشی از کار افتاد (کمبود حافظه و ...)، به‌جای بسته شدن اپ صفحه از نو ساخته شود */
        @Override
        public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail) {
            ((android.view.ViewGroup) view.getParent()).removeView(view);
            view.destroy();
            recreate();
            return true;
        }
    }

    private class ChromeClient extends WebChromeClient {
        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            progress.setVisibility(newProgress < 100 ? View.VISIBLE : View.GONE);
            progress.setProgress(newProgress);
        }

        /** موقعیت مکانی فقط برای سایت خودمان و با اجازه کاربر */
        @Override
        public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
            if (!isOwnUrl(Uri.parse(origin))) {
                callback.invoke(origin, false, false);
                return;
            }
            boolean has = ContextCompat.checkSelfPermission(MainActivity.this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
                    || ContextCompat.checkSelfPermission(MainActivity.this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
            if (has) {
                callback.invoke(origin, true, false);
                return;
            }
            geoOrigin = origin;
            geoCallback = callback;
            locationPermission.launch(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION});
        }

        /**
         * فقط میکروفون و دوربین و فقط برای خود سایت شجره‌نامه (پیام صوتی، دستیار صوتی، تماس صوتی و تصویری).
         */
        @Override
        public void onPermissionRequest(PermissionRequest request) {
            runOnUiThread(() -> {
                java.util.List<String> resources = java.util.Arrays.asList(request.getResources());
                boolean onlyMedia = !resources.isEmpty();
                for (String r : resources) {
                    if (!PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r) && !PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r)) {
                        onlyMedia = false;
                    }
                }
                if (!onlyMedia || !isOwnUrl(request.getOrigin())) {
                    request.deny();
                    return;
                }
                List<String> missing = new ArrayList<>();
                if (resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE) && !has(Manifest.permission.RECORD_AUDIO)) {
                    missing.add(Manifest.permission.RECORD_AUDIO);
                }
                if (resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE) && !has(Manifest.permission.CAMERA)) {
                    missing.add(Manifest.permission.CAMERA);
                }
                if (missing.isEmpty()) {
                    grantAllowed(request);
                    return;
                }
                if (pendingMediaRequest != null) {
                    pendingMediaRequest.deny();
                }
                pendingMediaRequest = request;
                mediaPermission.launch(missing.toArray(new String[0]));
            });
        }

        @Override
        public void onPermissionRequestCanceled(PermissionRequest request) {
            if (request == pendingMediaRequest) {
                pendingMediaRequest = null;
            }
        }

        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
            if (fileCallback != null) {
                fileCallback.onReceiveValue(null);
            }
            fileCallback = callback;
            // گزینه «عکس/فیلم گرفتن» بدون اجازه دوربین ممکن نیست (CAMERA در مانیفست اعلام شده)
            if (!has(Manifest.permission.CAMERA)) {
                pendingChooser = params;
                cameraForChooser.launch(Manifest.permission.CAMERA);
                return true;
            }
            if (!openChooser(params, true)) {
                fileCallback = null;
                return false;
            }
            return true;
        }
    }

    private boolean has(String permission) {
        return ContextCompat.checkSelfPermission(this, permission) == PackageManager.PERMISSION_GRANTED;
    }

    /** فقط منابعی که اندروید اجازه‌شان را دارد به صفحه داده می‌شود */
    private void grantAllowed(PermissionRequest request) {
        List<String> allowed = new ArrayList<>();
        for (String r : request.getResources()) {
            if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r) && has(Manifest.permission.RECORD_AUDIO)) {
                allowed.add(r);
            } else if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r) && has(Manifest.permission.CAMERA)) {
                allowed.add(r);
            }
        }
        if (allowed.isEmpty()) {
            request.deny();
        } else {
            request.grant(allowed.toArray(new String[0]));
        }
    }

    /** انتخاب عکس/ویدیو از گالری، و اگر اجازه دوربین هست گزینه‌های «عکس گرفتن» و «فیلم گرفتن» */
    private boolean openChooser(WebChromeClient.FileChooserParams params, boolean camera) {
        String accept = String.join(",", params.getAcceptTypes()).toLowerCase(java.util.Locale.ROOT);
        boolean wantsImage = accept.isEmpty() || accept.contains("image") || accept.contains("*");
        boolean wantsVideo = accept.isEmpty() || accept.contains("video") || accept.contains("*");

        cameraUri = null;
        videoUri = null;
        List<Intent> extra = new ArrayList<>();
        if (camera && wantsImage) {
            cameraUri = newCaptureUri("photo_", ".jpg");
            if (cameraUri != null) {
                extra.add(captureIntent(MediaStore.ACTION_IMAGE_CAPTURE, cameraUri));
            }
        }
        if (camera && wantsVideo) {
            videoUri = newCaptureUri("video_", ".mp4");
            if (videoUri != null) {
                extra.add(captureIntent(MediaStore.ACTION_VIDEO_CAPTURE, videoUri));
            }
        }

        Intent launch;
        if (params.isCaptureEnabled() && extra.size() == 1) {
            // <input capture> : مستقیم دوربین باز شود (دکمه «ضبط استوری»)
            launch = extra.get(0);
        } else {
            Intent pick = params.createIntent();
            pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, params.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE);
            launch = Intent.createChooser(pick, getString(R.string.choose_file));
            launch.putExtra(Intent.EXTRA_INITIAL_INTENTS, extra.toArray(new Intent[0]));
        }
        try {
            filePicker.launch(launch);
            return true;
        } catch (ActivityNotFoundException | SecurityException e) {
            return false;
        }
    }

    /** ساخت فایل موقت در کش و آدرس امن FileProvider برای ذخیره خروجی دوربین */
    private Uri newCaptureUri(String prefix, String suffix) {
        try {
            File dir = new File(getCacheDir(), "camera");
            //noinspection ResultOfMethodCallIgnored
            dir.mkdirs();
            File file = File.createTempFile(prefix, suffix, dir);
            return FileProvider.getUriForFile(this, getPackageName() + ".files", file);
        } catch (IOException | IllegalArgumentException e) {
            return null;
        }
    }

    private static Intent captureIntent(String action, Uri output) {
        Intent intent = new Intent(action);
        intent.putExtra(MediaStore.EXTRA_OUTPUT, output);
        intent.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION | Intent.FLAG_GRANT_READ_URI_PERMISSION);
        return intent;
    }

    /** آیا اپ دوربین چیزی در این فایل نوشته است؟ */
    private boolean hasContent(Uri uri) {
        if (uri == null) {
            return false;
        }
        try (android.os.ParcelFileDescriptor fd = getContentResolver().openFileDescriptor(uri, "r")) {
            return fd != null && fd.getStatSize() > 0;
        } catch (IOException | RuntimeException e) {
            return false;
        }
    }

    private void onFilePicked(ActivityResult result) {
        if (fileCallback == null) {
            return;
        }
        Uri[] uris = null;
        Intent data = result.getData();
        if (result.getResultCode() == RESULT_OK) {
            if (data != null && data.getClipData() != null) {
                int count = data.getClipData().getItemCount();
                uris = new Uri[count];
                for (int i = 0; i < count; i++) {
                    uris[i] = data.getClipData().getItemAt(i).getUri();
                }
            } else if (data != null && data.getData() != null) {
                uris = new Uri[]{data.getData()};
            } else if (hasContent(videoUri)) {
                uris = new Uri[]{videoUri};
            } else if (hasContent(cameraUri)) {
                uris = new Uri[]{cameraUri};
            }
        }
        fileCallback.onReceiveValue(uris);
        fileCallback = null;
    }
}
