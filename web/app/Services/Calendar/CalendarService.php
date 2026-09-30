<?php

namespace App\Services\Calendar;

use App\Exceptions\DomainException;
use App\Models\CalendarDay;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Services\KinshipDegrees;
use App\Support\CalendarEvents;
use App\Support\Hijri;
use App\Support\Jalali;
use Illuminate\Support\Facades\Cache;

/**
 * تقویم فارسی (مثل «باد صبا»): هر روز با تاریخ خورشیدی، قمری (مطابق تقویم رسمی) و میلادی، تعطیلی، مناسبت‌های
 * رسمی/مذهبی/جهانی و مناسبت‌های خانوادگی (تولد، سالگرد ازدواج و سالگرد درگذشت بستگان تا درجه ۴).
 */
class CalendarService
{
    public const GREGORIAN_MONTHS = [
        1 => 'ژانویه', 2 => 'فوریه', 3 => 'مارس', 4 => 'آوریل', 5 => 'مه', 6 => 'ژوئن',
        7 => 'ژوئیه', 8 => 'اوت', 9 => 'سپتامبر', 10 => 'اکتبر', 11 => 'نوامبر', 12 => 'دسامبر',
    ];

    public const WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    /** بازه پشتیبانی‌شده الگوریتم خورشیدی */
    public const MIN_YEAR = 1;

    public const MAX_YEAR = 3177;

    public function __construct(
        private readonly HijriCalendar $hijri,
        private readonly KinshipDegrees $degrees,
    ) {}

    /**
     * یک ماه خورشیدی کامل
     *
     * @param  callable(string $fromUtc, string $toUtc): array<string, array>|null  $reminders  یادآورهای کاربر به تفکیک روز (YYYY-MM-DD خورشیدی)
     */
    public function month(?User $user, int $jy, int $jm, ?callable $reminders = null, int $degree = 4): array
    {
        if ($jy < self::MIN_YEAR || $jy > self::MAX_YEAR || $jm < 1 || $jm > 12) {
            throw new DomainException('این تاریخ در بازه تقویم نیست.');
        }
        $length = Jalali::monthLength($jy, $jm);
        $first = sprintf('%04d-%02d-01', $jy, $jm);
        $last = sprintf('%04d-%02d-%02d', $jy, $jm, $length);
        $official = CalendarDay::query()->whereBetween('jalali', [$first, $last])->get()->keyBy('jalali');
        $family = $user ? $this->familyEvents($user, $jm, max(1, min(4, $degree))) : [];
        $today = Jalali::today();

        [$gy1, $gm1, $gd1] = Jalali::toGregorian($jy, $jm, 1);
        [$gy2, $gm2, $gd2] = Jalali::toGregorian($jy, $jm, $length);
        $mine = $reminders
            ? $reminders(
                now('Asia/Tehran')->setDate($gy1, $gm1, $gd1)->startOfDay()->utc()->toDateTimeString(),
                now('Asia/Tehran')->setDate($gy2, $gm2, $gd2)->endOfDay()->utc()->toDateTimeString(),
            )
            : [];

        $days = [];
        $hijriMonths = [];
        for ($d = 1; $d <= $length; $d++) {
            [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, $d);
            [$hy, $hm, $hd] = $this->hijri->fromGregorian($gy, $gm, $gd);
            $hijriMonths[$hy * 12 + $hm] = [$hy, $hm];
            $weekday = (Hijri::gregorianToJdn($gy, $gm, $gd) + 2) % 7; // ۰ = شنبه
            $key = sprintf('%04d-%02d-%02d', $jy, $jm, $d);
            $row = $official->get($key);
            if ($row) {
                $stored = array_filter((array) $row->events, fn ($e) => is_array($e) && isset($e['title']) && ! in_array($e['title'], self::WEEKDAYS, true));
                $events = array_map(fn (array $e) => [
                    'title' => $e['title'],
                    'note' => $e['note'] ?? '',
                    'kind' => ! empty($e['religious']) ? 'religious' : (preg_match('/[A-Za-z]/', (string) ($e['note'] ?? '')) ? 'international' : 'national'),
                    'holiday' => (bool) ($e['holiday'] ?? false),
                ], array_values($stored));
                $holiday = $row->is_holiday;
            } else {
                $events = CalendarEvents::forDay([$jy, $jm, $d], [$gy, $gm, $gd], [$hy, $hm, $hd], $this->hijri->monthLength($hy, $hm));
                $holiday = collect($events)->contains('holiday', true);
            }
            $days[] = [
                'date' => $key,
                'day' => $d,
                'weekday' => $weekday,
                'g' => [$gy, $gm, $gd],
                'h' => [$hy, $hm, $hd],
                'holiday' => $holiday || $weekday === 6,
                'official' => $row !== null,
                'today' => $today === [$jy, $jm, $d],
                'events' => $events,
                'family' => $family[$d] ?? [],
                'reminders' => $mine[$key] ?? [],
            ];
        }

        $gLabel = $gm1 === $gm2
            ? self::GREGORIAN_MONTHS[$gm1].' '.$gy1
            : self::GREGORIAN_MONTHS[$gm1].($gy1 !== $gy2 ? ' '.$gy1 : '').' – '.self::GREGORIAN_MONTHS[$gm2].' '.$gy2;
        $hList = array_values($hijriMonths);
        $hLabel = implode(' – ', array_map(fn ($h) => Hijri::MONTHS[$h[1]], $hList)).' '.end($hList)[0];

        return [
            'year' => $jy,
            'month' => $jm,
            'month_name' => Jalali::MONTHS[$jm],
            'length' => $length,
            'leap' => Jalali::isLeap($jy),
            'gregorian_label' => $gLabel,
            'hijri_label' => $hLabel,
            'official' => $official->count() === $length,
            'days' => $days,
        ];
    }

