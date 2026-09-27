<?php

namespace App\Services\Sms\Drivers;

/**
 * قاصدک - ارسال کد تأیید با قالب
 * مستندات: https://ghasedak.me/docs
 */
class GhasedakDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->http()
            ->withHeaders(['apikey' => $this->require('api_key')])
            ->asForm()
            ->post('https://api.ghasedak.me/v2/verification/send/simple', [
                'receptor' => $phone,
                'type' => 1,
                'template' => $this->require('template'),
                'param1' => $code,
            ]);

        $this->ensureOk($response, $response->successful() && (int) $response->json('result.code') === 200);
    }
}
