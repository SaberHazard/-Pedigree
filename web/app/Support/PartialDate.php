<?php

namespace App\Support;

/**
 * «تاریخ جزئی» شمسی: فقط سال، یا سال و ماه، یا تاریخ کامل.
 *
 * قالب ذخیره: "1305" یا "1305-07" یا "1305-07-12"
 * این قالب به صورت متنی هم درست مرتب می‌شود (برای ORDER BY).
 */
final class PartialDate
{
    public function __construct(
        public readonly int $year,
        public readonly ?int $month = null,
        public readonly ?int $day = null,
    ) {}

    /**
     * خواندن ورودی کاربر (با اعداد فارسی/عربی/لاتین و جداکننده - یا /)
     * خروجی null یعنی ورودی نامعتبر است.
     */
    public static function parse(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $value = trim(PersianText::toLatinDigits($value));
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(\d{1,4})(?:[-\/.](\d{1,2})(?:[-\/.](\d{1,2}))?)?$/', $value, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $month = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
        $day = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

        if ($year < 1 || $year > 1600) {
            return null;
        }
        if ($month !== null && ($month < 1 || $month > 12)) {
            return null;
        }
        if ($day !== null && ($month === null || $day < 1 || $day > Jalali::monthLength($year, $month))) {
            return null;
        }

        return new self($year, $month, $day);
    }

    /** تبدیل مستقیم ورودی به قالب ذخیره (یا null) */
    public static function normalize(?string $value): ?string
    {
        return self::parse($value)?->toString();
    }

    public function toString(): string
    {
        $out = str_pad((string) $this->year, 4, '0', STR_PAD_LEFT);
        if ($this->month !== null) {
            $out .= '-'.str_pad((string) $this->month, 2, '0', STR_PAD_LEFT);
            if ($this->day !== null) {
                $out .= '-'.str_pad((string) $this->day, 2, '0', STR_PAD_LEFT);
            }
        }

        return $out;
    }

    /** نمایش فارسی: «۱۲ مهر ۱۳۰۵» */
    public function toPersian(): string
    {
        $parts = [];
        if ($this->day !== null) {
            $parts[] = (string) $this->day;
        }
        if ($this->month !== null) {
            $parts[] = Jalali::MONTHS[$this->month];
        }
        $parts[] = (string) $this->year;

        return PersianText::toPersianDigits(implode(' ', $parts));
    }

    /** اگر تاریخ کامل باشد معادل میلادی‌اش را برمی‌گرداند (Y-m-d) */
    public function toGregorianString(): ?string
    {
        if ($this->month === null || $this->day === null) {
            return null;
        }
        [$gy, $gm, $gd] = Jalali::toGregorian($this->year, $this->month, $this->day);

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /** سال میلادی تقریبی (برای خروجی GEDCOM وقتی فقط سال شمسی معلوم است) */
    public function approximateGregorianYear(): int
    {
        return $this->year + 621;
    }
}
