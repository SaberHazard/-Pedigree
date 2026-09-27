<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * نظر یکی از اعضای خاندان درباره یک شخص
 *
 * @property int $id
 * @property string $person_id
 * @property ?int $user_id
 * @property string $body
 * @property ?Carbon $hidden_at
 * @property ?int $hidden_by
 * @property ?Carbon $edited_at
 */
class PersonComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['body'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime', 'edited_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }
}
