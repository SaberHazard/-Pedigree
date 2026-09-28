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
            throw new SmsException("تنظیم «{$key}» برای این پنل پیامکی وارد نشده است (پنل مدیریت ← تنظیمات و اتصال‌ها).");
        }

        return (string) $value;
    }

    /**
     * اگر پاسخ ناموفق بود، خطا ثبت و پرتاب می‌شود (بدون ثبت کلید API در لاگ).
     * پیام خود پنل (مثلاً «اعتبار کافی نیست») برای مدیر نمایش داده می‌شود.
     */
    protected function ensureOk(Response $response, bool $ok, mixed $providerMessage = null): void
    {
        if ($ok) {
            return;
        }
        Log::warning('SMS provider error', [
            'driver' => static::class,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ]);
        $detail = is_string($providerMessage) && trim($providerMessage) !== ''
            ? mb_substr(strip_tags($providerMessage), 0, 200)
            : 'کد پاسخ '.$response->status();

        throw new SmsException("پنل پیامکی خطا داد: {$detail}");
    }

    /** موبایل ایران به شکل 0912...؛ شماره خارجی همان +کدکشور */
    protected function local(string $phone): string
    {
        return preg_match('/^\+98(9\d{9})$/', $phone, $m) ? '0'.$m[1] : $phone;
    }

    /** شماره بین‌المللی +98912... */
    protected function international(string $phone): string
    {
        return preg_match('/^0(9\d{9})$/', $phone, $m) ? '+98'.$m[1] : $phone;
    }

    protected function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
