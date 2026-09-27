package ir.pedigree.app;

import android.annotation.SuppressLint;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.provider.MediaStore;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;

import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.FileProvider;

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
    private Uri cameraUri;
    private Uri videoUri;

    /** انتخاب فایل برای <input type="file"> صفحه (عکس و ویدیو) */
    private final ActivityResultLauncher<Intent> filePicker = registerForActivityResult(
            new ActivityResultContracts.StartActivityForResult(), this::onFilePicked);

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

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
        s.setSupportMultipleWindows(false);
        s.setUserAgentString(s.getUserAgentString() + " PedigreeApp/Android/" + BuildConfig.VERSION_NAME);

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptThirdPartyCookies(webView, false);

        webView.addJavascriptInterface(new NativeBridge(this, webView), "PedigreeNative");
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

    /** آیا آدرس متعلق به سایت خودمان است؟ */
    static boolean isOwnUrl(Uri uri) {
        Uri base = Uri.parse(BuildConfig.BASE_URL);
        return uri.getHost() != null && uri.getHost().equalsIgnoreCase(base.getHost());
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
            // لینک‌های بیرونی (مثلاً مستندات پنل پیامک) در مرورگر گوشی باز می‌شوند
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
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
    }

    private class ChromeClient extends WebChromeClient {
        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            progress.setVisibility(newProgress < 100 ? View.VISIBLE : View.GONE);
            progress.setProgress(newProgress);
        }

        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
            if (fileCallback != null) {
                fileCallback.onReceiveValue(null);
            }
            fileCallback = callback;

            String accept = String.join(",", params.getAcceptTypes()).toLowerCase(java.util.Locale.ROOT);
            boolean wantsImage = accept.isEmpty() || accept.contains("image") || accept.contains("*");
            boolean wantsVideo = accept.isEmpty() || accept.contains("video") || accept.contains("*");

            // گزینه‌های «عکس گرفتن» و «فیلم گرفتن» با دوربین گوشی
            cameraUri = null;
            videoUri = null;
            List<Intent> extra = new ArrayList<>();
            if (wantsImage) {
                cameraUri = newCaptureUri("photo_", ".jpg");
                if (cameraUri != null) {
                    extra.add(captureIntent(MediaStore.ACTION_IMAGE_CAPTURE, cameraUri));
                }
            }
            if (wantsVideo) {
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
                pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE);
                launch = Intent.createChooser(pick, getString(R.string.choose_file));
                launch.putExtra(Intent.EXTRA_INITIAL_INTENTS, extra.toArray(new Intent[0]));
            }
            try {
                filePicker.launch(launch);
            } catch (ActivityNotFoundException | SecurityException e) {
                fileCallback = null;
                return false;
            }
            return true;
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
