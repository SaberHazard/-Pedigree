<?php

namespace App\Services\Sms;

use App\Models\SmsTemplate;
use App\Support\PersianText;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * قالب‌های ثابت پیامک تبریک که فقط مدیر کل تعیین می‌کند.
 *
 * - هر قالب برای یک مناسبت است (تولد، سالگرد ازدواج، نوروز، یلدا)
 * - متغیرها با {نام_متغیر} در متن می‌آیند و هنگام ارسال با مشخصات گیرنده و فرستنده پر می‌شوند
 * - اگر همه متغیرهای یک سطر خالی باشند (مثلاً یادداشت یا لقب ندارد)، آن سطر حذف می‌شود
 * - هر قالب باید نام فرستنده را داشته باشد (کسی به نام دیگری پیامک نفرستد)؛ لینک مجاز نیست
 */
final class SmsTemplates
{
    public const OCCASIONS = [
        'birthday' => ['label' => 'تولد', 'emoji' => '🎂'],
        'anniversary' => ['label' => 'سالگرد ازدواج', 'emoji' => '💍'],
        'nowruz' => ['label' => 'نوروز', 'emoji' => '🌱'],
        'yalda' => ['label' => 'شب یلدا', 'emoji' => '🍉'],
        'sepandarmazgan' => ['label' => 'سپندارمذگان', 'emoji' => '💐'],
        'mother_day' => ['label' => 'روز مادر', 'emoji' => '🌷'],
        'father_day' => ['label' => 'روز پدر', 'emoji' => '🎁'],
        'nimeh_shaban' => ['label' => 'نیمه شعبان', 'emoji' => '✨'],
        'eid_fitr' => ['label' => 'عید فطر', 'emoji' => '🌙'],
        'eid_adha' => ['label' => 'عید قربان', 'emoji' => '🕌'],
        'eid_ghadir' => ['label' => 'عید غدیر', 'emoji' => '💚'],
    ];

    public const BODY_MAX = 500;

    public const TITLE_MAX = 60;

    /** حداکثر تعداد قالب در هر مناسبت */
    public const PER_OCCASION_MAX = 12;

    /** یکی از این متغیرها باید در متن باشد تا فرستنده معلوم باشد */
    public const SENDER_KEYS = ['from_line', 'from_line_formal', 'from_full_name', 'from_first_name'];

    /**
     * نام فارسی هر متغیر (برای نوشتن راحت در متن راست‌به‌چپ): {نام_گیرنده} همان {to_first_name} است
     */
    public const FA = [
        'نام_کامل_گیرنده' => 'to_full_name',
        'نام_گیرنده' => 'to_first_name',
        'نام_خانوادگی_گیرنده' => 'to_last_name',
        'عنوان_گیرنده' => 'to_title',
        'آقا_خانم_گیرنده' => 'to_mr_mrs',
        'لقب_گیرنده' => 'to_nickname',
        'سن_گیرنده' => 'to_age',
        'سال_تولد_گیرنده' => 'to_birth_year',
        'محل_تولد_گیرنده' => 'to_birth_place',
        'شهر_گیرنده' => 'to_city',
        'شغل_گیرنده' => 'to_occupation',
        'تحصیلات_گیرنده' => 'to_education',
        'نام_پدر_گیرنده' => 'to_father_name',
        'نام_مادر_گیرنده' => 'to_mother_name',
        'نام_کامل_فرستنده' => 'from_full_name',
        'نام_فرستنده' => 'from_first_name',
        'نام_خانوادگی_فرستنده' => 'from_last_name',
        'عنوان_فرستنده' => 'from_title',
        'آقا_خانم_فرستنده' => 'from_mr_mrs',
        'لقب_فرستنده' => 'from_nickname',
        'نسبت' => 'relation',
        'از_طرف' => 'from_line',
        'از_طرف_رسمی' => 'from_line_formal',
        'سال_ازدواج' => 'years_married',
        'نام_همسر' => 'spouse_name',
        'سال_نو' => 'new_year',
        'یادداشت' => 'note',
        'نام_سایت' => 'site_name',
        'نام_مناسبت' => 'occasion',
        'تاریخ_امروز' => 'today',
        'سال_جاری' => 'year',
    ];

