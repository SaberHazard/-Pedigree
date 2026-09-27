<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نسخه‌های قبلی یک متن پروفایل (هیچ‌وقت حذف نمی‌شوند؛ قابل بازگردانی)
 *
 * @property int $id
 * @property int $person_text_id
 * @property int $revision
 * @property array $segments
 * @property ?int $user_id
 * @property int $added
 * @property int $removed
 * @property ?int $restored_from
 */
class PersonTextRevision extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'revision' => 'integer',
            'added' => 'integer',
            'removed' => 'integer',
            'restored_from' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function text(): BelongsTo
    {
        return $this->belongsTo(PersonText::class, 'person_text_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
