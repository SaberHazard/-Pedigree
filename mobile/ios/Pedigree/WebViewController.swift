import UIKit
import WebKit

/// صفحه اصلی اپ: همان وب‌اپ سایت در WKWebView، به همراه قابلیت‌های بومی
/// (ذخیره/اشتراک فایل، چاپ، اشتراک لینک) از طریق پیام‌های جاوااسکریپت.
///
/// در وب‌اپ: window.webkit.messageHandlers.pedigree.postMessage({action: 'saveFile' | 'print' | 'share', ...})
final class WebViewController: UIViewController {

    private var webView: WKWebView!
    private let progress = UIProgressView(progressViewStyle: .bar)
    private let offlineView = OfflineView()
    private var progressObservation: NSKeyValueObservation?

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = UIColor(named: "AccentColor")

        let contentController = WKUserContentController()
        // پیام‌ها با یک واسطه ضعیف ثبت می‌شوند تا نشت حافظه رخ ندهد
        contentController.add(WeakMessageHandler(self), name: "pedigree")

        let config = WKWebViewConfiguration()
        config.userContentController = contentController
        config.allowsInlineMediaPlayback = true
        config.mediaTypesRequiringUserActionForPlayback = []
        config.applicationNameForUserAgent = "PedigreeApp/iOS/\(Config.appVersion)"
        config.websiteDataStore = .default()

        webView = WKWebView(frame: .zero, configuration: config)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = true
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        webView.isOpaque = false
        webView.backgroundColor = .systemBackground
        #if DEBUG
        if #available(iOS 16.4, *) { webView.isInspectable = true }
        #endif

        webView.translatesAutoresizingMaskIntoConstraints = false
        progress.translatesAutoresizingMaskIntoConstraints = false
        offlineView.translatesAutoresizingMaskIntoConstraints = false
        progress.progressTintColor = UIColor(red: 0.06, green: 0.46, blue: 0.43, alpha: 1)
        offlineView.isHidden = true
        offlineView.onRetry = { [weak self] in self?.reload() }

        view.addSubview(webView)
        view.addSubview(progress)
        view.addSubview(offlineView)
        NSLayoutConstraint.activate([
            webView.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
            webView.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            webView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            progress.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
            progress.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            progress.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            offlineView.topAnchor.constraint(equalTo: view.topAnchor),
            offlineView.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            offlineView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            offlineView.trailingAnchor.constraint(equalTo: view.trailingAnchor),
        ])

        progressObservation = webView.observe(\.estimatedProgress, options: [.new]) { [weak self] web, _ in
            self?.progress.setProgress(Float(web.estimatedProgress), animated: true)
            self?.progress.isHidden = web.estimatedProgress >= 1
        }

        webView.load(URLRequest(url: Config.baseURL))
    }

    func open(_ url: URL) {
        guard Config.isOwn(url) else { return }
        webView.load(URLRequest(url: url))
    }

    private func reload() {
        offlineView.isHidden = true
        if webView.url == nil {
            webView.load(URLRequest(url: Config.baseURL))
        } else {
            webView.reload()
        }
    }

    // MARK: - قابلیت‌های بومی

    /// ذخیره فایل ارسال‌شده از صفحه (base64) و نمایش برگه اشتراک (ذخیره در Files، ارسال، چاپ ...)
    private func saveFile(name: String, base64: String) {
        guard let data = Data(base64Encoded: base64, options: .ignoreUnknownCharacters) else { return }
        let safe = name.replacingOccurrences(of: "/", with: "_")
        let url = FileManager.default.temporaryDirectory.appendingPathComponent(safe)
        do {
            try data.write(to: url, options: .atomic)
        } catch {
            return
        }
        let sheet = UIActivityViewController(activityItems: [url], applicationActivities: nil)
        sheet.popoverPresentationController?.sourceView = view
        sheet.popoverPresentationController?.sourceRect = CGRect(x: view.bounds.midX, y: view.bounds.midY, width: 1, height: 1)
        present(sheet, animated: true)
    }

    /// چاپ صفحه فعلی (نمای چاپ درخت) با امکان ذخیره PDF از پنجره چاپ
    private func printPage() {
        let controller = UIPrintInteractionController.shared
        let info = UIPrintInfo(dictionary: nil)
        info.outputType = .general
        info.jobName = "شجره‌نامه"
        info.orientation = .landscape
        controller.printInfo = info
        controller.printFormatter = webView.viewPrintFormatter()
        controller.present(animated: true)
    }

    private func share(title: String, link: String) {
        guard let url = URL(string: link) else { return }
        let sheet = UIActivityViewController(activityItems: [title, url], applicationActivities: nil)
        sheet.popoverPresentationController?.sourceView = view
        present(sheet, animated: true)
    }
}

