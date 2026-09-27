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
 * انتخاب پنل پیامکی بر اساس تنظیم SMS_DRIVER در فایل ‎.env
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

        return new $class(
            (array) config("pedigree.sms.drivers.{$name}", []),
            (int) config('pedigree.sms.timeout', 10),
        );
    }

    public function sendOtp(string $phone, string $code): void
    {
        $this->driver()->sendOtp($phone, $code);
    }

    public function driverName(): string
    {
        return (string) config('pedigree.sms.driver', 'log');
    }
}
