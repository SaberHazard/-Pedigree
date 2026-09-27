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

            Intent pick = params.createIntent();
            pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE);

            // گزینه عکس گرفتن با دوربین
            List<Intent> extra = new ArrayList<>();
            try {
                File dir = new File(getCacheDir(), "camera");
                //noinspection ResultOfMethodCallIgnored
                dir.mkdirs();
                File photo = File.createTempFile("photo_", ".jpg", dir);
                cameraUri = FileProvider.getUriForFile(MainActivity.this, getPackageName() + ".files", photo);
                Intent camera = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
                camera.putExtra(MediaStore.EXTRA_OUTPUT, cameraUri);
                camera.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
                extra.add(camera);
            } catch (IOException e) {
                cameraUri = null;
            }

            Intent chooser = Intent.createChooser(pick, getString(R.string.choose_file));
            chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, extra.toArray(new Intent[0]));
            try {
                filePicker.launch(chooser);
            } catch (ActivityNotFoundException e) {
                fileCallback = null;
                return false;
            }
            return true;
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
            } else if (cameraUri != null) {
                uris = new Uri[]{cameraUri};
            }
        }
        fileCallback.onReceiveValue(uris);
        fileCallback = null;
    }
}
