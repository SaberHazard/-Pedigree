<?php

namespace App\Services\Sms\Drivers;

/**
 * SMS.ir - ارسال سریع (Verify)
 * مستندات: https://app.sms.ir/developer/help/verify
 */
class SmsIrDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->http()
            ->withHeaders(['X-API-KEY' => $this->require('api_key')])
            ->post('https://api.sms.ir/v1/send/verify', [
                'mobile' => $phone,
                'templateId' => (int) $this->require('template_id'),
                'parameters' => [
                    ['name' => $this->config['parameter'] ?? 'CODE', 'value' => $code],
                ],
            ]);

        $this->ensureOk($response, $response->successful() && (int) $response->json('status') === 1);
    }
}
