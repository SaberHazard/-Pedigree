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
        // کد ملی هنگام ثبت‌نام اجباری است (همراه با موبایل تأییدشده)
        'require_national_code' => (bool) env('PEDIGREE_REQUIRE_NATIONAL_CODE', true),
        // کسی که کد ملی ایرانی ندارد (مثلاً ساکن خارج یا تبعه کشور دیگر) با تیک «کد ملی ندارم» ثبت‌نام کند
        'allow_without_national_code' => (bool) env('PEDIGREE_ALLOW_NO_NATIONAL_CODE', true),
    ],

    // نام کاربری: برای سالمندانی که موبایل و کد ملی ندارند؛ مدیر یا بستگان نام کاربری و رمز تعیین می‌کنند
    'username' => [
        'min' => 3,
        'max' => 30,
        // نام‌های رزرو شده که کسی نمی‌تواند بردارد
        'reserved' => ['admin', 'administrator', 'root', 'system', 'support', 'pedigree', 'api', 'null', 'test'],
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
        // سقف کل پیامک‌های سایت در ۲۴ ساعت (محافظت از شارژ پنل در برابر حمله؛ ۰ = بدون سقف)
        'global_daily_limit' => (int) env('PEDIGREE_OTP_GLOBAL_DAILY', 2000),
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
        // پنل ارسال کد ورود
        'driver' => env('SMS_DRIVER', 'log'),
        // پنل ارسال پیامک‌های تبریک اعضا (خالی = همان پنل کد ورود)
        'message_driver' => env('SMS_MESSAGE_DRIVER', ''),

        'drivers' => [
            'kavenegar' => [
                'api_key' => env('KAVENEGAR_API_KEY'),
                // نام قالب «تأیید» که در پنل کاوه‌نگار ساخته‌اید (با متغیر %token)
                'template' => env('KAVENEGAR_OTP_TEMPLATE', 'verify'),
                // خط ارسال پیامک عادی (تبریک)؛ خالی = خط پیش‌فرض حساب
                'sender' => env('KAVENEGAR_SENDER'),
            ],
            'smsir' => [
                'api_key' => env('SMSIR_API_KEY'),
                'template_id' => env('SMSIR_OTP_TEMPLATE_ID'),
                // نام پارامتر داخل قالب sms.ir
                'parameter' => env('SMSIR_OTP_PARAMETER', 'CODE'),
                'line_number' => env('SMSIR_LINE_NUMBER'),
            ],
            'melipayamak' => [
                'username' => env('MELIPAYAMAK_USERNAME'),
                'password' => env('MELIPAYAMAK_PASSWORD'),
                // کد متن (bodyId) الگوی خدماتی
                'body_id' => env('MELIPAYAMAK_BODY_ID'),
                'from' => env('MELIPAYAMAK_FROM'),
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
                'line_number' => env('GHASEDAK_LINE_NUMBER'),
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
        // (مثلاً برای اصلاح اشتباه تایپی بلافاصله بعد از ثبت، حتی اگر بسته درجه یک نباشد)
        'creator_can_edit_unclaimed' => true,
        // این حق فقط تا چند ساعت بعد از ساخت پروفایل (۰ = بدون محدودیت).
        // پس از آن فقط بستگان درجه یک (و نوادگانِ درگذشته) ویرایش می‌کنند.
        'creator_edit_hours' => (int) env('PEDIGREE_CREATOR_EDIT_HOURS', 72),

        // تاریخچه تغییرات هر پروفایل (چه کسی چه چیزی را اضافه/حذف/ویرایش کرد) برای همه اعضا قابل دیدن باشد
        // (IP و مرورگر فقط برای مدیر نمایش داده می‌شود)
        'history_public' => (bool) env('PEDIGREE_HISTORY_PUBLIC', true),

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
            // فقط سقف ایمنی (در پنل مدیریت نیست): هر عکسی به JPEG کم‌حجم تبدیل می‌شود
            'max_upload_kb' => (int) env('PEDIGREE_IMAGE_MAX_KB', 51200),
            // سقف تعداد پیکسل (مگاپیکسل) پیش از باز کردن تصویر؛ جلوی «بمب فشرده‌سازی» را می‌گیرد
            'max_megapixels' => (int) env('PEDIGREE_IMAGE_MAX_MP', 60),
            // برای عکس‌های HEIC آیفون (بسته libheif-examples)؛ TIFF، PSD، JPEG XL و ... با ffmpeg
            'heif_convert' => env('HEIF_CONVERT_PATH', 'heif-convert'),
            // حداکثر ابعاد نسخه اصلی ذخیره‌شده (بزرگ‌تر از این کوچک می‌شود؛ مثل عکس HD تلگرام)
            'max_dimension' => 2560,
            // کیفیت JPEG پیشرونده؛ ۸۴ از نظر چشمی بدون افت است و حجم را چند برابر کم می‌کند
            'quality' => (int) env('PEDIGREE_IMAGE_QUALITY', 84),
            'variants' => [
                'thumb' => ['size' => 320, 'crop' => true],
                'medium' => ['size' => 1280, 'crop' => false],
            ],
        ],

        'video' => [
            'enabled' => (bool) env('PEDIGREE_VIDEO_ENABLED', true),
            // سقف حجم هر ویدیو (مگابایت) — از پنل مدیریت قابل تغییر
            'max_upload_mb' => (int) env('PEDIGREE_VIDEO_MAX_MB', (int) round(((int) env('PEDIGREE_VIDEO_MAX_KB', 512000)) / 1024)),
            // مسیر ffmpeg؛ اگر روی سرور نصب نباشد ویدیو بدون فشرده‌سازی ذخیره می‌شود
            'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
            'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),
            // ضلع کوچک‌تر ویدیو (۷۲۰ = کیفیت HD تلگرام)
            'max_height' => (int) env('PEDIGREE_VIDEO_MAX_SIDE', 720),
            // سقف مدت ویدیو (ثانیه)؛ بیشتر از این بریده می‌شود
            'max_duration' => (int) env('PEDIGREE_VIDEO_MAX_SECONDS', 3600),
            // تعداد هسته پردازنده برای فشرده‌سازی (تا سایت هنگام تبدیل ویدیو کند نشود)
            'threads' => (int) env('PEDIGREE_VIDEO_THREADS', 2),
            // CRF پایین‌تر = کیفیت بیشتر و حجم بیشتر (۲۳ تا ۲۸ مناسب است؛ ۲۶ شبیه تلگرام)
            'crf' => (int) env('PEDIGREE_VIDEO_CRF', 26),
            // سرعت فشرده‌سازی: slow = حجم کمتر با همان کیفیت (کندتر)، medium = متعادل، veryfast = سریع
            'preset' => env('PEDIGREE_VIDEO_PRESET', 'slow'),
            'timeout' => 7200,
            // بیشترین تعداد ویدیوی در صف تبدیل (کل سایت)؛ جلوی پر شدن پردازنده را می‌گیرد
            'queue_max' => (int) env('PEDIGREE_VIDEO_QUEUE_MAX', 30),
        ],

        // مدت اعتبار لینک امضاشده فایل‌ها (ساعت)
        'url_ttl_hours' => 24,

        // سقف آپلود هر عضو در روز (ضد سوءاستفاده)
        'daily_uploads_per_user' => (int) env('PEDIGREE_DAILY_UPLOADS', 100),
        'daily_videos_per_user' => (int) env('PEDIGREE_DAILY_VIDEOS', 20),
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
    | پروفایل کامل
    |--------------------------------------------------------------------------
    */
    'profile' => [
        // مقطع تحصیلی (به ترتیب از پایین به بالا؛ برای آمار «بالاترین مدرک» هم استفاده می‌شود)
        'education_levels' => [
            'illiterate' => 'بی‌سواد',
            'literate' => 'سواد خواندن و نوشتن (مکتب‌خانه / نهضت)',
            'primary' => 'ابتدایی',
            'middle' => 'سیکل (راهنمایی / متوسطه اول)',
            'high_school' => 'دیپلم',
            'associate' => 'کاردانی (فوق‌دیپلم)',
            'bachelor' => 'کارشناسی (لیسانس)',
            'master' => 'کارشناسی ارشد (فوق‌لیسانس)',
            'professional_doctorate' => 'دکترای حرفه‌ای (پزشکی، دندان‌پزشکی، داروسازی، دامپزشکی)',
            'phd' => 'دکترای تخصصی (PhD)',
            'specialist' => 'تخصص پزشکی',
            'subspecialist' => 'فوق‌تخصص',
            'fellowship' => 'فلوشیپ',
            'postdoc' => 'پسادکترا',
            'hawza_1' => 'حوزوی سطح ۱',
            'hawza_2' => 'حوزوی سطح ۲',
            'hawza_3' => 'حوزوی سطح ۳',
            'hawza_4' => 'حوزوی سطح ۴ (خارج / اجتهاد)',
        ],
        // گروه رشته تحصیلی؛ «engineer» یعنی دارندگان لیسانس و فوق‌لیسانس این گروه «مهندس» خوانده می‌شوند
        'education_field_groups' => [
            'engineering' => ['label' => 'فنی و مهندسی (برق، مکانیک، عمران، کامپیوتر، صنایع، شیمی، مواد، معدن، نفت، هوافضا ...)', 'engineer' => true],
            'architecture' => ['label' => 'معماری و شهرسازی', 'engineer' => true],
            'agriculture' => ['label' => 'مهندسی کشاورزی، منابع طبیعی و محیط زیست', 'engineer' => true],
            'medical' => ['label' => 'پزشکی، دندان‌پزشکی، داروسازی، دامپزشکی', 'engineer' => false],
            'nursing' => ['label' => 'پرستاری، مامایی و پیراپزشکی', 'engineer' => false],
            'science' => ['label' => 'علوم پایه (ریاضی، فیزیک، شیمی محض، زیست‌شناسی ...)', 'engineer' => false],
            'humanities' => ['label' => 'علوم انسانی (حقوق، مدیریت، اقتصاد، روان‌شناسی، ادبیات ...)', 'engineer' => false],
            'art' => ['label' => 'هنر', 'engineer' => false],
            'seminary' => ['label' => 'علوم حوزوی و الهیات', 'engineer' => false],
            'other' => ['label' => 'سایر', 'engineer' => false],
        ],
        // عنوان خودکار پیش از نام
        'honorifics' => [
            // دکترای حرفه‌ای (پزشکی ...) و بالاتر ← «دکتر»
            'doctor_levels' => ['professional_doctorate', 'phd', 'specialist', 'subspecialist', 'fellowship', 'postdoc'],
            // اعضای هیئت علمی از استادیار به بالا (دارای دکترا) ← «دکتر»
            'doctor_ranks' => ['assistant_professor', 'associate_professor', 'professor', 'distinguished_professor', 'emeritus'],
            // لیسانس و فوق‌لیسانس در رشته‌های فنی ← «مهندس»
            'engineer_levels' => ['bachelor', 'master'],
            // اگر گروه رشته انتخاب نشده باشد، فقط وقتی نام رشته صراحتاً یکی از این‌ها را داشته باشد
            'engineer_keywords' => ['مهندسی', 'engineering', 'معماری', 'شهرسازی'],
            // عنوان‌هایی که پیش از «دکتر/مهندس» می‌آیند: «حاج دکتر ...»، «شهید مهندس ...»
            'before' => ['حاج', 'حاجیه', 'حاجی', 'کربلایی', 'مشهدی', 'شهید', 'آیت‌الله', 'حجت‌الاسلام'],
        ],

        // مرتبه علمی (برای اعضای هیئت علمی)
        'academic_ranks' => [
            'instructor' => 'مربی',
            'assistant_professor' => 'استادیار',
            'associate_professor' => 'دانشیار',
            'professor' => 'استاد (پروفسور)',
            'distinguished_professor' => 'استاد ممتاز',
            'emeritus' => 'استاد بازنشسته (امریتوس)',
        ],
        'blood_types' => ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'],
        // شبکه‌های اجتماعی قابل ثبت در پروفایل (به همین ترتیب نمایش داده می‌شوند؛ سه مورد اول همیشه در فرم پیداست)
        //  kind: handle = شناسه، phone = شماره (مثل واتس‌اپ)، handle_or_phone = هر دو (تلگرام)
        //  hosts: دامنه‌های مجاز برای وقتی کاربر لینک کامل را می‌چسباند (لینک دامنه دیگر پذیرفته نمی‌شود)
        //  prefix: بخشی از مسیر لینک که پیش از شناسه می‌آید (مثلاً in/ در لینکدین)
        //  fetch: عکس پروفایل عمومی قابل دریافت خودکار است
        'social_networks' => [
            'instagram' => ['label' => 'اینستاگرام', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9._]{1,30}', 'url' => 'https://www.instagram.com/{h}/', 'hosts' => ['instagram.com', 'instagr.am'], 'fetch' => true],
            'telegram' => ['label' => 'تلگرام', 'kind' => 'handle_or_phone', 'pattern' => '[A-Za-z][A-Za-z0-9_]{3,31}', 'url' => 'https://t.me/{h}', 'hosts' => ['t.me', 'telegram.me', 'telegram.dog'], 'fetch' => true],
            'whatsapp' => ['label' => 'واتس‌اپ', 'kind' => 'phone', 'url' => 'https://wa.me/{h}', 'hosts' => ['wa.me', 'api.whatsapp.com', 'whatsapp.com']],
            'eitaa' => ['label' => 'ایتا', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_]{3,40}', 'url' => 'https://eitaa.com/{h}', 'hosts' => ['eitaa.com']],
            'bale' => ['label' => 'بله', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_]{3,40}', 'url' => 'https://ble.ir/{h}', 'hosts' => ['ble.ir', 'bale.ai']],
            'rubika' => ['label' => 'روبیکا', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_.]{3,40}', 'url' => 'https://rubika.ir/{h}', 'hosts' => ['rubika.ir']],
            'soroush' => ['label' => 'سروش پلاس', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_]{3,40}', 'url' => 'https://splus.ir/{h}', 'hosts' => ['splus.ir', 'sapp.ir']],
            'linkedin' => ['label' => 'لینکدین', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_\-]{3,100}', 'url' => 'https://www.linkedin.com/in/{h}/', 'hosts' => ['linkedin.com'], 'prefix' => 'in'],
            'x' => ['label' => 'ایکس (توییتر)', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_]{1,15}', 'url' => 'https://x.com/{h}', 'hosts' => ['x.com', 'twitter.com'], 'fetch' => true],
            'threads' => ['label' => 'تردز', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9._]{1,30}', 'url' => 'https://www.threads.net/@{h}', 'hosts' => ['threads.net', 'threads.com']],
            'tiktok' => ['label' => 'تیک‌تاک', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9._]{2,24}', 'url' => 'https://www.tiktok.com/@{h}', 'hosts' => ['tiktok.com']],
            'youtube' => ['label' => 'یوتیوب', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9._\-]{3,100}', 'url' => 'https://www.youtube.com/@{h}', 'hosts' => ['youtube.com'], 'fetch' => true],
            'aparat' => ['label' => 'آپارات', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_]{3,60}', 'url' => 'https://www.aparat.com/{h}', 'hosts' => ['aparat.com'], 'fetch' => true],
            'facebook' => ['label' => 'فیس‌بوک', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9.]{3,80}', 'url' => 'https://www.facebook.com/{h}', 'hosts' => ['facebook.com', 'fb.com']],
            'snapchat' => ['label' => 'اسنپ‌چت', 'kind' => 'handle', 'pattern' => '[A-Za-z][A-Za-z0-9._\-]{2,14}', 'url' => 'https://www.snapchat.com/add/{h}', 'hosts' => ['snapchat.com'], 'prefix' => 'add'],
            'pinterest' => ['label' => 'پینترست', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_]{3,30}', 'url' => 'https://www.pinterest.com/{h}/', 'hosts' => ['pinterest.com']],
            'github' => ['label' => 'گیت‌هاب', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9](?:[A-Za-z0-9\-]{0,38})', 'url' => 'https://github.com/{h}', 'hosts' => ['github.com'], 'fetch' => true],
            'virasty' => ['label' => 'ویراستی', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9_]{3,40}', 'url' => 'https://virasty.com/{h}', 'hosts' => ['virasty.com']],
            'bluesky' => ['label' => 'بلواسکای', 'kind' => 'handle', 'pattern' => '[A-Za-z0-9](?:[A-Za-z0-9.\-]{1,251})', 'url' => 'https://bsky.app/profile/{h}', 'hosts' => ['bsky.app'], 'prefix' => 'profile', 'fetch' => true],
        ],
        // اگر شخص در سایت عکس پروفایل ندارد، عکس کدام شبکه به ترتیب جای آن نمایش داده شود
        'social_avatar_priority' => ['instagram', 'whatsapp', 'telegram', 'github'],

        // سقف تعداد ویژگی‌های دلخواه (مثلاً «غذای محبوب: قورمه‌سبزی»)
        'max_attributes' => 40,

        // متن‌های بلند پروفایل که با رنگ هر نویسنده نمایش داده می‌شوند
        'texts' => [
            'summary' => ['label' => 'بیوگرافی (معرفی کوتاه)', 'max' => 2000],
            'description' => ['label' => 'توضیحات بستگان', 'max' => 20000],
            'biography' => ['label' => 'زندگی‌نامه کامل و خاطرات', 'max' => 60000],
            'resume' => ['label' => 'رزومه (متن آزاد)', 'max' => 30000],
        ],
        // انواع سوابق رزومه
        'resume_types' => [
            'education' => 'تحصیل',
            'work' => 'سابقه کار',
            'military' => 'خدمت سربازی',
            'award' => 'افتخار و جایزه',
            'certificate' => 'گواهی‌نامه و دوره',
            'publication' => 'کتاب و مقاله',
            'skill' => 'مهارت',
            'volunteer' => 'فعالیت داوطلبانه و خیریه',
            'travel' => 'سفر و مهاجرت',
            'other' => 'سایر',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | نقشه (محل سکونت، آرامگاه، نقشه خاندان)
    |--------------------------------------------------------------------------
    | کاشی‌های نقشه از سرویس دلخواه (پیش‌فرض OpenStreetMap). اگر آدرس را عوض
    | کنید، دامنه آن خودکار به CSP اضافه می‌شود.
    */
    /*
    |--------------------------------------------------------------------------
    | پیامک تبریک اعضا (از پنل پیامکی سایت)
    |--------------------------------------------------------------------------
    | همه این مقدارها از پنل مدیریت ← «تنظیمات و اتصال‌ها» هم قابل تغییرند.
    */
    'member_sms' => [
        // فقط اعضایی که پروفایلشان حداقل این درصد کامل است
        'min_completeness' => (int) env('PEDIGREE_MEMBER_SMS_MIN_PROFILE', 95),
        // دامنه تبریک خودکار: all | d4 | d3 | d2 | d1
        'auto_max_scope' => 'd2',
        'send_hour' => 9,
        'daily_per_user' => 10,
        'monthly_per_user' => 60,
        'daily_per_recipient' => 5,
        'global_daily' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | پیام‌رسان داخلی (فقط متن و ایموجی)
    |--------------------------------------------------------------------------
    */
    'messaging' => [
        // سقف پیام هر عضو در روز
        'daily_limit' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | گروه خاندان (زنده کردن خاطرات با عکس و فیلم قدیمی)
    |--------------------------------------------------------------------------
    */
    'group' => [
        'enabled' => (bool) env('PEDIGREE_GROUP_ENABLED', true),
        'name' => env('PEDIGREE_GROUP_NAME', 'گروه خاطرات خاندان'),
        // حداقل فاصله دو پیام یک نفر (ثانیه) و سقف‌ها (ضد اسپم و فلود)
        'slow_mode_seconds' => (int) env('PEDIGREE_GROUP_SLOW_MODE', 3),
        'per_minute' => 15,
        'daily_messages' => (int) env('PEDIGREE_GROUP_DAILY', 300),
        'daily_media' => (int) env('PEDIGREE_GROUP_DAILY_MEDIA', 20),
        'max_length' => 2000,
        // لینک در گروه (جلوگیری از تبلیغ و فیشینگ)
        'allow_links' => (bool) env('PEDIGREE_GROUP_LINKS', false),
        // «سؤال روز» برای زنده کردن خاطرات، هر روز این ساعت (وقت تهران)
        'daily_prompt' => (bool) env('PEDIGREE_GROUP_PROMPT', true),
        'prompt_hour' => (int) env('PEDIGREE_GROUP_PROMPT_HOUR', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | دستیار هوش مصنوعی
    |--------------------------------------------------------------------------
    | گفتگو بدون ذخیره در سرور؛ هیچ اطلاعاتی از شجره‌نامه برای سرویس فرستاده نمی‌شود
    | (فقط همان چیزی که کاربر در گفتگو می‌نویسد). کلیدها از پنل مدیریت هم قابل تنظیم‌اند.
    */
    'ai' => [
        'enabled' => (bool) env('PEDIGREE_AI_ENABLED', true),
        // gemini | openrouter | groq | cerebras | mistral | deepseek | openai | anthropic | xai | custom (هر سرویس سازگار با OpenAI)
        'provider' => env('PEDIGREE_AI_PROVIDER', 'gemini'),
        // اگر سرور در ایران است (این سرویس‌ها IP ایران را نمی‌پذیرند)؛ خالی = پراکسی شبکه‌های اجتماعی
        'proxy' => env('PEDIGREE_AI_PROXY'),
        'timeout' => 60,
        'max_output_tokens' => 1500,
        // سقف پیام هر عضو و کل سایت در روز
        'daily_per_user' => (int) env('PEDIGREE_AI_DAILY_PER_USER', 40),
        'global_daily' => (int) env('PEDIGREE_AI_GLOBAL_DAILY', 2000),
        'providers' => [
            'gemini' => ['api_key' => env('GEMINI_API_KEY'), 'model' => env('GEMINI_MODEL', 'gemini-flash-latest')],
            'openrouter' => ['api_key' => env('OPENROUTER_API_KEY'), 'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free')],
            'groq' => ['api_key' => env('GROQ_API_KEY'), 'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile')],
            'openai' => ['api_key' => env('OPENAI_API_KEY'), 'model' => env('OPENAI_MODEL', 'gpt-4.1-mini')],
            'anthropic' => ['api_key' => env('ANTHROPIC_API_KEY'), 'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5')],
            'deepseek' => ['api_key' => env('DEEPSEEK_API_KEY'), 'model' => env('DEEPSEEK_MODEL', 'deepseek-chat')],
            'mistral' => ['api_key' => env('MISTRAL_API_KEY'), 'model' => env('MISTRAL_MODEL', 'mistral-small-latest')],
            'cerebras' => ['api_key' => env('CEREBRAS_API_KEY'), 'model' => env('CEREBRAS_MODEL', 'llama-3.3-70b')],
            'xai' => ['api_key' => env('XAI_API_KEY'), 'model' => env('XAI_MODEL', 'grok-3-mini')],
            'custom' => ['base_url' => env('PEDIGREE_AI_BASE_URL'), 'api_key' => env('PEDIGREE_AI_API_KEY'), 'model' => env('PEDIGREE_AI_MODEL')],
        ],
        // اگر سرویس اصلی شلوغ بود یا سهمیه رایگانش تمام شد، این سرویس امتحان می‌شود (خالی = هیچ)
        'fallback_provider' => env('PEDIGREE_AI_FALLBACK', ''),
        // بازسازی و رنگی کردن عکس‌های قدیمی با مدل تصویری Gemini (کلید همان Gemini)
        'image' => [
            'enabled' => (bool) env('PEDIGREE_AI_IMAGE_ENABLED', true),
            'model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
            'daily_per_user' => (int) env('PEDIGREE_AI_IMAGE_DAILY', 5),
            'global_daily' => (int) env('PEDIGREE_AI_IMAGE_GLOBAL', 60),
            'timeout' => 120,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | اعلان تولد
    |--------------------------------------------------------------------------
    */
    'birthdays' => [
        'notify_hour' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | دریافت عکس پروفایل از شبکه‌های اجتماعی
    |--------------------------------------------------------------------------
    | سرور فقط به دامنه‌های ثابت همین شبکه‌ها وصل می‌شود (نه هر آدرسی که کاربر بدهد)،
    | بدون ریدایرکت آزاد، با سقف حجم و زمان، و هرگز به IP داخلی/خصوصی.
    | اگر سرور در ایران است و تلگرام/اینستاگرام فیلتر است، یک پراکسی خروجی تعیین کنید.
    | اینستاگرام صفحه عمومی را معمولاً فقط به کاربر واردشده نشان می‌دهد؛ روش رسمی
    | «Business Discovery» (برای حساب‌های تجاری/تولیدکننده) با توکن زیر فعال می‌شود.
    */
    'social' => [
        'fetch_enabled' => (bool) env('PEDIGREE_SOCIAL_FETCH', true),
        'proxy' => env('PEDIGREE_SOCIAL_PROXY'), // مثلاً socks5h://127.0.0.1:1080 یا http://proxy:3128
        'timeout' => 8,
        'max_html_kb' => 768,
        'max_image_kb' => 6144,
        // مدت نگه‌داری نتیجه پیش‌نمایش (دقیقه)
        'preview_cache_minutes' => 360,
        // سقف کل درخواست‌های سرور به هر شبکه در ساعت
        'hourly_limit' => (int) env('PEDIGREE_SOCIAL_HOURLY', 300),
        'instagram' => [
            'graph_token' => env('PEDIGREE_INSTAGRAM_TOKEN'),
            'graph_user_id' => env('PEDIGREE_INSTAGRAM_USER_ID'),
        ],
        // اختیاری: کلیدهای رسمی ایکس و یوتیوب برای دریافت عکس پروفایل
        'x' => ['bearer_token' => env('PEDIGREE_X_BEARER_TOKEN')],
        'youtube' => ['api_key' => env('PEDIGREE_YOUTUBE_API_KEY')],
        // دامنه‌هایی که عکس از آن‌ها دانلود می‌شود (دقیق یا زیردامنه)
        'image_hosts' => [
            'telegram' => ['telesco.pe', 'cdn-telegram.org', 't.me'],
            'instagram' => ['cdninstagram.com', 'fbcdn.net'],
            'github' => ['avatars.githubusercontent.com'],
            'bluesky' => ['cdn.bsky.app'],
            'x' => ['pbs.twimg.com'],
            'youtube' => ['yt3.ggpht.com', 'yt3.googleusercontent.com'],
            'aparat' => ['aparat.com', 'aparatcdn.com', 'cloud.aparat.com'],
        ],
    ],

    'map' => [
        'enabled' => (bool) env('PEDIGREE_MAP_ENABLED', true),
        'tiles' => env('PEDIGREE_MAP_TILES', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('PEDIGREE_MAP_ATTRIBUTION', '© OpenStreetMap contributors'),
        'max_zoom' => 19,
        // مرکز پیش‌فرض (ایران)
        'center' => [32.4, 53.7],
        'zoom' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | نظرات و امتیاز ویژگی‌های اخلاقی (نظرسنجی کل خاندان)
    |--------------------------------------------------------------------------
    */
    'comments' => [
        'enabled' => true,
        'max_length' => 3000,
    ],
    'ratings' => [
        'enabled' => true,
        // نام امتیازدهندگان برای همه نمایش داده شود
        'show_raters' => true,
        // ویژگی‌ها (کلید انگلیسی ثابت، برچسب فارسی قابل تغییر)
        'traits' => [
            'kindness' => 'مهربانی',
            'humor' => 'شوخ‌طبعی',
            'charisma' => 'کاریزما و جذبه',
            'generosity' => 'دست‌ودل‌بازی',
            'honesty' => 'صداقت',
            'patience' => 'صبر و حوصله',
            'wisdom' => 'پختگی و خرد',
            'intelligence' => 'هوش',
            'diligence' => 'سخت‌کوشی',
            'responsibility' => 'مسئولیت‌پذیری',
            'reliability' => 'قابل‌اعتماد بودن',
            'hospitality' => 'مهمان‌نوازی',
            'family_devotion' => 'خانواده‌دوستی',
            'helpfulness' => 'کمک به دیگران',
            'courage' => 'شجاعت',
            'modesty' => 'فروتنی',
            'manners' => 'ادب و احترام',
            'calmness' => 'آرامش و خونسردی',
            'sociability' => 'خوش‌برخوردی و اجتماعی بودن',
            'eloquence' => 'خوش‌سخنی',
            'creativity' => 'خلاقیت و هنر',
            'leadership' => 'مدیریت و بزرگی',
            'punctuality' => 'وقت‌شناسی',
            'cooking' => 'دست‌پخت',
        ],
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