    /** قالب‌های پیش‌فرض (در نصب اولیه و «بازگردانی پیش‌فرض‌ها») */
    public const DEFAULTS = [
        ['birthday', 'warm', 'گرم و صمیمی', "🎂 {نام_کامل_گیرنده} عزیز، زادروزت خجسته باد! سالی سرشار از سلامتی و شادی برایت آرزومندم.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['birthday', 'short', 'کوتاه', "🌹 {نام_کامل_گیرنده} عزیز، تولدت مبارک! همیشه سلامت و شاد باشی.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['birthday', 'respect', 'رسمی و محترمانه', "🎉 {نام_کامل_گیرنده} گرامی، زادروزتان مبارک! سایه‌تان همیشه بر سر خانواده باشد.\n{از_طرف_رسمی}\n{یادداشت}\n{نام_سایت}"],
        ['birthday', 'success', 'آرزوی موفقیت', "🎈 {نام_کامل_گیرنده} عزیز، تولدت مبارک! به امید سالی پر از موفقیت و خبرهای خوب.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['anniversary', 'anniv', 'سالگرد ازدواج', "💍 {نام_کامل_گیرنده} عزیز، {سال_ازدواج}مین سالگرد ازدواج شما و {نام_همسر} مبارک! زندگی‌تان همیشه پر از عشق و آرامش باشد.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['nowruz', 'nowruz', 'نوروز', "🌱 {نام_کامل_گیرنده} عزیز، نوروز {سال_نو} مبارک! سالی پر از سلامتی، شادی و برکت برایت آرزومندم.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['nowruz', 'nowruz_formal', 'نوروز (رسمی)', "🌷 {نام_کامل_گیرنده} گرامی، فرا رسیدن نوروز {سال_نو} را شادباش می‌گویم. سال نو بر شما و خانواده مبارک.\n{از_طرف_رسمی}\n{یادداشت}\n{نام_سایت}"],
        ['yalda', 'yalda', 'شب یلدا', "🍉 {نام_کامل_گیرنده} عزیز، شب یلدا مبارک! بلندترین شب سال کنار عزیزانت گرم و روشن باشد.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['sepandarmazgan', 'sepandarmazgan', 'سپندارمذگان', "💐 {نام_کامل_گیرنده} عزیز، سپندارمذگان، جشن مهر و عشق ایرانی، مبارک! دلت همیشه گرم و روزگارت پر از مهربانی.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['mother_day', 'mother_day', 'روز مادر', "🌷 {نام_کامل_گیرنده} عزیز، روز مادر مبارک! سایه مهربانت همیشه بر سر خانواده باشد.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['father_day', 'father_day', 'روز پدر', "🎁 {نام_کامل_گیرنده} عزیز، روز پدر مبارک! همیشه تکیه‌گاه و مایه افتخار خانواده باشی.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['nimeh_shaban', 'nimeh_shaban', 'نیمه شعبان', "✨ {نام_کامل_گیرنده} عزیز، نیمه شعبان و جشن میلاد بر شما مبارک! روزهایتان پر از نور و امید.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['eid_fitr', 'eid_fitr', 'عید فطر', "🌙 {نام_کامل_گیرنده} عزیز، عید سعید فطر مبارک! طاعاتت قبول و روزهایت سرشار از شادی و برکت.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['eid_adha', 'eid_adha', 'عید قربان', "🕌 {نام_کامل_گیرنده} عزیز، عید سعید قربان مبارک! امیدوارم این عید برایت پر از خیر و برکت باشد.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
        ['eid_ghadir', 'eid_ghadir', 'عید غدیر', "💚 {نام_کامل_گیرنده} عزیز، عید سعید غدیر مبارک! روزگارتان پر از شادی و سلامتی.\n{از_طرف}\n{یادداشت}\n{نام_سایت}"],
    ];

    /**
     * فهرست متغیرها: کلید ← [برچسب، گروه، مناسبت‌های مجاز (null = همه)، نمونه]
     *
     * @return array<string, array{label:string, group:string, occasions:?array, sample:string}>
     */
    public static function variables(): array
    {
        $v = fn (string $label, string $group, string $sample, ?array $occasions = null) => compact('label', 'group', 'occasions', 'sample');

        return [
            'to_full_name' => $v('نام کامل گیرنده (با دکتر/مهندس)', 'گیرنده', 'دکتر مریم احمدی'),
            'to_first_name' => $v('نام گیرنده', 'گیرنده', 'مریم'),
            'to_last_name' => $v('نام خانوادگی گیرنده', 'گیرنده', 'احمدی'),
            'to_title' => $v('عنوان گیرنده (حاج، دکتر، مهندس ...)', 'گیرنده', 'دکتر'),
            'to_mr_mrs' => $v('«آقای» یا «خانم» گیرنده', 'گیرنده', 'خانم'),
            'to_nickname' => $v('شهرت / لقب گیرنده', 'گیرنده', 'مریم‌جان'),
            'to_age' => $v('سن گیرنده (چندسالگی)', 'گیرنده', '۳۵', ['birthday']),
            'to_birth_year' => $v('سال تولد گیرنده', 'گیرنده', '۱۳۷۰'),
            'to_birth_place' => $v('محل تولد گیرنده', 'گیرنده', 'شیراز'),
            'to_city' => $v('شهر محل زندگی گیرنده', 'گیرنده', 'تهران'),
            'to_occupation' => $v('شغل گیرنده', 'گیرنده', 'پزشک'),
            'to_education' => $v('مقطع تحصیلی گیرنده', 'گیرنده', 'دکترای تخصصی'),
            'to_father_name' => $v('نام پدر گیرنده', 'گیرنده', 'حسن'),
            'to_mother_name' => $v('نام مادر گیرنده', 'گیرنده', 'زهرا'),

            'from_full_name' => $v('نام کامل فرستنده (با دکتر/مهندس)', 'فرستنده', 'مهندس علی احمدی'),
            'from_first_name' => $v('نام فرستنده', 'فرستنده', 'علی'),
            'from_last_name' => $v('نام خانوادگی فرستنده', 'فرستنده', 'احمدی'),
            'from_title' => $v('عنوان فرستنده', 'فرستنده', 'مهندس'),
            'from_mr_mrs' => $v('«آقای» یا «خانم» فرستنده', 'فرستنده', 'آقای'),
            'from_nickname' => $v('شهرت / لقب فرستنده', 'فرستنده', 'علی کوچولو'),

            'relation' => $v('نسبت فرستنده با گیرنده (از روی شجره‌نامه)', 'نسبت', 'پسرخاله'),
            'from_line' => $v('سطر آماده: «از طرف پسرخاله عزیزت، ...»', 'نسبت', 'از طرف پسرخاله عزیزت، مهندس علی احمدی'),
            'from_line_formal' => $v('سطر آماده رسمی: «از طرف پسرخاله شما، ...»', 'نسبت', 'از طرف پسرخاله شما، مهندس علی احمدی'),

            'years_married' => $v('چندمین سالگرد ازدواج', 'مناسبت', '۲۵', ['anniversary']),
            'spouse_name' => $v('نام کامل همسر گیرنده', 'مناسبت', 'مهندس حسین رضایی', ['anniversary']),
            'new_year' => $v('سال نو (شمسی)', 'مناسبت', '۱۴۰۶', ['nowruz']),
            'occasion' => $v('نام مناسبت (نوروز، عید فطر ...)', 'مناسبت', 'نوروز'),
            'note' => $v('یادداشت کوتاه فرستنده (اختیاری)', 'دیگر', 'با عشق، خانواده رضایی'),
            'site_name' => $v('نام سایت', 'دیگر', (string) config('pedigree.site_name')),
            'today' => $v('تاریخ امروز (شمسی)', 'دیگر', '۶ مهر ۱۴۰۵'),
            'year' => $v('سال جاری (شمسی)', 'دیگر', '۱۴۰۵'),
        ];
    }

    /** کلید انگلیسی متغیر (از نام فارسی یا انگلیسی)؛ null اگر ناشناخته */
    public static function key(string $token): ?string
    {
        $token = trim(str_replace(["\u{200C}", ' ', 'ي', 'ك'], ['_', '_', 'ی', 'ک'], $token));
        if (isset(self::FA[$token])) {
            return self::FA[$token];
        }

        return isset(self::variables()[$token]) ? $token : null;
    }

    /** نام فارسی متغیر */
    public static function faName(string $key): string
    {
        return array_search($key, self::FA, true) ?: $key;
    }

    /** مقادیر نمونه برای پیش‌نمایش در پنل مدیریت */
    public static function sampleValues(): array
    {
        return array_map(fn ($v) => $v['sample'], self::variables());
    }

    /** @return string[] متغیرهای به‌کاررفته در متن */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{([^{}\n]{1,40})\}/u', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * تمیز کردن و اعتبارسنجی متن قالب (پیام خطا فارسی و دقیق)
     *
     * @throws ValidationException
     */
    public static function validateBody(string $occasion, string $body): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['body' => $message]);

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $body) ?? '';
        $body = preg_replace("/[ \t]+\n/u", "\n", $body) ?? '';
        $body = trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? '');

        if (mb_strlen($body) < 5) {
            $fail('متن قالب خیلی کوتاه است.');
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            $fail(PersianText::toPersianDigits('متن قالب حداکثر '.self::BODY_MAX.' نویسه باشد.'));
        }
        if (substr_count($body, '{') !== substr_count($body, '}')) {
            $fail('آکولادها { } جفت نیستند؛ متغیرها باید مثل {نام_گیرنده} نوشته شوند.');
        }

        $vars = self::variables();
        $tokens = self::placeholders($body);
        $unknown = array_values(array_filter($tokens, fn ($t) => self::key($t) === null));
        if ($unknown) {
            $fail('این متغیرها وجود ندارند: '.implode('، ', array_map(fn ($k) => '{'.$k.'}', $unknown)));
        }
        $used = array_values(array_unique(array_map(fn ($t) => self::key($t), $tokens)));
        $wrongOccasion = array_values(array_filter($used, fn ($k) => $vars[$k]['occasions'] !== null && ! in_array($occasion, $vars[$k]['occasions'], true)));
        if ($wrongOccasion) {
            $fail('این متغیرها برای این مناسبت نیستند: '.implode('، ', array_map(fn ($k) => '{'.self::faName($k).'} ('.$vars[$k]['label'].')', $wrongOccasion)));
        }
        if (! array_intersect($used, self::SENDER_KEYS)) {
            $fail('متن باید نام فرستنده را داشته باشد؛ یکی از {از_طرف}، {از_طرف_رسمی}، {نام_کامل_فرستنده} یا {نام_فرستنده} را بگذارید.');
        }
        if (preg_match('~(https?://|www\.)~iu', $body)) {
            $fail('لینک در پیامک تبریک مجاز نیست.');
        }

        return $body;
    }

    /**
     * جایگذاری متغیرها؛ سطری که همه متغیرهایش خالی باشد حذف می‌شود.
     *
     * @param  array<string, string|int|null>  $values
     */
    public static function render(string $body, array $values): string
    {
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            $vars = 0;
            $filled = 0;
            $line = preg_replace_callback('/\{([^{}\n]{1,40})\}/u', function ($m) use (&$vars, &$filled, $values) {
                $key = self::key($m[1]);
                if ($key === null) {
                    return $m[0];
                }
                $vars++;
                $value = trim((string) ($values[$key] ?? ''));
                if ($value !== '') {
                    $filled++;
                }

                return $value;
            }, $line) ?? $line;
            if ($vars > 0 && $filled === 0) {
                continue;
            }
            // پرانتز یا گیومه‌ای که متغیرِ داخلش خالی مانده: «()» ، ««»»
            $line = preg_replace('/\(\s*\)|«\s*»|\[\s*\]|"\s*"/u', '', $line) ?? $line;
            $line = trim(preg_replace('/[ \t]{2,}/u', ' ', $line) ?? $line);
            // «احمدی ،» ← «احمدی،»
            $line = preg_replace('/\s+([،,.!؟?:؛])/u', '$1', $line) ?? $line;
            $out[] = $line;
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)) ?? '');
    }

