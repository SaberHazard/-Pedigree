<?php

namespace App\Services\Calendar;

use App\Models\CalendarDay;
use App\Models\HijriMonth;
use App\Services\Social\SafeHttp;
use App\Support\ErrorReporter;
use App\Support\Hijri;
use App\Support\Jalali;
use App\Support\Outbound;
use App\Support\PersianText;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * همگام‌سازی تقویم رسمی کشور (منبع: time.ir از طریق holidayapi.ir):
 *  - تعطیلی و مناسبت‌های هر روز خورشیدی (مذهبی، ملی و جهانی)
 *  - از مناسبت‌های قمری (مثل «۱۳ رجب») آغاز رسمی هر ماه قمری محاسبه می‌شود تا تاریخ قمری کل سایت دقیقاً مطابق تقویم رسمی باشد
 *
 * هر روز یک درخواست کوچک است؛ روزهای گذشته یک بار و روزهای آینده هر ۳۰ روز یک بار دوباره گرفته می‌شوند
 * (تقویم رسمی سال بعد معمولاً اواخر سال منتشر یا اصلاح می‌شود). پاسخ‌ها کاملاً اعتبارسنجی و پاک‌سازی می‌شوند.
 */
class OfficialCalendarSync
{
    private const HOST = 'holidayapi.ir';

    /** نام ماه‌های قمری در متن تقویم رسمی (با املاهای مختلف) */
    private const HIJRI_NAMES = [
        'محرم' => 1, 'صفر' => 2,
        'ربیع الاول' => 3, 'ربیع الاولی' => 3,
        'ربیع الثانی' => 4, 'ربیع الاخر' => 4, 'ربیع الآخر' => 4,
        'جمادی الاول' => 5, 'جمادی الاولی' => 5,
        'جمادی الثانی' => 6, 'جمادی الثانیه' => 6, 'جمادی الاخره' => 6, 'جمادی الآخره' => 6,
        'رجب' => 7, 'شعبان' => 8, 'رمضان' => 9, 'شوال' => 10,
        'ذی القعده' => 11, 'ذیقعده' => 11, 'ذی قعده' => 11, 'ذوالقعده' => 11, 'ذو القعده' => 11,
        'ذی الحجه' => 12, 'ذیحجه' => 12, 'ذی حجه' => 12, 'ذوالحجه' => 12, 'ذو الحجه' => 12,
    ];

    public function __construct(
        private readonly SafeHttp $http,
        private readonly HijriCalendar $hijri,
    ) {}

    /** مسیر اتصال: پراکسی خاص تقویم، وگرنه (سرور خارج) پراکسی داخل ایران، وگرنه مستقیم */
    public static function proxy(): string
    {
        $specific = trim((string) config('pedigree.calendar.proxy'));
        if ($specific !== '') {
            return $specific;
        }

        return (Outbound::location() === 'abroad' ? Outbound::iran() : null) ?? SafeHttp::DIRECT;
    }

    public static function enabled(): bool
    {
        return (bool) config('pedigree.calendar.official_sync', true);
    }

    /**
     * همگام‌سازی حداکثر $limit روزِ نیازمند به‌روزرسانی از سال‌های داده‌شده
     *
     * @param  int[]  $years  سال‌های خورشیدی
     * @return array{fetched: int, failed: int, months: int}
     */
    public function sync(array $years, int $limit = 80): array
    {
        $stats = ['fetched' => 0, 'failed' => 0, 'months' => 0];
        if (! self::enabled()) {
            return $stats;
        }
        $today = Jalali::today();
        $todayKey = sprintf('%04d-%02d-%02d', ...$today);
        foreach ($years as $year) {
            $known = CalendarDay::query()->where('jalali', 'like', sprintf('%04d-', $year).'%')->pluck('fetched_at', 'jalali');
            for ($m = 1; $m <= 12 && $limit > $stats['fetched'] + $stats['failed']; $m++) {
                $len = Jalali::monthLength($year, $m);
                for ($d = 1; $d <= $len && $limit > $stats['fetched'] + $stats['failed']; $d++) {
                    $key = sprintf('%04d-%02d-%02d', $year, $m, $d);
                    $fetched = $known[$key] ?? null;
                    // گذشته: یک بار کافی است؛ آینده: هر ۳۰ روز (تقویم رسمی ممکن است اصلاح شود)
                    if ($fetched !== null && ($key < $todayKey || now()->diffInDays($fetched, true) < 30)) {
                        continue;
                    }
                    $this->fetchDay($year, $m, $d) ? $stats['fetched']++ : $stats['failed']++;
                    if ($stats['failed'] >= 5 && $stats['fetched'] === 0) {
                        // سرویس در دسترس نیست؛ دفعه بعد
                        break 3;
                    }
                    usleep(150_000);
                }
            }
        }
        if ($stats['fetched'] > 0) {
            $stats['months'] = $this->calibrateHijri();
        }

        return $stats;
    }