// MARK: - پیام‌های جاوااسکریپت

extension WebViewController: WKScriptMessageHandler {
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        // امنیت: فقط پیام صفحات سایت خودمان پذیرفته می‌شود
        guard Config.isOwn(message.frameInfo.request.url),
              let body = message.body as? [String: Any],
              let action = body["action"] as? String else { return }

        switch action {
        case "saveFile":
            if let name = body["name"] as? String, let data = body["data"] as? String {
                saveFile(name: name, base64: data)
            }
        case "print":
            printPage()
        case "share":
            share(title: body["title"] as? String ?? "", link: body["url"] as? String ?? "")
        default:
            break
        }
    }
}

// MARK: - ناوبری

extension WebViewController: WKNavigationDelegate {
    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction,
                 decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        guard let url = navigationAction.request.url else { return decisionHandler(.cancel) }
        if Config.isOwn(url) || url.scheme == "about" || url.scheme == "blob" || url.scheme == "data" {
            decisionHandler(.allow)
            return
        }
        // لینک‌های بیرونی در Safari باز می‌شوند
        if ["http", "https", "tel", "mailto", "sms"].contains(url.scheme ?? "") {
            UIApplication.shared.open(url)
        }
        decisionHandler(.cancel)
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        if (error as NSError).code != NSURLErrorCancelled {
            offlineView.isHidden = false
        }
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        offlineView.isHidden = true
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
        webView.reload()
    }
}

// MARK: - پنجره‌های جاوااسکریپت

extension WebViewController: WKUIDelegate {
    func webView(_ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String,
                 initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping () -> Void) {
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "باشه", style: .default) { _ in completionHandler() })
        present(alert, animated: true)
    }

    func webView(_ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String,
                 initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (Bool) -> Void) {
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "انصراف", style: .cancel) { _ in completionHandler(false) })
        alert.addAction(UIAlertAction(title: "تأیید", style: .default) { _ in completionHandler(true) })
        present(alert, animated: true)
    }

    /// لینک‌هایی که در پنجره جدید باز می‌شوند (target=_blank، مثل اینستاگرام و مسیریابی):
    /// صفحات بیرونی در Safari/اپ مربوطه باز می‌شوند و هیچ پنجره WebView جدیدی ساخته نمی‌شود.
    func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
                 for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
        if let url = navigationAction.request.url {
            if Config.isOwn(url) {
                webView.load(URLRequest(url: url))
            } else if ["http", "https", "tel", "mailto", "sms"].contains(url.scheme ?? "") {
                UIApplication.shared.open(url)
            }
        }
        return nil
    }

    /// دوربین/میکروفون برای ضبط استوری داخل صفحه (getUserMedia).
    /// فقط سایت خود شجره‌نامه اجازه دارد؛ بقیه دامنه‌ها رد می‌شوند.
    /// سیستم‌عامل خودش بار اول از کاربر اجازه می‌گیرد (متن‌ها در Info.plist).
    @available(iOS 15.0, *)
    func webView(_ webView: WKWebView, requestMediaCapturePermissionFor origin: WKSecurityOrigin,
                 initiatedByFrame frame: WKFrameInfo, type: WKMediaCaptureType,
                 decisionHandler: @escaping (WKPermissionDecision) -> Void) {
        let trusted = frame.isMainFrame
            && origin.host.caseInsensitiveCompare(Config.baseURL.host ?? "") == .orderedSame
        decisionHandler(trusted ? .prompt : .deny)
    }
}

/// واسطه ضعیف برای جلوگیری از چرخه نگهداری (retain cycle) بین WKUserContentController و کنترلر
private final class WeakMessageHandler: NSObject, WKScriptMessageHandler {
    weak var target: WKScriptMessageHandler?

    init(_ target: WKScriptMessageHandler) {
        self.target = target
    }

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        target?.userContentController(controller, didReceive: message)
    }
}
