<?php

namespace App\Support;

/**
 * «ایندکس کور»: هش HMAC از یک مقدار حساس.
 *
 * چون کد ملی و موبایل رمزنگاری می‌شوند (و رمزنگاری Laravel هر بار خروجی
 * متفاوت می‌دهد) نمی‌توان مستقیم رویشان جستجو کرد. این هش ثابت و یکطرفه
 * امکان جستجو و یکتا بودن را بدون افشای مقدار اصلی فراهم می‌کند.
 */
final class BlindIndex
{
    public static function make(string $value, string $context): string
    {
        return hash_hmac('sha256', $context.'|'.$value, self::key());
    }

    private static function key(): string
    {
        $key = config('pedigree.blind_index_key');
        if (! empty($key)) {
            return (string) $key;
        }

        // مشتق از APP_KEY
        return hash('sha256', 'pedigree-blind-index|'.config('app.key'), true);
    }
}
