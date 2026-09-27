import Foundation

/// تنظیمات اپ
enum Config {
    /// آدرس سایت شجره‌نامه (حتماً HTTPS). برای تغییر، در project.yml مقدار PEDIGREE_BASE_URL را عوض کنید
    /// یا مستقیماً همین‌جا بنویسید.
    static let baseURL: URL = {
        #if DEBUG
        // سرور محلی روی مک (php artisan serve)
        return URL(string: "http://localhost:8000/")!
        #else
        // مقدار از تنظیم PEDIGREE_BASE_URL در project.yml (از طریق Info.plist) خوانده می‌شود
        let value = Bundle.main.object(forInfoDictionaryKey: "PedigreeBaseURL") as? String
        return URL(string: value ?? "https://example.com/")!
        #endif
    }()

    /// آیا آدرس متعلق به سایت خودمان است؟
    static func isOwn(_ url: URL?) -> Bool {
        guard let host = url?.host else { return false }
        return host.caseInsensitiveCompare(baseURL.host ?? "") == .orderedSame
    }

    static var appVersion: String {
        Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
    }
}
