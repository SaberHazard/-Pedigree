<?php

namespace App\Services\Sms\Drivers;

use Illuminate\Http\Client\PendingRequest;

/**
 * قاصدک
 * مستندات: https://ghasedak.me/docs
 *  - کد ورود: verification/send/simple با قالب
 *  - پیامک عادی: sms/send/simple از شماره خط تنظیم‌شده
 */
class GhasedakDriver extends AbstractDriver
{
    private const BASE = 'https://api.ghasedak.me/v2/';

    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->api()->post(self::BASE.'verification/send/simple', [
            'receptor' => $this->local($phone),
            'type' => 1,
            'template' => $this->require('template'),
            'param1' => $code,
        ]);

        $this->ensureOk($response, $response->successful() && (int) $response->json('result.code') === 200, $response->json('result.message'));
    }

    public function send(string $phone, string $text): ?string
    {
        $response = $this->api()->post(self::BASE.'sms/send/simple', [
            'receptor' => $this->local($phone),
            'linenumber' => $this->require('line_number'),
            'message' => $text,
        ]);
        $this->ensureOk($response, $response->successful() && (int) $response->json('result.code') === 200, $response->json('result.message'));

        return (string) ($response->json('items.0') ?? '') ?: null;
    }

    public function credit(): array
    {
        $response = $this->api()->post(self::BASE.'account/info');
        $this->ensureOk($response, $response->successful() && (int) $response->json('result.code') === 200, $response->json('result.message'));

        return ['amount' => $this->number($response->json('items.balance')), 'unit' => 'ریال'];
    }

    private function api(): PendingRequest
    {
        return $this->http()->asForm()->withHeaders(['apikey' => $this->require('api_key')]);
    }
}
