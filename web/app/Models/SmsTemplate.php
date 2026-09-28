<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قالب ثابت پیامک تبریک (مدیر کل)
 *
 * @property int $id
 * @property string $occasion birthday|anniversary|nowruz|yalda
 * @property ?string $slug
 * @property string $title
 * @property string $body
 * @property bool $active
 * @property int $sort_order
 */
class SmsTemplate extends Model
{
    protected $fillable = ['occasion', 'slug', 'title', 'body', 'active', 'sort_order', 'updated_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }
}
