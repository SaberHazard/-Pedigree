<?php

namespace App\Support;

/**
 * اعتبارسنجی کد ملی ایران بر اساس رقم کنترل.
 */
final class NationalCode
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/\D/', '', PersianText::toLatinDigits($value)) ?? '';
        if ($value === '') {
            return null;
        }

        // کدهای ۸ و ۹ رقمی قدیمی با صفر از چپ کامل می‌شوند
        return str_pad($value, 10, '0', STR_PAD_LEFT);
    }

    public static function isValid(?string $value): bool
    {
        $code = self::normalize($value);
        if ($code === null || ! preg_match('/^\d{10}$/', $code)) {
            return false;
        }
        // کدهایی مثل 1111111111 معتبر نیستند
        if (preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $check = (int) $code[9];

        return $remainder < 2 ? $check === $remainder : $check === 11 - $remainder;
    }

    /** نمایش ماسک‌شده: 001****789 */
    public static function mask(?string $code): ?string
    {
        if ($code === null || strlen($code) !== 10) {
            return $code;
        }

        return substr($code, 0, 3).'****'.substr($code, -3);
    }
}
