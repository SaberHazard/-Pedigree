<?php

namespace App\Services\Sms\Drivers;

use Illuminate\Http\Client\PendingRequest;

/**
 * SMS.ir
 * مستندات: https://app.sms.ir/developer/help
 *  - کد ورود: send/verify با قالب «ارسال سریع»
 *  - پیامک عادی: send/bulk از شماره خط تنظیم‌شده
 */
class SmsIrDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->api()->post('https://api.sms.ir/v1/send/verify', [
            'mobile' => $this->local($phone),
            'templateId' => (int) $this->require('template_id'),
            'parameters' => [
                ['name' => $this->config['parameter'] ?? 'CODE', 'value' => $code],
            ],
        ]);

        $this->ensureOk($response, $response->successful() && (int) $response->json('status') === 1, $response->json('message'));
    }

    public function send(string $phone, string $text): ?string
    {
        $response = $this->api()->post('https://api.sms.ir/v1/send/bulk', [
            'lineNumber' => (int) $this->require('line_number'),
            'messageText' => $text,
            'mobiles' => [$this->local($phone)],
        ]);
        $this->ensureOk($response, $response->successful() && (int) $response->json('status') === 1, $response->json('message'));

        return (string) ($response->json('data.packId') ?? '') ?: null;
    }

    public function credit(): array
    {
        $response = $this->api()->get('https://api.sms.ir/v1/credit');
        $this->ensureOk($response, $response->successful() && (int) $response->json('status') === 1, $response->json('message'));

        return ['amount' => $this->number($response->json('data')), 'unit' => 'پیامک'];
    }

    private function api(): PendingRequest
    {
        return $this->http()->withHeaders(['X-API-KEY' => $this->require('api_key')]);
    }
}
