<?php

namespace App\Services\Sms;

/**
 * رابط مشترک همه پنل‌های پیامکی.
 *
 * برای افزودن یک پنل جدید: یک کلاس در پوشه Drivers بسازید که این رابط را
 * پیاده‌سازی کند و نامش را در SmsManager::DRIVERS، config/pedigree.php و
 * SettingsSchema (پنل مدیریت) اضافه کنید.
 */
interface SmsDriver
{
    /**
     * ارسال کد تأیید با «قالب/پترن» خدماتی (سریع‌ترین و ارزان‌ترین روش در ایران)
     *
     * @throws SmsException
     */
    public function sendOtp(string $phone, string $code): void;

    /**
     * ارسال پیامک متنی (مثلاً تبریک تولد) از خط ارسال تنظیم‌شده
     *
     * @return string|null شناسه پیامک در پنل (در صورت وجود)
     *
     * @throws SmsException
     */
    public function send(string $phone, string $text): ?string;

    /**
     * اعتبار باقی‌مانده حساب
     *
     * @return array{amount: float, unit: string}
     *
     * @throws SmsException
     */
    public function credit(): array;
}
