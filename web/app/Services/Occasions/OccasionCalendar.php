<?php

namespace App\Services\Occasions;

use App\Models\Marriage;
use App\Models\Person;
use App\Support\Hijri;
use App\Support\Jalali;
use App\Support\PersianText;
use Illuminate\Support\Collection;

/**
 * مناسبت‌های پیامک تبریک و بازه ارسال هر کدام (وقت تهران):
 *  - تولد: روز تولد یا فردای آن — سالگرد ازدواج: روز سالگرد یا فردای آن (فقط ازدواج‌های پابرجا)
 *  - مناسبت‌های شمسی: نوروز (۲۵ اسفند تا ۱۳ فروردین)، شب یلدا، سپندارمذگان
 *  - مناسبت‌های قمری (با «اختلاف روز» قابل تنظیم در پنل): روز مادر، روز پدر، نیمه شعبان، عید فطر، قربان و غدیر
 *
 * مدیر کل هر مناسبت (جز تولد) را از پنل روشن یا خاموش می‌کند.
 */
class OccasionCalendar
{
    /**
     * kind: person (تولد)، couple (سالگرد)، seasonal (همه بستگان)
     * ranges: [ماه شروع، روز شروع، ماه پایان، روز پایان] در همان تقویم
     * audience: mothers | fathers (فقط مادران/پدرانی که در درخت فرزند دارند)
     * auto: روزِ تبریک خودکار ساعت ۰۰:۰۰ [ماه، روز] (پیش‌فرض: نخستین روز بازه)
     */
    public const DEFINITIONS = [
        'birthday' => ['kind' => 'person', 'window' => 'روز تولد یا فردای آن'],
        'anniversary' => ['kind' => 'couple', 'window' => 'روز سالگرد ازدواج یا فردای آن'],
        'nowruz' => ['kind' => 'seasonal', 'calendar' => 'jalali', 'ranges' => [[12, 25, 12, 30], [1, 1, 1, 13]], 'auto' => [1, 1], 'window' => 'از ۲۵ اسفند تا ۱۳ فروردین'],
        'yalda' => ['kind' => 'seasonal', 'calendar' => 'jalali', 'ranges' => [[9, 30, 9, 30], [10, 1, 10, 1]], 'window' => '۳۰ آذر و ۱ دی'],
        'sepandarmazgan' => ['kind' => 'seasonal', 'calendar' => 'jalali', 'ranges' => [[11, 29, 11, 30]], 'window' => '۲۹ و ۳۰ بهمن'],
        'mother_day' => ['kind' => 'seasonal', 'calendar' => 'hijri', 'ranges' => [[6, 20, 6, 21]], 'audience' => 'mothers', 'window' => '۲۰ و ۲۱ جمادی‌الثانی (فقط برای مادران)'],
        'father_day' => ['kind' => 'seasonal', 'calendar' => 'hijri', 'ranges' => [[7, 13, 7, 14]], 'audience' => 'fathers', 'window' => '۱۳ و ۱۴ رجب (فقط برای پدران)'],
        'nimeh_shaban' => ['kind' => 'seasonal', 'calendar' => 'hijri', 'ranges' => [[8, 15, 8, 16]], 'window' => '۱۵ و ۱۶ شعبان'],
        'eid_fitr' => ['kind' => 'seasonal', 'calendar' => 'hijri', 'ranges' => [[10, 1, 10, 3]], 'window' => '۱ تا ۳ شوال'],
        'eid_adha' => ['kind' => 'seasonal', 'calendar' => 'hijri', 'ranges' => [[12, 10, 12, 11]], 'window' => '۱۰ و ۱۱ ذی‌الحجه'],
        'eid_ghadir' => ['kind' => 'seasonal', 'calendar' => 'hijri', 'ranges' => [[12, 18, 12, 19]], 'window' => '۱۸ و ۱۹ ذی‌الحجه'],
    ];

