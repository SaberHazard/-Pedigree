<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * درخواست اتصال دو شخص از دو درخت مختلف.
 *
 * type:
 *  - spouse : subject و target با هم ازدواج کرده‌اند
 *  - father : target پدرِ subject است
 *  - mother : target مادرِ subject است
 *  - child  : target فرزندِ subject است
 */
class LinkRequest extends Model
{
    use HasUuids;

    public const TYPES = ['spouse', 'father', 'mother', 'child'];

    protected $fillable = ['type', 'subject_id', 'target_id', 'payload', 'message'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'subject_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'target_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
