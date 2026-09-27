<?php

namespace App\Services\Sms\Drivers;

/**
 * کاوه‌نگار - متد Verify Lookup
 * مستندات: https://kavenegar.com/rest.html#sms-Lookup
 * در پنل یک قالب با متغیر %token بسازید و نامش را در KAVENEGAR_OTP_TEMPLATE بگذارید.
 */
class KavenegarDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        $key = $this->require('api_key');
        $response = $this->http()->get("https://api.kavenegar.com/v1/{$key}/verify/lookup.json", [
            'receptor' => $phone,
            'token' => $code,
            'template' => $this->require('template'),
        ]);

        $this->ensureOk($response, $response->successful() && (int) $response->json('return.status') === 200);
    }
}
