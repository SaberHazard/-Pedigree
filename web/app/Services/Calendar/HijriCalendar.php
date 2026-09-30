<?php

namespace App\Services\Calendar;

use App\Models\HijriMonth;
use App\Services\Occasions\OccasionCalendar;
use App\Support\Hijri;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * تقویم هجری قمری مطابق تقویم رسمی ایران.
 *
 * آغاز هر ماه قمری در ایران با رؤیت/محاسبه نجومی مرکز تقویم تعیین می‌شود و ممکن است با محاسبه حسابی یک یا دو روز
 * فرق کند. این کلاس برای ماه‌هایی که آغاز رسمی‌شان از تقویم رسمی همگام شده (یا مدیر دستی وارد کرده) دقیقاً همان را
 * به کار می‌برد و برای بقیه، محاسبه حسابی به‌علاوه «اختلاف روز» پنل مدیریت.
 */
class HijriCalendar
{
    /** @var array<int, array{0:int,1:int,2:int}>|null  روز ژولیانی آغاز ← [سال، ماه، JDN] مرتب */
    private ?array $starts = null;

    /** @return array{0:int,1:int,2:int} [سال، ماه، روز] قمری */
    public function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $jdn = Hijri::gregorianToJdn($gy, $gm, $gd);
        $starts = $this->starts();
        if ($starts === []) {
            return Hijri::fromJdn($jdn + OccasionCalendar::hijriOffset());
        }
        // آخرین ماه رسمی که پیش از این روز (یا همان روز) آغاز شده
        $found = null;
        foreach ($starts as $i => $row) {
            if ($row[2] > $jdn) {
                break;
            }
            $found = $i;
        }
        if ($found !== null) {
            [$y, $m, $start] = $starts[$found];
            $next = $starts[$found + 1] ?? null;
            $end = $next && self::follows($next, $y, $m) ? $next[2] : $start + self::tabularLength($y, $m);
            if ($jdn < $end) {
                return [$y, $m, $jdn - $start + 1];
            }
        }

        // بیرون از ماه‌های رسمی: محاسبه حسابی با همان جابه‌جایی نزدیک‌ترین ماه رسمی (بی‌پرش و بی‌تکرار روز)
        return Hijri::fromJdn($jdn - self::drift($starts[$found ?? 0]));
    }

    /** طول ماه قمری (۲۹ یا ۳۰) */
    public function monthLength(int $hy, int $hm): int
    {
        $starts = $this->starts();
        foreach ($starts as $i => [$y, $m, $jdn]) {
            if ($y === $hy && $m === $hm) {
                $next = $starts[$i + 1] ?? null;
                if ($next && self::follows($next, $y, $m)) {
                    return $next[2] - $jdn;
                }
                break;
            }
        }

        return self::tabularLength($hy, $hm);
    }

    /** تبدیل قمری به میلادی (برای تکرار ماهانه/سالانه قمری مثل عید فطر) */
    public function toGregorian(int $hy, int $hm, int $hd): array
    {
        $starts = $this->starts();
        if ($starts === []) {
            return Hijri::toGregorian($hy, $hm, $hd, OccasionCalendar::hijriOffset());
        }
        $target = $hy * 12 + $hm;
        $best = null;
        foreach ($starts as $row) {
            if ($target < $row[0] * 12 + $row[1]) {
                break;
            }
            $best = $row;
        }
        if ($best !== null && $best[0] === $hy && $best[1] === $hm) {
            return Hijri::jdnToGregorian($best[2] + $hd - 1);
        }

        return Hijri::jdnToGregorian(Hijri::toJdn($hy, $hm, $hd) + self::drift($best ?? $starts[0]));
    }

    /** پس از همگام‌سازی یا تغییر دستی */
    public function forget(): void
    {
        $this->starts = null;
        Cache::forget('hijri-official-starts');
    }

    /** آیا ردیف $row ماهِ بلافاصله بعد از (y, m) است؟ */
    private static function follows(array $row, int $y, int $m): bool
    {
        return $m === 12 ? $row[0] === $y + 1 && $row[1] === 1 : $row[0] === $y && $row[1] === $m + 1;
    }

    private static function tabularLength(int $y, int $m): int
    {
        [$ny, $nm] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];

        return Hijri::toJdn($ny, $nm, 1) - Hijri::toJdn($y, $m, 1);
    }

    /** فاصله آغاز رسمی یک ماه با آغاز حسابی آن (روز) */
    private static function drift(array $row): int
    {
        return $row[2] - Hijri::toJdn($row[0], $row[1], 1);
    }

    /** @return array<int, array{0:int,1:int,2:int}> */
    private function starts(): array
    {
        if ($this->starts !== null) {
            return $this->starts;
        }
        try {
            $this->starts = Cache::remember('hijri-official-starts', 3600, fn () => HijriMonth::query()
                ->orderBy('starts_on')
                ->get(['year', 'month', 'starts_on'])
                ->map(function (HijriMonth $m) {
                    [$y, $mo, $d] = array_map('intval', explode('-', (string) $m->starts_on));

                    return [$m->year, $m->month, Hijri::gregorianToJdn($y, $mo, $d)];
                })->all());
        } catch (Throwable) {
            // پیش از اجرای migration یا بدون پایگاه‌داده: فقط محاسبه حسابی
            $this->starts = [];
        }

        return $this->starts;
    }
}
