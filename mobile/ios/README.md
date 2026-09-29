# اپ iOS شجره‌نامه (Swift)

پوسته بومی `WKWebView` که همان وب‌اپ سایت را با همان ظاهر نمایش می‌دهد و قابلیت‌های بومی را
از طریق `window.webkit.messageHandlers.pedigree` فراهم می‌کند:

| پیام | کار |
|---|---|
| `{action: 'saveFile', name, mime, data}` | ذخیره/اشتراک خروجی PDF، تصویر و GEDCOM با برگه اشتراک iOS (ذخیره در Files، ارسال، چاپ) |
| `{action: 'print'}` | چاپ با `UIPrintInteractionController` (امکان ذخیره PDF) |
| `{action: 'share', title, url}` | اشتراک لینک |

آپلود عکس/ویدیو و عکس گرفتن با دوربین را WKWebView خودش پشتیبانی می‌کند. میکروفون (پیام صوتی و گفتگوی صوتی با هوش مصنوعی)
با توضیح فارسی `NSMicrophoneUsageDescription` درخواست می‌شود.

آیکن اپ (`Assets.xcassets/AppIcon.appiconset/icon-1024.png`) و صفحه شروع (رنگ `LaunchBackground` روشن/تیره و تصویر `LaunchLogo`)
همان لوگو و رنگ‌های ملایم سایت‌اند؛ رنگ‌های بومی در `Theme.swift`.

## ساخت

پیش‌نیاز: macOS با Xcode 15 یا جدیدتر.

```bash
brew install xcodegen
cd mobile/ios
xcodegen generate        # ساخت Pedigree.xcodeproj از روی project.yml
open Pedigree.xcodeproj
```

1. در `project.yml` مقدار `PEDIGREE_BASE_URL` را به آدرس سایت خود تغییر دهید (حتماً HTTPS) و دوباره `xcodegen generate` بزنید.
2. در Xcode، تیم توسعه (Signing & Capabilities) را انتخاب کنید.
3. نسخه Debug به `http://localhost:8000` (سرور محلی روی همان مک) وصل می‌شود.
4. برای باز شدن لینک‌های سایت داخل اپ (Universal Links)، قابلیت Associated Domains با مقدار
   `applinks:your-domain.com` را اضافه و فایل `apple-app-site-association` را روی سرور قرار دهید.

> اگر نمی‌خواهید از XcodeGen استفاده کنید: در Xcode یک پروژه App جدید (UIKit، Swift) بسازید،
> فایل‌های پوشه `Pedigree/` را به آن اضافه کنید و `Info.plist` همین پوشه را در تنظیمات هدف انتخاب کنید.

## پوش‌نوتیفیکیشن (اختیاری)

با Firebase Messaging: پس از دریافت توکن در اپ، آن را به صفحه بدهید:

```swift
webView.evaluateJavaScript("window.pedigreeRegisterDevice && window.pedigreeRegisterDevice('ios', '\(token)')")
```

سرور با `FCM_ENABLED=true` اعلان‌ها (رأی‌گیری، تأیید عکس، ویرایش پروفایل و ...) را ارسال می‌کند.