    /** متن قابل‌نمایش برای اعضا: متغیرها به صورت «برچسب کوتاه» */
    public static function display(string $body): string
    {
        $vars = self::variables();
        $special = [
            'from_line' => 'از طرف «نسبت و نام شما»',
            'from_line_formal' => 'از طرف «نسبت و نام شما»',
            'note' => '«یادداشت کوتاه شما»',
            'site_name' => (string) config('pedigree.site_name'),
        ];

        return preg_replace_callback('/\{([^{}\n]{1,40})\}/u', function ($m) use ($vars, $special) {
            $key = self::key($m[1]);
            if ($key === null) {
                return $m[0];
            }

            return $special[$key] ?? '«'.trim(preg_replace('/\s*\(.*?\)/u', '', $vars[$key]['label']) ?? '').'»';
        }, $body) ?? $body;
    }

    /** @return Collection<int, SmsTemplate> قالب‌های فعال یک مناسبت به ترتیب نمایش */
    public static function active(string $occasion): Collection
    {
        return SmsTemplate::query()->where('occasion', $occasion)->where('active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /** قالب فعال با شناسه (یا نام قدیمی مثل warm)؛ اگر نبود اولین قالب فعال */
    public static function resolve(string $occasion, int|string|null $ref): ?SmsTemplate
    {
        $active = self::active($occasion);
        if ($ref !== null && $ref !== '') {
            $match = $active->first(fn (SmsTemplate $t) => (string) $t->id === (string) $ref || ($t->slug !== null && $t->slug === $ref));
            if ($match) {
                return $match;
            }
        }

        return $active->first();
    }

    /** تعداد بخش‌های پیامک (فارسی: ۷۰ نویسه، چندبخشی ۶۷) */
    public static function parts(string $text): int
    {
        $length = mb_strlen($text);

        return $length <= 70 ? 1 : (int) ceil($length / 67);
    }

    /** ساخت قالب‌های پیش‌فرض (نصب اولیه یا بازگردانی) */
    public static function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $i => [$occasion, $slug, $title, $body]) {
            SmsTemplate::query()->updateOrCreate(['slug' => $slug], [
                'occasion' => $occasion,
                'title' => $title,
                'body' => $body,
                'active' => true,
                'sort_order' => $i,
            ]);
        }
    }
}