    /** سازگاری با کد قبلی: متن بازه هر مناسبت */
    public const WINDOWS = [
        'birthday' => 'روز تولد یا فردای آن',
        'anniversary' => 'روز سالگرد ازدواج یا فردای آن',
        'nowruz' => 'از ۲۵ اسفند تا ۱۳ فروردین',
        'yalda' => '۳۰ آذر و ۱ دی',
        'sepandarmazgan' => '۲۹ و ۳۰ بهمن',
        'mother_day' => '۲۰ و ۲۱ جمادی‌الثانی (فقط برای مادران)',
        'father_day' => '۱۳ و ۱۴ رجب (فقط برای پدران)',
        'nimeh_shaban' => '۱۵ و ۱۶ شعبان',
        'eid_fitr' => '۱ تا ۳ شوال',
        'eid_adha' => '۱۰ و ۱۱ ذی‌الحجه',
        'eid_ghadir' => '۱۸ و ۱۹ ذی‌الحجه',
    ];

    public function __construct(private readonly BirthdayService $birthdays) {}

    /** مناسبت‌های فعال (تولد همیشه فعال است و قابل خاموش کردن نیست) */
    public static function enabledKeys(): array
    {
        $on = (array) config('pedigree.occasions.enabled', array_keys(self::DEFINITIONS));

        return array_values(array_filter(array_keys(self::DEFINITIONS), fn ($k) => $k === 'birthday' || in_array($k, $on, true)));
    }

    public static function enabled(string $occasion): bool
    {
        return in_array($occasion, self::enabledKeys(), true);
    }

    public static function isSeasonal(string $occasion): bool
    {
        return (self::DEFINITIONS[$occasion]['kind'] ?? null) === 'seasonal';
    }

    /** اختلاف روز تقویم قمری (پنل مدیریت؛ بین ‎-3 و 3) */
    public static function hijriOffset(): int
    {
        return max(-3, min(3, (int) config('pedigree.occasions.hijri_offset', 0)));
    }

    /** آیا بازه مناسبت امروز باز است؟ تولد و سالگرد برای هر شخص جداگانه‌اند. */
    public function isOpen(string $occasion): bool
    {
        if (! isset(self::DEFINITIONS[$occasion]) || ! self::enabled($occasion)) {
            return false;
        }
        if (! self::isSeasonal($occasion)) {
            return true;
        }
        [$y, $m, $d] = $this->birthdays->today();

        return $this->openOn($occasion, Jalali::toGregorian($y, $m, $d));
    }

    /**
     * آیا امروز (وقت تهران) روز تبریک خودکار این مناسبت همگانی است؟ (مثلاً ۱ فروردین برای نوروز، ۱ شوال برای عید فطر)
     */
    public function dueToday(string $occasion): bool
    {
        $def = self::DEFINITIONS[$occasion] ?? null;
        if (! $def || ($def['kind'] ?? null) !== 'seasonal' || ! self::enabled($occasion)) {
            return false;
        }
        [$am, $ad] = $def['auto'] ?? array_slice($def['ranges'][0], 0, 2);
        [$y, $m, $d] = $this->birthdays->today();
        if ($def['calendar'] === 'hijri') {
            [$gy, $gm, $gd] = Jalali::toGregorian($y, $m, $d);
            [, $m, $d] = Hijri::fromGregorian($gy, $gm, $gd, self::hijriOffset());
        }

        return $m === $am && $d === $ad;
    }

    /** آیا بازه این مناسبت در این روز (میلادی) باز است؟ */
    public function openOn(string $occasion, array $gregorian): bool
    {
        $def = self::DEFINITIONS[$occasion] ?? null;
        if (! $def || ($def['kind'] ?? null) !== 'seasonal') {
            return false;
        }
        [$gy, $gm, $gd] = $gregorian;
        [, $m, $d] = $def['calendar'] === 'hijri'
            ? Hijri::fromGregorian($gy, $gm, $gd, self::hijriOffset())
            : Jalali::fromGregorian($gy, $gm, $gd);
        foreach ($def['ranges'] as [$m1, $d1, $m2, $d2]) {
            $v = $m * 100 + $d;
            if ($v >= $m1 * 100 + $d1 && $v <= $m2 * 100 + $d2) {
                return true;
            }
        }

        return false;
    }

