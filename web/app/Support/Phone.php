<?php

namespace App\Support;

/**
 * یکدست‌سازی شماره موبایل.
 *
 * موبایل ایران در قالب 09xxxxxxxxx ذخیره می‌شود (هر ورودی مثل +989..., 00989..., 989..., 9...)
 * شماره‌های خارج از ایران (برای اقوام مقیم خارج) در قالب بین‌المللی +کدکشور ذخیره می‌شوند.
 */
final class Phone
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = PersianText::toLatinDigits(trim($value));
        $value = preg_replace('/[\s\-().]/', '', $value) ?? '';

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        // ایران
        if (preg_match('/^(?:\+98|98|0)?(9\d{9})$/', $value, $m)) {
            return '0'.$m[1];
        }

        // سایر کشورها: + و ۸ تا ۱۵ رقم
        if (preg_match('/^\+[1-9]\d{7,14}$/', $value)) {
            return $value;
        }

        return null;
    }

    public static function isIranian(string $normalized): bool
    {
        return str_starts_with($normalized, '09');
    }

    /** نمایش ماسک‌شده: 0912***4567 */
    public static function mask(?string $normalized): ?string
    {
        if ($normalized === null || strlen($normalized) < 7) {
            return $normalized;
        }

        return substr($normalized, 0, 4).str_repeat('*', strlen($normalized) - 8).substr($normalized, -4);
    }
}
