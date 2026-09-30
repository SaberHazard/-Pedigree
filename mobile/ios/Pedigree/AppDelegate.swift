import UIKit
import UserNotifications

/// نقطه شروع اپ iOS شجره‌نامه
@main
final class AppDelegate: UIResponder, UIApplicationDelegate {
    var window: UIWindow?

    func application(_ application: UIApplication,
                     didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        let window = UIWindow(frame: UIScreen.main.bounds)
        window.rootViewController = WebViewController()
        window.makeKeyAndVisible()
        self.window = window
        UNUserNotificationCenter.current().delegate = self
        return true
    }

    /// باز شدن لینک‌های سایت (Universal Links) داخل اپ
    func application(_ application: UIApplication,
                     continue userActivity: NSUserActivity,
                     restorationHandler: @escaping ([UIUserActivityRestoring]?) -> Void) -> Bool {
        guard userActivity.activityType == NSUserActivityTypeBrowsingWeb,
              let url = userActivity.webpageURL,
              let controller = window?.rootViewController as? WebViewController else { return false }
        controller.open(url)
        return true
    }
}

// MARK: - هشدارهای محلی

extension AppDelegate: UNUserNotificationCenterDelegate {
    /// اپ باز است: اعلان هشدار با صدا نمایش داده شود
    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification,
                                withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void) {
        completionHandler([.banner, .list, .sound])
    }

    /// لمس اعلان: همان روز در تقویم سایت باز شود
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse,
                                withCompletionHandler completionHandler: @escaping () -> Void) {
        defer { completionHandler() }
        guard response.notification.request.identifier.hasPrefix(LocalAlarms.prefix),
              let controller = window?.rootViewController as? WebViewController else { return }
        let link = LocalAlarms.validLink(response.notification.request.content.userInfo["link"] as? String)
        if let url = URL(string: Config.baseURL.absoluteString + link) {
            controller.open(url)
        }
    }
}
