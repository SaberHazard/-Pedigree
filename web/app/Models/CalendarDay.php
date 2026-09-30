<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * یک روز تقویم رسمی (همگام‌شده): تعطیلی و مناسبت‌ها.
 *
 * @property string $jalali YYYY-MM-DD
 * @property bool $is_holiday
 * @property array|null $events [{title, note, holiday, religious}]
 */
class CalendarDay extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'jalali';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gregorian' => 'date:Y-m-d',
            'is_holiday' => 'boolean',
            'events' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
