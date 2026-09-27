<?php

namespace App\Rules;

use App\Support\PartialDate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * اعتبارسنجی تاریخ جزئی شمسی: 1305 یا 1305-07 یا 1305-07-12
 */
class PartialDateRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || PartialDate::parse($value) === null) {
            $fail('تاریخ :attribute معتبر نیست (نمونه صحیح: ۱۳۰۵ یا ۱۳۰۵/۰۷/۱۲).');
        }
    }
}
