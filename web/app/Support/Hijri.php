<?php

namespace App\Support;

/**
 * تقویم قمری (هجری قمری جدولی/حسابی) برای مناسبت‌هایی مثل عید فطر، قربان، غدیر، روز پدر و روز مادر.
 *
 * تقویم رسمی قمری ایران با رؤیت ماه تعیین می‌شود و ممکن است با محاسبه حسابی یک یا دو روز فرق کند؛
 * برای همین مدیر سایت در پنل «اختلاف روز تقویم قمری» را تنظیم می‌کند (مثلاً ‎-1).
 */
final class Hijri
{
    public const MONTHS = [
        1 => 'محرم', 2 => 'صفر', 3 => 'ربیع‌الاول', 4 => 'ربیع‌الثانی', 5 => 'جمادی‌الاول', 6 => 'جمادی‌الثانی',
        7 => 'رجب', 8 => 'شعبان', 9 => 'رمضان', 10 => 'شوال', 11 => 'ذی‌القعده', 12 => 'ذی‌الحجه',
    ];

    /** روز ژولیانی ۱ محرم سال ۱ (جمعه ۱۶ ژوئیه ۶۲۲) */
    private const EPOCH = 1948440;

    /** @return array{0:int,1:int,2:int} [سال، ماه، روز] قمری */
    public static function fromGregorian(int $y, int $m, int $d, int $offsetDays = 0): array
    {
        return self::fromJdn(self::gregorianToJdn($y, $m, $d) + $offsetDays);
    }

    /** @return array{0:int,1:int,2:int} [سال، ماه، روز] میلادی */
    public static function toGregorian(int $hy, int $hm, int $hd, int $offsetDays = 0): array
    {
        return self::jdnToGregorian(self::toJdn($hy, $hm, $hd) - $offsetDays);
    }

    public static function toJdn(int $y, int $m, int $d): int
    {
        return $d + (int) ceil(29.5 * ($m - 1)) + ($y - 1) * 354 + intdiv(3 + 11 * $y, 30) + self::EPOCH - 1;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function fromJdn(int $jdn): array
    {
        $y = intdiv(30 * ($jdn - self::EPOCH) + 10646, 10631);
        $m = (int) min(12, ceil(($jdn - (29 + self::toJdn($y, 1, 1))) / 29.5) + 1);
        $m = max(1, $m);
        $d = $jdn - self::toJdn($y, $m, 1) + 1;

        return [$y, $m, $d];
    }

    public static function gregorianToJdn(int $y, int $m, int $d): int
    {
        $a = intdiv(14 - $m, 12);
        $y2 = $y + 4800 - $a;
        $m2 = $m + 12 * $a - 3;

        return $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function jdnToGregorian(int $jdn): array
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        return [100 * $b + $d - 4800 + intdiv($m, 10), $m + 3 - 12 * intdiv($m, 10), $e - intdiv(153 * $m + 2, 5) + 1];
    }
}
