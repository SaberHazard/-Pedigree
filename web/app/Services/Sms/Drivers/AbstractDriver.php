<?php

namespace App\Services\Sms\Drivers;

use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractDriver implements SmsDriver
{
    public function __construct(protected array $config, protected int $timeout = 10) {}

    protected function http(): PendingRequest
    {
        // فقط خطای اتصال دوباره تلاش می‌شود (نه پاسخ خطا) تا پیامک تکراری ارسال نشود
        return Http::timeout($this->timeout)
            ->acceptJson()
            ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /** مقدار تنظیم الزامی یا خطا */
    protected function require(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if ($value === null || $value === '') {
            throw new SmsException("SMS config [{$key}] is missing for ".static::class);
        }

        return (string) $value;
    }

    /** اگر پاسخ ناموفق بود، خطا ثبت و پرتاب می‌شود (بدون ثبت کلید API در لاگ) */
    protected function ensureOk(Response $response, bool $ok): void
    {
        if (! $ok) {
            Log::warning('SMS provider error', [
                'driver' => static::class,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new SmsException('SMS provider responded with an error.');
        }
    }
}
