import Foundation
import UserNotifications

/// هشدارهای محلی iOS: فهرست زنگ‌های آینده (از سایت) به خود گوشی سپرده می‌شود تا سر ثانیه، حتی با اپ بسته و بدون
/// اینترنت، با صدا اعلان بدهد. iOS حداکثر ۶۴ اعلان زمان‌دار نگه می‌دارد؛ ۶۰ مورد نزدیک‌تر تنظیم می‌شود.
///
/// امنیت: فقط پیام صفحات سایت خودمان (بررسی در WebViewController)، کلید و لینک با الگوی سخت‌گیرانه، متن کوتاه‌شده،
/// حداکثر ۶۲ روز آینده.
enum LocalAlarms {
    static let prefix = "pedigree-alarm-"
    private static let maxItems = 60
    private static let maxAhead: TimeInterval = 62 * 24 * 3600

    static func replace(with items: [[String: Any]]) {
        let center = UNUserNotificationCenter.current()
        center.requestAuthorization(options: [.alert, .sound, .badge]) { granted, _ in
            center.getPendingNotificationRequests { pending in
                let old = pending.map(\.identifier).filter { $0.hasPrefix(prefix) }
                center.removePendingNotificationRequests(withIdentifiers: old)
                guard granted else { return }
                let now = Date().timeIntervalSince1970
                var count = 0
                for item in items where count < maxItems {
                    guard let key = item["key"] as? String, isValidKey(key),
                          let atMs = (item["at"] as? NSNumber)?.doubleValue else { continue }
                    let at = atMs / 1000
                    guard at > now + 1, at < now + maxAhead else { continue }
                    let content = UNMutableNotificationContent()
                    content.title = shorten(item["title"] as? String ?? "", 120)
                    content.body = shorten(item["body"] as? String ?? "", 400)
                    content.sound = .default
                    content.userInfo = ["link": validLink(item["link"] as? String)]
                    content.interruptionLevel = .timeSensitive
                    let trigger = UNTimeIntervalNotificationTrigger(timeInterval: at - now, repeats: false)
                    center.add(UNNotificationRequest(identifier: prefix + key, content: content, trigger: trigger))
                    count += 1
                }
            }
        }
    }

    /// لینک داخل سایت (مثل «#/calendar?date=1405-01-06»)؛ هر چیز دیگر ← صفحه تقویم
    static func validLink(_ link: String?) -> String {
        guard let link, link.range(of: "^#/[A-Za-z0-9/?=&._@%-]{0,200}$", options: .regularExpression) != nil else {
            return "#/calendar"
        }
        return link
    }

    private static func isValidKey(_ key: String) -> Bool {
        key.range(of: "^[A-Za-z0-9-]{1,64}$", options: .regularExpression) != nil
    }

    private static func shorten(_ text: String, _ max: Int) -> String {
        let clean = text.replacingOccurrences(of: "[\\p{Cc}&&[^\\n]]", with: " ", options: .regularExpression)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return String(clean.prefix(max))
    }
}
