<?php

namespace App\Support;

/**
 * ابزارهای یکدست‌سازی متن فارسی.
 *
 * مشکل رایج: کاربران با کیبورد عربی «ي» و «ك» تایپ می‌کنند یا اعداد فارسی
 * وارد می‌کنند؛ اگر یکدست نشود جستجو و مقایسه کد ملی/موبایل خراب می‌شود.
 */
final class PersianText
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /** تبدیل اعداد فارسی و عربی به لاتین */
    public static function toLatinDigits(string $value): string
    {
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $value = str_replace(self::PERSIAN_DIGITS, $latin, $value);

        return str_replace(self::ARABIC_DIGITS, $latin, $value);
    }

    /** تبدیل اعداد لاتین به فارسی (برای نمایش و پیامک) */
    public static function toPersianDigits(string $value): string
    {
        return str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::PERSIAN_DIGITS, $value);
    }

    /**
     * یکدست‌سازی حروف و فاصله‌ها:
     * ي→ی ، ك→ک ، حذف کشیده (ـ) ، حذف فاصله‌های اضافه
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(
            ['ي', 'ى', 'ك', 'ـ', "\u{200F}", "\u{200E}", "\u{FEFF}"],
            ['ی', 'ی', 'ک', '', '', '', ''],
            $value
        );
        // تبدیل فاصله‌های متوالی به یک فاصله (نیم‌فاصله حفظ می‌شود)
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * متن مخصوص جستجو: یکدست‌سازی + حذف نیم‌فاصله و حرکه‌ها + حروف کوچک
     * تا «محمدعلی»، «محمد‌علی» و «محمد علی» همدیگر را پیدا کنند.
     */
    public static function searchable(?string $value): string
    {
        $value = self::normalize(self::toLatinDigits((string) $value)) ?? '';
        $value = str_replace(["\u{200C}", 'أ', 'إ', 'آ', 'ؤ', 'ة'], [' ', 'ا', 'ا', 'ا', 'و', 'ه'], $value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value) ?? $value;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
