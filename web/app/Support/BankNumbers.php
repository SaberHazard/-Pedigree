<?php

namespace App\Support;

/**
 * بررسی و قالب‌بندی شماره کارت (الگوریتم لون) و شبا (IBAN ایران، باقی‌مانده ۹۷)
 */
final class BankNumbers
{
    public static function digits(string $value): string
    {
        return preg_replace('/\D/', '', PersianText::toLatinDigits($value)) ?? '';
    }

    public static function validCard(string $value): bool
    {
        $d = self::digits($value);
        if (strlen($d) !== 16) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 16; $i++) {
            $n = (int) $d[$i];
            if ($i % 2 === 0) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
        }

        return $sum % 10 === 0;
    }

    /** شبا: IR و ۲۴ رقم؛ ورودی با یا بدون IR */
    public static function normalizeSheba(string $value): string
    {
        return 'IR'.self::digits(preg_replace('/^\s*IR/i', '', PersianText::toLatinDigits($value)) ?? '');
    }

    public static function validSheba(string $value): bool
    {
        $iban = self::normalizeSheba($value);
        if (! preg_match('/^IR\d{24}$/', $iban)) {
            return false;
        }
        // جابه‌جایی ۴ نویسه اول به انتها و تبدیل حروف (I=18, R=27)
        $numeric = substr($iban, 4).'1827'.substr($iban, 2, 2);
        $mod = 0;
        foreach (str_split($numeric) as $ch) {
            $mod = ($mod * 10 + (int) $ch) % 97;
        }

        return $mod === 1;
    }
}
