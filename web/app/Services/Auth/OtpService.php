<?php

namespace App\Services\Auth;

use App\Exceptions\DomainException;
use App\Models\OtpCode;
use App\Services\Sms\SmsException;
use App\Services\Sms\SmsManager;
use App\Support\BlindIndex;
use Illuminate\Support\Facades\Log;

/**
 * مدیریت کدهای یکبارمصرف پیامکی.
 *
 * نکات امنیتی:
 *  - خود کد ذخیره نمی‌شود، فقط HMAC آن
 *  - هر کد فقط چند بار قابل امتحان است و بعد باطل می‌شود
 *  - بین دو ارسال فاصله اجباری و در روز سقف ارسال وجود دارد
 *  - با ارسال کد جدید، کدهای قبلی باطل می‌شوند
 */
class OtpService
{
    public function __construct(private readonly SmsManager $sms) {}

    /**
     * ساخت و ارسال کد.
     *
     * @return string کد تولیدشده (فقط برای حالت توسعه برگردانده می‌شود)
     */
    public function send(string $phone, string $purpose, ?string $ip = null): string
    {
        $phoneHash = BlindIndex::make($phone, 'phone');

        $wait = $this->secondsUntilResend($phone, $purpose);
        if ($wait > 0) {
            throw new DomainException("لطفاً {$wait} ثانیه دیگر دوباره تلاش کنید.", 429, 'otp_cooldown');
        }

        $today = OtpCode::where('phone_hash', $phoneHash)->where('created_at', '>=', now()->subDay())->count();
        if ($today >= (int) config('pedigree.otp.daily_limit_per_phone', 10)) {
            throw new DomainException('تعداد درخواست کد برای این شماره در ۲۴ ساعت گذشته بیش از حد مجاز است.', 429, 'otp_daily_limit');
        }

        // باطل کردن کدهای قبلی
        OtpCode::where('phone_hash', $phoneHash)->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $length = max(4, min(8, (int) config('pedigree.otp.length', 6)));
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone_hash' => $phoneHash,
            'purpose' => $purpose,
            'code_hash' => $this->hash($phone, $purpose, $code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds((int) config('pedigree.otp.ttl', 120)),
            'ip_address' => $ip,
        ]);

        try {
            $this->sms->sendOtp($phone, $code);
        } catch (SmsException $e) {
            Log::error('OTP SMS failed: '.$e->getMessage());
            throw new DomainException('ارسال پیامک با خطا مواجه شد. لطفاً کمی بعد دوباره تلاش کنید یا با کد ملی و رمز وارد شوید.', 503, 'sms_failed');
        }

        return $code;
    }

    /** بررسی کد؛ در صورت درستی، کد مصرف‌شده علامت می‌خورد */
    public function verify(string $phone, string $purpose, string $code): bool
    {
        $record = OtpCode::where('phone_hash', BlindIndex::make($phone, 'phone'))
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $record) {
            return false;
        }

        if ($record->attempts >= (int) config('pedigree.otp.max_attempts', 5)) {
            $record->update(['consumed_at' => now()]);

            return false;
        }

        if (! hash_equals($record->code_hash, $this->hash($phone, $purpose, $code))) {
            $record->increment('attempts');

            return false;
        }

        $record->update(['consumed_at' => now()]);

        return true;
    }

    public function secondsUntilResend(string $phone, string $purpose): int
    {
        $last = OtpCode::where('phone_hash', BlindIndex::make($phone, 'phone'))
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if (! $last) {
            return 0;
        }

        $cooldown = (int) config('pedigree.otp.resend_cooldown', 60);
        $elapsed = (int) $last->created_at->diffInSeconds(now(), true);

        return max(0, $cooldown - $elapsed);
    }

    private function hash(string $phone, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}|{$phone}|{$code}", (string) config('app.key'));
    }
}
