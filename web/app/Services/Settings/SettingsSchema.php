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

    private const AI_PROVIDERS = [
        'gemini' => 'Google Gemini (رایگان با سهمیه روزانه)',
        'openrouter' => 'OpenRouter (مدل‌های رایگان و پولی)',
        'groq' => 'Groq (رایگان با سهمیه)',
        'cerebras' => 'Cerebras (رایگان با سهمیه)',
        'mistral' => 'Mistral (سهمیه رایگان آزمایشی)',
        'deepseek' => 'DeepSeek (پولی و ارزان)',
        'openai' => 'ChatGPT از OpenAI (پولی)',
        'anthropic' => 'Claude از Anthropic (پولی)',
        'xai' => 'Grok از xAI (پولی)',
        'custom' => 'سرویس دیگر سازگار با OpenAI',
    ];

    /** نام مدل: حرف و عدد لاتین و . _ : / @ - */
    private const MODEL_PATTERN = '#^[A-Za-z0-9._:/@-]*$#';

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
                'description' => 'اعضایی که پروفایلشان به اندازه کافی کامل است با پنل پیامکی سایت تبریک تولد می‌فرستند (دستی یا خودکار از طرف خودشان). متن ثابت است: نام کامل با عنوان دکتر/مهندس و نسبت فامیلی که خود سایت حساب می‌کند، به‌علاوه یادداشتی خیلی کوتاه. هزینه از اعتبار پنل کم می‌شود؛ سقف‌ها را متناسب تنظیم کنید.',
                'fields' => [
                    'pedigree.member_sms.min_completeness' => ['label' => 'حداقل درصد تکمیل پروفایل فرستنده', 'type' => 'int', 'min' => 0, 'max' => 100],
                    'pedigree.member_sms.auto_max_scope' => ['label' => 'بیشترین دامنه تبریک خودکار', 'type' => 'select', 'options' => self::SCOPES],
                    'pedigree.member_sms.send_hour' => ['label' => 'ساعت ارسال تبریک خودکار (به وقت تهران)', 'type' => 'int', 'min' => 6, 'max' => 22],
                    'pedigree.member_sms.daily_per_user' => ['label' => 'سقف پیامک هر عضو در روز', 'type' => 'int', 'min' => 1, 'max' => 1000],
                    'pedigree.member_sms.monthly_per_user' => ['label' => 'سقف پیامک هر عضو در ماه', 'type' => 'int', 'min' => 1, 'max' => 10000],
                    'pedigree.member_sms.daily_per_recipient' => ['label' => 'سقف پیامکی که یک نفر در روز دریافت می‌کند', 'type' => 'int', 'min' => 1, 'max' => 100],
                    'pedigree.member_sms.global_daily' => ['label' => 'سقف کل پیامک‌های تبریک سایت در روز', 'type' => 'int', 'min' => 1, 'max' => 100000],
                ],
            ],
            'birthdays' => [
                'label' => 'اعلان تولد',
                'icon' => 'bell',
                'description' => 'روز تولد هر عضو، به همه اعضا (جز خود او) در سایت و اپ اعلان داده می‌شود. اعلان تولد و پیامک تبریک قابل خاموش کردن نیست.',
                'fields' => [
                    'pedigree.birthdays.notify_hour' => ['label' => 'ساعت ارسال اعلان (به وقت تهران)', 'type' => 'int', 'min' => 0, 'max' => 23],
                ],
            ],

            'features' => [
                'label' => 'بخش‌های سایت',
                'icon' => 'settings',
                'description' => 'روشن یا خاموش کردن بخش‌های سایت و سقف‌های آن‌ها. اعلان تولد و پیامک تبریک قابل خاموش کردن نیست.',
                'fields' => [
                    'pedigree.messaging.enabled' => ['label' => 'پیام‌رسان خصوصی اعضا', 'type' => 'bool'],
                    'pedigree.messaging.daily_limit' => ['label' => 'سقف پیام خصوصی هر عضو در روز', 'type' => 'int', 'min' => 10, 'max' => 5000],
                    'pedigree.comments.enabled' => ['label' => 'نوشتن نظر و خاطره درباره اشخاص', 'type' => 'bool'],
                    'pedigree.ratings.enabled' => ['label' => 'امتیاز دادن به ویژگی‌های اشخاص', 'type' => 'bool'],
                    'pedigree.permissions.history_public' => ['label' => 'تاریخچه تغییرات پروفایل‌ها برای همه اعضا', 'type' => 'bool'],
                ],
            ],
            'group' => [
                'label' => 'گروه خاطرات خاندان',
                'icon' => 'users',
                'description' => 'گفتگوی همه اعضا با متن، ایموجی و عکس و فیلم قدیمی. پیامِ فقط ایموجی پذیرفته نمی‌شود. گزارش‌ها و اعضای محدودشده در تب «گروه خاندان» همین پنل هستند.',
                'fields' => [
                    'pedigree.group.enabled' => ['label' => 'گروه خاندان فعال باشد', 'type' => 'bool'],
                    'pedigree.group.name' => ['label' => 'نام گروه', 'type' => 'text', 'max' => 60],
                    'pedigree.group.slow_mode_seconds' => ['label' => 'حداقل فاصله دو پیام یک نفر (ثانیه)', 'type' => 'int', 'min' => 0, 'max' => 600],
                    'pedigree.group.per_minute' => ['label' => 'سقف پیام هر عضو در دقیقه', 'type' => 'int', 'min' => 1, 'max' => 120],
                    'pedigree.group.daily_messages' => ['label' => 'سقف پیام هر عضو در روز', 'type' => 'int', 'min' => 1, 'max' => 5000],
                    'pedigree.group.daily_media' => ['label' => 'سقف عکس و فیلم هر عضو در روز', 'type' => 'int', 'min' => 0, 'max' => 500],
                    'pedigree.group.allow_links' => ['label' => 'فرستادن لینک مجاز باشد', 'type' => 'bool', 'help' => 'خاموش بماند تا تبلیغ و لینک فیشینگ فرستاده نشود'],
                    'pedigree.group.daily_prompt' => ['label' => '«سؤال روز» برای زنده کردن خاطرات', 'type' => 'bool'],
                    'pedigree.group.prompt_hour' => ['label' => 'ساعت سؤال روز (وقت تهران)', 'type' => 'int', 'min' => 0, 'max' => 23],
                ],
            ],
            'announcement' => [
                'label' => 'اطلاعیه سایت',
                'icon' => 'bell',
                'description' => 'پیامی که بالای صفحه اول همه اعضا نمایش داده می‌شود. برای فرستادن اعلان روی گوشی همه، از تب «نمای کلی» اعلان همگانی بفرستید.',
                'fields' => [
                    'pedigree.announcement.enabled' => ['label' => 'اطلاعیه نمایش داده شود', 'type' => 'bool'],
                    'pedigree.announcement.text' => ['label' => 'متن اطلاعیه', 'type' => 'text', 'max' => 500],
                    'pedigree.announcement.level' => ['label' => 'رنگ', 'type' => 'select', 'options' => ['info' => 'آبی (خبر)', 'success' => 'سبز (خبر خوش)', 'warning' => 'نارنجی (مهم)']],
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

            'ai' => [
                'label' => 'دستیار هوش مصنوعی',
                'icon' => 'bot',
                'description' => 'گفتگو، بازی‌ها (مشاعره، بیست سؤالی، چیستان ...)، زنده کردن خاطره و بازسازی عکس قدیمی با هوش مصنوعی برای همه اعضا. رایگان‌ها: Google Gemini (کلید رایگان از AI Studio با سهمیه روزانه)، OpenRouter (مدل‌های «:free»)، Groq و Cerebras؛ Mistral سهمیه آزمایشی رایگان دارد. DeepSeek ارزان و ChatGPT، Claude و Grok پولی‌اند. با «سرویس پشتیبان» وقتی سهمیه یکی تمام شود دیگری جواب می‌دهد. گفتگوها در سرور ذخیره نمی‌شوند و هیچ اطلاعاتی از شجره‌نامه برای سرویس فرستاده نمی‌شود. سرویس‌های خارجی IP ایران را نمی‌پذیرند؛ اگر سرور در ایران است پراکسی بگذارید یا از یک درگاه داخلی سازگار با OpenAI استفاده کنید.',
                'fields' => [
                    'pedigree.ai.enabled' => ['label' => 'دستیار هوش مصنوعی فعال باشد', 'type' => 'bool'],
                    'pedigree.ai.provider' => ['label' => 'سرویس اصلی', 'type' => 'select', 'options' => self::AI_PROVIDERS],
                    'pedigree.ai.fallback_provider' => ['label' => 'سرویس پشتیبان (اگر اصلی شلوغ بود)', 'type' => 'select', 'options' => ['' => 'هیچ'] + self::AI_PROVIDERS, 'help' => 'مثلاً Gemini اصلی و Groq پشتیبان؛ وقتی سهمیه رایگان اولی تمام شود دومی جواب می‌دهد'],
                    'pedigree.ai.proxy' => ['label' => 'پراکسی خروجی (اگر سرور در ایران است)', 'type' => 'secret', 'kind' => 'proxy', 'placeholder' => 'socks5h://127.0.0.1:1080', 'help' => 'خالی = همان پراکسی شبکه‌های اجتماعی (اگر تنظیم شده باشد)'],
                    'pedigree.ai.daily_per_user' => ['label' => 'سقف پیام هر عضو در روز', 'type' => 'int', 'min' => 1, 'max' => 1000],
                    'pedigree.ai.global_daily' => ['label' => 'سقف کل پیام‌های سایت در روز', 'type' => 'int', 'min' => 10, 'max' => 100000],
                    'pedigree.ai.image.enabled' => ['label' => 'بازسازی و رنگی کردن عکس‌های قدیمی (با کلید Gemini)', 'type' => 'bool', 'help' => 'اعضا از منوی هر عکس، نسخه بازسازی‌شده یا رنگی می‌سازند؛ ممکن است هزینه داشته باشد'],
                    'pedigree.ai.image.model' => ['label' => 'مدل تصویری Gemini', 'type' => 'text', 'max' => 80, 'pattern' => '/^[A-Za-z0-9._-]*$/', 'placeholder' => 'gemini-2.5-flash-image'],
                    'pedigree.ai.image.daily_per_user' => ['label' => 'سقف بازسازی عکس هر عضو در روز', 'type' => 'int', 'min' => 1, 'max' => 100],
                    'pedigree.ai.image.global_daily' => ['label' => 'سقف کل بازسازی عکس سایت در روز', 'type' => 'int', 'min' => 1, 'max' => 5000],
                ],
            ],
            'ai_keys' => [
                'label' => 'کلیدهای هوش مصنوعی',
                'icon' => 'key',
                'description' => 'فقط کلید سرویسی که در «دستیار هوش مصنوعی» انتخاب کرده‌اید لازم است. نام مدل را می‌توانید عوض کنید (مثلاً مدل تازه‌تر همان سرویس).',
                'fields' => [
                    'pedigree.ai.providers.gemini.api_key' => ['label' => 'Gemini: کلید API (رایگان)', 'type' => 'secret', 'link' => 'https://aistudio.google.com/apikey'],
                    'pedigree.ai.providers.gemini.model' => ['label' => 'Gemini: مدل', 'type' => 'text', 'max' => 80, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'gemini-flash-latest'],
                    'pedigree.ai.providers.openrouter.api_key' => ['label' => 'OpenRouter: کلید API', 'type' => 'secret', 'link' => 'https://openrouter.ai/settings/keys'],
                    'pedigree.ai.providers.openrouter.model' => ['label' => 'OpenRouter: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'meta-llama/llama-3.3-70b-instruct:free', 'help' => 'مدل‌های رایگان با «:free» تمام می‌شوند', 'link' => 'https://openrouter.ai/models?max_price=0'],
                    'pedigree.ai.providers.groq.api_key' => ['label' => 'Groq: کلید API (رایگان)', 'type' => 'secret', 'link' => 'https://console.groq.com/keys'],
                    'pedigree.ai.providers.groq.model' => ['label' => 'Groq: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'llama-3.3-70b-versatile'],
                    'pedigree.ai.providers.cerebras.api_key' => ['label' => 'Cerebras: کلید API (رایگان)', 'type' => 'secret', 'link' => 'https://cloud.cerebras.ai/'],
                    'pedigree.ai.providers.cerebras.model' => ['label' => 'Cerebras: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'llama-3.3-70b'],
                    'pedigree.ai.providers.mistral.api_key' => ['label' => 'Mistral: کلید API', 'type' => 'secret', 'link' => 'https://console.mistral.ai/api-keys'],
                    'pedigree.ai.providers.mistral.model' => ['label' => 'Mistral: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'mistral-small-latest'],
                    'pedigree.ai.providers.deepseek.api_key' => ['label' => 'DeepSeek: کلید API', 'type' => 'secret', 'link' => 'https://platform.deepseek.com/api_keys'],
                    'pedigree.ai.providers.deepseek.model' => ['label' => 'DeepSeek: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'deepseek-chat'],
                    'pedigree.ai.providers.openai.api_key' => ['label' => 'ChatGPT (OpenAI): کلید API', 'type' => 'secret', 'link' => 'https://platform.openai.com/api-keys'],
                    'pedigree.ai.providers.openai.model' => ['label' => 'ChatGPT: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'gpt-4.1-mini'],
                    'pedigree.ai.providers.anthropic.api_key' => ['label' => 'Claude (Anthropic): کلید API', 'type' => 'secret', 'link' => 'https://console.anthropic.com/settings/keys'],
                    'pedigree.ai.providers.anthropic.model' => ['label' => 'Claude: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'claude-sonnet-5', 'help' => 'claude-opus-5-5 قوی‌ترین، claude-sonnet-5 متعادل، claude-haiku-4-5-20251001 ارزان‌ترین'],
                    'pedigree.ai.providers.xai.api_key' => ['label' => 'Grok (xAI): کلید API', 'type' => 'secret', 'link' => 'https://console.x.ai/'],
                    'pedigree.ai.providers.xai.model' => ['label' => 'Grok: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN, 'placeholder' => 'grok-3-mini'],
                    'pedigree.ai.providers.custom.base_url' => ['label' => 'سرویس سازگار با OpenAI: آدرس پایه', 'type' => 'url', 'placeholder' => 'https://api.example.com/v1', 'help' => 'آدرسی که ‎/chat/completions‎ به انتهایش اضافه می‌شود؛ مثلاً درگاه‌های داخلی که از ایران بدون پراکسی کار می‌کنند'],
                    'pedigree.ai.providers.custom.api_key' => ['label' => 'سرویس سازگار با OpenAI: کلید API', 'type' => 'secret'],
                    'pedigree.ai.providers.custom.model' => ['label' => 'سرویس سازگار با OpenAI: مدل', 'type' => 'text', 'max' => 120, 'pattern' => self::MODEL_PATTERN],
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

            'media' => [
                'label' => 'عکس و ویدیو',
                'icon' => 'image',
                'description' => 'هر عکسی با هر قالبی (JPG، PNG، HEIC آیفون، WebP، TIFF ...) به JPEG کم‌حجم (حداکثر ۲۵۶۰ پیکسل، بدون اطلاعات مکانی) و هر ویدیویی (MOV، MKV، AVI ...) مثل تلگرام به MP4 با ضلع کوچک‌تر ۷۲۰ و حداکثر ۳۰ فریم تبدیل می‌شود؛ معمولاً ۵ تا ۱۰ برابر کم‌حجم‌تر بدون افت محسوس. فایل اصلی پس از تبدیل پاک می‌شود. عکس‌های بزرگ پیش از آپلود در خود مرورگر هم کوچک می‌شوند.',
                'fields' => [
                    'pedigree.media.video.enabled' => ['label' => 'آپلود ویدیو مجاز باشد', 'type' => 'bool'],
                    'pedigree.media.video.max_upload_mb' => ['label' => 'بیشترین حجم هر ویدیو (مگابایت)', 'type' => 'int', 'min' => 10, 'max' => 4096, 'help' => 'سقف آپلود PHP و وب‌سرور (Nginx) هم باید دست‌کم همین اندازه باشد'],
                    'pedigree.media.video.max_duration' => ['label' => 'بیشترین مدت ویدیو (ثانیه)', 'type' => 'int', 'min' => 10, 'max' => 14400, 'help' => 'بیشتر از این بریده می‌شود؛ ۳۶۰۰ = یک ساعت'],
                    'pedigree.media.video.max_height' => ['label' => 'وضوح ویدیو', 'type' => 'select', 'options' => [
                        '480' => '۴۸۰p — کم‌حجم‌ترین',
                        '720' => '۷۲۰p — مثل تلگرام (پیشنهادی)',
                        '1080' => '۱۰۸۰p — کیفیت کامل (حجم بیشتر)',
                    ]],
                    'pedigree.media.video.crf' => ['label' => 'فشرده‌سازی ویدیو', 'type' => 'select', 'options' => [
                        '23' => 'کیفیت بالا (حجم بیشتر)',
                        '26' => 'متعادل (پیشنهادی)',
                        '28' => 'صرفه‌جویی بیشتر در فضا',
                    ]],
                    'pedigree.media.video.preset' => ['label' => 'سرعت تبدیل ویدیو', 'type' => 'select', 'options' => [
                        'slow' => 'کند ولی کم‌حجم‌ترین (پیشنهادی)',
                        'medium' => 'متعادل',
                        'veryfast' => 'سریع (حجم بیشتر؛ برای سرور ضعیف)',
                    ]],
                    'pedigree.media.image.quality' => ['label' => 'کیفیت عکس JPEG', 'type' => 'int', 'min' => 70, 'max' => 95, 'help' => '۸۴ از نظر چشمی بدون افت است'],
                    'pedigree.media.daily_uploads_per_user' => ['label' => 'سقف آپلود هر عضو در روز', 'type' => 'int', 'min' => 5, 'max' => 5000],
                    'pedigree.media.daily_videos_per_user' => ['label' => 'سقف آپلود ویدیوی هر عضو در روز', 'type' => 'int', 'min' => 1, 'max' => 500],
                    'pedigree.media.video.queue_max' => ['label' => 'بیشترین ویدیوی در صف تبدیل (کل سایت)', 'type' => 'int', 'min' => 3, 'max' => 1000, 'help' => 'جلوگیری از پر شدن پردازنده سرور'],
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
