<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دستگاه موبایل ثبت‌شده برای پوش‌نوتیفیکیشن
 */
class Device extends Model
{
    protected $fillable = ['user_id', 'platform', 'token', 'app_version', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
