<?php

namespace App\Providers;

use App\Services\Access\PersonAccess;
use App\Services\Kinship;
use App\Services\Sms\SmsManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // این سرویس‌ها در طول یک درخواست نتایج را کش می‌کنند (scoped = یک نمونه برای هر درخواست)
        $this->app->scoped(Kinship::class);
        $this->app->scoped(PersonAccess::class);
        $this->app->singleton(SmsManager::class);
    }

    public function boot(): void
    {
        $this->configureRateLimits();
    }

    /**
     * محدودیت نرخ درخواست‌ها (جلوگیری از سوءاستفاده، حدس زدن رمز و هدر رفتن شارژ پیامک)
     */
    private function configureRateLimits(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp-m:'.$request->ip()),
            Limit::perHour((int) config('pedigree.otp.hourly_limit_per_ip', 20))->by('otp-h:'.$request->ip()),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by('otp-v:'.$request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by('login:'.$request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(120)->by('up:'.($request->user()?->id ?: $request->ip())));
    }
}
