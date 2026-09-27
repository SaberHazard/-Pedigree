<?php

/*
|--------------------------------------------------------------------------
| تنظیمات اختصاصی نرم‌افزار شجره‌نامه (Pedigree)
|--------------------------------------------------------------------------
|
| تمام رفتارهای قابل تنظیم برنامه اینجا جمع شده است تا برای تغییر یک رفتار
| (مثلاً قانون رأی‌گیری، طول کد پیامکی یا سطح دسترسی بستگان) نیازی به
| دست‌زدن به کد نباشد. بیشتر مقادیر از فایل ‎.env‎ خوانده می‌شوند.
|
*/

return [

    // نام نمایشی سایت که در عنوان صفحات، پیامک‌ها و خروجی PDF استفاده می‌شود
    'site_name' => env('PEDIGREE_SITE_NAME', 'شجره‌نامه خانوادگی'),

    // کلید جداگانه برای «ایندکس کور» (هش قابل جستجوی کد ملی و موبایل).
    // اگر خالی بماند از APP_KEY مشتق می‌شود. بعد از راه‌اندازی هرگز عوضش نکنید.
    'blind_index_key' => env('PEDIGREE_BLIND_INDEX_KEY'),

    /*
    |--------------------------------------------------------------------------
    | ثبت‌نام و ورود
    |--------------------------------------------------------------------------
    */
    'registration' => [
        // اگر true باشد، شماره‌ای که در سیستم نیست می‌تواند بعد از تأیید پیامکی ثبت‌نام کند
        'enabled' => (bool) env('PEDIGREE_REGISTRATION', true),
        // اولین کسی که ثبت‌نام کند مدیر کل شود؟ (امن‌تر: false و استفاده از php artisan pedigree:install)
        'first_user_is_admin' => (bool) env('PEDIGREE_FIRST_USER_ADMIN', false),
    ],

    // روی هاست‌های بدون supervisor، صف پردازش (فشرده‌سازی ویدیو) هر دقیقه با cron اجرا شود
    'queue_via_scheduler' => (bool) env('PEDIGREE_QUEUE_VIA_SCHEDULER', true),

    // مهمان (کاربر واردنشده) اجازه دیدن درخت را دارد یا نه
    'guest_view' => (bool) env('PEDIGREE_GUEST_VIEW', false),

    'otp' => [
        'length' => (int) env('PEDIGREE_OTP_LENGTH', 6),
        // مدت اعتبار کد (ثانیه)
        'ttl' => (int) env('PEDIGREE_OTP_TTL', 120),
        // حداکثر دفعات اشتباه واردکردن یک کد
        'max_attempts' => 5,
        // فاصله مجاز بین دو درخواست کد برای یک شماره (ثانیه)
        'resend_cooldown' => (int) env('PEDIGREE_OTP_COOLDOWN', 60),
        // سقف تعداد پیامک برای یک شماره در ۲۴ ساعت (جلوگیری از اسپم و هدررفتن شارژ پنل)
        'daily_limit_per_phone' => (int) env('PEDIGREE_OTP_DAILY_LIMIT', 10),
        // بعد از این تعداد درخواست از یک IP در یک ساعت، کپچا اجباری می‌شود
        'captcha_after' => (int) env('PEDIGREE_OTP_CAPTCHA_AFTER', 3),
        // سقف کل درخواست‌های یک IP در یک ساعت
        'hourly_limit_per_ip' => (int) env('PEDIGREE_OTP_IP_LIMIT', 20),
    ],

    'password' => [
        'min_length' => 8,
        // تعداد تلاش ناموفق مجاز پیش از قفل موقت
        'max_attempts' => 5,
        // مدت قفل موقت (ثانیه)
        'lockout_seconds' => 900,
        // اگر کاربر در این بازه (دقیقه) با پیامک وارد شده باشد، برای تعیین رمز جدید رمز فعلی لازم نیست
        'otp_grace_minutes' => 15,
    ],

    // مدت اعتبار توکن‌های اپ موبایل (روز)
    'token_ttl_days' => (int) env('PEDIGREE_TOKEN_TTL_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | پنل پیامکی
    |--------------------------------------------------------------------------
    | درایورهای موجود: log (فقط ثبت در لاگ - برای توسعه), kavenegar, smsir,
    | melipayamak, ippanel (فراز اس‌ام‌اس/ippanel), ghasedak
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),

        'drivers' => [
            'kavenegar' => [
                'api_key' => env('KAVENEGAR_API_KEY'),
                // نام قالب «تأیید» که در پنل کاوه‌نگار ساخته‌اید (با متغیر %token)
                'template' => env('KAVENEGAR_OTP_TEMPLATE', 'verify'),
            ],
            'smsir' => [
                'api_key' => env('SMSIR_API_KEY'),
                'template_id' => env('SMSIR_OTP_TEMPLATE_ID'),
                // نام پارامتر داخل قالب sms.ir
                'parameter' => env('SMSIR_OTP_PARAMETER', 'CODE'),
            ],
            'melipayamak' => [
                'username' => env('MELIPAYAMAK_USERNAME'),
                'password' => env('MELIPAYAMAK_PASSWORD'),
                // کد متن (bodyId) الگوی خدماتی
                'body_id' => env('MELIPAYAMAK_BODY_ID'),
            ],
            'ippanel' => [
                'api_key' => env('IPPANEL_API_KEY'),
                'sender' => env('IPPANEL_SENDER', '+983000505'),
                'pattern_code' => env('IPPANEL_PATTERN_CODE'),
                'variable' => env('IPPANEL_PATTERN_VARIABLE', 'code'),
            ],
            'ghasedak' => [
                'api_key' => env('GHASEDAK_API_KEY'),
                'template' => env('GHASEDAK_OTP_TEMPLATE'),
            ],
        ],

        // مهلت انتظار برای پاسخ پنل پیامکی (ثانیه)
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | سطح دسترسی بستگان
    |--------------------------------------------------------------------------
    | self    = خود شخص
    | parent  = پدر/مادر شخص (مثلاً برای ساخت پروفایل فرزند)
    | child   = فرزندان شخص
    | sibling = خواهر و برادر (تنی یا ناتنی)
    | spouse  = همسر
    */
    'permissions' => [
        'editor_relations' => ['self', 'parent', 'child', 'sibling', 'spouse'],

        // برای شخص درگذشته، نوادگانش تا این تعداد نسل هم حق ویرایش دارند (نوه، نتیجه ...)
        'deceased_descendant_depth' => 6,

        // سازنده یک پروفایل تا وقتی صاحب پروفایل خودش وارد سیستم نشده، حق ویرایش دارد
        'creator_can_edit_unclaimed' => true,

        // اتصال دو شخص موجود (مثلاً وصل‌کردن همسر از درخت دیگر) اگر درخواست‌دهنده
        // دسترسی ویرایش هر دو طرف را نداشته باشد، به تأیید طرف مقابل نیاز دارد.
        'link_requires_approval' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | رسانه (عکس و ویدیو)
    |--------------------------------------------------------------------------
    */
    'media' => [
        'disk' => env('PEDIGREE_MEDIA_DISK', 'media'),

        'image' => [
            'max_upload_kb' => (int) env('PEDIGREE_IMAGE_MAX_KB', 20480),
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'],
            // حداکثر ابعاد نسخه اصلی ذخیره‌شده (بزرگ‌تر از این کوچک می‌شود)
            'max_dimension' => 2560,
            // کیفیت WebP؛ ۸۵ از نظر چشمی بدون افت است و حجم را چند برابر کم می‌کند
            'quality' => 85,
            'variants' => [
                'thumb' => ['size' => 320, 'crop' => true],
                'medium' => ['size' => 1280, 'crop' => false],
            ],
        ],

        'video' => [
            'enabled' => (bool) env('PEDIGREE_VIDEO_ENABLED', true),
            'max_upload_kb' => (int) env('PEDIGREE_VIDEO_MAX_KB', 512000),
            'mimes' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska', 'video/3gpp', 'video/x-msvideo'],
            // مسیر ffmpeg؛ اگر روی سرور نصب نباشد ویدیو بدون فشرده‌سازی ذخیره می‌شود
            'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
            'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),
            'max_height' => 720,
            // CRF پایین‌تر = کیفیت بیشتر و حجم بیشتر (۲۳ تا ۲۸ مناسب است)
            'crf' => 25,
            'timeout' => 1800,
        ],

        // مدت اعتبار لینک امضاشده فایل‌ها (ساعت)
        'url_ttl_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | تأیید عکس و ویدیو (رأی‌گیری)
    |--------------------------------------------------------------------------
    */
    'approval' => [
        // عکسی که مدیر آپلود کند خودکار تأیید شود
        'admin_auto_approve' => true,
        // عکسی که شخص برای پروفایل خودش آپلود کند خودکار تأیید شود
        'self_auto_approve' => true,
        // اگر صاحب پروفایل زنده است و حساب فعال دارد، فقط خودش تصمیم می‌گیرد
        'owner_decides_when_alive' => true,

        // ترتیب گروه رأی‌دهندگان؛ اولین گروهی که عضو دارای حساب داشته باشد رأی می‌دهد
        'voter_groups' => [
            'deceased' => ['children', 'siblings', 'spouses', 'parents'],
            'living' => ['parents', 'siblings', 'children', 'spouses'],
        ],

        // آستانه اکثریت: بیشتر از این نسبت از رأی‌دهندگان باید موافق باشند (۰.۵ = اکثریت مطلق)
        'threshold' => 0.5,

        // اگر هیچ رأی‌دهنده‌ای پیدا نشد: admin (تصمیم با مدیر) یا approve (تأیید خودکار)
        'fallback' => env('PEDIGREE_APPROVAL_FALLBACK', 'admin'),

        // بعد از این تعداد روز، رأی‌گیری ناتمام با آرای داده‌شده جمع‌بندی می‌شود
        'timeout_days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | درخت
    |--------------------------------------------------------------------------
    */
    'tree' => [
        'default_depth' => 4,
        'max_depth' => 30,
        // سقف تعداد اشخاص در یک پاسخ (محافظت از سرور)
        'max_nodes' => 5000,
    ],
];
