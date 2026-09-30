<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تنظیم یادآوری مناسبت‌ها برای هر کاربر
 *
 * @property int $user_id
 * @property bool $enabled
 * @property string $time HH:MM
 * @property array $categories
 * @property array $days_before
 * @property int $degree
 */
class ReminderSetting extends Model
{
    public const CATEGORIES = [
        'birthday' => 'تولد بستگان',
        'anniversary' => 'سالگرد ازدواج بستگان',
        'death' => 'سالگرد درگذشت بستگان',
        'official' => 'تعطیلات و مناسبت‌های رسمی',
    ];

    public const DAYS_BEFORE = [0, 1, 2, 3, 7, 14];

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['enabled', 'time', 'categories', 'days_before', 'degree'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'categories' => 'array',
            'days_before' => 'array',
            'degree' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
