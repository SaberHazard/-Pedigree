<?php

namespace App\Services\Sms\Drivers;

use Illuminate\Http\Client\PendingRequest;

/**
 * IPPanel (فراز اس‌ام‌اس و پنل‌های مبتنی بر ippanel)
 * مستندات: https://docs.ippanel.com
 *  - کد ورود: ارسال پترن
 *  - پیامک عادی: send/webservice/single
 */
class IppanelDriver extends AbstractDriver
{
    private const BASE = 'https://api2.ippanel.com/api/v1/';

    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->api()->post(self::BASE.'sms/pattern/normal/send', [
            'code' => $this->require('pattern_code'),
            'sender' => $this->require('sender'),
            'recipient' => $this->international($phone),
            'variable' => [($this->config['variable'] ?? 'code') => $code],
        ]);

        $this->ensureOk($response, $response->successful(), $response->json('meta.message'));
    }

    public function send(string $phone, string $text): ?string
    {
        $response = $this->api()->post(self::BASE.'sms/send/webservice/single', [
            'recipient' => [$this->international($phone)],
            'sender' => $this->require('sender'),
            'message' => $text,
        ]);
        $this->ensureOk($response, $response->successful() && $response->json('meta.status') !== false, $response->json('meta.message'));

        return (string) ($response->json('data.message_id') ?? '') ?: null;
    }

    public function credit(): array
    {
        $response = $this->api()->get(self::BASE.'sms/accounting/credit/show');
        $this->ensureOk($response, $response->successful(), $response->json('meta.message'));

        return ['amount' => $this->number($response->json('data.credit')), 'unit' => 'ریال'];
    }

    private function api(): PendingRequest
    {
        return $this->http()->withHeaders(['apikey' => $this->require('api_key')]);
    }
}
