<?php

namespace App\Models;

use App\Support\VoiceUrl;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * پیام صوتی فشرده (AAC تک‌کاناله حدود ۳۲ کیلوبیت، مثل وویس تلگرام) در فضای خصوصی؛ فقط با لینک امضاشده
 *
 * @property string $id
 * @property int $duration_ms
 * @property ?array $waveform
 */
class VoiceNote extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'context', 'disk', 'path', 'mime', 'size', 'duration_ms', 'waveform'];

    protected function casts(): array
    {
        return ['waveform' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** اطلاعات پخش برای کسی که اجازه شنیدن دارد (لینک امضاشده زمان‌دار) */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'url' => VoiceUrl::for($this),
            'duration' => round($this->duration_ms / 1000, 1),
            'waveform' => array_values((array) ($this->waveform ?? [])),
        ];
    }
}
