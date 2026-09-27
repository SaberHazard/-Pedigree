# راهنمای نصب و راه‌اندازی روی سرور

## پیش‌نیازها

| مورد | نسخه/توضیح |
|---|---|
| PHP | 8.3 یا جدیدتر |
| افزونه‌های PHP | `pdo_mysql` (یا `pdo_pgsql`)، `mbstring`، `openssl`، `fileinfo`، `gd` یا `imagick` (با پشتیبانی WebP)، `curl`، `zip`، `bcmath` (اختیاری) |
| پایگاه داده | MySQL 8+ یا MariaDB 10.6+ (پیشنهادی)؛ PostgreSQL 14+ هم پشتیبانی می‌شود |
| Composer | 2.x |
| ffmpeg | اختیاری ولی پیشنهادی (فشرده‌سازی ویدیو و ساخت پوستر) |
| HTTPS | الزامی (برای کوکی امن و اپ موبایل) |

> وب‌اپ به Node.js و مرحله build نیاز ندارد؛ فایل‌های JS و CSS مستقیماً از `public/assets` سرو می‌شوند.

## ۱. دریافت کد و نصب وابستگی‌ها

```bash
git clone <repo> pedigree && cd pedigree/web
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

## ۲. پایگاه داده

```sql
CREATE DATABASE pedigree CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pedigree'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON pedigree.* TO 'pedigree'@'localhost';
```

مقادیر `DB_*` را در `.env` تنظیم کنید، سپس:

```bash
php artisan migrate --force
```

## ۳. تنظیمات مهم `.env`

```ini
APP_URL=https://your-domain.com
APP_DEBUG=false
SANCTUM_STATEFUL_DOMAINS=your-domain.com,www.your-domain.com
SESSION_SECURE_COOKIE=true
# یک رشته تصادفی ۶۴ کاراکتری؛ بعد از راه‌اندازی هرگز عوضش نکنید
PEDIGREE_BLIND_INDEX_KEY=...
SMS_DRIVER=kavenegar
KAVENEGAR_API_KEY=...
KAVENEGAR_OTP_TEMPLATE=verify
```

ساخت کلید ایندکس: `php -r "echo bin2hex(random_bytes(32));"`

> ⚠️ از `APP_KEY` و `PEDIGREE_BLIND_INDEX_KEY` نسخه پشتیبان امن نگه دارید.
> کد ملی، شماره شناسنامه و موبایل با این کلیدها رمزنگاری شده‌اند و بدون آن‌ها قابل بازیابی نیستند.

## ۴. ساخت مدیر کل

```bash
php artisan pedigree:install
```

مدیر کل خودش هم یک شخص در شجره‌نامه است؛ پس از ورود، پدر، مادر و بقیه را از روی درخت اضافه کنید.

## ۵. پوشه‌ها و دسترسی‌ها

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

فقط پوشه `web/public` باید ریشه وب (Document Root) باشد. عکس‌ها و ویدیوها در
`storage/app/private/media` ذخیره می‌شوند و مستقیم در دسترس نیستند (فقط با لینک امضاشده).

## ۶. زمان‌بند (cron) — الزامی

```cron
* * * * * cd /path/to/pedigree/web && php artisan schedule:run >> /dev/null 2>&1
```

این یک خط این کارها را انجام می‌دهد: پردازش صف (فشرده‌سازی ویدیو)، جمع‌بندی رأی‌گیری‌های منقضی،
پاک‌سازی کدهای پیامکی و توکن‌های قدیمی.

اگر روی VPS هستید و supervisor دارید، بهتر است صف را جداگانه اجرا کنید و `PEDIGREE_QUEUE_VIA_SCHEDULER=false` بگذارید:

```ini
[program:pedigree-queue]
command=php /path/to/pedigree/web/artisan queue:work --sleep=3 --tries=2 --timeout=1800
user=www-data
autostart=true
autorestart=true
```

## ۷. تنظیمات PHP برای آپلود ویدیو

در `php.ini` (یا تنظیمات هاست):

```ini
upload_max_filesize = 512M
post_max_size = 520M
memory_limit = 512M
max_execution_time = 300
```

## ۸. نمونه تنظیم Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /path/to/pedigree/web/public;
    index index.php;

    client_max_body_size 520M;
    gzip on;
    gzip_types application/json application/javascript text/css image/svg+xml;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

روی هاست اشتراکی (cPanel/DirectAdmin) فایل `public/.htaccess` از قبل آماده است؛ فقط Document Root را روی `web/public` بگذارید.

## ۹. پشتیبان‌گیری

- پایگاه داده: `mysqldump --single-transaction pedigree > backup.sql` (روزانه)
- فایل‌ها: پوشه `web/storage/app/private/media`
- فایل `.env` (به‌خصوص `APP_KEY` و `PEDIGREE_BLIND_INDEX_KEY`)
- خروجی GEDCOM کل شجره‌نامه از پنل مدیریت (قابل باز شدن در هر نرم‌افزار شجره‌نامه)

## ۱۰. ذخیره رسانه در فضای ابری (اختیاری)

برای ذخیره عکس و ویدیو در فضای ابری سازگار با S3 (مثل ابرآروان):

```ini
PEDIGREE_MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_BUCKET=pedigree-media
AWS_ENDPOINT=https://s3.ir-thr-at1.arvanstorage.ir
AWS_USE_PATH_STYLE_ENDPOINT=true
```

و بسته `composer require league/flysystem-aws-s3-v3` را نصب کنید. باکت باید **خصوصی** باشد.

## ۱۱. به‌روزرسانی

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

فایل‌های جاوااسکریپت نسخه‌گذاری خودکار دارند (بر اساس زمان تغییر فایل)، پس کاربران بلافاصله نسخه جدید را می‌گیرند.
