<?php

namespace App\Rules;

use App\Support\NationalCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NationalCodeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || ! NationalCode::isValid($value)) {
            $fail('کد ملی وارد شده معتبر نیست.');
        }
    }
}
