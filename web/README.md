# web — بک‌اند Laravel و وب‌اپ

راهنمای کامل پروژه در [README اصلی](../README.md) است. این فایل خلاصه‌ای برای توسعه‌دهنده است.

## دستورات مفید

```bash
php artisan serve                          # اجرای محلی
php artisan test                           # اجرای تست‌ها
./vendor/bin/pint                          # مرتب‌سازی کد PHP
php artisan db:seed --class=DemoSeeder     # داده نمونه
php artisan pedigree:install               # ساخت مدیر کل
php artisan pedigree:resolve-votes         # جمع‌بندی رأی‌گیری‌های منقضی (خودکار با زمان‌بند)
php artisan pedigree:prune                 # پاک‌سازی داده‌های موقت (خودکار با زمان‌بند)
php artisan queue:work                     # پردازش صف (فشرده‌سازی ویدیو)
```

## جریان یک درخواست

```
routes/api.php → Controller (اعتبارسنجی، Gate/Policy) → Service (منطق) → Model → Resource/Presenter (JSON)
```

- دسترسی‌ها: `app/Policies/*` → `app/Services/Access/PersonAccess.php`
- هر تغییر مهم با `AuditLogger` ثبت می‌شود.
- خطاهای قابل نمایش به کاربر با `App\Exceptions\DomainException` (پیام فارسی، کد 422/403/429).

## فرانت‌اند

فایل‌ها در `public/assets` هستند و بدون build اجرا می‌شوند. `SpaController` یک import map
با نسخه (زمان تغییر فایل) می‌سازد تا کش مرورگر بعد از هر تغییر به‌روز شود.
صفحه ورودی: `resources/views/app.blade.php` ← `public/assets/js/main.js`.
