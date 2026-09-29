<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * پوشش امن هر پنل پیامکی: هر خطای پیش‌بینی‌نشده (قطعی شبکه، مهلت اتصال، پاسخ خراب) به یک SmsException
 * با پیام فارسی تبدیل می‌شود. پیام خام خطا (که ممکن است آدرس پنل و حتی کلید API داخل آن باشد) نه به کاربر
 * نشان داده می‌شود و نه در لاگ ثبت می‌شود؛ فقط نوع خطا و نام پنل.
 */
final class SafeDriver implements SmsDriver
{
    public function __construct(private readonly SmsDriver $inner, private readonly string $name) {}

    public function sendOtp(string $phone, string $code): void
    {
        $this->guard(fn () => $this->inner->sendOtp($phone, $code));
    }

    public function send(string $phone, string $text): ?string
    {
        return $this->guard(fn () => $this->inner->send($phone, $text));
    }

    public function credit(): array
    {
        return $this->guard(fn () => $this->inner->credit());
    }

    public function inner(): SmsDriver
    {
        return $this->inner;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (SmsException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('SMS provider unreachable', ['driver' => $this->name, 'error' => $e::class]);

            throw new SmsException('اتصال به پنل پیامکی برقرار نشد (قطعی شبکه یا پاسخ نامعتبر). کمی بعد دوباره امتحان کنید؛ اگر سرور خارج از ایران است، «پراکسی ایران» را در تنظیمات بررسی کنید.');
        }
    }
}
