# راهنمای نصب و راه‌اندازی روی سرور

## انتخاب سرور (سرور مجازی یا هاست؟ داخل یا خارج ایران؟)

- **سرور مجازی (VPS) پیشنهاد می‌شود، نه هاست اشتراکی**: این برنامه برای فشرده‌سازی فیلم و پیام صوتی به `ffmpeg`، برای
  کارهای زمان‌دار به `cron` و صف، و برای اجرای این ابزارها به `proc_open` نیاز دارد که بیشتر هاست‌های اشتراکی ندارند یا محدود
  می‌کنند. هاست اشتراکی فقط برای آزمایش یا خاندان خیلی کوچک (بدون فیلم و پیام صوتی) مناسب است (`.htaccess` آماده است).
- **مشخصات پیشنهادی**: ۴ هسته، ۸ گیگابایت رم، دیسک NVMe حداقل ۱۶۰ گیگابایت (برای عکس و فیلم؛ یا فضای ابری S3 در بخش ۱۰)،
  Ubuntu 24.04. برای شروع با چند صد عضو، ۲ هسته و ۴ گیگابایت هم کافی است.
- **خارج از ایران** (مثلاً آلمان یا فنلاند، پینگ مناسب به ایران): دسترسی مستقیم به سرویس‌های هوش مصنوعی و شبکه‌های اجتماعی،
  بدون مشکل تحریم، و در قطعی اینترنت بین‌الملل سایت برای کاربران خارج از ایران در دسترس می‌ماند. ولی:
  - در قطعی اینترنت بین‌الملل، کاربران داخل ایران به سایت دسترسی ندارند؛
  - بعضی پنل‌های پیامکی و درگاه‌های پرداخت ایرانی IP خارجی را نمی‌پذیرند: **«پراکسی داخل ایران»** (`PEDIGREE_IRAN_PROXY`؛
    یک سرور کوچک ایرانی با پراکسی http) در پنل مدیریت تنظیم کنید؛
  - درگاه پرداخت باید دامنه سایت را تأیید کند (اینماد/ثبت دامنه در پنل درگاه).
- **داخل ایران (پیش‌فرض برنامه)**: پیامک، درگاه و تقویم رسمی بی‌دردسر و سایت در زمان «اینترنت ملی» هم برای اعضای داخل
  ایران کار می‌کند؛ هوش مصنوعی، Firebase و شبکه‌های اجتماعی از **یک پراکسی خروجی** به سرور خارج (مثلاً کوچک‌ترین سرور
  هتزنر آلمان) می‌روند: پنل مدیریت ← تنظیمات ← «محل سرور و پراکسی» (راهنمای تونل SSH امن و دکمه آزمایش همان‌جاست؛
  `PEDIGREE_SERVER_LOCATION=iran` و `PEDIGREE_FOREIGN_PROXY=socks5h://127.0.0.1:1080`).
- **سبک نگه داشتن سرور**: صدا و تصویر تماس‌ها مستقیم بین گوشی‌هاست، اوقات شرعی و زنگ هشدارها در خود مرورگر/گوشی، و عکس و
  فیلم را می‌توان در فضای ابری (بخش ۱۰) گذاشت تا دیسک و ترافیک سرور کم مصرف شود.
- **تماس صوتی زنده با هوش مصنوعی** مستقیم بین گوشی کاربر و سرویس است؛ کاربر داخل ایران (در هر حالت) برای آن VPN لازم دارد.
- در هر دو حالت سایت را پشت CDN با محافظ حمله (Cloudflare یا ابرآروان) بگذارید (بخش ۸-۱).

## پیش‌نیازها

| مورد | نسخه/توضیح |
|---|---|
| PHP | 8.3 یا جدیدتر |
| افزونه‌های PHP | `pdo_mysql` (یا `pdo_pgsql`)، `mbstring`، `openssl`، `fileinfo`، `gd` (با JPEG، PNG، WebP؛ یا `imagick`)، `curl`، `zip`، `bcmath` (اختیاری) |
| پایگاه داده | MySQL 8+ یا MariaDB 10.6+ (پیشنهادی)؛ PostgreSQL 14+ هم پشتیبانی می‌شود |
| Composer | 2.x |
| ffmpeg | بسیار پیشنهادی: فشرده‌سازی **پیام‌های صوتی** (AAC مثل تلگرام)، تبدیل همه فیلم‌ها به MP4 کم‌حجم، ساخت پوستر، بررسی فیلم هنگام آپلود و تبدیل عکس‌های TIFF، PSD، JPEG XL و JPEG 2000 (`apt install ffmpeg`) |
| heif-convert | برای عکس‌های **HEIC/HEIF آیفون** و AVIF (`apt install libheif-examples libheif-plugin-libde265`) |
| HTTPS | الزامی (برای کوکی امن، اپ موبایل، **میکروفون** برای پیام صوتی و درگاه پرداخت) |

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

