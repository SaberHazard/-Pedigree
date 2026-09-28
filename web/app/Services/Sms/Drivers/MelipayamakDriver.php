<?php

namespace App\Services\Sms\Drivers;

/**
 * ملی‌پیامک
 * مستندات: https://www.melipayamak.com/api/
 *  - کد ورود: BaseServiceNumber با کد الگو (bodyId)
 *  - پیامک عادی: SendSMS از شماره خط تنظیم‌شده
 */
class MelipayamakDriver extends AbstractDriver
{
    private const BASE = 'https://rest.payamak-panel.com/api/SendSMS/';

    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->http()->asForm()->post(self::BASE.'BaseServiceNumber', $this->auth() + [
            'text' => $code,
            'to' => $this->local($phone),
            'bodyId' => $this->require('body_id'),
        ]);

        // در صورت موفقیت، Value یک شناسه عددی طولانی (recId) است
        $value = (string) $response->json('Value');
        $this->ensureOk($response, $response->successful() && strlen($value) > 5, $response->json('StrRetStatus'));
    }

    public function send(string $phone, string $text): ?string
    {
        $response = $this->http()->asForm()->post(self::BASE.'SendSMS', $this->auth() + [
            'to' => $this->local($phone),
            'from' => $this->require('from'),
            'text' => $text,
            'isFlash' => 'false',
        ]);
        $value = (string) $response->json('Value');
        $this->ensureOk($response, $response->successful() && (int) $response->json('RetStatus') === 1 && strlen($value) > 5, $response->json('StrRetStatus'));

        return $value;
    }

    public function credit(): array
    {
        $response = $this->http()->asForm()->post(self::BASE.'GetCredit', $this->auth());
        $this->ensureOk($response, $response->successful() && (int) $response->json('RetStatus') === 1, $response->json('StrRetStatus'));

        return ['amount' => $this->number($response->json('Value')), 'unit' => 'پیامک'];
    }

    private function auth(): array
    {
        return ['username' => $this->require('username'), 'password' => $this->require('password')];
    }
}
