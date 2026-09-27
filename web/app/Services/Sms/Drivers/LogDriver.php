<?php

namespace App\Services\Sms\Drivers;

use Illuminate\Support\Facades\Log;

/**
 * درایور توسعه: کد به جای پیامک در storage/logs/laravel.log نوشته می‌شود.
 * هرگز در محیط واقعی استفاده نکنید.
 */
class LogDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        Log::info("[SMS:log] OTP for {$phone}: {$code}");
    }
}
