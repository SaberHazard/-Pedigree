<?php

namespace App\Services\Sms;

use App\Services\Sms\Drivers\GhasedakDriver;
use App\Services\Sms\Drivers\IppanelDriver;
use App\Services\Sms\Drivers\KavenegarDriver;
use App\Services\Sms\Drivers\LogDriver;
use App\Services\Sms\Drivers\MelipayamakDriver;
use App\Services\Sms\Drivers\SmsIrDriver;
use InvalidArgumentException;

/**
 * انتخاب پنل پیامکی (پنل مدیریت ← تنظیمات و اتصال‌ها، یا SMS_DRIVER در ‎.env)
 *  - driver: کد ورود
 *  - message_driver: پیامک‌های تبریک اعضا
 */
class SmsManager
{
    private const DRIVERS = [
        'log' => LogDriver::class,
        'kavenegar' => KavenegarDriver::class,
        'smsir' => SmsIrDriver::class,
        'melipayamak' => MelipayamakDriver::class,
        'ippanel' => IppanelDriver::class,
        'ghasedak' => GhasedakDriver::class,
    ];

    public function driver(?string $name = null): SmsDriver
    {
        $name ??= config('pedigree.sms.driver', 'log');
        $class = self::DRIVERS[$name] ?? throw new InvalidArgumentException("SMS driver [{$name}] is not supported.");

        return new SafeDriver(new $class(
            (array) config("pedigree.sms.drivers.{$name}", []),
            (int) config('pedigree.sms.timeout', 10),
        ), $name);
    }

    public function sendOtp(string $phone, string $code): void
    {
        $this->driver()->sendOtp($phone, $code);
    }

    public function driverName(): string
    {
        return (string) config('pedigree.sms.driver', 'log');
    }

    /** پنل پیامک‌های متنی اعضا (تبریک تولد)؛ خالی = همان پنل کد ورود */
    public function messageDriverName(): string
    {
        $name = (string) config('pedigree.sms.message_driver', '');

        return $name !== '' && isset(self::DRIVERS[$name]) ? $name : $this->driverName();
    }

    public function messageDriver(): SmsDriver
    {
        return $this->driver($this->messageDriverName());
    }

    /** آیا پنل پیام‌ها واقعی است؟ (درایور آزمایشی log فقط در محیط توسعه و تست قابل قبول است) */
    public function messagesReady(): bool
    {
        return $this->messageDriverName() !== 'log' || app()->environment('local', 'testing');
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::DRIVERS);
    }
}
