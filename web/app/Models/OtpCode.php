<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * کد یکبارمصرف (فقط هش کد ذخیره می‌شود)
 */
class OtpCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['phone_hash', 'purpose', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'ip_address'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
