<?php

namespace App\Services\Sms;

/**
 * رابط مشترک همه پنل‌های پیامکی.
 *
 * برای افزودن یک پنل جدید: یک کلاس در پوشه Drivers بسازید که این رابط را
 * پیاده‌سازی کند و نامش را در SmsManager::DRIVERS و config/pedigree.php اضافه کنید.
 */
interface SmsDriver
{
    /**
     * ارسال کد تأیید با «قالب/پترن» خدماتی (سریع‌ترین و ارزان‌ترین روش در ایران)
     *
     * @throws SmsException
     */
    public function sendOtp(string $phone, string $code): void;
}
