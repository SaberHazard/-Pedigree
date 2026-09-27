# متدهای پل جاوااسکریپت نباید در نسخه نهایی حذف یا تغییر نام داده شوند
-keepclassmembers class ir.pedigree.app.NativeBridge {
    @android.webkit.JavascriptInterface <methods>;
}
-keepattributes JavascriptInterface
