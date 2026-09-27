<?php

namespace App\Services\Sms\Drivers;

/**
 * IPPanel (فراز اس‌ام‌اس و پنل‌های مبتنی بر ippanel) - ارسال پترن
 * مستندات: https://docs.ippanel.com
 */
class IppanelDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->http()
            ->withHeaders(['apikey' => $this->require('api_key')])
            ->post('https://api2.ippanel.com/api/v1/sms/pattern/normal/send', [
                'code' => $this->require('pattern_code'),
                'sender' => $this->require('sender'),
                'recipient' => $phone,
                'variable' => [($this->config['variable'] ?? 'code') => $code],
            ]);

        $this->ensureOk($response, $response->successful());
    }
}
