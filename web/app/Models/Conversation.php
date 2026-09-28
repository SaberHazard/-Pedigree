<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * گفتگوی دونفره بین دو عضو.
 *
 * @property int $id
 * @property int $user_one_id
 * @property int $user_two_id
 * @property ?Carbon $last_message_at
 */
class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class);
    }

    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id));
    }

    public function hasParticipant(User $user): bool
    {
        return $this->user_one_id === $user->id || $this->user_two_id === $user->id;
    }

    public function otherId(User $user): int
    {
        return $this->user_one_id === $user->id ? $this->user_two_id : $this->user_one_id;
    }

    /** گفتگوی دو کاربر (یا null) */
    public static function between(User $a, User $b): ?self
    {
        [$one, $two] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return self::query()->where('user_one_id', $one)->where('user_two_id', $two)->first();
    }

    public static function findOrCreateBetween(User $a, User $b): self
    {
        [$one, $two] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return self::query()->firstOrCreate(['user_one_id' => $one, 'user_two_id' => $two]);
    }
}
