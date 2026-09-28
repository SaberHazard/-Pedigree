<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک پیام متنی (با ایموجی). متن با کلید برنامه رمزنگاری‌شده ذخیره می‌شود.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_id
 * @property string $body
 * @property ?Carbon $read_at
 * @property ?Carbon $deleted_at
 */
class DirectMessage extends Model
{
    protected $fillable = ['conversation_id', 'sender_id', 'body'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'read_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
