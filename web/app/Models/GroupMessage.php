<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * پیام «گروه خاندان»
 *
 * @property int $id
 * @property ?int $user_id
 * @property string $kind text|media|prompt
 * @property string $body
 * @property ?string $media_id
 * @property ?int $reply_to_id
 * @property ?Carbon $pinned_at
 * @property ?Carbon $deleted_at
 */
class GroupMessage extends Model
{
    public const KIND_TEXT = 'text';

    public const KIND_MEDIA = 'media';

    public const KIND_PROMPT = 'prompt';

    protected $fillable = ['user_id', 'kind', 'body', 'media_id', 'reply_to_id'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'pinned_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(GroupReaction::class, 'message_id');
    }
}
