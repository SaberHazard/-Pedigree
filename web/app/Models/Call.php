<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک تماس مستقیم (WebRTC) بین دو یا چند عضو
 *
 * @property string $id
 * @property int $created_by
 * @property string $kind audio|video
 * @property string $status ringing|active|ended
 */
class Call extends Model
{
    use HasUlids;

    public const KINDS = ['audio', 'video'];

    protected $fillable = ['created_by', 'kind', 'status', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
