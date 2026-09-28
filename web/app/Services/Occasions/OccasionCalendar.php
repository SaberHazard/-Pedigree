<?php

namespace App\Services\Occasions;

use App\Models\Marriage;
use App\Models\Person;
use App\Support\Jalali;
use Illuminate\Support\Collection;

/**
 * مناسبت‌های پیامک تبریک و بازه ارسال هر کدام (تقویم شمسی، وقت تهران):
 *  - تولد: روز تولد یا فردای آن
 *  - سالگرد ازدواج: روز سالگرد یا فردای آن (فقط ازدواج‌های پابرجا با تاریخ کامل)
 *  - نوروز: ۲۵ اسفند تا ۱۳ فروردین
 *  - شب یلدا: ۳۰ آذر و ۱ دی
 */
class OccasionCalendar
{
    public const WINDOWS = [
        'birthday' => 'روز تولد یا فردای آن',
        'anniversary' => 'روز سالگرد ازدواج یا فردای آن',
        'nowruz' => 'از ۲۵ اسفند تا ۱۳ فروردین',
        'yalda' => '۳۰ آذر و ۱ دی',
    ];

    public function __construct(private readonly BirthdayService $birthdays) {}

    /** آیا بازه مناسبت‌های فصلی (نوروز، یلدا) امروز باز است؟ تولد و سالگرد برای هر شخص جداگانه‌اند. */
    public function isOpen(string $occasion): bool
    {
        [, $m, $d] = $this->birthdays->today();

        return match ($occasion) {
            'birthday', 'anniversary' => true,
            'nowruz' => ($m === 12 && $d >= 25) || ($m === 1 && $d <= 13),
            'yalda' => ($m === 9 && $d === 30) || ($m === 10 && $d === 1),
            default => false,
        };
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
