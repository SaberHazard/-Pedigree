import UIKit

/// رنگ‌های ملایم اپ (هماهنگ با سایت؛ روشن و تیره)
enum Theme {
    /// سبزآبی مریمی
    static let primary = UIColor(named: "AccentColor") ?? UIColor(red: 0.169, green: 0.463, blue: 0.431, alpha: 1)
    /// متن روی رنگ اصلی (سفید در تم روشن، سبز تیره در تم تیره)
    static let onPrimary = UIColor { trait in
        trait.userInterfaceStyle == .dark ? UIColor(red: 0.024, green: 0.149, blue: 0.133, alpha: 1) : .white
    }
    /// پس‌زمینه کرم عاجی / تیره آرام
    static let background = UIColor(named: "LaunchBackground") ?? .systemBackground
}