این یک خط این کارها را انجام می‌دهد: **زنگ هشدارها و یادآوری مناسبت‌ها سر دقیقه** (وقت تهران)، همگام‌سازی تقویم رسمی
(هر ۱۰ دقیقه تا کامل شود)، پایان تماس‌های رهاشده و «تماس از دست رفته»، پردازش صف (فشرده‌سازی ویدیو)، جمع‌بندی رأی‌گیری‌های منقضی،
پاک‌سازی کدهای پیامکی و توکن‌های قدیمی، اعلان تولد، «سؤال روز» گروه خاندان، و ثبت «ضربان» زمان‌بند (هر ۵ دقیقه) که در
**پنل مدیریت ← نمای کلی ← سلامت سرور** نشان می‌دهد cron واقعاً اجرا می‌شود.

**ساعت سرور باید دقیق باشد** (زنگ هشدارها و ساعت همگام سایت به آن وابسته است). منطقه زمانی سرور مهم نیست (همه چیز به وقت
تهران حساب می‌شود) ولی همگام‌سازی ساعت (NTP) روشن باشد؛ «نمای کلی ← سلامت سرور» اختلاف ساعت را نشان می‌دهد:

```bash
timedatectl set-ntp true && timedatectl status
```

اگر روی VPS هستید و supervisor دارید، بهتر است صف را جداگانه اجرا کنید و `PEDIGREE_QUEUE_VIA_SCHEDULER=false` بگذارید:

```ini
[program:pedigree-queue]
command=php /path/to/pedigree/web/artisan queue:work --sleep=3 --tries=2 --timeout=1800
user=www-data
autostart=true
autorestart=true
```

## ۷. تنظیمات PHP برای آپلود ویدیو

همه فیلم‌ها (MOV آیفون، 3GP، MKV، AVI، WMV و ...) با ffmpeg مثل تلگرام به MP4 تبدیل می‌شوند (ضلع کوچک‌تر ۷۲۰، حداکثر ۳۰ فریم،
H.264 با CRF ۲۶ و preset کند، سقف بیت‌ریت، صدای ۹۶ کیلوبیت) و فایل اصلی پس از تبدیل پاک می‌شود؛ پس `ffmpeg` را نصب کنید.
همه عکس‌ها به JPEG تبدیل می‌شوند (HEIC با `heif-convert`). **سقف حجم فیلم** از پنل مدیریت (بخش «عکس و فیلم») تعیین
می‌شود؛ `upload_max_filesize`/`post_max_size` در PHP و `client_max_body_size` در Nginx را دست‌کم به همان اندازه بگذارید —
بخش «سلامت سرور» در نمای کلی پنل مدیریت اگر کمتر باشد هشدار می‌دهد. برای عکس سقفی لازم نیست (فقط سقف ایمنی ۵۰ مگابایت).

