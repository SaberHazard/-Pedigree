<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * آغاز رسمی یک ماه قمری در ایران (از تقویم رسمی یا تنظیم دستی مدیر).
 *
 * @property int $year
 * @property int $month
 * @property string $starts_on Y-m-d میلادی
 * @property string $source official|manual
 */
class HijriMonth extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer'];
    }
}
