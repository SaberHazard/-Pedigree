<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * هشدار / یادآور شخصی
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property ?string $note
 * @property string $starts_on YYYY-MM-DD خورشیدی
 * @property string $time HH:MM وقت تهران
 * @property string $repeat
 * @property ?string $until_on
 * @property int $remind_before دقیقه
 * @property ?Carbon $next_at
 * @property bool $active
 */
class Reminder extends Model
{
    public const REPEATS = [
        'none' => 'یک بار',
        'daily' => 'هر روز',
        'weekly' => 'هر هفته',
        'monthly' => 'هر ماه (خورشیدی)',
        'yearly' => 'هر سال (خورشیدی)',
        'monthly_hijri' => 'هر ماه (قمری)',
        'yearly_hijri' => 'هر سال (قمری)',
    ];

    /** دقیقه‌های «زودتر یادآوری کن» */
    public const BEFORE = [0, 5, 10, 15, 30, 60, 120, 180, 360, 720, 1440, 2880, 4320, 10080];

    public const MAX_PER_USER = 300;

    protected $fillable = ['title', 'note', 'starts_on', 'time', 'repeat', 'until_on', 'remind_before', 'active', 'person_id'];

    protected function casts(): array
    {
        return [
            'remind_before' => 'integer',
            'active' => 'boolean',
            'next_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
