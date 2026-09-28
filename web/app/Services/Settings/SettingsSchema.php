<?php

namespace App\Services\Settings;

/**
 * فهرست تنظیماتی که مدیر کل از پنل مدیریت («تنظیمات و اتصال‌ها») تغییر می‌دهد.
 *
 * کلید هر فیلد همان مسیر config است؛ مقدار ذخیره‌شده در پنل بر مقدار فایل ‎.env مقدم است.
 * فقط کلیدهای همین فهرست قابل تغییرند (کلید دلخواه پذیرفته نمی‌شود).
 *
 * نوع فیلدها:
 *   text | url | int | bool | select | secret (کلید/رمز؛ هرگز به مرورگر برنمی‌گردد) | secret_json
 */
final class SettingsSchema
{
    private const SMS_PROVIDERS = [
        'log' => 'آزمایشی (فقط در لاگ سرور؛ پیامکی ارسال نمی‌شود)',
        'kavenegar' => 'کاوه‌نگار',
        'smsir' => 'SMS.ir (اس‌ام‌اس دات آی‌آر)',
        'melipayamak' => 'ملی‌پیامک',
        'ippanel' => 'IPPanel (فراز اس‌ام‌اس)',
        'ghasedak' => 'قاصدک',
    ];

    private const SCOPES = [
        'all' => 'همه اعضای شجره‌نامه',
        'd4' => 'بستگان تا درجه ۴',
        'd3' => 'بستگان تا درجه ۳',
        'd2' => 'بستگان تا درجه ۲',
        'd1' => 'فقط بستگان درجه ۱',
    ];

