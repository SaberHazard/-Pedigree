<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک خطای ثبت‌شده (سرور یا مرورگر) برای پنل مدیریت.
 *
 * @property int $id
 * @property string $ref کد پیگیری کوتاه که به کاربر هم نشان داده می‌شود
 * @property string $source server|client
 * @property string $type
 * @property string $message
 * @property ?string $location
 * @property ?string $path
 * @property int $count
 */
class ErrorReport extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
