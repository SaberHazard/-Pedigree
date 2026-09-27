<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * امتیاز ۱ تا ۵ یک عضو به یکی از ویژگی‌های یک شخص
 *
 * @property int $id
 * @property string $person_id
 * @property int $user_id
 * @property string $trait
 * @property int $score
 */
class PersonRating extends Model
{
    protected $fillable = ['person_id', 'user_id', 'trait', 'score'];

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