    /**
     * تبدیل تاریخ بین سه تقویم
     *
     * @return array{jalali: array, gregorian: array, hijri: array, weekday: int, weekday_name: string}
     */
    public function convert(string $calendar, int $y, int $m, int $d): array
    {
        [$gy, $gm, $gd] = match ($calendar) {
            'jalali' => Jalali::isValid($y, $m, $d) && $y >= self::MIN_YEAR && $y <= self::MAX_YEAR
                ? Jalali::toGregorian($y, $m, $d) : throw new DomainException('تاریخ خورشیدی معتبر نیست.'),
            'gregorian' => checkdate($m, $d, $y) && $y >= 623 && $y <= 3798
                ? [$y, $m, $d] : throw new DomainException('تاریخ میلادی معتبر نیست.'),
            'hijri' => $y >= 1 && $y <= 3300 && $m >= 1 && $m <= 12 && $d >= 1 && $d <= $this->hijri->monthLength($y, $m)
                ? $this->hijri->toGregorian($y, $m, $d) : throw new DomainException('تاریخ قمری معتبر نیست.'),
            default => throw new DomainException('نوع تقویم معتبر نیست.'),
        };
        $weekday = (Hijri::gregorianToJdn($gy, $gm, $gd) + 2) % 7;

        return [
            'jalali' => Jalali::fromGregorian($gy, $gm, $gd),
            'gregorian' => [$gy, $gm, $gd],
            'hijri' => $this->hijri->fromGregorian($gy, $gm, $gd),
            'weekday' => $weekday,
            'weekday_name' => self::WEEKDAYS[$weekday],
        ];
    }

    /**
     * مناسبت‌های خانوادگی یک ماه خورشیدی برای بستگان کاربر تا درجه داده‌شده (فقط تاریخ‌های کامل)
     *
     * @return array<int, array<int, array{type: string, person: array, years: ?int}>> روز ← فهرست
     */
    private function familyEvents(User $user, int $jm, int $degree = 4): array
    {
        $me = $user->person;
        if ($me === null) {
            return [];
        }
        $ids = Cache::remember("calendar-kin:{$user->id}:{$degree}", 600, fn () => array_keys($this->degrees->from($me, $degree)) ?: []);
        $ids[] = $me->id;
        $ids = array_values(array_unique($ids));
        $pattern = '%-'.sprintf('%02d', $jm).'-%';
        $out = [];
        [$ty] = Jalali::today();

        $people = Person::query()->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('birth_date', 'like', $pattern)->orWhere('death_date', 'like', $pattern))
            ->get(['id', 'first_name', 'last_name', 'title', 'gender', 'birth_date', 'death_date', 'is_deceased', 'education_level', 'education_field', 'education_field_group', 'academic_rank', 'honorific_mode']);
        foreach ($people as $p) {
            $ref = ['id' => $p->id, 'name' => $p->fullName(), 'gender' => $p->gender];
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $p->birth_date, $b) && (int) $b[2] === $jm) {
                $out[(int) $b[3]][] = ['type' => $p->is_deceased ? 'birth_memorial' : 'birthday', 'person' => $ref, 'years' => $ty - (int) $b[1]];
            }
            if ($p->is_deceased && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $p->death_date, $x) && (int) $x[2] === $jm) {
                $out[(int) $x[3]][] = ['type' => 'death_anniversary', 'person' => $ref, 'years' => $ty - (int) $x[1]];
            }
        }

        $marriages = Marriage::query()->where('status', 'married')->where('marriage_date', 'like', $pattern)
            ->where(fn ($q) => $q->whereIn('husband_id', $ids)->orWhereIn('wife_id', $ids))
            ->with(['husband:id,first_name,last_name,gender,is_deceased', 'wife:id,first_name,last_name,gender,is_deceased'])
            ->limit(200)->get();
        foreach ($marriages as $m) {
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $m->marriage_date, $x) || ! $m->husband || ! $m->wife || $m->husband->is_deceased || $m->wife->is_deceased) {
                continue;
            }
            $out[(int) $x[3]][] = [
                'type' => 'anniversary',
                'person' => ['id' => $m->husband->id, 'name' => trim($m->husband->first_name.' و '.$m->wife->first_name.' '.$m->husband->last_name), 'gender' => 'm'],
                'years' => $ty - (int) $x[1],
            ];
        }

        return $out;
    }
}
