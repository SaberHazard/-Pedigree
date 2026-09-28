<?php

namespace App\Services\Sms\Drivers;

use Illuminate\Support\Facades\Log;

/**
 * درایور توسعه: پیامک‌ها به جای ارسال در storage/logs/laravel.log نوشته می‌شوند.
 * هرگز در محیط واقعی استفاده نکنید.
 */
class LogDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        Log::info("[SMS:log] OTP for {$phone}: {$code}");
    }

    public function send(string $phone, string $text): ?string
    {
        Log::info("[SMS:log] message to {$phone}: {$text}");

        return 'log-'.bin2hex(random_bytes(4));
    }

    public function credit(): array
    {
        return ['amount' => 0.0, 'unit' => 'آزمایشی (پیامکی ارسال نمی‌شود)'];
    }
}
