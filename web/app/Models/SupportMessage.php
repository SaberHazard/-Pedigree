<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * پیام گفتگو با پشتیبانی (متن رمزنگاری‌شده، با ایموجی، یا پیام صوتی)
 */
class SupportMessage extends Model
{
    protected $fillable = ['thread_id', 'sender_id', 'from_admin', 'body', 'voice_id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'from_admin' => 'boolean', 'deleted_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SupportThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(VoiceNote::class, 'voice_id');
    }
}
