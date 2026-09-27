<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * خطای «قانون کسب‌وکار» با پیام فارسی قابل نمایش به کاربر.
 * مثال: «این شخص از قبل پدر دارد».
 * به صورت خودکار به پاسخ JSON با کد 422 تبدیل می‌شود.
 */
class DomainException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422, private readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