پردازنده سرور: تبدیل فیلم در صف و با ۲ هسته انجام می‌شود (`PEDIGREE_VIDEO_THREADS`) و صف سقف دارد
(`PEDIGREE_VIDEO_QUEUE_MAX`، پیش‌فرض ۳۰)؛ اگر سرور ضعیف است، در پنل «سرعت فشرده‌سازی» را روی `medium` یا `veryfast` بگذارید.

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

    # آپلود عکس و ویدیو (فقط این مسیرها فایل بزرگ می‌پذیرند؛ اندازه را هم‌اندازه سقف فیلم در پنل مدیریت بگذارید)
    location ~ ^/api/(persons/[^/]+/(media|avatar)|group/media)$ {
        client_max_body_size 520m;
        client_body_timeout 300s;
        limit_req zone=pedigree_api burst=10 nodelay;
        try_files $uri /index.php?$query_string;
    }

    # پیام صوتی و تبدیل صدا به متن (سقف پیام صوتی ۱۰ مگابایت)
    location ~ ^/api/(messages/[0-9]+/voice|group/voice|support/voice|admin/support/[0-9]+/voice|assistant/transcribe)$ {
        client_max_body_size 12m;
        client_body_timeout 120s;
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

    # اگر PHP یا سرور موقتاً در دسترس نبود: صفحه فارسی ساده به جای صفحه خطای nginx (بدون هیچ جزئیات سرور)
    error_page 502 503 504 /error.html;
    location = /error.html { internal; }
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
| درگاه پرداخت | در پنل درگاه (زرین‌پال/زیبال/Pay.ir) دامنه سایت را ثبت کنید؛ نشانی بازگشت خودکار `https://your-domain.com/donate/callback/...` است. اول با «حالت آزمایشی» (`PEDIGREE_DONATE_SANDBOX=true`) یک پرداخت امتحان کنید. اگر سرور خارج از ایران است و درگاه IP خارجی را رد می‌کند، `PEDIGREE_IRAN_PROXY` |
| پیامک از سرور خارج | اگر پنل پیامکی فقط IP ایران را می‌پذیرد: همان `PEDIGREE_IRAN_PROXY` (پیامک و درگاه از آن استفاده می‌کنند؛ هوش مصنوعی و شبکه‌های اجتماعی نه) |
| عضویت | `PEDIGREE_REQUIRE_APPROVAL=true` (پیش‌فرض): عضو تازه تا تأیید مدیر چیزی نمی‌بیند؛ مدیران اعلان می‌گیرند |
| تماس زنده هوش مصنوعی | پیش‌فرض خاموش؛ اگر روشن کردید (`PEDIGREE_AI_LIVE=gemini`) سقف روزانه هر عضو و کل سایت را متناسب با هزینه بگذارید. CSP فقط وقتی روشن است اتصال مرورگر به همان سرویس را اجازه می‌دهد |
| اینستاگرام | اینستاگرام صفحه عمومی را معمولاً فقط به کاربرِ واردشده نشان می‌دهد؛ برای دریافت رسمی عکس حساب‌های تجاری/تولیدکننده، توکن Graph API (`PEDIGREE_INSTAGRAM_TOKEN`) و شناسه حساب تجاری خودتان (`PEDIGREE_INSTAGRAM_USER_ID`) را بگذارید. در غیر این صورت کاربر عکس را دستی آپلود می‌کند |

محدودیت‌هایی که خود برنامه اعمال می‌کند: محدودیت نرخ برای هر API، قفل ورود پس از رمز اشتباه، کپچا و سقف پیامک،
سقف ابعاد عکس (جلوگیری از «بمب فشرده‌سازی»)، اجرای ffmpeg بدون دسترسی شبکه و فقط برای قالب‌های مجاز با سقف پیکسل،
بررسی فیلم با ffprobe هنگام آپلود، سقف صف تبدیل فیلم و سقف روزانه آپلود هر عضو، حالت آهسته و سقف پیام در گروه خاندان،
سقف روزانه کل سایت برای پیامک و هوش مصنوعی، سقف تعداد گره درخت و عمق، سقف زمان پردازش متن‌ها، و CSP سخت‌گیرانه.
گزارش آسیب‌پذیری: [SECURITY.md](../SECURITY.md).

## ۹. پشتیبان‌گیری

- پایگاه داده: `mysqldump --single-transaction pedigree > backup.sql` (روزانه)
- فایل‌ها: پوشه `web/storage/app/private/media`
- فایل `.env` (به‌خصوص `APP_KEY` و `PEDIGREE_BLIND_INDEX_KEY`)
- خروجی GEDCOM کل شجره‌نامه از پنل مدیریت (قابل باز شدن در هر نرم‌افزار شجره‌نامه)

## ۱۰. ذخیره رسانه در فضای ابری (اختیاری)

عکس‌ها، ویدیوها و پیام‌های صوتی می‌توانند در فضای ابری سازگار با S3 (ابرآروان، لیارا، پارس‌پک، MinIO، AWS) باشند؛ فایل
با لینک امضاشده ۳۰ دقیقه‌ای مستقیم از فضای ابری به عضو مجاز می‌رسد و دیسک و ترافیک سرور سایت مصرف نمی‌شود.

۱. یک باکت **خصوصی** بسازید و کلید دسترسی بگیرید.
۲. پنل مدیریت ← تنظیمات ← «فضای ذخیره عکس و ویدیو»: نشانی سرویس، منطقه، باکت و کلیدها ← ذخیره ← «آزمایش فضای ابری».
   (یا در `.env`: `AWS_ENDPOINT`، `AWS_DEFAULT_REGION`، `AWS_BUCKET`، `AWS_ACCESS_KEY_ID`، `AWS_SECRET_ACCESS_KEY`،
   `AWS_USE_PATH_STYLE_ENDPOINT`.)
۳. «محل ذخیره فایل‌های تازه» را روی فضای ابری بگذارید (`PEDIGREE_MEDIA_DISK=s3`).
۴. انتقال فایل‌های قبلی (بدون قطعی، قابل ادامه):

```bash
php artisan pedigree:media-move --to=s3 --limit=2000
```

برای خروجی PDF با عکس، CORS باکت را برای دامنه سایت (فقط GET/HEAD) باز کنید. نمونه ابرآروان تهران:
`AWS_ENDPOINT=https://s3.ir-thr-at1.arvanstorage.ir`، `AWS_DEFAULT_REGION=ir-thr-at1`، `AWS_USE_PATH_STYLE_ENDPOINT=false`.

## ۱۰-۱. رله تماس (TURN، اختیاری)

تماس‌های صوتی و تصویری بیشتر وقت‌ها مستقیم وصل می‌شوند. برای اینترنت‌هایی که اتصال مستقیم نمی‌دهند (بعضی اپراتورهای همراه)
یک رله coturn روی یک سرور کوچک **جدا** (تا IP سرور سایت معلوم نشود) بگذارید؛ راهنمای کامل و فیلدها در پنل ← تنظیمات ←
«تماس صوتی و تصویری» است (`PEDIGREE_TURN_URLS`، `PEDIGREE_TURN_SECRET`، `PEDIGREE_TURN_RELAY_ONLY`).

## ۱۱. به‌روزرسانی

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

فایل‌های جاوااسکریپت نسخه‌گذاری خودکار دارند (بر اساس زمان تغییر فایل)، پس کاربران بلافاصله نسخه جدید را می‌گیرند.
