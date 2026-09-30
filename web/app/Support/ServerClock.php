<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * دقت ساعت سرور (برای هشدارها و پیامک ساعت ۰۰:۰۰): اختلاف ساعت سرور با ساعت یک سرور مرجع از روی هدر Date پاسخ HTTP.
 * همه زمان‌بندی‌ها با منطقه Asia/Tehran حساب می‌شوند؛ این بررسی فقط جلو یا عقب بودن خود ساعت سرور را نشان می‌دهد.
 */
final class ServerClock
{
    /** مرجع‌ها: یکی داخل ایران (در اینترنت ملی هم در دسترس) و یکی جهانی */
    private const REFERENCES = ['https://holidayapi.ir/', 'https://www.cloudflare.com/'];

    /** اختلاف به ثانیه (مثبت = ساعت سرور جلوتر است)؛ null = قابل سنجش نبود. نیم ساعت کش */
    public static function drift(): ?int
    {
        return Cache::remember('server-clock-drift', 1800, function () {
            foreach (self::REFERENCES as $url) {
                try {
                    $before = microtime(true);
                    $response = Http::timeout(8)->withOptions(['allow_redirects' => false])->head($url);
                    $after = microtime(true);
                    $date = $response->header('Date');
                    if ($date === '' || ($remote = strtotime($date)) === false) {
                        continue;
                    }

                    // زمان وسط رفت‌وبرگشت (هدر Date دقت ثانیه دارد)
                    return (int) round(($before + $after) / 2 - $remote);
                } catch (Throwable) {
                    continue;
                }
            }

            return null;
        });
    }
}