    /** @return array<string, array> گروه‌ها به ترتیب نمایش */
    public static function groups(): array
    {
        return [
            'general' => [
                'label' => 'تنظیمات عمومی سایت',
                'icon' => 'settings',
                'fields' => [
                    'pedigree.site_name' => ['label' => 'نام سایت', 'type' => 'text', 'max' => 80],
                    'pedigree.registration.enabled' => ['label' => 'ثبت‌نام اعضای جدید باز باشد', 'type' => 'bool'],
                    'pedigree.guest_view' => ['label' => 'دیدن درخت بدون ورود (مهمان)', 'type' => 'bool', 'help' => 'برای حفظ حریم خانواده معمولاً خاموش بماند.'],
                ],
            ],

            'sms' => [
                'label' => 'پنل پیامکی',
                'icon' => 'mail',
                'description' => 'پنلی که کد ورود با آن ارسال می‌شود و پنلی که اعضا با آن تبریک تولد می‌فرستند (می‌توانند یکی باشند). کلید هر پنل را در کارت همان پنل وارد کنید.',
                'fields' => [
                    'pedigree.sms.driver' => ['label' => 'پنل ارسال کد ورود (OTP)', 'type' => 'select', 'options' => self::SMS_PROVIDERS],
                    'pedigree.sms.message_driver' => ['label' => 'پنل ارسال پیامک‌های تبریک اعضا', 'type' => 'select', 'options' => ['' => 'همان پنل کد ورود'] + self::SMS_PROVIDERS],
                    'pedigree.otp.global_daily_limit' => ['label' => 'سقف کل پیامک‌های کد ورود در روز', 'type' => 'int', 'min' => 10, 'max' => 1000000, 'help' => 'جلوگیری از خالی شدن اعتبار پنل در حمله'],
                ],
            ],
            'kavenegar' => [
                'label' => 'کاوه‌نگار',
                'icon' => 'key',
                'provider' => 'kavenegar',
                'link' => 'https://panel.kavenegar.com/client/setting/account',
                'description' => 'کلید API از «تنظیمات حساب ← API Key». برای کد ورود یک قالب تأیید (Verify Lookup) با متغیر ⁦%token⁩ بسازید.',
                'fields' => [
                    'pedigree.sms.drivers.kavenegar.api_key' => ['label' => 'کلید API', 'type' => 'secret'],
                    'pedigree.sms.drivers.kavenegar.template' => ['label' => 'نام قالب کد ورود', 'type' => 'text', 'max' => 60, 'placeholder' => 'verify'],
                    'pedigree.sms.drivers.kavenegar.sender' => ['label' => 'شماره خط ارسال پیامک عادی', 'type' => 'text', 'max' => 20, 'placeholder' => '10008663', 'help' => 'خالی = خط پیش‌فرض حساب'],
                ],
            ],
            'smsir' => [
                'label' => 'SMS.ir',
                'icon' => 'key',
                'provider' => 'smsir',
                'link' => 'https://app.sms.ir/developer/help',
                'description' => 'کلید API از «برنامه‌نویسان ← لیست کلیدهای API». برای کد ورود یک قالب «ارسال سریع» با پارامتر ⁦CODE⁩ بسازید.',
                'fields' => [
                    'pedigree.sms.drivers.smsir.api_key' => ['label' => 'کلید API', 'type' => 'secret'],
                    'pedigree.sms.drivers.smsir.template_id' => ['label' => 'شناسه قالب کد ورود', 'type' => 'text', 'max' => 20, 'placeholder' => '123456'],
                    'pedigree.sms.drivers.smsir.parameter' => ['label' => 'نام پارامتر قالب', 'type' => 'text', 'max' => 30, 'placeholder' => 'CODE'],
                    'pedigree.sms.drivers.smsir.line_number' => ['label' => 'شماره خط ارسال پیامک عادی', 'type' => 'text', 'max' => 20, 'placeholder' => '30007732000000'],
                ],
            ],
            'melipayamak' => [
                'label' => 'ملی‌پیامک',
                'icon' => 'key',
                'provider' => 'melipayamak',
                'link' => 'https://www.melipayamak.com/api/',
                'fields' => [
                    'pedigree.sms.drivers.melipayamak.username' => ['label' => 'نام کاربری', 'type' => 'text', 'max' => 60],
                    'pedigree.sms.drivers.melipayamak.password' => ['label' => 'رمز / کلید API', 'type' => 'secret'],
                    'pedigree.sms.drivers.melipayamak.body_id' => ['label' => 'کد الگوی کد ورود (bodyId)', 'type' => 'text', 'max' => 20],
                    'pedigree.sms.drivers.melipayamak.from' => ['label' => 'شماره خط ارسال پیامک عادی', 'type' => 'text', 'max' => 20],
                ],
            ],
            'ippanel' => [
                'label' => 'IPPanel (فراز اس‌ام‌اس)',
                'icon' => 'key',
                'provider' => 'ippanel',
                'link' => 'https://docs.ippanel.com',
                'fields' => [
                    'pedigree.sms.drivers.ippanel.api_key' => ['label' => 'کلید API', 'type' => 'secret'],
                    'pedigree.sms.drivers.ippanel.sender' => ['label' => 'شماره خط ارسال', 'type' => 'text', 'max' => 20, 'placeholder' => '+983000505'],
                    'pedigree.sms.drivers.ippanel.pattern_code' => ['label' => 'کد پترن کد ورود', 'type' => 'text', 'max' => 40],
                    'pedigree.sms.drivers.ippanel.variable' => ['label' => 'نام متغیر پترن', 'type' => 'text', 'max' => 30, 'placeholder' => 'code'],
                ],
            ],
            'ghasedak' => [
                'label' => 'قاصدک',
                'icon' => 'key',
                'provider' => 'ghasedak',
                'link' => 'https://ghasedak.me/docs',
                'fields' => [
                    'pedigree.sms.drivers.ghasedak.api_key' => ['label' => 'کلید API', 'type' => 'secret'],
                    'pedigree.sms.drivers.ghasedak.template' => ['label' => 'نام قالب کد ورود', 'type' => 'text', 'max' => 60],
                    'pedigree.sms.drivers.ghasedak.line_number' => ['label' => 'شماره خط ارسال پیامک عادی', 'type' => 'text', 'max' => 20],
                ],
            ],

            'member_sms' => [
                'label' => 'پیامک تبریک اعضا',
                'icon' => 'cake',
                'description' => 'اعضایی که پروفایلشان به اندازه کافی کامل است می‌توانند از پنل پیامکی سایت برای تبریک تولد بستگان پیامک بفرستند (دستی یا خودکار از طرف خودشان). هزینه از اعتبار پنل شما کم می‌شود؛ سقف‌ها را متناسب تنظیم کنید.',
                'fields' => [
                    'pedigree.member_sms.enabled' => ['label' => 'اعضا بتوانند پیامک تبریک بفرستند', 'type' => 'bool'],
                    'pedigree.member_sms.min_completeness' => ['label' => 'حداقل درصد تکمیل پروفایل فرستنده', 'type' => 'int', 'min' => 0, 'max' => 100],
                    'pedigree.member_sms.auto_enabled' => ['label' => 'تبریک خودکار از طرف اعضا مجاز باشد', 'type' => 'bool'],
                    'pedigree.member_sms.auto_max_scope' => ['label' => 'بیشترین دامنه تبریک خودکار', 'type' => 'select', 'options' => self::SCOPES],
                    'pedigree.member_sms.send_hour' => ['label' => 'ساعت ارسال تبریک خودکار (به وقت تهران)', 'type' => 'int', 'min' => 6, 'max' => 22],
                    'pedigree.member_sms.daily_per_user' => ['label' => 'سقف پیامک هر عضو در روز', 'type' => 'int', 'min' => 1, 'max' => 1000],
                    'pedigree.member_sms.monthly_per_user' => ['label' => 'سقف پیامک هر عضو در ماه', 'type' => 'int', 'min' => 1, 'max' => 10000],
                    'pedigree.member_sms.daily_per_recipient' => ['label' => 'سقف پیامکی که یک نفر در روز دریافت می‌کند', 'type' => 'int', 'min' => 1, 'max' => 100],
                    'pedigree.member_sms.global_daily' => ['label' => 'سقف کل پیامک‌های تبریک سایت در روز', 'type' => 'int', 'min' => 1, 'max' => 100000],
                    'pedigree.member_sms.max_length' => ['label' => 'حداکثر طول متن (نویسه)', 'type' => 'int', 'min' => 40, 'max' => 600],
                ],
            ],
            'birthdays' => [
                'label' => 'اعلان تولد',
                'icon' => 'bell',
                'description' => 'روز تولد هر عضو، به بقیه اعضا (جز خود او) در سایت و اپ اعلان داده می‌شود.',
                'fields' => [
                    'pedigree.birthdays.notify' => ['label' => 'اعلان تولد فعال باشد', 'type' => 'bool'],
                    'pedigree.birthdays.scope' => ['label' => 'به چه کسانی اعلان داده شود', 'type' => 'select', 'options' => self::SCOPES],
                    'pedigree.birthdays.notify_hour' => ['label' => 'ساعت ارسال اعلان (به وقت تهران)', 'type' => 'int', 'min' => 0, 'max' => 23],
                ],
            ],

            'push' => [
                'label' => 'اعلان روی گوشی (Firebase)',
                'icon' => 'phone',
                'link' => 'https://console.firebase.google.com/',
                'description' => 'برای نمایش اعلان‌ها روی اپ اندروید و iOS: در کنسول Firebase ← Project settings ← Service accounts یک کلید JSON بسازید و محتوای آن را اینجا بچسبانید.',
                'fields' => [
                    'services.fcm.enabled' => ['label' => 'اعلان گوشی فعال باشد', 'type' => 'bool'],
                    'services.fcm.credentials_json' => ['label' => 'فایل JSON حساب سرویس', 'type' => 'secret_json', 'required_keys' => ['project_id', 'client_email', 'private_key']],
                ],
            ],

            'social' => [
                'label' => 'شبکه‌های اجتماعی',
                'icon' => 'share',
                'description' => 'دریافت نام و عکس پروفایل عمومی. تلگرام، گیت‌هاب، بلواسکای و آپارات کلید لازم ندارند؛ اینستاگرام (فقط حساب‌های تجاری/تولیدکننده)، ایکس و یوتیوب با کلید رسمی خودشان.',
                'fields' => [
                    'pedigree.social.fetch_enabled' => ['label' => 'دریافت خودکار عکس پروفایل', 'type' => 'bool'],
                    'pedigree.social.proxy' => ['label' => 'پراکسی خروجی (اگر سرور در ایران است)', 'type' => 'secret', 'kind' => 'proxy', 'placeholder' => 'socks5h://127.0.0.1:1080', 'help' => 'فقط http، https، socks5 یا socks5h'],
                    'pedigree.social.hourly_limit' => ['label' => 'سقف درخواست به هر شبکه در ساعت', 'type' => 'int', 'min' => 10, 'max' => 10000],
                    'pedigree.social.instagram.graph_token' => ['label' => 'اینستاگرام: توکن Graph API', 'type' => 'secret', 'link' => 'https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/business_discovery'],
                    'pedigree.social.instagram.graph_user_id' => ['label' => 'اینستاگرام: شناسه عددی حساب تجاری شما', 'type' => 'text', 'max' => 30, 'pattern' => '/^\d*$/'],
                    'pedigree.social.x.bearer_token' => ['label' => 'ایکس (توییتر): Bearer Token', 'type' => 'secret', 'link' => 'https://developer.x.com/en/portal/dashboard'],
                    'pedigree.social.youtube.api_key' => ['label' => 'یوتیوب: کلید YouTube Data API', 'type' => 'secret', 'link' => 'https://console.cloud.google.com/apis/library/youtube.googleapis.com'],
                ],
            ],

            'map' => [
                'label' => 'نقشه',
                'icon' => 'pin',
                'description' => 'نقشه خاندان و انتخاب موقعیت خانه/مزار. می‌توانید سرویس نقشه دیگری (مثلاً نشان یا Map.ir با کلید خودتان) بگذارید.',
                'fields' => [
                    'pedigree.map.enabled' => ['label' => 'نقشه فعال باشد', 'type' => 'bool'],
                    'pedigree.map.tiles' => ['label' => 'آدرس کاشی‌های نقشه', 'type' => 'url', 'kind' => 'tiles', 'placeholder' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    'pedigree.map.attribution' => ['label' => 'متن حق نشر نقشه', 'type' => 'text', 'max' => 200],
                    'pedigree.map.max_zoom' => ['label' => 'بیشترین بزرگ‌نمایی', 'type' => 'int', 'min' => 10, 'max' => 22],
                ],
            ],
        ];
    }

    /** @return array<string, array> کلید ← تعریف فیلد (با نام گروه) */
    public static function fields(): array
    {
        $out = [];
        foreach (self::groups() as $group => $def) {
            foreach ($def['fields'] as $key => $field) {
                $out[$key] = $field + ['group' => $group];
            }
        }

        return $out;
    }

    public static function isSecret(string $key): bool
    {
        return in_array(self::fields()[$key]['type'] ?? null, ['secret', 'secret_json'], true);
    }

    /** @return string[] نام پنل‌های پیامکی */
    public static function smsProviders(): array
    {
        return array_keys(self::SMS_PROVIDERS);
    }
}
