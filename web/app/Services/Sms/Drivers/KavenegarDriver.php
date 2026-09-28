<?php

namespace App\Services\Sms\Drivers;

/**
 * کاوه‌نگار
 * مستندات: https://kavenegar.com/rest.html
 *  - کد ورود: Verify Lookup با قالبی که متغیر %token دارد
 *  - پیامک عادی: sms/send از خط تنظیم‌شده (یا خط پیش‌فرض حساب)
 */
class KavenegarDriver extends AbstractDriver
{
    public function sendOtp(string $phone, string $code): void
    {
        $response = $this->http()->get($this->url('verify/lookup.json'), [
            'receptor' => $this->local($phone),
            'token' => $code,
            'template' => $this->require('template'),
        ]);

        $this->ensureOk($response, $response->successful() && (int) $response->json('return.status') === 200, $response->json('return.message'));
    }

    public function send(string $phone, string $text): ?string
    {
        $params = ['receptor' => $this->local($phone), 'message' => $text];
        if (! empty($this->config['sender'])) {
            $params['sender'] = (string) $this->config['sender'];
        }
        $response = $this->http()->asForm()->post($this->url('sms/send.json'), $params);
        $this->ensureOk($response, $response->successful() && (int) $response->json('return.status') === 200, $response->json('return.message'));

        return (string) ($response->json('entries.0.messageid') ?? '') ?: null;
    }

    public function credit(): array
    {
        $response = $this->http()->get($this->url('account/info.json'));
        $this->ensureOk($response, $response->successful() && (int) $response->json('return.status') === 200, $response->json('return.message'));

        return ['amount' => $this->number($response->json('entries.remaincredit')), 'unit' => 'ریال'];
    }

    private function url(string $path): string
    {
        return 'https://api.kavenegar.com/v1/'.rawurlencode($this->require('api_key')).'/'.$path;
    }
}
