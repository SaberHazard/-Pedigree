<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || Phone::normalize($value) === null) {
            $fail('شماره موبایل معتبر نیست (نمونه: ۰۹۱۲۱۲۳۴۵۶۷).');
        }
    }
}
