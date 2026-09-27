<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رأی یک عضو درباره تأیید یک عکس/ویدیو.
 * ردیف رأی‌دهندگان هنگام آپلود ساخته می‌شود (decision = null یعنی هنوز رأی نداده).
 */
class MediaVote extends Model
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    protected $fillable = ['media_id', 'user_id', 'decision', 'comment', 'voted_at'];

    protected function casts(): array
    {
        return ['voted_at' => 'datetime'];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
