<?php

namespace App\Services\Sms\Drivers;

/**
 * ملی‌پیامک - ارسال با خط خدماتی اشتراکی (BaseServiceNumber)
 * مستندات: https://www.melipayamak.com/api/
 */
class MelipayamakDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->http()->asForm()->post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', [
            'username' => $this->require('username'),
            'password' => $this->require('password'),
            'text' => $code,
            'to' => $phone,
            'bodyId' => $this->require('body_id'),
        ]);

        // در صورت موفقیت، Value یک شناسه عددی طولانی (recId) است
        $value = (string) $response->json('Value');
        $this->ensureOk($response, $response->successful() && strlen($value) > 5);
    }
}
