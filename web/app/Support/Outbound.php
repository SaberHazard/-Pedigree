<?php

namespace App\Support;

/**
 * مسیر اتصال به سرویس‌های بیرونی بسته به محل سرور:
 *
 *  - سرور در ایران (پیش‌فرض): سرویس‌های خارجی (هوش مصنوعی، Firebase برای نوتیفیکیشن گوشی، عکس پروفایل تلگرام و
 *    اینستاگرام) IP ایران را نمی‌پذیرند یا فیلترند؛ همه از «پراکسی خروجی خارج» (مثلاً تونل SSH به سرور آلمان) می‌روند.
 *    سرویس‌های ایرانی (پیامک، درگاه پرداخت، تقویم رسمی) مستقیم.
 *  - سرور خارج از ایران: سرویس‌های خارجی مستقیم؛ پنل پیامک و درگاه‌های ایرانی در صورت نیاز از «پراکسی داخل ایران».
 *
 * هر بخش می‌تواند پراکسی جداگانه خودش را هم داشته باشد (مثلاً فقط برای هوش مصنوعی)؛ اگر خالی باشد همین پراکسی عمومی.
 */
final class Outbound
{
    public const LOCATIONS = ['iran' => 'ایران', 'abroad' => 'خارج از ایران (مثلاً آلمان)'];

    public static function location(): string
    {
        return config('pedigree.network.location') === 'abroad' ? 'abroad' : 'iran';
    }

    /** پراکسی سرویس‌های خارجی: پراکسی خاص همان بخش، وگرنه پراکسی عمومی خارج؛ null = مستقیم */
    public static function foreign(?string $specific = null): ?string
    {
        $proxy = trim((string) ($specific ?: config('pedigree.network.foreign_proxy')));

        return $proxy !== '' ? $proxy : null;
    }

    /** پراکسی سرویس‌های ایرانی (پیامک، درگاه پرداخت) وقتی سرور خارج از ایران است؛ null = مستقیم */
    public static function iran(): ?string
    {
        $proxy = trim((string) config('pedigree.iran_proxy'));

        return $proxy !== '' ? $proxy : null;
    }
}
