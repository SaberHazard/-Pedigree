<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * گفتگوی هر عضو با پشتیبانی سایت (یک رشته برای هر عضو؛ همه مدیران می‌بینند و پاسخ می‌دهند)
 */
class SupportThread extends Model
{
    protected $fillable = ['user_id', 'last_message_at', 'user_read_id', 'admin_read_id', 'closed'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'closed' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'thread_id');
    }
}
