<?php

namespace Tests\Unit;

use App\Support\Jalali;
use App\Support\NationalCode;
use App\Support\PartialDate;
use App\Support\PersianText;
use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_jalali_conversion_matches_known_dates(): void
    {
        $this->assertSame([2024, 3, 20], Jalali::toGregorian(1403, 1, 1));
        $this->assertSame([1403, 1, 1], Jalali::fromGregorian(2024, 3, 20));
        $this->assertSame([1979, 2, 11], Jalali::toGregorian(1357, 11, 22));
        $this->assertSame([2021, 3, 20], Jalali::toGregorian(1399, 12, 30));
        $this->assertTrue(Jalali::isLeap(1399));
        $this->assertFalse(Jalali::isLeap(1400));
        $this->assertSame(29, Jalali::monthLength(1400, 12));
    }

    public function test_partial_dates(): void
    {
        $this->assertSame('1305', PartialDate::normalize('۱۳۰۵'));
        $this->assertSame('1305-07', PartialDate::normalize('1305/7'));
        $this->assertSame('1305-07-12', PartialDate::normalize('1305/07/12'));
        $this->assertNull(PartialDate::normalize('1305/13/01'));
        $this->assertNull(PartialDate::normalize('1400/12/30'));
        $this->assertSame('۱۲ مهر ۱۳۰۵', PartialDate::parse('1305-07-12')->toPersian());
    }

    public function test_national_code_validation(): void
    {
        $this->assertTrue(NationalCode::isValid('1234567891'));
        $this->assertTrue(NationalCode::isValid('۱۲۳۴۵۶۷۸۹۱'));
        $this->assertFalse(NationalCode::isValid('1234567890'));
        $this->assertFalse(NationalCode::isValid('1111111111'));
        $this->assertFalse(NationalCode::isValid('12345'));
    }

    public function test_phone_normalization(): void
    {
        $this->assertSame('09121234567', Phone::normalize('+98 912 123 4567'));
        $this->assertSame('09121234567', Phone::normalize('۰۹۱۲۱۲۳۴۵۶۷'));
        $this->assertSame('09121234567', Phone::normalize('00989121234567'));
        $this->assertSame('+491701234567', Phone::normalize('+49 170 1234567'));
        $this->assertNull(Phone::normalize('12345'));
    }

    public function test_persian_text_normalization(): void
    {
        $this->assertSame('علی کریمی', PersianText::normalize('علي  كريمي'));
        $this->assertSame('محمد علی', PersianText::searchable("محمد\u{200C}علی"));
    }
}