    /**
     * نخستین روز بازه بعدی (یا همین امروز) به شمسی، مثل «۲۹ اسفند ۱۴۰۵»؛ برای نمایش «امسال کِی است»
     */
    public function nextText(string $occasion): ?string
    {
        if (! self::isSeasonal($occasion)) {
            return null;
        }
        [$y, $m, $d] = $this->birthdays->today();
        $jdn = Hijri::gregorianToJdn(...Jalali::toGregorian($y, $m, $d));
        for ($i = 0; $i <= 400; $i++) {
            $g = Hijri::jdnToGregorian($jdn + $i);
            if ($this->openOn($occasion, $g)) {
                [$jy, $jm, $jd] = Jalali::fromGregorian(...$g);

                return PersianText::toPersianDigits(($i === 0 ? 'امروز، ' : '')."{$jd} ".Jalali::MONTHS[$jm]." {$jy}");
            }
        }

        return null;
    }

    /** سال نوی شمسی (در اسفند، سال بعد) */
    public function newYear(): int
    {
        [$y, $m] = $this->birthdays->today();

        return $m === 12 ? $y + 1 : $y;
    }

    /** «۶ مهر ۱۴۰۵» */
    public function todayText(): string
    {
        [$y, $m, $d] = $this->birthdays->today();

        return "{$d} ".Jalali::MONTHS[$m]." {$y}";
    }

    /**
     * سالگردهای ازدواج در بازه [امروز - before، امروز + after]
     *
     * @return Collection<int, array{marriage: Marriage, in_days: int, years: int}>
     */
    public function anniversaries(int $before = 1, int $after = 7): Collection
    {
        [$jy, $jm, $jd] = $this->birthdays->today();
        $base = new \DateTimeImmutable(vsprintf('%04d-%02d-%02d', Jalali::toGregorian($jy, $jm, $jd)), new \DateTimeZone('Asia/Tehran'));
        $days = [];
        for ($offset = -$before; $offset <= $after; $offset++) {
            $date = $base->modify(($offset >= 0 ? '+' : '').$offset.' day');
            [$y, $m, $d] = Jalali::fromGregorian((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
            $days[sprintf('%02d-%02d', $m, $d)] = [$offset, $y];
            if ($m === 12 && $d === 29 && ! Jalali::isLeap($y)) {
                $days['12-30'] = [$offset, $y];
            }
        }

        $out = collect();
        $query = Marriage::query()->with(['husband.avatar', 'wife.avatar'])->where('status', 'married')
            ->where(function ($q) use ($days) {
                foreach (array_keys($days) as $md) {
                    $q->orWhere('marriage_date', 'like', '____-'.$md);
                }
            });
        foreach ($query->get() as $marriage) {
            $md = substr((string) $marriage->marriage_date, 5);
            $year = (int) substr((string) $marriage->marriage_date, 0, 4);
            if (! isset($days[$md]) || $year <= 0 || ! $marriage->husband || ! $marriage->wife) {
                continue;
            }
            [$offset, $currentYear] = $days[$md];
            if ($currentYear - $year < 1) {
                continue;
            }
            $out->push(['marriage' => $marriage, 'in_days' => $offset, 'years' => $currentYear - $year]);
        }

        return $out->sortBy('in_days')->values();
    }

    /**
     * سالگرد ازدواجِ باز (امروز یا دیروز) برای این شخص
     *
     * @return array{in_days: int, years: int, spouse: Person}|null
     */
    public function anniversaryFor(Person $person): ?array
    {
        foreach ($this->anniversaries(1, 0) as $row) {
            $m = $row['marriage'];
            if ($m->husband_id === $person->id || $m->wife_id === $person->id) {
                return [
                    'in_days' => $row['in_days'],
                    'years' => $row['years'],
                    'spouse' => $m->husband_id === $person->id ? $m->wife : $m->husband,
                ];
            }
        }

        return null;
    }
}
