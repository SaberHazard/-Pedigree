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

> 💡 کلیدهای پنل پیامکی، Firebase، شبکه‌های اجتماعی، نقشه، **دستیار هوش مصنوعی** و قوانین عکس و ویدیو را می‌توانید
> به جای `.env` بعداً از **پنل مدیریت ← تنظیمات و اتصال‌ها (API)** وارد کنید (رمزنگاری‌شده در پایگاه داده). فقط `APP_KEY`،
> `PEDIGREE_BLIND_INDEX_KEY` و تنظیمات پایگاه داده باید در `.env` باشند.

> 🤖 **دستیار هوش مصنوعی**: رایگان‌ترین راه، کلید Google Gemini از [AI Studio](https://aistudio.google.com/apikey) است
> (یا OpenRouter با مدل‌های «‎:free» یا Groq). این سرویس‌ها به IP ایران پاسخ نمی‌دهند؛ اگر سرور در ایران است،
> یک پراکسی خروجی (مثلاً `socks5h://127.0.0.1:1080`) در همان کارت تنظیم کنید یا «سرویس سازگار با OpenAI» را با یک
> درگاه داخلی پر کنید. دکمه «آزمایش دستیار» اتصال را می‌سنجد.

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
(اعلان تولدها و پیامک‌های تبریک خودکار، جمع‌بندی رأی‌ها، پاک‌سازی و صف همه با همین cron اجرا می‌شوند.)

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

ویدیوها با ffmpeg مثل تلگرام فشرده می‌شوند (ضلع کوچک‌تر ۷۲۰، حداکثر ۳۰ فریم، H.264 با CRF ۲۶ و سقف بیت‌ریت، صدای ۹۶ کیلوبیت)
و فایل اصلی پس از تبدیل پاک می‌شود؛ پس `ffmpeg` را نصب کنید (`apt install ffmpeg`). اگر سقف حجم ویدیو را در پنل
مدیریت بالا می‌برید، `upload_max_filesize`/`post_max_size` و `client_max_body_size` در Nginx را هم هم‌اندازه کنید.

در `php.ini` (یا تنظیمات هاست):

```ini
upload_max_filesize = 512M
post_max_size = 520M
memory_limit = 512M
max_execution_time = 300
```

## ۸. نمونه تنظیم Nginx (سخت‌شده)

```nginx
# محدودیت نرخ درخواست به ازای هر IP (جلوی حمله‌های پرتکرار و ربات‌ها)
limit_req_zone  $binary_remote_addr zone=pedigree_api:20m rate=10r/s;
limit_req_zone  $binary_remote_addr zone=pedigree_auth:10m rate=10r/m;
limit_conn_zone $binary_remote_addr zone=pedigree_conn:10m;

server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /path/to/pedigree/web/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;

    server_tokens off;
    limit_conn pedigree_conn 40;
    # جلوی حمله «اتصال کند» (Slowloris)
    client_header_timeout 15s;
    client_body_timeout 60s;
    send_timeout 60s;
    keepalive_timeout 30s;
    client_max_body_size 2m;

    gzip on;
    gzip_types application/json application/javascript text/css image/svg+xml;

    # آپلود عکس و ویدیو (فقط این مسیرها فایل بزرگ می‌پذیرند)
    location ~ ^/api/persons/[^/]+/(media|avatar)$ {
        client_max_body_size 520m;
        client_body_timeout 300s;
        limit_req zone=pedigree_api burst=10 nodelay;
        try_files $uri /index.php?$query_string;
    }

    # ورود و درخواست پیامک
    location ~ ^/api/auth/ {
        limit_req zone=pedigree_auth burst=10 nodelay;
        try_files $uri /index.php?$query_string;
    }

    location /api/ {
        limit_req zone=pedigree_api burst=40 nodelay;
        try_files $uri /index.php?$query_string;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # فقط index.php اجرا شود (هیچ فایل PHP دیگری در public اجرا نمی‌شود)
    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ \.php$ { return 404; }

    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location ~ /\.(?!well-known) { deny all; }
    limit_req_status 429;
}
```

روی هاست اشتراکی (cPanel/DirectAdmin) فایل `public/.htaccess` از قبل آماده و سخت‌شده است؛ Document Root را روی `web/public` بگذارید.
اگر اشتباهاً روی `web` گذاشته شود، فایل `web/.htaccess` دسترسی به `.env` و پوشه‌های داخلی را می‌بندد.

## ۸-۱. چک‌لیست امنیت و پایداری (پیش از انتشار عمومی)

| مورد | کار |
|---|---|
| HTTPS | گواهی Let's Encrypt (`certbot --nginx`) و `SESSION_SECURE_COOKIE=true` |
| حالت تولید | `APP_ENV=production`، `APP_DEBUG=false`، `LOG_LEVEL=warning` |
| کلیدها | `APP_KEY` و `PEDIGREE_BLIND_INDEX_KEY` را جای امن (خارج از سرور) پشتیبان بگیرید؛ بدون آن‌ها کد ملی، موبایل و نشانی‌ها قابل بازیابی نیستند |
| محافظ حمله DDoS | سایت را پشت CDN با محافظ (ابرآروان، Cloudflare) بگذارید و `TRUSTED_PROXIES` را تنظیم کنید تا IP واقعی کاربران دیده شود |
| دیوار آتش | فقط پورت‌های ۲۲، ۸۰ و ۴۴۳ باز باشد (`ufw allow 22,80,443/tcp`)؛ پایگاه‌داده فقط روی `127.0.0.1` |
| SSH | ورود فقط با کلید، غیرفعال کردن ورود root با رمز |
| fail2ban | مسدود کردن IPهایی که مدام خطای 429/401 می‌گیرند (فیلتر nginx-limit-req) |
| به‌روزرسانی سیستم | `unattended-upgrades` برای وصله‌های امنیتی خودکار سیستم‌عامل، PHP و ffmpeg |
| به‌روزرسانی برنامه | هشدارهای Dependabot گیت‌هاب را دنبال کنید؛ `composer audit` ماهانه |
| PHP | `expose_php=Off`، OPcache روشن، `php artisan optimize` پس از هر به‌روزرسانی |
| پشتیبان | پشتیبان روزانه رمزنگاری‌شده پایگاه‌داده و پوشه رسانه در جای دیگر؛ هر چند ماه یک بار بازیابی را امتحان کنید |
| پایش | سرویس پایش (UptimeRobot و ...) روی آدرس `https://your-domain.com/up`؛ هشدار پر شدن دیسک |
| صف | `queue:work` با supervisor (یا cron طبق بخش ۶) تا تبدیل ویدیو سایت را کند نکند |
| پیامک | سقف روزانه کل پیامک‌ها `PEDIGREE_OTP_GLOBAL_DAILY` را متناسب با تعداد اعضا تنظیم کنید |
| شبکه‌های اجتماعی | اگر سرور در ایران است و تلگرام/اینستاگرام فیلتر است، `PEDIGREE_SOCIAL_PROXY` را روی یک پراکسی خروجی بگذارید (مثلاً `socks5h://127.0.0.1:1080`)؛ یا برای خاموش کردن دریافت عکس `PEDIGREE_SOCIAL_FETCH=false`. سرور فقط به دامنه‌های ثابت همان شبکه‌ها وصل می‌شود |
| اینستاگرام | اینستاگرام صفحه عمومی را معمولاً فقط به کاربرِ واردشده نشان می‌دهد؛ برای دریافت رسمی عکس حساب‌های تجاری/تولیدکننده، توکن Graph API (`PEDIGREE_INSTAGRAM_TOKEN`) و شناسه حساب تجاری خودتان (`PEDIGREE_INSTAGRAM_USER_ID`) را بگذارید. در غیر این صورت کاربر عکس را دستی آپلود می‌کند |

محدودیت‌هایی که خود برنامه اعمال می‌کند: محدودیت نرخ برای هر API، قفل ورود پس از رمز اشتباه، کپچا و سقف پیامک،
سقف ابعاد عکس (جلوگیری از «بمب فشرده‌سازی»)، اجرای ffmpeg بدون دسترسی شبکه و فقط برای قالب‌های ویدیویی،
سقف تعداد گره درخت و عمق، سقف زمان پردازش متن‌ها، و CSP سخت‌گیرانه. گزارش آسیب‌پذیری: [SECURITY.md](../SECURITY.md).

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