    public function fetchDay(int $y, int $m, int $d): bool
    {
        try {
            $res = $this->http->get(sprintf('https://%s/jalali/%04d/%02d/%02d', self::HOST, $y, $m, $d), [self::HOST], 64 * 1024, ['Accept' => 'application/json'], 0, self::proxy(), 12);
            $json = json_decode($res['body'], true);
            if ($res['status'] !== 200 || ! is_array($json) || ! array_key_exists('is_holiday', $json) || ! is_array($json['events'] ?? null)) {
                return false;
            }
            $events = [];
            foreach (array_slice($json['events'], 0, 15) as $e) {
                if (! is_array($e) || ! is_string($e['description'] ?? null)) {
                    continue;
                }
                $title = self::clean($e['description'], 200);
                // «جمعه» در داده رسمی یک مناسبت جداست؛ تعطیلی جمعه را خود تقویم نشان می‌دهد
                if ($title === '' || in_array($title, CalendarService::WEEKDAYS, true)) {
                    continue;
                }
                $events[] = [
                    'title' => $title,
                    'note' => self::clean((string) ($e['additional_description'] ?? ''), 60),
                    'holiday' => (bool) ($e['is_holiday'] ?? false),
                    'religious' => (bool) ($e['is_religious'] ?? false),
                ];
            }
            [$gy, $gm, $gd] = Jalali::toGregorian($y, $m, $d);
            CalendarDay::query()->updateOrCreate(
                ['jalali' => sprintf('%04d-%02d-%02d', $y, $m, $d)],
                ['gregorian' => sprintf('%04d-%02d-%02d', $gy, $gm, $gd), 'is_holiday' => (bool) $json['is_holiday'], 'events' => $events, 'fetched_at' => now()],
            );

            return true;
        } catch (Throwable $e) {
            Log::info('Official calendar fetch failed', ['day' => "{$y}-{$m}-{$d}", 'error' => $e::class]);

            return false;
        }
    }

    /**
     * از مناسبت‌های قمری روزهای همگام‌شده، آغاز رسمی ماه‌های قمری را حساب و ذخیره می‌کند.
     * هر مشاهده «روز N از ماه M» آغاز ماه را می‌دهد؛ رأی اکثریت، و فقط اگر با ماه‌های مجاور ۲۹ یا ۳۰ روز فاصله داشته باشد.
     *
     * @return int تعداد ماه‌های ثبت یا به‌روزشده
     */
    public function calibrateHijri(): int
    {
        try {
            $votes = [];
            CalendarDay::query()->whereNotNull('events')->orderBy('jalali')->chunk(500, function ($days) use (&$votes) {
                foreach ($days as $day) {
                    foreach ((array) $day->events as $event) {
                        $parsed = self::parseHijriNote((string) ($event['note'] ?? ''));
                        if ($parsed === null) {
                            continue;
                        }
                        [$hd, $hm] = $parsed;
                        [$gy, $gm, $gd] = array_map('intval', explode('-', substr((string) $day->getRawOriginal('gregorian'), 0, 10)));
                        $startJdn = Hijri::gregorianToJdn($gy, $gm, $gd) - ($hd - 1);
                        // سال قمری: نزدیک‌ترین سال حسابی که آغاز همین ماهش به این روز نزدیک است
                        [$ty] = Hijri::fromJdn($startJdn + 15);
                        $best = null;
                        foreach ([$ty - 1, $ty, $ty + 1] as $cy) {
                            $diff = abs(Hijri::toJdn($cy, $hm, 1) - $startJdn);
                            if ($best === null || $diff < $best[1]) {
                                $best = [$cy, $diff];
                            }
                        }
                        if ($best === null || $best[1] > 5) {
                            continue;
                        }
                        $key = $best[0].'-'.$hm;
                        $votes[$key][$startJdn] = ($votes[$key][$startJdn] ?? 0) + 1;
                    }
                }
            });

            $starts = [];
            foreach ($votes as $key => $candidates) {
                arsort($candidates);
                [$y, $m] = array_map('intval', explode('-', $key));
                $starts[$y * 12 + $m] = [$y, $m, (int) array_key_first($candidates)];
            }
            ksort($starts);
            // سازگاری: فاصله دو ماه پشت‌سرهم ۲۹ یا ۳۰ روز
            $valid = [];
            foreach ($starts as $idx => $row) {
                $prev = $starts[$idx - 1] ?? null;
                if ($prev && ! in_array($row[2] - $prev[2], [29, 30], true)) {
                    Log::warning('Hijri calibration conflict', ['month' => $row[0].'-'.$row[1]]);

                    continue;
                }
                $valid[] = $row;
            }

            $count = 0;
            foreach ($valid as [$y, $m, $jdn]) {
                $existing = HijriMonth::query()->where('year', $y)->where('month', $m)->first();
                if ($existing && $existing->source === 'manual') {
                    continue;
                }
                [$gy, $gm, $gd] = Hijri::jdnToGregorian($jdn);
                $date = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
                if (! $existing || $existing->starts_on !== $date) {
                    HijriMonth::query()->updateOrCreate(['year' => $y, 'month' => $m], ['starts_on' => $date, 'source' => 'official']);
                    $count++;
                }
            }
            $this->hijri->forget();

            return $count;
        } catch (Throwable $e) {
            ErrorReporter::record($e);

            return 0;
        }
    }

    /**
     * «۱۳ رجب» / «2 شوال» ← [روز، ماه]
     *
     * @return array{0:int,1:int}|null
     */
    public static function parseHijriNote(string $note): ?array
    {
        $note = PersianText::toLatinDigits(trim(str_replace(["\u{200C}", 'ي', 'ك', 'ة', '‌'], [' ', 'ی', 'ک', 'ه', ' '], $note)));
        $note = preg_replace('/\s+/u', ' ', $note) ?? $note;
        if (! preg_match('/^(\d{1,2}) (.+)$/u', $note, $m)) {
            return null;
        }
        $day = (int) $m[1];
        $month = self::HIJRI_NAMES[str_replace('آ', 'ا', $m[2])] ?? self::HIJRI_NAMES[$m[2]] ?? null;
        if ($month === null || $day < 1 || $day > 30) {
            return null;
        }

        return [$day, $month];
    }

    private static function clean(string $text, int $max): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, $max);
    }
}
