<?php

namespace App\Services\Auth;

use App\Exceptions\DomainException;
use App\Models\OtpCode;
use App\Services\Sms\SmsException;
use App\Services\Sms\SmsManager;
use App\Support\BlindIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * مدیریت کدهای یکبارمصرف پیامکی.
 *
 * نکات امنیتی:
 *  - خود کد ذخیره نمی‌شود، فقط HMAC آن
 *  - هر کد فقط چند بار قابل امتحان است و بعد باطل می‌شود
 *  - بین دو ارسال فاصله اجباری و در روز سقف ارسال وجود دارد
 *  - با ارسال کد جدید، کدهای قبلی باطل می‌شوند
 *  - شمارش تلاش‌ها اتمی است (درخواست‌های هم‌زمان نمی‌توانند سقف تلاش را دور بزنند)
 *  - سقف کل پیامک‌های روزانه سایت از خالی شدن شارژ پنل با حمله توزیع‌شده جلوگیری می‌کند
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

        // قفل کوتاه برای هر شماره: دو درخواست هم‌زمان باعث ارسال دو پیامک نشود
        $lock = Cache::lock('otp-send:'.$phoneHash, 15);
        if (! $lock->get()) {
            throw new DomainException('درخواست قبلی در حال انجام است؛ چند لحظه دیگر تلاش کنید.', 429, 'otp_busy');
        }

        try {
            return $this->sendLocked($phone, $phoneHash, $purpose, $ip);
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(string $phone, string $phoneHash, string $purpose, ?string $ip): string
    {
        $wait = $this->secondsUntilResend($phone, $purpose);
        if ($wait > 0) {
            throw new DomainException("لطفاً {$wait} ثانیه دیگر دوباره تلاش کنید.", 429, 'otp_cooldown');
        }

        $today = OtpCode::where('phone_hash', $phoneHash)->where('created_at', '>=', now()->subDay())->count();
        if ($today >= (int) config('pedigree.otp.daily_limit_per_phone', 10)) {
            throw new DomainException('تعداد درخواست کد برای این شماره در ۲۴ ساعت گذشته بیش از حد مجاز است.', 429, 'otp_daily_limit');
        }

        $global = (int) config('pedigree.otp.global_daily_limit', 2000);
        if ($global > 0 && OtpCode::where('created_at', '>=', now()->subDay())->count() >= $global) {
            Log::critical('Global daily OTP limit reached; SMS sending paused', ['limit' => $global]);
            throw new DomainException('ارسال پیامک موقتاً متوقف است. لطفاً با رمز عبور وارد شوید یا کمی بعد تلاش کنید.', 503, 'otp_global_limit');
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

        // ابتدا شمارنده به صورت اتمی بالا می‌رود؛ اگر سقف پر شده باشد هیچ مقایسه‌ای انجام نمی‌شود
        $max = (int) config('pedigree.otp.max_attempts', 5);
        $reserved = OtpCode::whereKey($record->id)->whereNull('consumed_at')->where('attempts', '<', $max)->increment('attempts');
        if ($reserved === 0) {
            $record->update(['consumed_at' => now()]);

            return false;
        }

        if (! hash_equals($record->code_hash, $this->hash($phone, $purpose, $code))) {
            return false;
        }

        // مصرف اتمی: فقط یک درخواست می‌تواند کد را مصرف کند
        return OtpCode::whereKey($record->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) === 1;
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
