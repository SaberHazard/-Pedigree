# اپ اندروید شجره‌نامه (Java)

این اپ یک «پوسته بومی» (WebView) است که همان وب‌اپ سایت را با همان ظاهر نمایش می‌دهد؛
بنابراین هر تغییری در سایت بلافاصله در اپ هم دیده می‌شود و نیازی به انتشار نسخه جدید نیست.

قابلیت‌های بومی (از طریق `NativeBridge.java` ↔ `web/public/assets/js/core/native.js`):

| قابلیت | توضیح |
|---|---|
| ذخیره فایل | خروجی PDF، تصویر، SVG و GEDCOM در `Download/Pedigree` ذخیره و با برنامه مناسب باز می‌شود |
| چاپ | چاپ با سرویس چاپ اندروید (امکان «ذخیره به PDF») |
| اشتراک | اشتراک لینک پروفایل با برنامه‌های دیگر |
| دوربین و گالری | آپلود عکس/ویدیو و عکس پروفایل با گرفتن عکس یا انتخاب از گالری |
| لینک‌های سایت | باز شدن لینک‌های دامنه سایت (مثلاً از اعلان یا پیامک) داخل اپ |
| صفحه قطع اینترنت | نمایش پیام و دکمه تلاش دوباره |

## ساخت

1. پوشه `mobile/android` را در **Android Studio** (نسخه Ladybug یا جدیدتر) باز کنید.
   اگر فایل `gradlew` وجود ندارد، Android Studio آن را خودکار می‌سازد (یا دستور `gradle wrapper` را اجرا کنید).
2. در `app/build.gradle` مقدار `BASE_URL` را به آدرس سایت خود تغییر دهید (حتماً با HTTPS و `/` در انتها).
3. در `AndroidManifest.xml` مقدار `android:host="example.com"` را به دامنه خود تغییر دهید.
4. نسخه توسعه به آدرس `http://10.0.2.2:8000` (سرور محلی روی کامپیوتر) وصل می‌شود:
   ```bash
   cd web && php artisan serve --host=0.0.0.0 --port=8000
   ```
   و در `.env` مقدار `SANCTUM_STATEFUL_DOMAINS` را `10.0.2.2:8000` هم اضافه کنید.
5. ساخت نسخه نهایی: `Build > Generate Signed App Bundle / APK`.

## پوش‌نوتیفیکیشن (اختیاری)

سرور از قبل آماده ارسال اعلان با Firebase است (`FCM_ENABLED=true` در `.env`). برای فعال‌سازی در اپ:

1. پروژه Firebase بسازید و `google-services.json` را در پوشه `app/` قرار دهید.
2. پلاگین `com.google.gms.google-services` و وابستگی `firebase-messaging` (در `app/build.gradle` به صورت کامنت) را فعال کنید.
3. یک `FirebaseMessagingService` بسازید و در `onNewToken` توکن را به صفحه بدهید:
   ```java
   webView.evaluateJavascript("window.pedigreeRegisterDevice && window.pedigreeRegisterDevice('android', '" + token + "')", null);
   ```
   وب‌اپ توکن را با `POST /api/account/devices` در سرور ثبت می‌کند.

## ساخت صفحات بومی در آینده

همه داده‌ها از REST API در دسترس است (مستندات: `docs/API.md`). برای ورود بدون WebView:
`POST /api/auth/otp` و سپس `POST /api/auth/otp/verify` با `device_name` → توکن Bearer برمی‌گردد.
